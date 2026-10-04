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

require_once __DIR__ . '/classes/SmartStockException.php';
require_once __DIR__ . '/classes/SmartStockScope.php';
require_once __DIR__ . '/classes/SmartStockRepository.php';
require_once __DIR__ . '/classes/SmartStockSynchronizer.php';

class Ps_SmartStock extends Module
{
    const ADMIN_CONTROLLER = 'AdminSmartStock';

    const HOOKS = [
        'displayAdminProductsExtra',
        'actionObjectStockAvailableAddAfter',
        'actionObjectStockAvailableUpdateAfter',
        'actionUpdateQuantity',
        'actionProductDelete',
        'actionObjectCombinationDeleteAfter',
    ];

    /** @var SmartStockRepository|null */
    private $repository;

    /** @var SmartStockSynchronizer|null */
    private $synchronizer;

    public function __construct()
    {
        $this->name = 'ps_smartstock';
        $this->tab = 'administration';
        $this->version = '2.0.0';
        $this->author = 'SmartDev';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = $this->l('SmartStock - Shared stock for combinations');
        $this->description = $this->l('Sells several formats of the same product (50 g, 100 g, 500 g...) from a single shared stock expressed in base units.');
        $this->confirmUninstall = $this->l('Uninstalling deletes every shared stock configuration. Combination quantities are kept as they are. Continue?');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->getRepository()->createTables()
            && $this->registerHook(self::HOOKS)
            && $this->installAdminTab();
    }

    public function uninstall(): bool
    {
        return $this->uninstallAdminTab()
            && $this->getRepository()->dropTables()
            && parent::uninstall();
    }

    /**
     * Creates the "Catalog > Shared stock" back office menu entry.
     */
    public function installAdminTab(): bool
    {
        if ((int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER) > 0) {
            return true;
        }
        $adminTab = new Tab();
        $adminTab->class_name = self::ADMIN_CONTROLLER;
        $adminTab->module = $this->name;
        $adminTab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        $adminTab->active = true;
        $adminTab->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $adminTab->name[(int) $language['id_lang']] = $language['iso_code'] === 'fr' ? 'Stock partagé' : 'Shared stock';
        }
        return (bool) $adminTab->add();
    }

    public function uninstallAdminTab(): bool
    {
        $adminTabId = (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER);
        if ($adminTabId === 0) {
            return true;
        }
        $adminTab = new Tab($adminTabId);
        return (bool) $adminTab->delete();
    }

    /**
     * Converts the configuration stored by version 1.x in core tables, then removes the core table alterations.
     */
    public function migrateLegacySchema(): bool
    {
        $repository = $this->getRepository();
        if (!$repository->columnExists('product', 'use_common_stock') || !$repository->columnExists('product_attribute', 'stock_deduction')) {
            return $repository->dropLegacyColumns();
        }
        $editedScope = SmartStockScope::fromShopId((int) Configuration::get('PS_SHOP_DEFAULT'));
        foreach ($repository->getLegacyConfiguration() as $productId => $ratiosByCombination) {
            $this->getSynchronizer()->configureProduct($productId, true, '', $ratiosByCombination, $editedScope, null);
        }
        return $repository->dropLegacyColumns();
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink(self::ADMIN_CONTROLLER));
    }

    public function getRepository(): SmartStockRepository
    {
        if ($this->repository === null) {
            $this->repository = new SmartStockRepository();
        }
        return $this->repository;
    }

    public function getSynchronizer(): SmartStockSynchronizer
    {
        if ($this->synchronizer === null) {
            $this->synchronizer = new SmartStockSynchronizer($this->getRepository());
        }
        return $this->synchronizer;
    }

    /**
     * Shared stock panel of the product page (legacy page of 1.7 and "Modules" tab of the 8.x page).
     */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $productId = (int) (isset($params['id_product']) ? $params['id_product'] : Tools::getValue('id_product'));
        if ($productId <= 0) {
            return '';
        }
        $productState = $this->getSynchronizer()->getProductState($productId, SmartStockScope::fromContext(), (int) $this->context->language->id);
        $this->context->smarty->assign([
            'smartstock_state_json' => json_encode($productState),
            'smartstock_has_combinations' => !empty($productState['combinations']),
            'smartstock_ajax_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
            'smartstock_dashboard_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
            'smartstock_module_uri' => $this->getPathUri(),
            'smartstock_version' => $this->version,
            'smartstock_messages_json' => json_encode($this->getJavascriptMessages()),
        ]);
        return $this->display(__FILE__, 'views/templates/admin/product_tab.tpl');
    }

    public function hookActionObjectStockAvailableAddAfter(array $params): void
    {
        $this->forwardStockAvailableEvent($params);
    }

    public function hookActionObjectStockAvailableUpdateAfter(array $params): void
    {
        $this->forwardStockAvailableEvent($params);
    }

    public function hookActionUpdateQuantity(array $params): void
    {
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        $combinationId = isset($params['id_product_attribute']) ? (int) $params['id_product_attribute'] : 0;
        $shopId = isset($params['id_shop']) ? (int) $params['id_shop'] : 0;
        $this->forwardStockChange($productId, $combinationId, $shopId > 0 ? SmartStockScope::fromShopId($shopId) : null);
    }

    public function hookActionProductDelete(array $params): void
    {
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($productId > 0) {
            $this->getRepository()->deleteProduct($productId);
        }
    }

    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        if (isset($params['object']) && $params['object'] instanceof Combination && (int) $params['object']->id > 0) {
            $this->getRepository()->deleteCombination((int) $params['object']->id);
        }
    }

    private function forwardStockAvailableEvent(array $params): void
    {
        if (!isset($params['object']) || !$params['object'] instanceof StockAvailable) {
            return;
        }
        $stockAvailable = $params['object'];
        $this->forwardStockChange((int) $stockAvailable->id_product, (int) $stockAvailable->id_product_attribute, SmartStockScope::fromStockAvailable($stockAvailable));
    }

    /**
     * Stock events happen during order validation: a failure is logged and never interrupts the checkout.
     * Any change left unprocessed stays pending in the mirrors and is absorbed by the next reconciliation.
     */
    private function forwardStockChange(int $productId, int $combinationId, ?SmartStockScope $scope): void
    {
        try {
            $this->getSynchronizer()->handleStockChange($productId, $combinationId, $scope);
        } catch (Throwable $throwable) {
            PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 3, null, 'Product', $productId, true);
        }
    }

    /**
     * Strings used by the back office script.
     *
     * @return array<string, string>
     */
    public function getJavascriptMessages(): array
    {
        return [
            'saved' => $this->l('Shared stock saved.'),
            'error' => $this->l('An error occurred, please reload the page and try again.'),
            'invalidRatio' => $this->l('Each value must be a positive whole number (0 keeps the combination on its own stock).'),
            'invalidQuantity' => $this->l('Please enter a whole number.'),
            'confirmDisable' => $this->l('Disable the shared stock? Combination quantities are kept as they are and become independent again.'),
            'independent' => $this->l('Own stock'),
        ];
    }
}
