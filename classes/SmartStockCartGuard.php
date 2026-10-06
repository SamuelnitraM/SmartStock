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
 * Prevents overselling across formats: PrestaShop checks each cart line on its own, so 3 x 500 g and 10 x 50 g
 * can both look available while the shared stock only holds 1500 g. The guard compares the total demand of the cart,
 * in base units, with the shared stock and lowers the quantities in excess.
 */
class SmartStockCartGuard
{
    /** @var bool True while the guard modifies a cart, the cart hooks it triggers being ignored */
    private static $isEnforcing = false;

    /** @var array{0: int, 1: int, 2: int}|null Product, combination and customization of the line the customer just changed */
    private static $lastChangedLine;

    /** @var SmartStockRepository */
    private $repository;

    public function __construct(SmartStockRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Remembers the line being changed, so that it is the first one lowered when the cart exceeds the shared stock.
     */
    public function rememberChangedLine(int $productId, int $combinationId, int $customizationId): void
    {
        if (!self::$isEnforcing) {
            self::$lastChangedLine = [$productId, $combinationId, $customizationId];
        }
    }

    /**
     * Lowers the cart quantities exceeding the shared stocks.
     *
     * @return array<int, array{id_product: int, product_name: string, pool_quantity: int, unit: string}> Adjusted products
     */
    public function enforce(Cart $cart): array
    {
        if (self::$isEnforcing) {
            return [];
        }
        $changedLine = self::$lastChangedLine;
        self::$lastChangedLine = null;
        if ((int) $cart->id <= 0 || !Configuration::get('PS_STOCK_MANAGEMENT') || !SmartStockSettings::isCartGuardEnabled()) {
            return [];
        }
        $managedLinesByProduct = $this->getManagedLinesByProduct($cart);
        if (empty($managedLinesByProduct)) {
            return [];
        }
        $scope = SmartStockScope::fromShopId((int) $cart->id_shop);
        $adjustedProducts = [];
        self::$isEnforcing = true;
        try {
            foreach ($managedLinesByProduct as $productId => $managedLines) {
                $poolQuantity = $this->repository->getPoolQuantity($productId, $scope);
                if ($poolQuantity === null || Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($productId, (int) $cart->id_shop))) {
                    continue;
                }
                $demandedQuantity = array_sum(array_map(function ($managedLine) {
                    return $managedLine['quantity'] * $managedLine['ratio'];
                }, $managedLines));
                $excessQuantity = $demandedQuantity - max(0, $poolQuantity);
                if ($excessQuantity <= 0) {
                    continue;
                }
                foreach ($this->sortLinesByReductionPriority($managedLines, $changedLine) as $managedLine) {
                    if ($excessQuantity <= 0) {
                        break;
                    }
                    $removedQuantity = min($managedLine['quantity'], (int) ceil($excessQuantity / $managedLine['ratio']));
                    $cart->updateQty($removedQuantity, $productId, $managedLine['id_product_attribute'], $managedLine['id_customization'] ?: false, 'down', $managedLine['id_address_delivery'], null, true, true);
                    $excessQuantity -= $removedQuantity * $managedLine['ratio'];
                }
                $productConfiguration = $this->repository->getProductConfiguration($productId);
                $adjustedProducts[] = [
                    'id_product' => $productId,
                    'product_name' => $managedLines[0]['name'],
                    'pool_quantity' => max(0, $poolQuantity),
                    'unit' => $productConfiguration !== null ? $productConfiguration['unit'] : '',
                ];
            }
        } finally {
            self::$isEnforcing = false;
        }
        return $adjustedProducts;
    }

    /**
     * @return array<int, array<int, array>> Cart lines drawing from a shared stock, indexed by product
     */
    private function getManagedLinesByProduct(Cart $cart): array
    {
        $managedLinesByProduct = [];
        foreach ($cart->getProducts(true) as $cartProduct) {
            $productId = (int) $cartProduct['id_product'];
            $combinationId = (int) $cartProduct['id_product_attribute'];
            if ($combinationId <= 0 || !$this->repository->isProductActive($productId)) {
                continue;
            }
            $managedRatios = $this->repository->getManagedRatios($productId);
            if (!isset($managedRatios[$combinationId])) {
                continue;
            }
            $managedLinesByProduct[$productId][] = [
                'id_product_attribute' => $combinationId,
                'id_customization' => isset($cartProduct['id_customization']) ? (int) $cartProduct['id_customization'] : 0,
                'id_address_delivery' => (int) $cartProduct['id_address_delivery'],
                'quantity' => (int) $cartProduct['cart_quantity'],
                'ratio' => $managedRatios[$combinationId],
                'name' => (string) $cartProduct['name'],
            ];
        }
        return $managedLinesByProduct;
    }

    /**
     * The line the customer just changed is lowered first, then the smallest formats, whose fine granularity
     * removes as little as possible beyond the excess.
     */
    private function sortLinesByReductionPriority(array $managedLines, ?array $changedLine): array
    {
        usort($managedLines, function ($firstLine, $secondLine) use ($changedLine) {
            $isFirstChanged = $changedLine !== null && $firstLine['id_product_attribute'] === $changedLine[1] && $firstLine['id_customization'] === $changedLine[2];
            $isSecondChanged = $changedLine !== null && $secondLine['id_product_attribute'] === $changedLine[1] && $secondLine['id_customization'] === $changedLine[2];
            if ($isFirstChanged !== $isSecondChanged) {
                return $isFirstChanged ? -1 : 1;
            }
            return $firstLine['ratio'] - $secondLine['ratio'];
        });
        return $managedLines;
    }
}
