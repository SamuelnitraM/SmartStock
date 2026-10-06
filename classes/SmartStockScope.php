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
 * Identifies a stock perimeter exactly the way the stock_available table does:
 * a single shop (id_shop > 0, id_shop_group = 0) or a shop group sharing its stock (id_shop = 0, id_shop_group > 0).
 */
class SmartStockScope
{
    /** @var int */
    private $shopId;

    /** @var int */
    private $shopGroupId;

    public function __construct(int $shopId, int $shopGroupId)
    {
        $this->shopId = $shopId;
        $this->shopGroupId = $shopGroupId;
    }

    /**
     * Resolves the stock perimeter used by PrestaShop for the given shop.
     */
    public static function fromShopId(int $shopId): self
    {
        $shopGroupId = (int) Shop::getGroupFromShop($shopId);
        $shopGroup = new ShopGroup($shopGroupId);
        if ((bool) $shopGroup->share_stock) {
            return new self(0, $shopGroupId);
        }
        return new self($shopId, 0);
    }

    /**
     * Reads the perimeter directly from a stock_available row.
     */
    public static function fromStockAvailable(StockAvailable $stockAvailable): self
    {
        return new self((int) $stockAvailable->id_shop, (int) $stockAvailable->id_shop_group);
    }

    /**
     * Resolves the perimeter of the back office context, falling back to the default shop for group or global contexts.
     */
    public static function fromContext(): self
    {
        $contextShopId = Shop::getContext() === Shop::CONTEXT_SHOP ? (int) Shop::getContextShopID() : 0;
        $shopId = $contextShopId > 0 ? $contextShopId : (int) Configuration::get('PS_SHOP_DEFAULT');
        return self::fromShopId($shopId);
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getShopGroupId(): int
    {
        return $this->shopGroupId;
    }

    /**
     * Shop identifier to expose to third-party hooks, which expect a real shop.
     */
    public function getRepresentativeShopId(): int
    {
        if ($this->shopId > 0) {
            return $this->shopId;
        }
        $groupShopIds = Shop::getShops(true, $this->shopGroupId, true);
        return empty($groupShopIds) ? (int) Configuration::get('PS_SHOP_DEFAULT') : (int) reset($groupShopIds);
    }

    /**
     * SQL restriction matching this perimeter, for a table exposing id_shop and id_shop_group columns.
     */
    public function getSqlRestriction(string $tableAlias = ''): string
    {
        $columnPrefix = $tableAlias === '' ? '' : $tableAlias . '.';
        return ' AND ' . $columnPrefix . 'id_shop = ' . $this->shopId . ' AND ' . $columnPrefix . 'id_shop_group = ' . $this->shopGroupId;
    }

    public function getKey(): string
    {
        return $this->shopId . '-' . $this->shopGroupId;
    }
}
