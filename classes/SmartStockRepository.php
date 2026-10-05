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
 * Database access layer of the module. Every SQL statement of the module lives here.
 *
 * Tables:
 * - smartstock_product:     per-product activation, unit label and low stock alert threshold.
 * - smartstock_combination: units consumed from the shared stock by one sale of a combination (ratio).
 * - smartstock_pool:        shared stock quantity, expressed in base units, per stock perimeter.
 * - smartstock_mirror:      last combination quantity accounted into the pool, per stock perimeter.
 * - smartstock_movement:    journal of every pool change (sale, return, receipt, loss, inventory...).
 */
class SmartStockRepository
{
    const TABLE_PRODUCT = 'smartstock_product';
    const TABLE_COMBINATION = 'smartstock_combination';
    const TABLE_POOL = 'smartstock_pool';
    const TABLE_MIRROR = 'smartstock_mirror';
    const TABLE_MOVEMENT = 'smartstock_movement';
    const LOCK_TIMEOUT_SECONDS = 10;

    /** @var array<int, array|null> Request-level cache of product configurations */
    private static $productConfigurationCache = [];

    /** @var array<int, array<int, int>> Request-level cache of combination ratios */
    private static $ratioCache = [];

    /** @var Db */
    private $database;

    public function __construct()
    {
        $this->database = Db::getInstance();
    }

    public function createTables(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $tableQueries = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_PRODUCT . '` (
                `id_product` INT(10) UNSIGNED NOT NULL,
                `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `unit` VARCHAR(16) NOT NULL DEFAULT \'\',
                `alert_threshold` INT(11) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_product`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_COMBINATION . '` (
                `id_product_attribute` INT(10) UNSIGNED NOT NULL,
                `id_product` INT(10) UNSIGNED NOT NULL,
                `ratio` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_product_attribute`),
                KEY `id_product` (`id_product`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_POOL . '` (
                `id_product` INT(10) UNSIGNED NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_shop_group` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `quantity` INT(11) NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_product`, `id_shop`, `id_shop_group`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_MIRROR . '` (
                `id_product_attribute` INT(10) UNSIGNED NOT NULL,
                `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_shop_group` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_product` INT(10) UNSIGNED NOT NULL,
                `quantity` INT(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_product_attribute`, `id_shop`, `id_shop_group`),
                KEY `id_product` (`id_product`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_MOVEMENT . '` (
                `id_smartstock_movement` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_product` INT(10) UNSIGNED NOT NULL,
                `id_product_attribute` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_shop_group` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `quantity_delta` INT(11) NOT NULL,
                `quantity_after` INT(11) NOT NULL,
                `reason` VARCHAR(32) NOT NULL,
                `id_order` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `id_employee` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `comment` VARCHAR(255) NOT NULL DEFAULT \'\',
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_smartstock_movement`),
                KEY `product_scope_date` (`id_product`, `id_shop`, `id_shop_group`, `date_add`),
                KEY `date_add` (`date_add`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($tableQueries as $tableQuery) {
            if (!$this->database->execute($tableQuery)) {
                return false;
            }
        }
        return $this->addMissingColumns();
    }

    /**
     * Brings tables created by a previous version up to the current schema.
     */
    private function addMissingColumns(): bool
    {
        if ($this->columnExists(self::TABLE_PRODUCT, 'alert_threshold')) {
            return true;
        }
        return $this->database->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . self::TABLE_PRODUCT . '` ADD `alert_threshold` INT(11) NOT NULL DEFAULT 0 AFTER `unit`'
        );
    }

    public function dropTables(): bool
    {
        $tableNames = [self::TABLE_PRODUCT, self::TABLE_COMBINATION, self::TABLE_POOL, self::TABLE_MIRROR, self::TABLE_MOVEMENT];
        foreach ($tableNames as $tableName) {
            if (!$this->database->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $tableName . '`')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the product configuration (active, unit, alert threshold) or null when the product was never configured.
     */
    public function getProductConfiguration(int $productId): ?array
    {
        if (!array_key_exists($productId, self::$productConfigurationCache)) {
            $configurationRow = $this->database->getRow(
                'SELECT `id_product`, `active`, `unit`, `alert_threshold` FROM `' . _DB_PREFIX_ . self::TABLE_PRODUCT . '`
                WHERE `id_product` = ' . $productId,
                false
            );
            self::$productConfigurationCache[$productId] = $configurationRow ? [
                'id_product' => (int) $configurationRow['id_product'],
                'active' => (bool) $configurationRow['active'],
                'unit' => (string) $configurationRow['unit'],
                'alert_threshold' => (int) $configurationRow['alert_threshold'],
            ] : null;
        }
        return self::$productConfigurationCache[$productId];
    }

    public function isProductActive(int $productId): bool
    {
        $productConfiguration = $this->getProductConfiguration($productId);
        return $productConfiguration !== null && $productConfiguration['active'];
    }

    public function saveProductConfiguration(int $productId, bool $isActive, string $unit, int $alertThreshold): bool
    {
        $currentDate = date('Y-m-d H:i:s');
        $result = $this->database->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_PRODUCT . '` (`id_product`, `active`, `unit`, `alert_threshold`, `date_add`, `date_upd`)
            VALUES (' . $productId . ', ' . (int) $isActive . ', \'' . pSQL($unit) . '\', ' . max(0, $alertThreshold) . ', \'' . $currentDate . '\', \'' . $currentDate . '\')
            ON DUPLICATE KEY UPDATE `active` = VALUES(`active`), `unit` = VALUES(`unit`), `alert_threshold` = VALUES(`alert_threshold`), `date_upd` = VALUES(`date_upd`)'
        );
        unset(self::$productConfigurationCache[$productId]);
        return $result;
    }

    /**
     * @return int[] Identifiers of every product with the shared stock enabled
     */
    public function getActiveProductIds(): array
    {
        $productRows = $this->database->executeS(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . self::TABLE_PRODUCT . '` WHERE `active` = 1 ORDER BY `id_product`',
            true,
            false
        );
        return array_map('intval', array_column($productRows ?: [], 'id_product'));
    }

    /**
     * @return array<int, int> Ratios indexed by combination identifier, including unmanaged combinations (ratio 0)
     */
    public function getRatios(int $productId): array
    {
        if (!isset(self::$ratioCache[$productId])) {
            $ratioRows = $this->database->executeS(
                'SELECT `id_product_attribute`, `ratio` FROM `' . _DB_PREFIX_ . self::TABLE_COMBINATION . '`
                WHERE `id_product` = ' . $productId,
                true,
                false
            );
            $ratiosByCombination = [];
            foreach ($ratioRows ?: [] as $ratioRow) {
                $ratiosByCombination[(int) $ratioRow['id_product_attribute']] = (int) $ratioRow['ratio'];
            }
            self::$ratioCache[$productId] = $ratiosByCombination;
        }
        return self::$ratioCache[$productId];
    }

    /**
     * @return array<int, int> Ratios of the combinations drawing from the shared stock (ratio > 0)
     */
    public function getManagedRatios(int $productId): array
    {
        return array_filter($this->getRatios($productId), function ($ratio) {
            return $ratio > 0;
        });
    }

    /**
     * Replaces the ratios of a product. Combinations missing from the list are removed from the configuration.
     *
     * @param array<int, int> $ratiosByCombination
     */
    public function saveRatios(int $productId, array $ratiosByCombination): bool
    {
        unset(self::$ratioCache[$productId]);
        if (!$this->database->delete(self::TABLE_COMBINATION, '`id_product` = ' . $productId)) {
            return false;
        }
        if (empty($ratiosByCombination)) {
            return true;
        }
        $valueRows = [];
        foreach ($ratiosByCombination as $combinationId => $ratio) {
            $valueRows[] = '(' . (int) $combinationId . ', ' . $productId . ', ' . max(0, (int) $ratio) . ')';
        }
        return $this->database->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_COMBINATION . '` (`id_product_attribute`, `id_product`, `ratio`)
            VALUES ' . implode(', ', $valueRows)
        );
    }

    /**
     * Returns the shared stock quantity of a perimeter, or null when no pool exists yet.
     */
    public function getPoolQuantity(int $productId, SmartStockScope $scope): ?int
    {
        $poolQuantity = $this->database->getValue(
            'SELECT `quantity` FROM `' . _DB_PREFIX_ . self::TABLE_POOL . '`
            WHERE `id_product` = ' . $productId . $scope->getSqlRestriction(),
            false
        );
        return $poolQuantity === false ? null : (int) $poolQuantity;
    }

    public function savePoolQuantity(int $productId, SmartStockScope $scope, int $quantity): bool
    {
        return $this->database->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_POOL . '` (`id_product`, `id_shop`, `id_shop_group`, `quantity`, `date_upd`)
            VALUES (' . $productId . ', ' . $scope->getShopId() . ', ' . $scope->getShopGroupId() . ', ' . $quantity . ', \'' . date('Y-m-d H:i:s') . '\')
            ON DUPLICATE KEY UPDATE `quantity` = VALUES(`quantity`), `date_upd` = VALUES(`date_upd`)'
        );
    }

    /**
     * @return array<int, int> Last accounted quantities indexed by combination identifier
     */
    public function getMirrorQuantities(int $productId, SmartStockScope $scope): array
    {
        $mirrorRows = $this->database->executeS(
            'SELECT `id_product_attribute`, `quantity` FROM `' . _DB_PREFIX_ . self::TABLE_MIRROR . '`
            WHERE `id_product` = ' . $productId . $scope->getSqlRestriction(),
            true,
            false
        );
        $mirrorQuantities = [];
        foreach ($mirrorRows ?: [] as $mirrorRow) {
            $mirrorQuantities[(int) $mirrorRow['id_product_attribute']] = (int) $mirrorRow['quantity'];
        }
        return $mirrorQuantities;
    }

    /**
     * @param array<int, int> $quantitiesByCombination
     */
    public function saveMirrorQuantities(int $productId, SmartStockScope $scope, array $quantitiesByCombination): bool
    {
        if (empty($quantitiesByCombination)) {
            return true;
        }
        $valueRows = [];
        foreach ($quantitiesByCombination as $combinationId => $quantity) {
            $valueRows[] = '(' . (int) $combinationId . ', ' . $scope->getShopId() . ', ' . $scope->getShopGroupId() . ', ' . $productId . ', ' . (int) $quantity . ')';
        }
        return $this->database->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_MIRROR . '` (`id_product_attribute`, `id_shop`, `id_shop_group`, `id_product`, `quantity`)
            VALUES ' . implode(', ', $valueRows) . '
            ON DUPLICATE KEY UPDATE `quantity` = VALUES(`quantity`)'
        );
    }

    /**
     * Removes the mirrors of combinations that no longer draw from the shared stock.
     *
     * @param int[] $managedCombinationIds
     */
    public function deleteUnmanagedMirrors(int $productId, array $managedCombinationIds): bool
    {
        $whereClause = '`id_product` = ' . $productId;
        if (!empty($managedCombinationIds)) {
            $whereClause .= ' AND `id_product_attribute` NOT IN (' . implode(', ', array_map('intval', $managedCombinationIds)) . ')';
        }
        return $this->database->delete(self::TABLE_MIRROR, $whereClause);
    }

    /**
     * Removes the runtime state (pools and mirrors) of a product while keeping its configuration.
     */
    public function deleteProductRuntimeState(int $productId): bool
    {
        return $this->database->delete(self::TABLE_POOL, '`id_product` = ' . $productId)
            && $this->database->delete(self::TABLE_MIRROR, '`id_product` = ' . $productId);
    }

    public function deleteProduct(int $productId): bool
    {
        unset(self::$productConfigurationCache[$productId], self::$ratioCache[$productId]);
        return $this->deleteProductRuntimeState($productId)
            && $this->database->delete(self::TABLE_COMBINATION, '`id_product` = ' . $productId)
            && $this->database->delete(self::TABLE_MOVEMENT, '`id_product` = ' . $productId)
            && $this->database->delete(self::TABLE_PRODUCT, '`id_product` = ' . $productId);
    }

    public function deleteCombination(int $combinationId): bool
    {
        self::$ratioCache = [];
        return $this->database->delete(self::TABLE_MIRROR, '`id_product_attribute` = ' . $combinationId)
            && $this->database->delete(self::TABLE_COMBINATION, '`id_product_attribute` = ' . $combinationId);
    }

    /**
     * Lists every stock perimeter holding stock rows or a pool for the product.
     *
     * @return SmartStockScope[]
     */
    public function getProductScopes(int $productId): array
    {
        $scopeRows = $this->database->executeS(
            'SELECT `id_shop`, `id_shop_group` FROM `' . _DB_PREFIX_ . 'stock_available` WHERE `id_product` = ' . $productId . '
            UNION
            SELECT `id_shop`, `id_shop_group` FROM `' . _DB_PREFIX_ . self::TABLE_POOL . '` WHERE `id_product` = ' . $productId,
            true,
            false
        );
        $scopes = [];
        foreach ($scopeRows ?: [] as $scopeRow) {
            $scope = new SmartStockScope((int) $scopeRow['id_shop'], (int) $scopeRow['id_shop_group']);
            $scopes[$scope->getKey()] = $scope;
        }
        return array_values($scopes);
    }

    /**
     * Reads the sellable quantities of the combinations of a product within a perimeter.
     *
     * @param int[]|null $combinationIds Restricts the result to these combinations when provided
     *
     * @return array<int, int> Quantities indexed by combination identifier
     */
    public function getCombinationStockQuantities(int $productId, SmartStockScope $scope, ?array $combinationIds = null): array
    {
        if ($combinationIds !== null && empty($combinationIds)) {
            return [];
        }
        $combinationFilter = $combinationIds === null
            ? ' AND `id_product_attribute` <> 0'
            : ' AND `id_product_attribute` IN (' . implode(', ', array_map('intval', $combinationIds)) . ')';
        $stockRows = $this->database->executeS(
            'SELECT `id_product_attribute`, `quantity` FROM `' . _DB_PREFIX_ . 'stock_available`
            WHERE `id_product` = ' . $productId . $combinationFilter . $scope->getSqlRestriction(),
            true,
            false
        );
        $stockQuantities = [];
        foreach ($stockRows ?: [] as $stockRow) {
            $stockQuantities[(int) $stockRow['id_product_attribute']] = (int) $stockRow['quantity'];
        }
        return $stockQuantities;
    }

    /**
     * Writes a combination quantity only if it still holds the expected value, so that a concurrent
     * core update is never overwritten. The physical quantity keeps the core invariant physical = sellable + reserved.
     */
    public function compareAndSetCombinationQuantity(int $productId, int $combinationId, SmartStockScope $scope, int $expectedQuantity, int $targetQuantity): bool
    {
        $updateSucceeded = $this->database->execute(
            'UPDATE `' . _DB_PREFIX_ . 'stock_available`
            SET `quantity` = ' . $targetQuantity . ', `physical_quantity` = ' . $targetQuantity . ' + `reserved_quantity`
            WHERE `id_product` = ' . $productId . '
            AND `id_product_attribute` = ' . $combinationId . $scope->getSqlRestriction() . '
            AND `quantity` = ' . $expectedQuantity
        );
        return $updateSucceeded && (int) $this->database->Affected_Rows() > 0;
    }

    /**
     * Recomputes the product-level stock row as the sum of its combinations, as the core does.
     */
    public function refreshProductTotalQuantity(int $productId, SmartStockScope $scope): bool
    {
        $totalQuantity = (int) $this->database->getValue(
            'SELECT SUM(`quantity`) FROM `' . _DB_PREFIX_ . 'stock_available`
            WHERE `id_product` = ' . $productId . ' AND `id_product_attribute` <> 0' . $scope->getSqlRestriction(),
            false
        );
        return $this->database->execute(
            'UPDATE `' . _DB_PREFIX_ . 'stock_available`
            SET `quantity` = ' . $totalQuantity . ', `physical_quantity` = ' . $totalQuantity . ' + `reserved_quantity`
            WHERE `id_product` = ' . $productId . ' AND `id_product_attribute` = 0' . $scope->getSqlRestriction()
        );
    }

    /**
     * Records a pool movement and returns its identifier.
     */
    public function insertMovement(int $productId, int $combinationId, SmartStockScope $scope, int $quantityDelta, int $quantityAfter, string $reason, int $employeeId, string $comment): int
    {
        $this->database->insert(self::TABLE_MOVEMENT, [
            'id_product' => $productId,
            'id_product_attribute' => $combinationId,
            'id_shop' => $scope->getShopId(),
            'id_shop_group' => $scope->getShopGroupId(),
            'quantity_delta' => $quantityDelta,
            'quantity_after' => $quantityAfter,
            'reason' => pSQL($reason),
            'id_employee' => $employeeId,
            'comment' => pSQL(Tools::substr($comment, 0, 255)),
            'date_add' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->database->Insert_ID();
    }

    /**
     * Links movements to the order that caused them. A movement linked to an order is a sale or a restock, never a manual edit.
     *
     * @param int[] $movementIds
     */
    public function attachOrderToMovements(array $movementIds, int $orderId): bool
    {
        if (empty($movementIds) || $orderId <= 0) {
            return true;
        }
        return $this->database->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE_MOVEMENT . '`
            SET `id_order` = ' . $orderId . ', `reason` = IF(`quantity_delta` < 0, \'' . SmartStockMovementReason::SALE . '\', \'' . SmartStockMovementReason::RESTOCK . '\')
            WHERE `id_order` = 0 AND `id_product_attribute` > 0
            AND `id_smartstock_movement` IN (' . implode(', ', array_map('intval', $movementIds)) . ')'
        );
    }

    /**
     * Latest movements, optionally restricted to a product and a perimeter, with the combination label resolved by the caller.
     */
    public function getMovements(?int $productId, ?SmartStockScope $scope, int $limit): array
    {
        $whereClause = '1';
        if ($productId !== null) {
            $whereClause .= ' AND m.`id_product` = ' . $productId;
        }
        if ($scope !== null) {
            $whereClause .= $scope->getSqlRestriction('m');
        }
        $movementRows = $this->database->executeS(
            'SELECT m.`id_smartstock_movement`, m.`id_product`, m.`id_product_attribute`, m.`quantity_delta`, m.`quantity_after`,
                m.`reason`, m.`id_order`, m.`comment`, m.`date_add`, TRIM(CONCAT(IFNULL(e.`firstname`, \'\'), \' \', IFNULL(e.`lastname`, \'\'))) AS `employee_name`
            FROM `' . _DB_PREFIX_ . self::TABLE_MOVEMENT . '` m
            LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON e.`id_employee` = m.`id_employee`
            WHERE ' . $whereClause . '
            ORDER BY m.`id_smartstock_movement` DESC
            LIMIT ' . max(1, $limit),
            true,
            false
        );
        $movements = [];
        foreach ($movementRows ?: [] as $movementRow) {
            $movements[] = [
                'id' => (int) $movementRow['id_smartstock_movement'],
                'id_product' => (int) $movementRow['id_product'],
                'id_product_attribute' => (int) $movementRow['id_product_attribute'],
                'quantity_delta' => (int) $movementRow['quantity_delta'],
                'quantity_after' => (int) $movementRow['quantity_after'],
                'reason' => (string) $movementRow['reason'],
                'id_order' => (int) $movementRow['id_order'],
                'employee_name' => (string) $movementRow['employee_name'],
                'comment' => (string) $movementRow['comment'],
                'date_add' => (string) $movementRow['date_add'],
            ];
        }
        return $movements;
    }

    public function countMovementsSince(string $sinceDate): int
    {
        return (int) $this->database->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE_MOVEMENT . '` WHERE `date_add` >= \'' . pSQL($sinceDate) . '\'',
            false
        );
    }

    /**
     * Candidates for the configuration assistant: products with several combinations and no shared stock yet.
     *
     * @return array<int, array<int, string>> Combination labels indexed by product then combination identifier
     */
    public function getCandidateProductCombinations(int $languageId, int $productLimit): array
    {
        $combinationRows = $this->database->executeS(
            'SELECT pa.`id_product`, pa.`id_product_attribute`,
                GROUP_CONCAT(CONCAT(agl.`public_name`, \' - \', al.`name`) ORDER BY ag.`position` SEPARATOR \', \') AS `label`
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN (
                SELECT cpa.`id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` cpa
                LEFT JOIN `' . _DB_PREFIX_ . self::TABLE_PRODUCT . '` sp ON sp.`id_product` = cpa.`id_product`
                WHERE sp.`active` IS NULL OR sp.`active` = 0
                GROUP BY cpa.`id_product` HAVING COUNT(*) >= 2
                ORDER BY cpa.`id_product` DESC
                LIMIT ' . max(1, $productLimit) . '
            ) candidates ON candidates.`id_product` = pa.`id_product`
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.`id_product_attribute` = pa.`id_product_attribute`
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $languageId . '
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON agl.`id_attribute_group` = ag.`id_attribute_group` AND agl.`id_lang` = ' . $languageId . '
            GROUP BY pa.`id_product`, pa.`id_product_attribute`
            ORDER BY pa.`id_product` DESC, pa.`id_product_attribute`',
            true,
            false
        );
        $labelsByProduct = [];
        foreach ($combinationRows ?: [] as $combinationRow) {
            $labelsByProduct[(int) $combinationRow['id_product']][(int) $combinationRow['id_product_attribute']] = (string) $combinationRow['label'];
        }
        return $labelsByProduct;
    }

    /**
     * Acquires a MySQL named lock serializing every shared stock operation of a product across requests.
     */
    public function acquireProductLock(int $productId): bool
    {
        return (int) $this->database->getValue(
            'SELECT GET_LOCK(\'' . pSQL($this->getLockName($productId)) . '\', ' . self::LOCK_TIMEOUT_SECONDS . ')',
            false
        ) === 1;
    }

    public function releaseProductLock(int $productId): void
    {
        $this->database->getValue('SELECT RELEASE_LOCK(\'' . pSQL($this->getLockName($productId)) . '\')', false);
    }

    private function getLockName(int $productId): string
    {
        return 'smartstock_' . substr(md5(_DB_NAME_ . _DB_PREFIX_), 0, 16) . '_' . $productId;
    }

    /**
     * Tells whether a column exists, used to detect the legacy schema of version 1.x.
     */
    public function columnExists(string $tableName, string $columnName): bool
    {
        $columnRows = $this->database->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . bqSQL($tableName) . '` LIKE \'' . pSQL($columnName) . '\'',
            true,
            false
        );
        return !empty($columnRows);
    }

    /**
     * @return array<int, array<int, int>> Legacy ratios indexed by product then combination identifier
     */
    public function getLegacyConfiguration(): array
    {
        $legacyRows = $this->database->executeS(
            'SELECT pa.`id_product`, pa.`id_product_attribute`, pa.`stock_deduction`
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = pa.`id_product`
            WHERE p.`use_common_stock` = 1',
            true,
            false
        );
        $legacyConfiguration = [];
        foreach ($legacyRows ?: [] as $legacyRow) {
            $legacyConfiguration[(int) $legacyRow['id_product']][(int) $legacyRow['id_product_attribute']] = max(0, (int) $legacyRow['stock_deduction']);
        }
        return $legacyConfiguration;
    }

    public function dropLegacyColumns(): bool
    {
        $legacyColumns = [
            ['product', 'use_common_stock'],
            ['product_attribute', 'use_common_stock'],
            ['product_attribute', 'stock_deduction'],
        ];
        foreach ($legacyColumns as $legacyColumn) {
            list($tableName, $columnName) = $legacyColumn;
            if ($this->columnExists($tableName, $columnName)
                && !$this->database->execute('ALTER TABLE `' . _DB_PREFIX_ . $tableName . '` DROP COLUMN `' . $columnName . '`')) {
                return false;
            }
        }
        return true;
    }
}
