{**
 * SmartStock - Shared stock for product combinations.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 *}
<link rel="stylesheet" href="{$smartstock_module_uri|escape:'html':'UTF-8'}views/css/admin.css?v={$smartstock_version|escape:'html':'UTF-8'}">
<div class="panel smartstock-dashboard"
     data-smartstock-dashboard
     data-ajax-url="{$smartstock_ajax_url|escape:'html':'UTF-8'}"
     data-messages="{$smartstock_messages_json|escape:'html':'UTF-8'}">
  <div class="panel-heading smartstock-dashboard-heading">
    <span>{l s='Shared stocks' mod='ps_smartstock'} <span class="badge">{$smartstock_products|count}</span></span>
    <button type="button" class="btn btn-default js-smartstock-reconcile-all">
      <i class="icon-refresh"></i> {l s='Resynchronize all' mod='ps_smartstock'}
    </button>
  </div>
  {if $smartstock_is_multishop}
    <div class="alert alert-info">{l s='Quantities shown and edited for the stock of shop:' mod='ps_smartstock'} <strong>{$smartstock_shop_name|escape:'html':'UTF-8'}</strong></div>
  {/if}
  <span class="js-smartstock-feedback smartstock-feedback"></span>
  {if empty($smartstock_products)}
    <div class="alert alert-info">
      {l s='No product uses a shared stock yet. Open a product with combinations and enable the shared stock in its "Modules" tab.' mod='ps_smartstock'}
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
          <tr data-smartstock-row data-product-id="{$smartstock_product.id_product|intval}">
            <td>
              <a href="{$smartstock_product.edit_url|escape:'html':'UTF-8'}">{$smartstock_product.name|escape:'html':'UTF-8'}</a>
              <small class="smartstock-muted">#{$smartstock_product.id_product|intval}</small>
            </td>
            <td class="smartstock-pool">
              <span class="js-smartstock-pool-value">{$smartstock_product.pool_quantity|intval}</span>
              <span class="js-smartstock-unit-value">{$smartstock_product.unit|escape:'html':'UTF-8'}</span>
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
<script src="{$smartstock_module_uri|escape:'html':'UTF-8'}views/js/admin.js?v={$smartstock_version|escape:'html':'UTF-8'}"></script>
