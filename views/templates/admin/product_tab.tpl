{**
 * SmartStock - Shared stock for product combinations.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 *}
<link rel="stylesheet" href="{$smartstock_module_uri|escape:'html':'UTF-8'}views/css/admin.css?v={$smartstock_version|escape:'html':'UTF-8'}">
<div class="smartstock-panel panel"
     data-smartstock-product
     data-ajax-url="{$smartstock_ajax_url|escape:'html':'UTF-8'}"
     data-state="{$smartstock_state_json|escape:'html':'UTF-8'}"
     data-configuration="{$smartstock_configuration_json|escape:'html':'UTF-8'}">
  <h3 class="smartstock-title">
    <i class="material-icons">inventory_2</i>
    {l s='Shared stock between combinations' mod='ps_smartstock'}
    <a class="smartstock-dashboard-link" href="{$smartstock_dashboard_url|escape:'html':'UTF-8'}">{l s='All shared stocks' mod='ps_smartstock'}</a>
  </h3>
  {if !$smartstock_has_combinations}
    <div class="alert alert-info">{l s='This product has no combinations yet. Create its formats (50 g, 100 g...) first, then come back here.' mod='ps_smartstock'}</div>
  {else}
    <p class="smartstock-help">
      {l s='All combinations draw from one stock expressed in base units. Example: 1500 g of tea in stock gives 30 bags of 50 g, 15 bags of 100 g or 3 bags of 500 g. Every sale, return or cancellation updates the shared stock, then the quantity of every format.' mod='ps_smartstock'}
    </p>
    <label class="smartstock-switch">
      <input type="checkbox" class="js-smartstock-active">
      <span>{l s='Use a shared stock for this product' mod='ps_smartstock'}</span>
    </label>
    <div class="js-smartstock-settings smartstock-settings">
      <div class="smartstock-fields">
        <div class="smartstock-field">
          <label>{l s='Base unit' mod='ps_smartstock'}</label>
          <input type="text" class="form-control js-smartstock-unit" maxlength="16" placeholder="{l s='g, ml, piece...' mod='ps_smartstock'}">
        </div>
        <div class="smartstock-field">
          <label>{l s='Shared stock' mod='ps_smartstock'} (<span class="js-smartstock-unit-label"></span>)</label>
          <input type="number" step="1" class="form-control js-smartstock-pool">
          <small class="js-smartstock-pool-hint smartstock-hint">{l s='Initial value computed from the current quantities of the combinations: check it before saving.' mod='ps_smartstock'}</small>
        </div>
        <div class="smartstock-field js-smartstock-adjustment-block">
          <label>{l s='Stock movement' mod='ps_smartstock'} (<span class="js-smartstock-unit-label"></span>)</label>
          <div class="input-group">
            <input type="number" step="1" class="form-control js-smartstock-adjustment" placeholder="{l s='+5000 or -250' mod='ps_smartstock'}">
            <span class="input-group-btn input-group-append">
              <button type="button" class="btn btn-default btn-outline-secondary js-smartstock-apply-adjustment">{l s='Apply' mod='ps_smartstock'}</button>
            </span>
          </div>
          <small class="smartstock-hint">{l s='Goods receipt or loss, applied immediately and recorded in the history.' mod='ps_smartstock'}</small>
        </div>
        <div class="smartstock-field">
          <label>{l s='Alert threshold' mod='ps_smartstock'} (<span class="js-smartstock-unit-label"></span>)</label>
          <input type="number" min="0" step="1" class="form-control js-smartstock-threshold" placeholder="0">
          <small class="smartstock-hint">{l s='Email alert and "only X left" message below this quantity. 0 disables it.' mod='ps_smartstock'}</small>
        </div>
      </div>
      <table class="table smartstock-table">
        <thead>
          <tr>
            <th>{l s='Combination' mod='ps_smartstock'}</th>
            <th>{l s='Units consumed per sale' mod='ps_smartstock'} (<span class="js-smartstock-unit-label"></span>)</th>
            <th>{l s='Sellable quantity' mod='ps_smartstock'}</th>
          </tr>
        </thead>
        <tbody class="js-smartstock-rows"></tbody>
      </table>
      <p class="smartstock-hint">{l s='Set 0 to keep a combination on its own stock (gift box, sample...).' mod='ps_smartstock'}</p>
      <button type="button" class="btn btn-link js-smartstock-suggest">{l s='Fill from combination names' mod='ps_smartstock'}</button>
      <div class="js-smartstock-history-block smartstock-history-block">
        <h4>{l s='Latest movements' mod='ps_smartstock'}</h4>
        <table class="table smartstock-table smartstock-history">
          <thead>
            <tr>
              <th>{l s='Date' mod='ps_smartstock'}</th>
              <th>{l s='Reason' mod='ps_smartstock'}</th>
              <th>{l s='Movement' mod='ps_smartstock'}</th>
              <th>{l s='Shared stock after' mod='ps_smartstock'}</th>
              <th>{l s='Details' mod='ps_smartstock'}</th>
            </tr>
          </thead>
          <tbody class="js-smartstock-history-rows"></tbody>
        </table>
      </div>
    </div>
    <div class="smartstock-actions">
      <span class="js-smartstock-feedback smartstock-feedback"></span>
      <button type="button" class="btn btn-primary js-smartstock-save">{l s='Save shared stock' mod='ps_smartstock'}</button>
    </div>
    <template class="js-smartstock-history-template">
      <tr>
        <td class="smartstock-nowrap js-smartstock-history-date"></td>
        <td class="js-smartstock-history-reason"></td>
        <td class="smartstock-nowrap js-smartstock-history-delta"></td>
        <td class="smartstock-nowrap js-smartstock-history-after"></td>
        <td class="js-smartstock-history-details"></td>
      </tr>
    </template>
    <template class="js-smartstock-row-template">
      <tr>
        <td class="js-smartstock-row-name"></td>
        <td><input type="number" min="0" step="1" class="form-control smartstock-ratio js-smartstock-ratio"></td>
        <td class="js-smartstock-row-preview smartstock-preview"></td>
      </tr>
    </template>
  {/if}
</div>
<script src="{$smartstock_module_uri|escape:'html':'UTF-8'}views/js/admin.js?v={$smartstock_version|escape:'html':'UTF-8'}"></script>
