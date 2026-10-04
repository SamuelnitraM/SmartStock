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
 * 3. Apply the sum of differences to the pool.
 * 4. Write floor(pool / ratio) on every combination with a compare-and-set, so a concurrent core update is never lost:
 *    a row modified in between keeps its new value and is accounted by its own upcoming event.
 */
class SmartStockSynchronizer
{
    /** @var bool True while the module notifies third-party modules of its own stock writes */
    private static $isPropagating = false;

    /** @var array<int, int> Nesting depth of the product locks held by the current request */
    private static $lockDepthByProduct = [];

    /** @var array<int, array> Stock changes waiting to be broadcast once the product lock is released */
    private static $pendingNotifications = [];

    /** @var SmartStockRepository */
    private $repository;

    public function __construct(SmartStockRepository $repository)
    {
        $this->repository = $repository;
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
                $this->reconcileScope($productId, $scopeToReconcile, null, 0);
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
                $this->reconcileScope($productId, $scope, null, 0);
            }
        });
    }

    /**
     * Saves the configuration of a product and applies it immediately to its stock.
     *
     * @param array<int, int> $ratiosByCombination Units consumed per sale, 0 keeping the combination on its own stock
     * @param int|null $poolQuantity Shared stock to set on the edited perimeter, null to keep the current one
     */
    public function configureProduct(int $productId, bool $isActive, string $unit, array $ratiosByCombination, SmartStockScope $editedScope, ?int $poolQuantity): void
    {
        $this->runLocked($productId, function () use ($productId, $isActive, $unit, $ratiosByCombination, $editedScope, $poolQuantity) {
            $wasActive = $this->repository->isProductActive($productId);
            $productScopes = $this->repository->getProductScopes($productId);
            if ($wasActive) {
                foreach ($productScopes as $scope) {
                    $this->reconcileScope($productId, $scope, null, 0);
                }
            }
            if (!$this->repository->saveProductConfiguration($productId, $isActive, $unit)
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
                $poolOverride = $scope->getKey() === $editedScope->getKey() ? $poolQuantity : null;
                $this->reconcileScope($productId, $scope, $poolOverride, 0);
            }
        });
    }

    /**
     * Sets (inventory) or adjusts (goods receipt, loss) the shared stock of a perimeter.
     *
     * @param int|null $newPoolQuantity Absolute quantity to set, null to keep the current one
     * @param int $poolAdjustment Quantity added to the pool after the optional reset
     */
    public function updatePool(int $productId, SmartStockScope $scope, ?int $newPoolQuantity, int $poolAdjustment): void
    {
        if (!$this->repository->isProductActive($productId)) {
            throw new SmartStockException('The shared stock is not enabled for this product.');
        }
        $this->runLocked($productId, function () use ($productId, $scope, $newPoolQuantity, $poolAdjustment) {
            $this->reconcileScope($productId, $scope, $newPoolQuantity, $poolAdjustment);
        });
    }

    /**
     * Builds the complete state of a product for the back office screens.
     */
    public function getProductState(int $productId, SmartStockScope $scope, int $languageId): array
    {
        $productConfiguration = $this->repository->getProductConfiguration($productId);
        $isActive = $productConfiguration !== null && $productConfiguration['active'];
        $storedRatios = $this->repository->getRatios($productId);
        $stockQuantities = $this->repository->getCombinationStockQuantities($productId, $scope);
        $effectiveRatios = [];
        $combinations = [];
        foreach ($this->getCombinationLabels($productId, $languageId) as $combinationId => $combinationLabel) {
            $suggestedRatio = $this->suggestRatio($combinationLabel);
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
        return [
            'id_product' => $productId,
            'name' => (string) Product::getProductName($productId, null, $languageId),
            'active' => $isActive,
            'unit' => $productConfiguration !== null ? $productConfiguration['unit'] : '',
            'pool_quantity' => $storedPoolQuantity !== null ? $storedPoolQuantity : $this->computeDefaultPoolQuantity($stockQuantities, $effectiveRatios),
            'pool_initialized' => $storedPoolQuantity !== null,
            'combinations' => $combinations,
        ];
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
     * Core of the engine: converts the combination changes into pool movements, then redistributes the pool.
     */
    private function reconcileScope(int $productId, SmartStockScope $scope, ?int $poolOverride, int $poolAdjustment): void
    {
        $managedRatios = $this->repository->getManagedRatios($productId);
        if (empty($managedRatios)) {
            return;
        }
        $stockQuantities = $this->repository->getCombinationStockQuantities($productId, $scope, array_keys($managedRatios));
        if (empty($stockQuantities) && $poolOverride === null && $poolAdjustment === 0) {
            return;
        }
        $mirrorQuantities = $this->repository->getMirrorQuantities($productId, $scope);
        $poolQuantity = $this->repository->getPoolQuantity($productId, $scope);
        if ($poolQuantity === null) {
            $poolQuantity = $this->computeDefaultPoolQuantity($stockQuantities, $managedRatios);
            $mirrorQuantities = $stockQuantities;
        }
        foreach ($stockQuantities as $combinationId => $currentQuantity) {
            if (isset($mirrorQuantities[$combinationId])) {
                $poolQuantity += ($currentQuantity - $mirrorQuantities[$combinationId]) * $managedRatios[$combinationId];
            }
        }
        if ($poolOverride !== null) {
            $poolQuantity = $poolOverride;
        }
        $poolQuantity += $poolAdjustment;
        if (!$this->repository->savePoolQuantity($productId, $scope, $poolQuantity)) {
            throw new SmartStockException('Unable to save the shared stock.');
        }
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
                    $this->broadcastPendingNotifications();
                }
            }
        }
    }

    /**
     * Notifies third-party modules (back-in-stock alerts, marketplaces, ERP connectors...) of the quantities
     * written by the module, exactly as the core does after its own updates.
     */
    private function broadcastPendingNotifications(): void
    {
        $notifications = self::$pendingNotifications;
        self::$pendingNotifications = [];
        if (empty($notifications)) {
            return;
        }
        self::$isPropagating = true;
        try {
            foreach ($notifications as $notificationParameters) {
                Hook::exec('actionUpdateQuantity', $notificationParameters);
            }
        } finally {
            self::$isPropagating = false;
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
    private function getCombinationLabels(int $productId, int $languageId): array
    {
        $product = new Product($productId, false, $languageId);
        $combinationLabels = [];
        foreach ($product->getAttributesResume($languageId) ?: [] as $attributeSummary) {
            $combinationLabels[(int) $attributeSummary['id_product_attribute']] = (string) $attributeSummary['attribute_designation'];
        }
        return $combinationLabels;
    }

    /**
     * Guesses the units consumed by a combination from the first number of its label ("Weight - 250 g" gives 250).
     * A label without any number suggests 0, keeping the combination on its own stock.
     */
    private function suggestRatio(string $combinationLabel): int
    {
        if (!preg_match('/(\d+(?:[.,]\d+)?)/', $combinationLabel, $numberMatches)) {
            return 0;
        }
        return max(0, (int) round((float) str_replace(',', '.', $numberMatches[1])));
    }
}
