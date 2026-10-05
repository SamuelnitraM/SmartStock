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
 * Sends the low stock email when a shared stock goes below the alert threshold of its product.
 */
class SmartStockAlertNotifier
{
    const MAIL_TEMPLATE = 'smartstock_low_stock';

    /** @var Ps_SmartStock */
    private $module;

    public function __construct(Ps_SmartStock $module)
    {
        $this->module = $module;
    }

    /**
     * Low stock listener of the synchronizer. A mail failure is logged and never interrupts the stock update.
     */
    public function notifyLowStock(int $productId, SmartStockScope $scope, int $poolQuantity, int $alertThreshold): void
    {
        try {
            $languageId = $this->getMailLanguageId();
            $productConfiguration = $this->module->getRepository()->getProductConfiguration($productId);
            $unit = $productConfiguration !== null ? $productConfiguration['unit'] : '';
            $templateVariables = [
                '{product_name}' => (string) Product::getProductName($productId, null, $languageId),
                '{product_id}' => $productId,
                '{remaining}' => SmartStockUnit::formatQuantity($poolQuantity, $unit),
                '{threshold}' => SmartStockUnit::formatQuantity($alertThreshold, $unit),
            ];
            $mailSubject = sprintf($this->module->l('Low shared stock: %s', 'smartstockalertnotifier'), $templateVariables['{product_name}']);
            foreach (SmartStockSettings::getAlertRecipients() as $recipientEmail) {
                Mail::Send(
                    $languageId,
                    self::MAIL_TEMPLATE,
                    $mailSubject,
                    $templateVariables,
                    $recipientEmail,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $this->module->getLocalPath() . 'mails/',
                    false,
                    $scope->getRepresentativeShopId()
                );
            }
        } catch (Throwable $throwable) {
            PrestaShopLogger::addLog('SmartStock : ' . $throwable->getMessage(), 3, null, 'Product', $productId, true);
        }
    }

    /**
     * Default shop language when the module ships its templates, English otherwise.
     */
    private function getMailLanguageId(): int
    {
        $defaultLanguageId = (int) Configuration::get('PS_LANG_DEFAULT');
        $defaultLanguageIso = (string) Language::getIsoById($defaultLanguageId);
        if (is_file($this->module->getLocalPath() . 'mails/' . $defaultLanguageIso . '/' . self::MAIL_TEMPLATE . '.txt')) {
            return $defaultLanguageId;
        }
        $englishLanguageId = (int) Language::getIdByIso('en');
        return $englishLanguageId > 0 ? $englishLanguageId : $defaultLanguageId;
    }
}
