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
 * Back office entry point: dashboard of shared stocks and JSON endpoints used by the product panel.
 *
 * @property Ps_SmartStock $module
 */
class AdminSmartStockController extends ModuleAdminController
{
    const TRANSLATION_SOURCE = 'adminsmartstockcontroller';

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Shared stock', self::TRANSLATION_SOURCE);
    }

    public function initContent()
    {
        parent::initContent();
        $this->content .= $this->renderDashboard();
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Saves the panel of the product page: activation, unit, ratios and optional inventory of the shared stock.
     */
    public function ajaxProcessSaveProduct(): void
    {
        $this->ensureEditPermission();
        $productId = (int) Tools::getValue('id_product');
        if ($productId <= 0 || !Validate::isLoadedObject(new Product($productId))) {
            $this->respondWithError($this->module->l('Unknown product.', self::TRANSLATION_SOURCE));
        }
        $isActive = (bool) Tools::getValue('active');
        $unit = Tools::substr(trim(strip_tags((string) Tools::getValue('unit'))), 0, 16);
        $ratiosByCombination = $this->readRatios($productId);
        $poolQuantity = $this->readOptionalInteger('pool_quantity');
        $poolLoadedQuantity = $this->readOptionalInteger('pool_loaded_quantity');
        $poolOverride = ($poolQuantity !== null && $poolQuantity !== $poolLoadedQuantity) ? $poolQuantity : null;
        $scope = SmartStockScope::fromContext();
        $this->executeSafely(function () use ($productId, $isActive, $unit, $ratiosByCombination, $scope, $poolOverride) {
            $this->module->getSynchronizer()->configureProduct($productId, $isActive, $unit, $ratiosByCombination, $scope, $poolOverride);
        });
        $this->respondWithState($productId, $scope, $this->module->l('Shared stock saved.', self::TRANSLATION_SOURCE));
    }

    /**
     * Inventory (absolute value) or movement (signed adjustment) on a shared stock.
     */
    public function ajaxProcessUpdatePool(): void
    {
        $this->ensureEditPermission();
        $productId = (int) Tools::getValue('id_product');
        $poolQuantity = $this->readOptionalInteger('pool_quantity');
        $poolAdjustment = $this->readOptionalInteger('pool_adjustment');
        if ($poolQuantity === null && $poolAdjustment === null) {
            $this->respondWithError($this->module->l('Please enter a whole number.', self::TRANSLATION_SOURCE));
        }
        $scope = SmartStockScope::fromContext();
        $this->executeSafely(function () use ($productId, $scope, $poolQuantity, $poolAdjustment) {
            $this->module->getSynchronizer()->updatePool($productId, $scope, $poolQuantity, (int) $poolAdjustment);
        });
        $this->respondWithState($productId, $scope, $this->module->l('Shared stock updated.', self::TRANSLATION_SOURCE));
    }

    /**
     * Reconciles every shared stock, absorbing changes made by imports, web services or direct SQL.
     */
    public function ajaxProcessReconcileAll(): void
    {
        $this->ensureEditPermission();
        $productIds = $this->module->getRepository()->getActiveProductIds();
        $this->executeSafely(function () use ($productIds) {
            foreach ($productIds as $productId) {
                $this->module->getSynchronizer()->reconcileProduct($productId);
            }
        });
        $this->respondWithJson([
            'success' => true,
            'message' => sprintf($this->module->l('%d shared stock(s) resynchronized.', self::TRANSLATION_SOURCE), count($productIds)),
        ]);
    }

    private function renderDashboard(): string
    {
        $scope = SmartStockScope::fromContext();
        $languageId = (int) $this->context->language->id;
        $productStates = [];
        foreach ($this->module->getRepository()->getActiveProductIds() as $productId) {
            $productState = $this->module->getSynchronizer()->getProductState($productId, $scope, $languageId);
            $productState['edit_url'] = $this->context->link->getAdminLink('AdminProducts', true, ['id_product' => $productId, 'updateproduct' => '1']);
            $productStates[] = $productState;
        }
        $this->context->smarty->assign([
            'smartstock_products' => $productStates,
            'smartstock_ajax_url' => $this->context->link->getAdminLink(Ps_SmartStock::ADMIN_CONTROLLER),
            'smartstock_module_uri' => $this->module->getPathUri(),
            'smartstock_version' => $this->module->version,
            'smartstock_messages_json' => json_encode($this->module->getJavascriptMessages()),
            'smartstock_is_multishop' => Shop::isFeatureActive(),
            'smartstock_shop_name' => $this->context->shop->name,
        ]);
        return $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/dashboard.tpl');
    }

    /**
     * @return array<int, int> Ratios indexed by combination, restricted to the combinations of the product
     */
    private function readRatios(int $productId): array
    {
        $submittedRatios = Tools::getValue('ratios');
        if (!is_array($submittedRatios)) {
            return [];
        }
        $product = new Product($productId);
        $productCombinationIds = array_map('intval', array_column($product->getAttributesResume((int) $this->context->language->id) ?: [], 'id_product_attribute'));
        $ratiosByCombination = [];
        foreach ($submittedRatios as $combinationId => $ratio) {
            if (in_array((int) $combinationId, $productCombinationIds, true)) {
                $ratiosByCombination[(int) $combinationId] = max(0, (int) $ratio);
            }
        }
        return $ratiosByCombination;
    }

    private function readOptionalInteger(string $parameterName): ?int
    {
        $rawValue = trim((string) Tools::getValue($parameterName, ''));
        if ($rawValue === '') {
            return null;
        }
        if (!preg_match('/^[+-]?\d+$/', $rawValue)) {
            $this->respondWithError($this->module->l('Please enter a whole number.', self::TRANSLATION_SOURCE));
        }
        return (int) $rawValue;
    }

    private function ensureEditPermission(): void
    {
        if (!$this->access('edit')) {
            $this->respondWithError($this->module->l('You do not have permission to edit stocks.', self::TRANSLATION_SOURCE), 403);
        }
    }

    private function executeSafely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $throwable) {
            PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 3, null, 'Product', (int) Tools::getValue('id_product'), true);
            $this->respondWithError($this->module->l('The shared stock could not be updated. Details were written to the logs.', self::TRANSLATION_SOURCE), 500);
        }
    }

    private function respondWithState(int $productId, SmartStockScope $scope, string $message): void
    {
        $this->respondWithJson([
            'success' => true,
            'message' => $message,
            'state' => $this->module->getSynchronizer()->getProductState($productId, $scope, (int) $this->context->language->id),
        ]);
    }

    private function respondWithError(string $message, int $statusCode = 400): void
    {
        $this->respondWithJson(['success' => false, 'message' => $message], $statusCode);
    }

    private function respondWithJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender(json_encode($payload));
        exit;
    }
}
