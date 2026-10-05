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
 * Adds the movement journal, the alert threshold, the default settings and the cart, order and front office hooks.
 *
 * @param Ps_SmartStock $module
 */
function upgrade_module_2_1_0($module)
{
    return $module->getRepository()->createTables()
        && SmartStockSettings::installDefaults()
        && $module->registerHook(Ps_SmartStock::HOOKS);
}
