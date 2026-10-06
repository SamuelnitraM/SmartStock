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
 * Global settings of the module, stored in the PrestaShop configuration table.
 */
class SmartStockSettings
{
    const CART_GUARD = 'SMARTSTOCK_CART_GUARD';
    const FRONT_STOCK_DISPLAY = 'SMARTSTOCK_FRONT_STOCK_DISPLAY';
    const FRONT_PRICE_COMPARISON = 'SMARTSTOCK_FRONT_PRICE_COMPARISON';
    const ALERT_EMAILS = 'SMARTSTOCK_ALERT_EMAILS';

    const STOCK_DISPLAY_NEVER = 'never';
    const STOCK_DISPLAY_LOW = 'low';
    const STOCK_DISPLAY_ALWAYS = 'always';

    const DEFAULT_VALUES = [
        self::CART_GUARD => 1,
        self::FRONT_STOCK_DISPLAY => self::STOCK_DISPLAY_LOW,
        self::FRONT_PRICE_COMPARISON => 1,
        self::ALERT_EMAILS => '',
    ];

    public static function installDefaults(): bool
    {
        foreach (self::DEFAULT_VALUES as $settingName => $defaultValue) {
            if (Configuration::get($settingName) === false && !Configuration::updateValue($settingName, $defaultValue)) {
                return false;
            }
        }
        return true;
    }

    public static function uninstall(): bool
    {
        foreach (array_keys(self::DEFAULT_VALUES) as $settingName) {
            Configuration::deleteByName($settingName);
        }
        return true;
    }

    public static function isCartGuardEnabled(): bool
    {
        return (bool) Configuration::get(self::CART_GUARD);
    }

    public static function getFrontStockDisplay(): string
    {
        $displayMode = (string) Configuration::get(self::FRONT_STOCK_DISPLAY);
        return in_array($displayMode, [self::STOCK_DISPLAY_NEVER, self::STOCK_DISPLAY_LOW, self::STOCK_DISPLAY_ALWAYS], true) ? $displayMode : self::STOCK_DISPLAY_LOW;
    }

    public static function isPriceComparisonEnabled(): bool
    {
        return (bool) Configuration::get(self::FRONT_PRICE_COMPARISON);
    }

    /**
     * Recipients of the low stock alerts, the shop email address being used when none is configured.
     *
     * @return string[]
     */
    public static function getAlertRecipients(): array
    {
        $configuredRecipients = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) Configuration::get(self::ALERT_EMAILS))), function ($emailAddress) {
            return $emailAddress !== '' && Validate::isEmail($emailAddress);
        });
        return empty($configuredRecipients) ? [(string) Configuration::get('PS_SHOP_EMAIL')] : array_values($configuredRecipients);
    }

    /**
     * Validates and saves the settings submitted from the dashboard.
     *
     * @return string[] Names of the invalid email addresses, empty when everything was saved
     */
    public static function save(bool $isCartGuardEnabled, string $frontStockDisplay, bool $isPriceComparisonEnabled, string $alertEmails): array
    {
        $alertAddresses = array_filter(array_map('trim', preg_split('/[,;\s]+/', $alertEmails)));
        $invalidAddresses = array_values(array_filter($alertAddresses, function ($emailAddress) {
            return !Validate::isEmail($emailAddress);
        }));
        if (!empty($invalidAddresses)) {
            return $invalidAddresses;
        }
        Configuration::updateValue(self::CART_GUARD, (int) $isCartGuardEnabled);
        Configuration::updateValue(self::FRONT_STOCK_DISPLAY, in_array($frontStockDisplay, [self::STOCK_DISPLAY_NEVER, self::STOCK_DISPLAY_LOW, self::STOCK_DISPLAY_ALWAYS], true) ? $frontStockDisplay : self::STOCK_DISPLAY_LOW);
        Configuration::updateValue(self::FRONT_PRICE_COMPARISON, (int) $isPriceComparisonEnabled);
        Configuration::updateValue(self::ALERT_EMAILS, implode(', ', $alertAddresses));
        return [];
    }
}
