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
require_once __DIR__ . '/classes/SmartStockSettings.php';
require_once __DIR__ . '/classes/SmartStockUnit.php';
require_once __DIR__ . '/classes/SmartStockScope.php';
require_once __DIR__ . '/classes/SmartStockMovementReason.php';
require_once __DIR__ . '/classes/SmartStockPoolOperation.php';
require_once __DIR__ . '/classes/SmartStockRepository.php';
require_once __DIR__ . '/classes/SmartStockSynchronizer.php';
require_once __DIR__ . '/classes/SmartStockCartGuard.php';
require_once __DIR__ . '/classes/SmartStockFrontPresenter.php';
require_once __DIR__ . '/classes/SmartStockAlertNotifier.php';

class Ps_SmartStock extends Module
{
    const ADMIN_CONTROLLER = 'AdminSmartStock';
    const PRODUCT_PANEL_MOVEMENT_LIMIT = 15;
    const ORDER_URL_PLACEHOLDER = 999999999;

    const HOOKS = [
        'displayAdminProductsExtra',
        'displayProductAdditionalInfo',
        'actionFrontControllerSetMedia',
        'actionObjectStockAvailableAddAfter',
        'actionObjectStockAvailableUpdateAfter',
        'actionUpdateQuantity',
        'actionCartUpdateQuantityBefore',
        'actionCartSave',
        'actionCheckoutRender',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionProductDelete',
        'actionObjectCombinationDeleteAfter',
    ];

    /** @var SmartStockRepository|null */
    private $repository;

    /** @var SmartStockSynchronizer|null */
    private $synchronizer;

    /** @var SmartStockCartGuard|null */
    private $cartGuard;

    public function __construct()
    {
        $this->name = 'ps_smartstock';
        $this->tab = 'administration';
        $this->version = '2.1.0';
        $this->author = 'SmartDev';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = $this->l('SmartStock - Shared stock for combinations');
        $this->description = $this->l('Sells several formats of the same product (50 g, 100 g, 500 g...) from a single shared stock expressed in base units.');
        $this->confirmUninstall = $this->l('Uninstalling deletes every shared stock configuration and its history. Combination quantities are kept as they are. Continue?');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->getRepository()->createTables()
            && SmartStockSettings::installDefaults()
            && $this->registerHook(self::HOOKS)
            && $this->installAdminTab();
    }

    public function uninstall(): bool
    {
        return $this->uninstallAdminTab()
            && $this->getRepository()->dropTables()
            && SmartStockSettings::uninstall()
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
            $this->getSynchronizer()->configureProduct($productId, true, '', 0, $ratiosByCombination, $editedScope, null);
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
            $this->synchronizer->addLowStockListener([new SmartStockAlertNotifier($this), 'notifyLowStock']);
        }
        return $this->synchronizer;
    }

    public function getCartGuard(): SmartStockCartGuard
    {
        if ($this->cartGuard === null) {
            $this->cartGuard = new SmartStockCartGuard($this->getRepository());
        }
        return $this->cartGuard;
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
        $productState = $this->getSynchronizer()->getProductState($productId, SmartStockScope::fromContext(), (int) $this->context->language->id, self::PRODUCT_PANEL_MOVEMENT_LIMIT);
        $this->context->smarty->assign([
            'smartstock_state_json' => json_encode($productState),
            'smartstock_has_combinations' => !empty($productState['combinations']),
            'smartstock_ajax_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
            'smartstock_dashboard_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
            'smartstock_module_uri' => $this->getPathUri(),
            'smartstock_version' => $this->version,
            'smartstock_configuration_json' => json_encode($this->getJavascriptConfiguration()),
        ]);
        return $this->display(__FILE__, 'views/templates/admin/product_tab.tpl');
    }

    /**
     * Remaining shared stock and price comparison between formats, refreshed by the theme on every combination change.
     */
    public function hookDisplayProductAdditionalInfo(array $params): string
    {
        if (!isset($params['product'])) {
            return '';
        }
        $productId = (int) $params['product']['id_product'];
        $selectedCombinationId = (int) $params['product']['id_product_attribute'];
        try {
            $presentedStock = (new SmartStockFrontPresenter($this->getRepository(), $this->getSynchronizer()))->present($productId, $selectedCombinationId, $this->context);
        } catch (Throwable $throwable) {
            PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 2, null, 'Product', $productId, true);
            return '';
        }
        if ($presentedStock === null || ($presentedStock['stock'] === null && $presentedStock['comparison'] === null)) {
            return '';
        }
        $this->context->smarty->assign('smartstock', $presentedStock);
        return $this->fetch('module:' . $this->name . '/views/templates/hook/product_info.tpl');
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        if (isset($this->context->controller->php_self) && $this->context->controller->php_self === 'product') {
            $this->context->controller->registerStylesheet('module-' . $this->name . '-front', 'modules/' . $this->name . '/views/css/front.css', ['media' => 'all', 'priority' => 150]);
        }
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

    /**
     * Remembers the line the customer is increasing, so the cart guard lowers it first if the shared stock is exceeded.
     */
    public function hookActionCartUpdateQuantityBefore(array $params): void
    {
        if (!isset($params['product']) || !$params['product'] instanceof Product || (isset($params['operator']) && $params['operator'] !== 'up')) {
            return;
        }
        $this->getCartGuard()->rememberChangedLine(
            (int) $params['product']->id,
            isset($params['id_product_attribute']) ? (int) $params['id_product_attribute'] : 0,
            isset($params['id_customization']) ? (int) $params['id_customization'] : 0
        );
    }

    public function hookActionCartSave(array $params): void
    {
        if (isset($params['cart']) && $params['cart'] instanceof Cart) {
            $this->enforceCustomerCart($params['cart']);
        }
    }

    /**
     * Last check before payment: the shared stock may have been consumed by other customers since the cart was filled.
     */
    public function hookActionCheckoutRender(): void
    {
        if (isset($this->context->cart) && $this->context->cart instanceof Cart) {
            $this->enforceCustomerCart($this->context->cart);
        }
    }

    public function hookActionValidateOrder(array $params): void
    {
        if (isset($params['order']) && $params['order'] instanceof Order) {
            $this->attachRecordedMovementsToOrder((int) $params['order']->id);
        }
    }

    public function hookActionOrderStatusPostUpdate(array $params): void
    {
        $this->attachRecordedMovementsToOrder(isset($params['id_order']) ? (int) $params['id_order'] : 0);
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
     * Applies the cart guard on carts edited by customers on the shop pages and tells them which quantities were lowered.
     * Module front controllers (payment validations) and already ordered carts are never touched, so a paid cart
     * is never modified.
     */
    private function enforceCustomerCart(Cart $cart): void
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        if (!$controller instanceof FrontController || $controller instanceof ModuleFrontController || $cart->orderExists()) {
            return;
        }
        try {
            $adjustedProducts = $this->getCartGuard()->enforce($cart);
        } catch (Throwable $throwable) {
            PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 3, null, 'Cart', (int) $cart->id, true);
            return;
        }
        foreach ($adjustedProducts as $adjustedProduct) {
            $this->context->controller->errors[] = sprintf(
                $this->l('Only %1$s of %2$s are left in stock: the quantities in your cart have been adjusted.'),
                SmartStockUnit::formatQuantity($adjustedProduct['pool_quantity'], $adjustedProduct['unit']),
                $adjustedProduct['product_name']
            );
        }
    }

    private function attachRecordedMovementsToOrder(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            $this->getRepository()->attachOrderToMovements(SmartStockSynchronizer::takeUnattachedMovementIds(), $orderId);
        } catch (Throwable $throwable) {
            PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 2, null, 'Order', $orderId, true);
        }
    }

    /**
     * Back office order link in which the placeholder order identifier is replaced by the real one.
     */
    public function getOrderUrlTemplate(): string
    {
        return $this->context->link->getAdminLink('AdminOrders', true, ['id_order' => self::ORDER_URL_PLACEHOLDER, 'vieworder' => 1]);
    }

    /**
     * Labels of the movement reasons, used by the back office screens.
     *
     * @return array<string, string>
     */
    public function getMovementReasonLabels(): array
    {
        return [
            SmartStockMovementReason::ACTIVATION => $this->l('Activation'),
            SmartStockMovementReason::SALE => $this->l('Sale'),
            SmartStockMovementReason::RESTOCK => $this->l('Cancellation / return'),
            SmartStockMovementReason::COMBINATION_EDIT => $this->l('Combination quantity edited'),
            SmartStockMovementReason::RECEIPT => $this->l('Goods receipt'),
            SmartStockMovementReason::LOSS => $this->l('Loss'),
            SmartStockMovementReason::INVENTORY => $this->l('Inventory'),
        ];
    }

    /**
     * Strings and links used by the back office script.
     *
     * @return array<string, mixed>
     */
    public function getJavascriptConfiguration(): array
    {
        return [
            'orderUrlTemplate' => $this->getOrderUrlTemplate(),
            'orderUrlPlaceholder' => self::ORDER_URL_PLACEHOLDER,
            'saved' => $this->l('Shared stock saved.'),
            'error' => $this->l('An error occurred, please reload the page and try again.'),
            'invalidRatio' => $this->l('Each value must be a positive whole number (0 keeps the combination on its own stock).'),
            'invalidQuantity' => $this->l('Please enter a whole number.'),
            'confirmDisable' => $this->l('Disable the shared stock? Combination quantities are kept as they are and become independent again.'),
            'independent' => $this->l('Own stock'),
            'noMovement' => $this->l('No movement yet.'),
            'order' => $this->l('Order'),
            'reasons' => $this->getMovementReasonLabels(),
        ];
    }
}
