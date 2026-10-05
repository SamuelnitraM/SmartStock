{**
 * SmartStock - Shared stock for product combinations.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 *}
<link rel="stylesheet" href="{$smartstock_module_uri|escape:'html':'UTF-8'}views/css/admin.css?v={$smartstock_version|escape:'html':'UTF-8'}">
<div class="smartstock-dashboard"
     data-smartstock-dashboard
     data-ajax-url="{$smartstock_ajax_url|escape:'html':'UTF-8'}"
     data-configuration="{$smartstock_configuration_json|escape:'html':'UTF-8'}">
  <nav class="smartstock-anchors">
    <a href="#smartstock-stocks">{l s='Shared stocks' mod='ps_smartstock'}</a>
    <a href="#smartstock-assistant">{l s='Assistant' mod='ps_smartstock'}{if $smartstock_candidates|count} <span class="badge">{$smartstock_candidates|count}</span>{/if}</a>
    <a href="#smartstock-history">{l s='History' mod='ps_smartstock'}</a>
    <a href="#smartstock-import">{l s='Import / export' mod='ps_smartstock'}</a>
    <a href="#smartstock-settings">{l s='Settings' mod='ps_smartstock'}</a>
  </nav>
  <div class="smartstock-kpis">
    <div class="smartstock-kpi">
      <span class="smartstock-kpi-value">{$smartstock_kpis.managed|intval}</span>
      <span class="smartstock-kpi-label">{l s='Products with a shared stock' mod='ps_smartstock'}</span>
    </div>
    <div class="smartstock-kpi{if $smartstock_kpis.low > 0} smartstock-kpi-alert{/if}">
      <span class="smartstock-kpi-value">{$smartstock_kpis.low|intval}</span>
      <span class="smartstock-kpi-label">{l s='Below the alert threshold' mod='ps_smartstock'}</span>
    </div>
    <div class="smartstock-kpi">
      <span class="smartstock-kpi-value">{$smartstock_kpis.movements|intval}</span>
      <span class="smartstock-kpi-label">{l s='Movements over 7 days' mod='ps_smartstock'}</span>
    </div>
  </div>
  {if $smartstock_is_multishop}
    <div class="alert alert-info">{l s='Quantities shown and edited for the stock of shop:' mod='ps_smartstock'} <strong>{$smartstock_shop_name|escape:'html':'UTF-8'}</strong></div>
  {/if}
  <span class="js-smartstock-feedback smartstock-feedback"></span>

  <div class="panel" id="smartstock-stocks">
    <div class="panel-heading smartstock-panel-heading">
      <span>{l s='Shared stocks' mod='ps_smartstock'}</span>
      <button type="button" class="btn btn-default js-smartstock-reconcile-all">
        <i class="icon-refresh"></i> {l s='Resynchronize all' mod='ps_smartstock'}
      </button>
    </div>
    {if empty($smartstock_products)}
      <div class="alert alert-info">
        {l s='No product uses a shared stock yet. Use the assistant below, or open a product with combinations and enable the shared stock in its "Modules" tab.' mod='ps_smartstock'}
      </div>
    {else}
      <table class="table smartstock-table">
        <thead>
          <tr>
            <th>{l s='Product' mod='ps_smartstock'}</th>
            <th>{l s='Shared stock' mod='ps_smartstock'}</th>
            <th>{l s='Sellable quantities' mod='ps_smartstock'}</th>
            <th>{l s='Movement' mod='ps_smartstock'}</th>
            <th>{l s='Inventory' mod='ps_smartstock'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$smartstock_products item=smartstock_product}
            <tr data-smartstock-row data-product-id="{$smartstock_product.id_product|intval}" class="{if $smartstock_product.is_low}smartstock-row-low{/if}">
              <td>
                <a href="{$smartstock_product.edit_url|escape:'html':'UTF-8'}">{$smartstock_product.name|escape:'html':'UTF-8'}</a>
                <small class="smartstock-muted">#{$smartstock_product.id_product|intval}</small>
              </td>
              <td class="smartstock-pool">
                <span class="js-smartstock-pool-value">{$smartstock_product.pool_readable|escape:'html':'UTF-8'}</span>
                <small class="smartstock-muted js-smartstock-threshold">
                  {if $smartstock_product.alert_threshold > 0}{l s='alert below' mod='ps_smartstock'} {$smartstock_product.alert_threshold|intval} {$smartstock_product.unit|escape:'html':'UTF-8'}{/if}
                </small>
              </td>
              <td class="js-smartstock-formats">
                {foreach from=$smartstock_product.combinations item=smartstock_combination}
                  {if $smartstock_combination.ratio > 0}
                    <span class="smartstock-format{if $smartstock_combination.quantity <= 0} smartstock-format-empty{/if}">
                      {$smartstock_combination.name|escape:'html':'UTF-8'} : <strong>{$smartstock_combination.quantity|intval}</strong>
                    </span>
                  {/if}
                {/foreach}
              </td>
              <td>
                <div class="input-group smartstock-inline">
                  <input type="number" step="1" class="form-control js-smartstock-adjustment" placeholder="+5000 / -250">
                  <span class="input-group-btn">
                    <button type="button" class="btn btn-default js-smartstock-apply-adjustment">{l s='Apply' mod='ps_smartstock'}</button>
                  </span>
                </div>
              </td>
              <td>
                <div class="input-group smartstock-inline">
                  <input type="number" step="1" class="form-control js-smartstock-inventory" placeholder="{$smartstock_product.pool_quantity|intval}">
                  <span class="input-group-btn">
                    <button type="button" class="btn btn-default js-smartstock-apply-inventory">{l s='Set' mod='ps_smartstock'}</button>
                  </span>
                </div>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}
  </div>

  <div class="panel" id="smartstock-assistant">
    <div class="panel-heading smartstock-panel-heading">
      <span>{l s='Configuration assistant' mod='ps_smartstock'}</span>
      {if !empty($smartstock_candidates)}
        <button type="button" class="btn btn-primary js-smartstock-enable-selected">{l s='Enable selected products' mod='ps_smartstock'}</button>
      {/if}
    </div>
    <p class="smartstock-help">{l s='Products whose combination names reveal a quantity (50 g, 1 kg, 75 cl, pack of 6...). Units and ratios are detected automatically; the initial shared stock is computed from the current quantities and can be corrected afterwards.' mod='ps_smartstock'}</p>
    {if empty($smartstock_candidates)}
      <div class="alert alert-success">{l s='No other eligible product detected.' mod='ps_smartstock'}</div>
    {else}
      <table class="table smartstock-table">
        <thead>
          <tr>
            <th><input type="checkbox" class="js-smartstock-select-all" checked></th>
            <th>{l s='Product' mod='ps_smartstock'}</th>
            <th>{l s='Detected unit' mod='ps_smartstock'}</th>
            <th>{l s='Detected ratios' mod='ps_smartstock'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$smartstock_candidates item=smartstock_candidate}
            <tr>
              <td><input type="checkbox" class="js-smartstock-candidate" value="{$smartstock_candidate.id_product|intval}" checked></td>
              <td><a href="{$smartstock_candidate.edit_url|escape:'html':'UTF-8'}">{$smartstock_candidate.name|escape:'html':'UTF-8'}</a> <small class="smartstock-muted">#{$smartstock_candidate.id_product|intval}</small></td>
              <td><strong>{$smartstock_candidate.unit|escape:'html':'UTF-8'}</strong></td>
              <td>
                {foreach from=$smartstock_candidate.formats item=smartstock_format}
                  <span class="smartstock-format{if $smartstock_format.ratio <= 0} smartstock-format-independent{/if}">
                    {$smartstock_format.name|escape:'html':'UTF-8'} &rarr; {if $smartstock_format.ratio > 0}{$smartstock_format.ratio|intval}{else}{l s='Own stock' mod='ps_smartstock'}{/if}
                  </span>
                {/foreach}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}
  </div>

  <div class="panel" id="smartstock-history">
    <div class="panel-heading">{l s='Latest movements' mod='ps_smartstock'}</div>
    {if empty($smartstock_movements)}
      <div class="alert alert-info">{l s='No movement yet.' mod='ps_smartstock'}</div>
    {else}
      <table class="table smartstock-table smartstock-history">
        <thead>
          <tr>
            <th>{l s='Date' mod='ps_smartstock'}</th>
            <th>{l s='Product' mod='ps_smartstock'}</th>
            <th>{l s='Reason' mod='ps_smartstock'}</th>
            <th>{l s='Movement' mod='ps_smartstock'}</th>
            <th>{l s='Shared stock after' mod='ps_smartstock'}</th>
            <th>{l s='Details' mod='ps_smartstock'}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$smartstock_movements item=smartstock_movement}
            <tr>
              <td class="smartstock-nowrap">{$smartstock_movement.date_add|escape:'html':'UTF-8'}</td>
              <td>
                {$smartstock_movement.product_name|escape:'html':'UTF-8'}
                {if $smartstock_movement.combination_name}<br><small class="smartstock-muted">{$smartstock_movement.combination_name|escape:'html':'UTF-8'}</small>{/if}
              </td>
              <td><span class="smartstock-reason smartstock-reason-{$smartstock_movement.reason|escape:'html':'UTF-8'}">{if isset($smartstock_reason_labels[$smartstock_movement.reason])}{$smartstock_reason_labels[$smartstock_movement.reason]|escape:'html':'UTF-8'}{else}{$smartstock_movement.reason|escape:'html':'UTF-8'}{/if}</span></td>
              <td class="smartstock-nowrap {if $smartstock_movement.quantity_delta < 0}smartstock-negative{else}smartstock-positive{/if}">{$smartstock_movement.delta_readable|escape:'html':'UTF-8'}</td>
              <td class="smartstock-nowrap">{$smartstock_movement.after_readable|escape:'html':'UTF-8'}</td>
              <td>
                {if $smartstock_movement.id_order > 0}
                  <a href="{$smartstock_order_url_template|replace:$smartstock_order_url_placeholder:$smartstock_movement.id_order|escape:'html':'UTF-8'}">{l s='Order' mod='ps_smartstock'} #{$smartstock_movement.id_order|intval}</a>
                {/if}
                {if $smartstock_movement.employee_name}<small class="smartstock-muted">{$smartstock_movement.employee_name|escape:'html':'UTF-8'}</small>{/if}
                {if $smartstock_movement.comment}<small class="smartstock-muted">{$smartstock_movement.comment|escape:'html':'UTF-8'}</small>{/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}
  </div>

  <div class="panel" id="smartstock-import">
    <div class="panel-heading">{l s='Import / export' mod='ps_smartstock'}</div>
    <div class="smartstock-columns">
      <div>
        <h4>{l s='Export' mod='ps_smartstock'}</h4>
        <p class="smartstock-help">{l s='Downloads every shared stock as a CSV file (semicolon separated), ready for a physical inventory.' mod='ps_smartstock'}</p>
        <a class="btn btn-default" href="{$smartstock_export_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> {l s='Export CSV' mod='ps_smartstock'}</a>
      </div>
      <div>
        <h4>{l s='Inventory import' mod='ps_smartstock'}</h4>
        <p class="smartstock-help">{l s='Upload the exported file after editing the shared_stock column (and optionally alert_threshold). Every line is recorded as an inventory in the history.' mod='ps_smartstock'}</p>
        <form method="post" enctype="multipart/form-data" action="{$smartstock_controller_url|escape:'html':'UTF-8'}" class="smartstock-import-form">
          <input type="file" name="smartstock_csv" accept=".csv,text/csv" class="form-control" required>
          <button type="submit" name="submitSmartStockImport" value="1" class="btn btn-primary"><i class="icon-upload"></i> {l s='Import' mod='ps_smartstock'}</button>
        </form>
      </div>
    </div>
  </div>

  <div class="panel" id="smartstock-settings">
    <div class="panel-heading">{l s='Settings' mod='ps_smartstock'}</div>
    <form method="post" action="{$smartstock_controller_url|escape:'html':'UTF-8'}" class="smartstock-settings-form">
      <label class="smartstock-switch">
        <input type="checkbox" name="cart_guard" value="1" {if $smartstock_settings.cart_guard}checked{/if}>
        <span>{l s='Prevent overselling across formats in the cart' mod='ps_smartstock'}</span>
      </label>
      <p class="smartstock-hint">{l s='PrestaShop checks each cart line separately. When enabled, the whole cart is compared with the shared stock and quantities in excess are lowered with a message to the customer.' mod='ps_smartstock'}</p>
      <div class="smartstock-field">
        <label for="smartstock-front-stock-display">{l s='Remaining stock on the product page' mod='ps_smartstock'}</label>
        <select id="smartstock-front-stock-display" name="front_stock_display" class="form-control">
          <option value="never" {if $smartstock_settings.front_stock_display == 'never'}selected{/if}>{l s='Never' mod='ps_smartstock'}</option>
          <option value="low" {if $smartstock_settings.front_stock_display == 'low'}selected{/if}>{l s='Only below the alert threshold ("Hurry, only 300 g left!")' mod='ps_smartstock'}</option>
          <option value="always" {if $smartstock_settings.front_stock_display == 'always'}selected{/if}>{l s='Always ("In stock: 1.5 kg")' mod='ps_smartstock'}</option>
        </select>
      </div>
      <label class="smartstock-switch">
        <input type="checkbox" name="price_comparison" value="1" {if $smartstock_settings.price_comparison}checked{/if}>
        <span>{l s='Show the price per kg / litre of every format and highlight the best value' mod='ps_smartstock'}</span>
      </label>
      <div class="smartstock-field">
        <label for="smartstock-alert-emails">{l s='Low stock alert recipients' mod='ps_smartstock'}</label>
        <input type="text" id="smartstock-alert-emails" name="alert_emails" class="form-control" value="{$smartstock_settings.alert_emails|escape:'html':'UTF-8'}" placeholder="{$smartstock_settings.shop_email|escape:'html':'UTF-8'}">
        <small class="smartstock-hint">{l s='Comma separated. The shop email address is used when empty. Thresholds are set per product.' mod='ps_smartstock'}</small>
      </div>
      <button type="submit" name="submitSmartStockSettings" value="1" class="btn btn-primary">{l s='Save settings' mod='ps_smartstock'}</button>
    </form>
  </div>
</div>
<script src="{$smartstock_module_uri|escape:'html':'UTF-8'}views/js/admin.js?v={$smartstock_version|escape:'html':'UTF-8'}"></script>
