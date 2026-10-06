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
 * Reasons of the shared stock movements recorded in the journal.
 */
class SmartStockMovementReason
{
    const ACTIVATION = 'activation';
    const SALE = 'sale';
    const RESTOCK = 'restock';
    const COMBINATION_EDIT = 'combination_edit';
    const RECEIPT = 'receipt';
    const LOSS = 'loss';
    const INVENTORY = 'inventory';

    const ALL = [
        self::ACTIVATION,
        self::SALE,
        self::RESTOCK,
        self::COMBINATION_EDIT,
        self::RECEIPT,
        self::LOSS,
        self::INVENTORY,
    ];

    /**
     * Reason of a combination quantity change detected by the engine: an employee editing a quantity
     * in the back office, otherwise a sale or a restock (cancellation, return) handled by the shop itself.
     * Changes caused by an order are relabelled once the order is known.
     */
    public static function fromCombinationChange(int $quantityDelta): string
    {
        if (self::getContextEmployeeId() > 0) {
            return self::COMBINATION_EDIT;
        }
        return $quantityDelta < 0 ? self::SALE : self::RESTOCK;
    }

    public static function fromPoolAdjustment(int $poolAdjustment): string
    {
        return $poolAdjustment >= 0 ? self::RECEIPT : self::LOSS;
    }

    public static function getContextEmployeeId(): int
    {
        $context = Context::getContext();
        return isset($context->employee) && $context->employee instanceof Employee ? (int) $context->employee->id : 0;
    }
}
