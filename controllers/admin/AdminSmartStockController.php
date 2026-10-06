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
 * Back office entry point: dashboard, configuration assistant, CSV import/export, settings,
 * and JSON endpoints used by the product panel.
 *
 * @property Ps_SmartStock $module
 */
class AdminSmartStockController extends ModuleAdminController
{
    const TRANSLATION_SOURCE = 'adminsmartstockcontroller';
    const CANDIDATE_PRODUCT_LIMIT = 200;
    const CANDIDATE_DISPLAY_LIMIT = 30;
    const DASHBOARD_MOVEMENT_LIMIT = 50;
    const CSV_SEPARATOR = ';';
    const CSV_COLUMNS = ['id_product', 'product_name', 'unit', 'shared_stock', 'alert_threshold', 'formats'];

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Shared stock', self::TRANSLATION_SOURCE);
    }

    public function postProcess()
    {
        if (Tools::getValue('smartstockExport')) {
            $this->exportCsv();
        }
        if (Tools::isSubmit('submitSmartStockImport')) {
            $this->importCsv();
        }
        if (Tools::isSubmit('submitSmartStockSettings')) {
            $this->saveSettings();
        }
        return parent::postProcess();
    }

    public function initContent()
    {
        parent::initContent();
        $this->content .= $this->renderDashboard();
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Saves the panel of the product page: activation, unit, alert threshold, ratios and optional inventory of the shared stock.
     */
    public function ajaxProcessSaveProduct(): void
    {
        $this->ensureEditPermission();
        $productId = $this->readProductId();
        $isActive = (bool) Tools::getValue('active');
        $unit = $this->readUnit();
        $alertThreshold = max(0, (int) $this->readOptionalInteger('alert_threshold'));
        $ratiosByCombination = $this->readRatios($productId);
        $poolQuantity = $this->readOptionalInteger('pool_quantity');
        $poolLoadedQuantity = $this->readOptionalInteger('pool_loaded_quantity');
        $poolOverride = ($poolQuantity !== null && $poolQuantity !== $poolLoadedQuantity) ? $poolQuantity : null;
        $scope = SmartStockScope::fromContext();
        $this->executeSafely(function () use ($productId, $isActive, $unit, $alertThreshold, $ratiosByCombination, $scope, $poolOverride) {
            $this->module->getSynchronizer()->configureProduct($productId, $isActive, $unit, $alertThreshold, $ratiosByCombination, $scope, $poolOverride);
        });
        $this->respondWithState($productId, $scope, $this->module->l('Shared stock saved.', self::TRANSLATION_SOURCE));
    }

    /**
     * Inventory (absolute value) or movement (signed adjustment) on a shared stock.
     */
    public function ajaxProcessUpdatePool(): void
    {
        $this->ensureEditPermission();
        $productId = $this->readProductId();
        $poolQuantity = $this->readOptionalInteger('pool_quantity');
        $poolAdjustment = $this->readOptionalInteger('pool_adjustment');
        if ($poolQuantity === null && $poolAdjustment === null) {
            $this->respondWithError($this->module->l('Please enter a whole number.', self::TRANSLATION_SOURCE));
        }
        $comment = Tools::substr(trim(strip_tags((string) Tools::getValue('comment'))), 0, 255);
        $poolOperation = SmartStockPoolOperation::fromOptionalValues($poolQuantity, (int) $poolAdjustment, $comment);
        $scope = SmartStockScope::fromContext();
        $this->executeSafely(function () use ($productId, $scope, $poolOperation) {
            $this->module->getSynchronizer()->updatePool($productId, $scope, $poolOperation);
        });
        $this->respondWithState($productId, $scope, $this->module->l('Shared stock updated.', self::TRANSLATION_SOURCE));
    }

    /**
     * Ratio suggestions computed from the combination names for the unit typed by the employee.
     */
    public function ajaxProcessSuggestRatios(): void
    {
        $productId = $this->readProductId();
        $this->respondWithJson([
            'success' => true,
            'ratios' => $this->module->getSynchronizer()->suggestRatios($productId, $this->readUnit(), (int) $this->context->language->id),
        ]);
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

    /**
     * One-click activation of the products proposed by the configuration assistant, with the detected unit and ratios.
     */
    public function ajaxProcessEnableProducts(): void
    {
        $this->ensureEditPermission();
        $productIds = array_filter(array_map('intval', (array) Tools::getValue('product_ids', [])));
        $candidatesByProduct = $this->getCandidateProducts(PHP_INT_MAX);
        $scope = SmartStockScope::fromContext();
        $enabledCount = 0;
        $this->executeSafely(function () use ($productIds, $candidatesByProduct, $scope, &$enabledCount) {
            foreach ($productIds as $productId) {
                if (!isset($candidatesByProduct[$productId])) {
                    continue;
                }
                $candidate = $candidatesByProduct[$productId];
                $this->module->getSynchronizer()->configureProduct($productId, true, $candidate['unit'], 0, $candidate['ratios'], $scope, null);
                ++$enabledCount;
            }
        });
        $this->respondWithJson([
            'success' => true,
            'message' => sprintf($this->module->l('%d product(s) now use a shared stock. Check their shared stock value.', self::TRANSLATION_SOURCE), $enabledCount),
        ]);
    }

    private function renderDashboard(): string
    {
        $scope = SmartStockScope::fromContext();
        $languageId = (int) $this->context->language->id;
        $productStates = [];
        foreach ($this->module->getRepository()->getActiveProductIds() as $productId) {
            $productState = $this->module->getSynchronizer()->getProductState($productId, $scope, $languageId);
            $productState['edit_url'] = $this->getProductEditUrl($productId);
            $productStates[] = $productState;
        }
        usort($productStates, function ($firstState, $secondState) {
            return (int) $secondState['is_low'] - (int) $firstState['is_low'];
        });
        $candidateProducts = array_slice($this->getCandidateProducts(self::CANDIDATE_DISPLAY_LIMIT), 0, self::CANDIDATE_DISPLAY_LIMIT, true);
        $controllerUrl = $this->context->link->getAdminLink(Ps_SmartStock::ADMIN_CONTROLLER);
        $this->context->smarty->assign([
            'smartstock_products' => $productStates,
            'smartstock_candidates' => array_values($candidateProducts),
            'smartstock_movements' => $this->module->getSynchronizer()->getMovementHistory(null, $scope, $languageId, self::DASHBOARD_MOVEMENT_LIMIT),
            'smartstock_reason_labels' => $this->module->getMovementReasonLabels(),
            'smartstock_kpis' => [
                'managed' => count($productStates),
                'low' => count(array_filter($productStates, function ($productState) {
                    return $productState['is_low'];
                })),
                'movements' => $this->module->getRepository()->countMovementsSince(date('Y-m-d H:i:s', strtotime('-7 days'))),
            ],
            'smartstock_settings' => [
                'cart_guard' => SmartStockSettings::isCartGuardEnabled(),
                'front_stock_display' => SmartStockSettings::getFrontStockDisplay(),
                'price_comparison' => SmartStockSettings::isPriceComparisonEnabled(),
                'alert_emails' => (string) Configuration::get(SmartStockSettings::ALERT_EMAILS),
                'shop_email' => (string) Configuration::get('PS_SHOP_EMAIL'),
            ],
            'smartstock_controller_url' => $controllerUrl,
            'smartstock_export_url' => $controllerUrl . '&smartstockExport=1',
            'smartstock_ajax_url' => $controllerUrl,
            'smartstock_module_uri' => $this->module->getPathUri(),
            'smartstock_version' => $this->module->version,
            'smartstock_configuration_json' => json_encode($this->module->getJavascriptConfiguration()),
            'smartstock_order_url_template' => $this->module->getOrderUrlTemplate(),
            'smartstock_order_url_placeholder' => Ps_SmartStock::ORDER_URL_PLACEHOLDER,
            'smartstock_is_multishop' => Shop::isFeatureActive(),
            'smartstock_shop_name' => $this->context->shop->name,
        ]);
        return $this->context->smarty->fetch($this->module->getLocalPath() . 'views/templates/admin/dashboard.tpl');
    }

    /**
     * Products with several combinations whose names reveal a quantity (50 g, 1 kg, 75 cl, lot de 6...).
     *
     * @return array<int, array> Candidates indexed by product, with the detected unit and the suggested ratios
     */
    private function getCandidateProducts(int $displayLimit): array
    {
        $languageId = (int) $this->context->language->id;
        $candidateProducts = [];
        foreach ($this->module->getRepository()->getCandidateProductCombinations($languageId, self::CANDIDATE_PRODUCT_LIMIT) as $productId => $combinationLabels) {
            $detectedUnit = SmartStockUnit::detectBaseUnit(array_values($combinationLabels));
            if ($detectedUnit === null) {
                continue;
            }
            $suggestedRatios = [];
            $formats = [];
            foreach ($combinationLabels as $combinationId => $combinationLabel) {
                $suggestedRatios[$combinationId] = SmartStockUnit::suggestRatio($combinationLabel, $detectedUnit);
                $formats[] = ['name' => $combinationLabel, 'ratio' => $suggestedRatios[$combinationId]];
            }
            if (count(array_filter($suggestedRatios)) < 2) {
                continue;
            }
            $candidateProducts[$productId] = [
                'id_product' => $productId,
                'name' => (string) Product::getProductName($productId, null, $languageId),
                'unit' => $detectedUnit,
                'ratios' => $suggestedRatios,
                'formats' => $formats,
                'edit_url' => $this->getProductEditUrl($productId),
            ];
            if (count($candidateProducts) >= $displayLimit) {
                break;
            }
        }
        return $candidateProducts;
    }

    /**
     * Streams every shared stock as a CSV file (semicolon separated, UTF-8 with BOM for spreadsheet software).
     */
    private function exportCsv(): void
    {
        $scope = SmartStockScope::fromContext();
        $languageId = (int) $this->context->language->id;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="smartstock-' . date('Y-m-d-His') . '.csv"');
        $outputStream = fopen('php://output', 'w');
        fwrite($outputStream, "\xEF\xBB\xBF");
        fputcsv($outputStream, self::CSV_COLUMNS, self::CSV_SEPARATOR);
        foreach ($this->module->getRepository()->getActiveProductIds() as $productId) {
            $productState = $this->module->getSynchronizer()->getProductState($productId, $scope, $languageId);
            $formatSummaries = [];
            foreach ($productState['combinations'] as $combination) {
                if ($combination['ratio'] > 0) {
                    $formatSummaries[] = $combination['name'] . ' = ' . $combination['ratio'];
                }
            }
            fputcsv($outputStream, [
                $productId,
                $productState['name'],
                $productState['unit'],
                $productState['pool_quantity'],
                $productState['alert_threshold'],
                implode(' | ', $formatSummaries),
            ], self::CSV_SEPARATOR);
        }
        fclose($outputStream);
        exit;
    }

    /**
     * Applies an inventory file: the "shared_stock" column becomes the new shared stock, the optional
     * "alert_threshold" column updates the alert threshold. Columns are matched by their header name.
     */
    private function importCsv(): void
    {
        if (!$this->access('edit')) {
            $this->errors[] = $this->module->l('You do not have permission to edit stocks.', self::TRANSLATION_SOURCE);
            return;
        }
        $uploadedFile = isset($_FILES['smartstock_csv']) ? $_FILES['smartstock_csv'] : null;
        if ($uploadedFile === null || (int) $uploadedFile['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($uploadedFile['tmp_name'])) {
            $this->errors[] = $this->module->l('Please select a CSV file.', self::TRANSLATION_SOURCE);
            return;
        }
        $inputStream = fopen($uploadedFile['tmp_name'], 'r');
        $headerRow = fgetcsv($inputStream, 0, self::CSV_SEPARATOR);
        $columnIndexes = array_flip(array_map(function ($headerName) {
            return Tools::strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $headerName)));
        }, $headerRow ?: []));
        if (!isset($columnIndexes['id_product'], $columnIndexes['shared_stock'])) {
            fclose($inputStream);
            $this->errors[] = $this->module->l('The file must contain the id_product and shared_stock columns, separated by semicolons.', self::TRANSLATION_SOURCE);
            return;
        }
        $repository = $this->module->getRepository();
        $scope = SmartStockScope::fromContext();
        $importComment = sprintf($this->module->l('CSV import %s', self::TRANSLATION_SOURCE), $uploadedFile['name']);
        $updatedCount = 0;
        $lineNumber = 1;
        while (($csvRow = fgetcsv($inputStream, 0, self::CSV_SEPARATOR)) !== false) {
            ++$lineNumber;
            $productId = (int) $this->getCsvValue($csvRow, $columnIndexes, 'id_product');
            $sharedStockValue = trim($this->getCsvValue($csvRow, $columnIndexes, 'shared_stock'));
            if ($productId <= 0 && $sharedStockValue === '') {
                continue;
            }
            $productConfiguration = $repository->getProductConfiguration($productId);
            if ($productConfiguration === null || !$productConfiguration['active'] || !preg_match('/^[+-]?\d+$/', $sharedStockValue)) {
                $this->errors[] = sprintf($this->module->l('Line %d ignored: unknown product or invalid quantity.', self::TRANSLATION_SOURCE), $lineNumber);
                continue;
            }
            try {
                $alertThresholdValue = trim($this->getCsvValue($csvRow, $columnIndexes, 'alert_threshold'));
                if (preg_match('/^\d+$/', $alertThresholdValue)) {
                    $repository->saveProductConfiguration($productId, true, $productConfiguration['unit'], (int) $alertThresholdValue);
                }
                $this->module->getSynchronizer()->updatePool($productId, $scope, SmartStockPoolOperation::inventory((int) $sharedStockValue, $importComment));
                ++$updatedCount;
            } catch (Throwable $throwable) {
                PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 3, null, 'Product', $productId, true);
                $this->errors[] = sprintf($this->module->l('Line %d could not be applied. Details were written to the logs.', self::TRANSLATION_SOURCE), $lineNumber);
            }
        }
        fclose($inputStream);
        $this->confirmations[] = sprintf($this->module->l('%d shared stock(s) updated from the file.', self::TRANSLATION_SOURCE), $updatedCount);
    }

    private function getCsvValue(array $csvRow, array $columnIndexes, string $columnName): string
    {
        return isset($columnIndexes[$columnName], $csvRow[$columnIndexes[$columnName]]) ? (string) $csvRow[$columnIndexes[$columnName]] : '';
    }

    private function saveSettings(): void
    {
        if (!$this->access('edit')) {
            $this->errors[] = $this->module->l('You do not have permission to edit stocks.', self::TRANSLATION_SOURCE);
            return;
        }
        $invalidAddresses = SmartStockSettings::save(
            (bool) Tools::getValue('cart_guard'),
            (string) Tools::getValue('front_stock_display'),
            (bool) Tools::getValue('price_comparison'),
            (string) Tools::getValue('alert_emails')
        );
        if (!empty($invalidAddresses)) {
            $this->errors[] = sprintf($this->module->l('Invalid email address: %s', self::TRANSLATION_SOURCE), implode(', ', $invalidAddresses));
            return;
        }
        $this->confirmations[] = $this->module->l('Settings saved.', self::TRANSLATION_SOURCE);
    }

    private function getProductEditUrl(int $productId): string
    {
        return $this->context->link->getAdminLink('AdminProducts', true, ['id_product' => $productId, 'updateproduct' => '1']);
    }

    private function readProductId(): int
    {
        $productId = (int) Tools::getValue('id_product');
        if ($productId <= 0 || !Validate::isLoadedObject(new Product($productId))) {
            $this->respondWithError($this->module->l('Unknown product.', self::TRANSLATION_SOURCE));
        }
        return $productId;
    }

    private function readUnit(): string
    {
        return Tools::substr(trim(strip_tags((string) Tools::getValue('unit'))), 0, 16);
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
        $productCombinationIds = array_keys($this->module->getSynchronizer()->getCombinationLabels($productId, (int) $this->context->language->id));
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
            'state' => $this->module->getSynchronizer()->getProductState($productId, $scope, (int) $this->context->language->id, Ps_SmartStock::PRODUCT_PANEL_MOVEMENT_LIMIT),
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
