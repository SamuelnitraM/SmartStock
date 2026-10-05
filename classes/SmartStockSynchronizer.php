<?php
/**
 * SmartStock - Shared stock for product combinations.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Shared stock engine. Every operation altering a shared stock goes through this class.
 *
 * Model:
 * - Each product owns a pool expressed in base units (grams, millilitres, pieces...) per stock perimeter.
 * - Each managed combination consumes "ratio" base units per unit sold.
 * - The sellable quantity of a combination is always floor(pool / ratio).
 *
 * Reconciliation algorithm (idempotent, triggered by every core stock event):
 * 1. Under a per-product lock, read the current quantity of every managed combination.
 * 2. Compare it with the mirror (last quantity accounted into the pool) and convert the difference into base units.
 * 3. Apply the sum of differences, then the explicit operation (inventory, receipt, loss) to the pool, journaling each movement.
 * 4. Write floor(pool / ratio) on every combination with a compare-and-set, so a concurrent core update is never lost:
 *    a row modified in between keeps its new value and is accounted by its own upcoming event.
 * 5. Once the lock is released, notify third-party modules and the low stock listeners.
 */
class SmartStockSynchronizer
{
    /** @var bool True while the module notifies third-party modules of its own stock writes */
    private static $isPropagating = false;

    /** @var array<int, int> Nesting depth of the product locks held by the current request */
    private static $lockDepthByProduct = [];

    /** @var array<int, array> Stock changes waiting to be broadcast once the product lock is released */
    private static $pendingNotifications = [];

    /** @var array<int, array> Low stock crossings waiting to be broadcast once the product lock is released */
    private static $pendingLowStockAlerts = [];

    /** @var int[] Combination movements recorded during the request, waiting to be linked to an order */
    private static $unattachedMovementIds = [];

    /** @var SmartStockRepository */
    private $repository;

    /** @var callable[] Listeners called with (productId, SmartStockScope, poolQuantity, alertThreshold) */
    private $lowStockListeners = [];

    public function __construct(SmartStockRepository $repository)
    {
        $this->repository = $repository;
    }

    public function addLowStockListener(callable $lowStockListener): void
    {
        $this->lowStockListeners[] = $lowStockListener;
    }

    /**
     * Entry point of every stock event raised by the core.
     *
     * @param SmartStockScope|null $scope Perimeter of the event, or null to reconcile every perimeter of the product
     */
    public function handleStockChange(int $productId, int $combinationId, ?SmartStockScope $scope): void
    {
        if (self::$isPropagating || $productId <= 0 || $combinationId <= 0 || !$this->repository->isProductActive($productId)) {
            return;
        }
        if (!isset($this->repository->getManagedRatios($productId)[$combinationId])) {
            return;
        }
        $scopes = $scope === null ? $this->repository->getProductScopes($productId) : [$scope];
        $this->runLocked($productId, function () use ($productId, $scopes) {
            foreach ($scopes as $scopeToReconcile) {
                $this->reconcileScope($productId, $scopeToReconcile, SmartStockPoolOperation::none());
            }
        });
    }

    /**
     * Reconciles every perimeter of a product, absorbing any change made outside the module.
     */
    public function reconcileProduct(int $productId): void
    {
        if (!$this->repository->isProductActive($productId)) {
            return;
        }
        $this->runLocked($productId, function () use ($productId) {
            foreach ($this->repository->getProductScopes($productId) as $scope) {
                $this->reconcileScope($productId, $scope, SmartStockPoolOperation::none());
            }
        });
    }

    /**
     * Saves the configuration of a product and applies it immediately to its stock.
     *
     * @param array<int, int> $ratiosByCombination Units consumed per sale, 0 keeping the combination on its own stock
     * @param int|null $poolQuantity Shared stock to set on the edited perimeter, null to keep the current one
     */
    public function configureProduct(int $productId, bool $isActive, string $unit, int $alertThreshold, array $ratiosByCombination, SmartStockScope $editedScope, ?int $poolQuantity): void
    {
        $this->runLocked($productId, function () use ($productId, $isActive, $unit, $alertThreshold, $ratiosByCombination, $editedScope, $poolQuantity) {
            $wasActive = $this->repository->isProductActive($productId);
            $productScopes = $this->repository->getProductScopes($productId);
            if ($wasActive) {
                foreach ($productScopes as $scope) {
                    $this->reconcileScope($productId, $scope, SmartStockPoolOperation::none());
                }
            }
            if (!$this->repository->saveProductConfiguration($productId, $isActive, $unit, $alertThreshold)
                || !$this->repository->saveRatios($productId, $ratiosByCombination)) {
                throw new SmartStockException('Unable to save the product configuration.');
            }
            if (!$isActive || !$wasActive) {
                $this->repository->deleteProductRuntimeState($productId);
            }
            if (!$isActive) {
                return;
            }
            $this->repository->deleteUnmanagedMirrors($productId, array_keys($this->repository->getManagedRatios($productId)));
            foreach ($productScopes as $scope) {
                $isEditedScope = $scope->getKey() === $editedScope->getKey() && $poolQuantity !== null;
                $this->reconcileScope($productId, $scope, $isEditedScope ? SmartStockPoolOperation::inventory($poolQuantity) : SmartStockPoolOperation::none());
            }
        });
    }

    /**
     * Applies an inventory (absolute value) and/or a movement (goods receipt, loss) to the shared stock of a perimeter.
     */
    public function updatePool(int $productId, SmartStockScope $scope, SmartStockPoolOperation $poolOperation): void
    {
        if (!$this->repository->isProductActive($productId)) {
            throw new SmartStockException('The shared stock is not enabled for this product.');
        }
        $this->runLocked($productId, function () use ($productId, $scope, $poolOperation) {
            $this->reconcileScope($productId, $scope, $poolOperation);
        });
    }

    /**
     * Returns and forgets the combination movements recorded since the last call, to link them to an order.
     *
     * @return int[]
     */
    public static function takeUnattachedMovementIds(): array
    {
        $movementIds = self::$unattachedMovementIds;
        self::$unattachedMovementIds = [];
        return $movementIds;
    }

    /**
     * Builds the complete state of a product for the back office screens.
     */
    public function getProductState(int $productId, SmartStockScope $scope, int $languageId, int $movementLimit = 0): array
    {
        $productConfiguration = $this->repository->getProductConfiguration($productId);
        $isActive = $productConfiguration !== null && $productConfiguration['active'];
        $storedRatios = $this->repository->getRatios($productId);
        $stockQuantities = $this->repository->getCombinationStockQuantities($productId, $scope);
        $combinationLabels = $this->getCombinationLabels($productId, $languageId);
        $configuredUnit = $productConfiguration !== null ? $productConfiguration['unit'] : '';
        $detectedUnit = (string) SmartStockUnit::detectBaseUnit(array_values($combinationLabels));
        $suggestionUnit = $configuredUnit !== '' ? $configuredUnit : $detectedUnit;
        $effectiveRatios = [];
        $combinations = [];
        foreach ($combinationLabels as $combinationId => $combinationLabel) {
            $suggestedRatio = SmartStockUnit::suggestRatio($combinationLabel, $suggestionUnit);
            $ratio = array_key_exists($combinationId, $storedRatios) ? $storedRatios[$combinationId] : ($isActive ? 0 : $suggestedRatio);
            $effectiveRatios[$combinationId] = $ratio;
            $combinations[] = [
                'id_product_attribute' => $combinationId,
                'name' => $combinationLabel,
                'ratio' => $ratio,
                'suggested_ratio' => $suggestedRatio,
                'quantity' => isset($stockQuantities[$combinationId]) ? $stockQuantities[$combinationId] : 0,
            ];
        }
        $storedPoolQuantity = $isActive ? $this->repository->getPoolQuantity($productId, $scope) : null;
        $poolQuantity = $storedPoolQuantity !== null ? $storedPoolQuantity : $this->computeDefaultPoolQuantity($stockQuantities, $effectiveRatios);
        $alertThreshold = $productConfiguration !== null ? $productConfiguration['alert_threshold'] : 0;
        $displayUnit = $configuredUnit !== '' ? $configuredUnit : $detectedUnit;
        return [
            'id_product' => $productId,
            'name' => (string) Product::getProductName($productId, null, $languageId),
            'active' => $isActive,
            'unit' => $configuredUnit,
            'detected_unit' => $detectedUnit,
            'alert_threshold' => $alertThreshold,
            'pool_quantity' => $poolQuantity,
            'pool_readable' => SmartStockUnit::formatQuantity($poolQuantity, $displayUnit),
            'pool_initialized' => $storedPoolQuantity !== null,
            'is_low' => $isActive && $alertThreshold > 0 && $poolQuantity < $alertThreshold,
            'combinations' => $combinations,
            'movements' => $movementLimit > 0 ? $this->getMovementHistory($productId, $scope, $languageId, $movementLimit) : [],
        ];
    }

    /**
     * Latest movements of a product (or of every product when null), enriched with labels for display.
     */
    public function getMovementHistory(?int $productId, ?SmartStockScope $scope, int $languageId, int $limit): array
    {
        $movements = $this->repository->getMovements($productId, $scope, $limit);
        $combinationLabelsByProduct = [];
        $productNames = [];
        $movementUnits = [];
        foreach ($movements as &$movement) {
            $movementProductId = $movement['id_product'];
            if (!isset($combinationLabelsByProduct[$movementProductId])) {
                $combinationLabelsByProduct[$movementProductId] = $this->getCombinationLabels($movementProductId, $languageId);
                $productNames[$movementProductId] = (string) Product::getProductName($movementProductId, null, $languageId);
                $productConfiguration = $this->repository->getProductConfiguration($movementProductId);
                $movementUnits[$movementProductId] = $productConfiguration !== null ? $productConfiguration['unit'] : '';
            }
            $combinationLabels = $combinationLabelsByProduct[$movementProductId];
            $movement['product_name'] = $productNames[$movementProductId];
            $movement['combination_name'] = isset($combinationLabels[$movement['id_product_attribute']]) ? $combinationLabels[$movement['id_product_attribute']] : '';
            $movement['unit'] = $movementUnits[$movementProductId];
            $movement['delta_readable'] = ($movement['quantity_delta'] > 0 ? '+' : '') . SmartStockUnit::formatQuantity($movement['quantity_delta'], $movement['unit']);
            $movement['after_readable'] = SmartStockUnit::formatQuantity($movement['quantity_after'], $movement['unit']);
        }
        unset($movement);
        return $movements;
    }

    /**
     * Ratio suggestions for every combination of a product, for a given base unit.
     *
     * @return array<int, int> Suggested ratios indexed by combination identifier
     */
    public function suggestRatios(int $productId, string $baseUnit, int $languageId): array
    {
        $suggestedRatios = [];
        foreach ($this->getCombinationLabels($productId, $languageId) as $combinationId => $combinationLabel) {
            $suggestedRatios[$combinationId] = SmartStockUnit::suggestRatio($combinationLabel, $baseUnit);
        }
        return $suggestedRatios;
    }

    /**
     * Sellable quantity of a combination for a given pool: floor(pool / ratio), also for negative pools (backorders).
     */
    public static function computeSellableQuantity(int $poolQuantity, int $ratio): int
    {
        $quotient = intdiv($poolQuantity, $ratio);
        return ($poolQuantity % $ratio !== 0 && $poolQuantity < 0) ? $quotient - 1 : $quotient;
    }

    /**
     * Core of the engine: converts the combination changes into journaled pool movements, then redistributes the pool.
     */
    private function reconcileScope(int $productId, SmartStockScope $scope, SmartStockPoolOperation $poolOperation): void
    {
        $managedRatios = $this->repository->getManagedRatios($productId);
        if (empty($managedRatios)) {
            return;
        }
        $stockQuantities = $this->repository->getCombinationStockQuantities($productId, $scope, array_keys($managedRatios));
        if (empty($stockQuantities) && $poolOperation->isEmpty()) {
            return;
        }
        $employeeId = SmartStockMovementReason::getContextEmployeeId();
        $mirrorQuantities = $this->repository->getMirrorQuantities($productId, $scope);
        $storedPoolQuantity = $this->repository->getPoolQuantity($productId, $scope);
        $movements = [];
        if ($storedPoolQuantity === null) {
            $absoluteQuantity = $poolOperation->getAbsoluteQuantity();
            $poolQuantity = $absoluteQuantity !== null ? $absoluteQuantity : $this->computeDefaultPoolQuantity($stockQuantities, $managedRatios);
            $mirrorQuantities = $stockQuantities;
            $movements[] = [0, $poolQuantity, SmartStockMovementReason::ACTIVATION, $poolOperation->getComment()];
        } else {
            $poolQuantity = $storedPoolQuantity;
            foreach ($stockQuantities as $combinationId => $currentQuantity) {
                $combinationDelta = isset($mirrorQuantities[$combinationId]) ? $currentQuantity - $mirrorQuantities[$combinationId] : 0;
                if ($combinationDelta !== 0) {
                    $movements[] = [$combinationId, $combinationDelta * $managedRatios[$combinationId], SmartStockMovementReason::fromCombinationChange($combinationDelta), ''];
                }
            }
            if ($poolOperation->getAbsoluteQuantity() !== null) {
                $poolBeforeInventory = $poolQuantity + array_sum(array_column($movements, 1));
                $movements[] = [0, $poolOperation->getAbsoluteQuantity() - $poolBeforeInventory, SmartStockMovementReason::INVENTORY, $poolOperation->getComment()];
            }
        }
        if ($poolOperation->getAdjustment() !== 0) {
            $movements[] = [0, $poolOperation->getAdjustment(), SmartStockMovementReason::fromPoolAdjustment($poolOperation->getAdjustment()), $poolOperation->getComment()];
        }
        $poolQuantityBefore = $poolQuantity;
        $poolQuantity = $this->journalMovements($productId, $scope, $storedPoolQuantity === null ? 0 : $poolQuantity, $movements, $employeeId);
        if (!$this->repository->savePoolQuantity($productId, $scope, $poolQuantity)) {
            throw new SmartStockException('Unable to save the shared stock.');
        }
        if ($storedPoolQuantity !== null) {
            $this->detectLowStockCrossing($productId, $scope, $poolQuantityBefore, $poolQuantity);
        }
        $this->redistributePool($productId, $scope, $poolQuantity, $managedRatios, $stockQuantities);
    }

    /**
     * Records the movements in the journal and returns the resulting pool quantity.
     *
     * @param array<int, array{0: int, 1: int, 2: string, 3: string}> $movements Combination, delta, reason, comment
     */
    private function journalMovements(int $productId, SmartStockScope $scope, int $poolQuantity, array $movements, int $employeeId): int
    {
        foreach ($movements as $movement) {
            list($combinationId, $quantityDelta, $reason, $comment) = $movement;
            if ($quantityDelta === 0 && $reason !== SmartStockMovementReason::ACTIVATION) {
                continue;
            }
            $poolQuantity += $quantityDelta;
            $movementId = $this->repository->insertMovement($productId, $combinationId, $scope, $quantityDelta, $poolQuantity, $reason, $employeeId, $comment);
            if ($combinationId > 0 && $movementId > 0) {
                self::$unattachedMovementIds[] = $movementId;
            }
        }
        return $poolQuantity;
    }

    /**
     * Queues an alert when the pool goes below the alert threshold of the product.
     */
    private function detectLowStockCrossing(int $productId, SmartStockScope $scope, int $poolQuantityBefore, int $poolQuantityAfter): void
    {
        $productConfiguration = $this->repository->getProductConfiguration($productId);
        $alertThreshold = $productConfiguration !== null ? $productConfiguration['alert_threshold'] : 0;
        if ($alertThreshold > 0 && $poolQuantityBefore >= $alertThreshold && $poolQuantityAfter < $alertThreshold) {
            self::$pendingLowStockAlerts[] = [$productId, $scope, $poolQuantityAfter, $alertThreshold];
        }
    }

    /**
     * Writes the sellable quantity of every managed combination and updates the mirrors.
     *
     * @param array<int, int> $managedRatios
     * @param array<int, int> $stockQuantities Quantities read at the beginning of the reconciliation
     */
    private function redistributePool(int $productId, SmartStockScope $scope, int $poolQuantity, array $managedRatios, array $stockQuantities): void
    {
        $accountedQuantities = [];
        $hasWrittenStock = false;
        foreach ($stockQuantities as $combinationId => $currentQuantity) {
            $targetQuantity = self::computeSellableQuantity($poolQuantity, $managedRatios[$combinationId]);
            $accountedQuantities[$combinationId] = $currentQuantity;
            if ($targetQuantity !== $currentQuantity
                && $this->repository->compareAndSetCombinationQuantity($productId, $combinationId, $scope, $currentQuantity, $targetQuantity)) {
                $accountedQuantities[$combinationId] = $targetQuantity;
                $hasWrittenStock = true;
                self::$pendingNotifications[] = [
                    'id_product' => $productId,
                    'id_product_attribute' => $combinationId,
                    'quantity' => $targetQuantity,
                    'delta_quantity' => $targetQuantity - $currentQuantity,
                    'id_shop' => $scope->getRepresentativeShopId(),
                ];
            }
        }
        $this->repository->saveMirrorQuantities($productId, $scope, $accountedQuantities);
        if ($hasWrittenStock) {
            $this->repository->refreshProductTotalQuantity($productId, $scope);
            Cache::clean('StockAvailable::getQuantityAvailableByProduct_' . $productId . '*');
        }
    }

    /**
     * Runs an operation under the product lock. Locks are re-entrant within the request and
     * pending notifications are broadcast once the outermost lock is released.
     *
     * @return mixed Result of the operation
     */
    private function runLocked(int $productId, callable $operation)
    {
        $isOutermostCall = empty(self::$lockDepthByProduct[$productId]);
        if ($isOutermostCall && !$this->repository->acquireProductLock($productId)) {
            throw new SmartStockException('Unable to lock the shared stock of product ' . $productId . '.');
        }
        self::$lockDepthByProduct[$productId] = (isset(self::$lockDepthByProduct[$productId]) ? self::$lockDepthByProduct[$productId] : 0) + 1;
        try {
            return $operation();
        } finally {
            --self::$lockDepthByProduct[$productId];
            if (self::$lockDepthByProduct[$productId] === 0) {
                unset(self::$lockDepthByProduct[$productId]);
                $this->repository->releaseProductLock($productId);
                if (empty(self::$lockDepthByProduct)) {
                    $this->broadcastPendingEvents();
                }
            }
        }
    }

    /**
     * Notifies third-party modules (back-in-stock alerts, marketplaces, ERP connectors...) of the quantities
     * written by the module, exactly as the core does after its own updates, then the low stock listeners.
     */
    private function broadcastPendingEvents(): void
    {
        $notifications = self::$pendingNotifications;
        $lowStockAlerts = self::$pendingLowStockAlerts;
        self::$pendingNotifications = [];
        self::$pendingLowStockAlerts = [];
        if (!empty($notifications)) {
            self::$isPropagating = true;
            try {
                foreach ($notifications as $notificationParameters) {
                    Hook::exec('actionUpdateQuantity', $notificationParameters);
                }
            } finally {
                self::$isPropagating = false;
            }
        }
        foreach ($lowStockAlerts as $lowStockAlert) {
            foreach ($this->lowStockListeners as $lowStockListener) {
                call_user_func_array($lowStockListener, $lowStockAlert);
            }
        }
    }

    /**
     * Default pool: the base units represented by the current stock of every managed combination.
     *
     * @param array<int, int> $stockQuantities
     * @param array<int, int> $ratiosByCombination
     */
    private function computeDefaultPoolQuantity(array $stockQuantities, array $ratiosByCombination): int
    {
        $poolQuantity = 0;
        foreach ($stockQuantities as $combinationId => $stockQuantity) {
            $ratio = isset($ratiosByCombination[$combinationId]) ? (int) $ratiosByCombination[$combinationId] : 0;
            $poolQuantity += max(0, $stockQuantity) * max(0, $ratio);
        }
        return $poolQuantity;
    }

    /**
     * @return array<int, string> Human readable combination labels indexed by combination identifier
     */
    public function getCombinationLabels(int $productId, int $languageId): array
    {
        $product = new Product($productId, false, $languageId);
        $combinationLabels = [];
        foreach ($product->getAttributesResume($languageId) ?: [] as $attributeSummary) {
            $combinationLabels[(int) $attributeSummary['id_product_attribute']] = (string) $attributeSummary['attribute_designation'];
        }
        return $combinationLabels;
    }
}
