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
 * Moves the configuration of version 1.x (columns added to core tables) into the module tables,
 * registers the stock hooks and creates the back office menu entry.
 *
 * @param Ps_SmartStock $module
 */
function upgrade_module_2_0_0($module)
{
    return $module->getRepository()->createTables()
        && $module->registerHook(Ps_SmartStock::HOOKS)
        && $module->installAdminTab()
        && $module->migrateLegacySchema();
}
