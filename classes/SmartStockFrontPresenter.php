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
 * Data displayed on the product page: remaining shared stock and price comparison between formats.
 */
class SmartStockFrontPresenter
{
    /** @var SmartStockRepository */
    private $repository;

    /** @var SmartStockSynchronizer */
    private $synchronizer;

    public function __construct(SmartStockRepository $repository, SmartStockSynchronizer $synchronizer)
    {
        $this->repository = $repository;
        $this->synchronizer = $synchronizer;
    }

    /**
     * @return array|null Null when the product does not use a shared stock
     */
    public function present(int $productId, int $selectedCombinationId, Context $context): ?array
    {
        $productConfiguration = $this->repository->getProductConfiguration($productId);
        $managedRatios = $this->repository->getManagedRatios($productId);
        if ($productConfiguration === null || !$productConfiguration['active'] || empty($managedRatios)) {
            return null;
        }
        $poolQuantity = $this->repository->getPoolQuantity($productId, SmartStockScope::fromShopId((int) $context->shop->id));
        if ($poolQuantity === null) {
            return null;
        }
        $unit = $productConfiguration['unit'];
        $isAvailableWhenOutOfStock = (bool) Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($productId, (int) $context->shop->id));
        return [
            'stock' => $this->presentStock($poolQuantity, $unit, $productConfiguration['alert_threshold']),
            'comparison' => SmartStockSettings::isPriceComparisonEnabled() && count($managedRatios) >= 2
                ? $this->presentPriceComparison($productId, $selectedCombinationId, $managedRatios, $poolQuantity, $unit, $isAvailableWhenOutOfStock, $context)
                : null,
        ];
    }

    /**
     * Remaining stock message, according to the display setting: never, below the alert threshold, or always.
     */
    private function presentStock(int $poolQuantity, string $unit, int $alertThreshold): ?array
    {
        $displayMode = SmartStockSettings::getFrontStockDisplay();
        if ($poolQuantity <= 0 || $unit === '' || $displayMode === SmartStockSettings::STOCK_DISPLAY_NEVER) {
            return null;
        }
        $isLow = $alertThreshold > 0 && $poolQuantity < $alertThreshold;
        if ($displayMode === SmartStockSettings::STOCK_DISPLAY_LOW && !$isLow) {
            return null;
        }
        return [
            'is_low' => $isLow,
            'quantity' => SmartStockUnit::formatQuantity($poolQuantity, $unit),
        ];
    }

    /**
     * Price per reference unit (kg, litre, metre...) of every format and saving compared with the most expensive one.
     *
     * @param array<int, int> $managedRatios
     */
    private function presentPriceComparison(int $productId, int $selectedCombinationId, array $managedRatios, int $poolQuantity, string $unit, bool $isAvailableWhenOutOfStock, Context $context): ?array
    {
        $referenceUnit = SmartStockUnit::getReferenceUnit($unit);
        $customerId = isset($context->customer) ? (int) $context->customer->id : 0;
        $isTaxIncluded = (int) Product::getTaxCalculationMethod($customerId) === (int) PS_TAX_INC;
        $combinationLabels = $this->synchronizer->getCombinationLabels($productId, (int) $context->language->id);
        $comparisonRows = [];
        foreach ($managedRatios as $combinationId => $ratio) {
            if (!isset($combinationLabels[$combinationId])) {
                continue;
            }
            $combinationPrice = (float) Product::getPriceStatic($productId, $isTaxIncluded, $combinationId);
            $comparisonRows[] = [
                'id_product_attribute' => $combinationId,
                'name' => $combinationLabels[$combinationId],
                'ratio' => $ratio,
                'price' => $combinationPrice,
                'reference_price' => $combinationPrice / $ratio * $referenceUnit['size'],
                'is_available' => $isAvailableWhenOutOfStock || SmartStockSynchronizer::computeSellableQuantity($poolQuantity, $ratio) > 0,
                'is_selected' => $combinationId === $selectedCombinationId,
            ];
        }
        if (count($comparisonRows) < 2) {
            return null;
        }
        usort($comparisonRows, function ($firstRow, $secondRow) {
            return $firstRow['ratio'] - $secondRow['ratio'];
        });
        $highestReferencePrice = max(array_column($comparisonRows, 'reference_price'));
        $availableReferencePrices = array_column(array_filter($comparisonRows, function ($comparisonRow) {
            return $comparisonRow['is_available'];
        }), 'reference_price');
        $bestReferencePrice = empty($availableReferencePrices) ? null : min($availableReferencePrices);
        foreach ($comparisonRows as &$comparisonRow) {
            $savingPercent = $highestReferencePrice > 0 ? (int) round((1 - $comparisonRow['reference_price'] / $highestReferencePrice) * 100) : 0;
            $comparisonRow['saving_percent'] = max(0, $savingPercent);
            $comparisonRow['is_best_value'] = $bestReferencePrice !== null && $comparisonRow['is_available'] && abs($comparisonRow['reference_price'] - $bestReferencePrice) < 0.005 && $savingPercent > 0;
            $comparisonRow['price_formatted'] = $this->formatPrice($comparisonRow['price'], $context);
            $comparisonRow['reference_price_formatted'] = $this->formatPrice($comparisonRow['reference_price'], $context);
        }
        unset($comparisonRow);
        return [
            'reference_unit' => $referenceUnit['unit'],
            'rows' => $comparisonRows,
        ];
    }

    private function formatPrice(float $amount, Context $context): string
    {
        $locale = method_exists($context, 'getCurrentLocale') ? $context->getCurrentLocale() : null;
        if ($locale !== null && isset($context->currency)) {
            return (string) $locale->formatPrice($amount, $context->currency->iso_code);
        }
        return number_format($amount, 2, ',', ' ');
    }
}
