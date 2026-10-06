{**
 * SmartStock - Shared stock for product combinations.
 *
 * @author    SmartDev
 * @copyright SmartDev
 * @license   Commercial
 *}
<div class="smartstock-front">
  {if $smartstock.stock}
    <p class="smartstock-front-stock{if $smartstock.stock.is_low} smartstock-front-stock-low{/if}">
      {if $smartstock.stock.is_low}
        {l s='Hurry, only %s left in stock!' sprintf=[$smartstock.stock.quantity] mod='ps_smartstock'}
      {else}
        {l s='In stock: %s' sprintf=[$smartstock.stock.quantity] mod='ps_smartstock'}
      {/if}
    </p>
  {/if}
  {if $smartstock.comparison}
    <table class="smartstock-front-comparison">
      <caption>{l s='Compare our formats' mod='ps_smartstock'}</caption>
      <thead>
        <tr>
          <th>{l s='Format' mod='ps_smartstock'}</th>
          <th>{l s='Price' mod='ps_smartstock'}</th>
          <th>{l s='Price per %s' sprintf=[$smartstock.comparison.reference_unit] mod='ps_smartstock'}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$smartstock.comparison.rows item=smartstock_row}
          <tr class="{if $smartstock_row.is_selected}smartstock-front-selected{/if}{if !$smartstock_row.is_available} smartstock-front-unavailable{/if}">
            <td>{$smartstock_row.name|escape:'html':'UTF-8'}</td>
            <td>{$smartstock_row.price_formatted|escape:'html':'UTF-8'}</td>
            <td>{$smartstock_row.reference_price_formatted|escape:'html':'UTF-8'}</td>
            <td>
              {if !$smartstock_row.is_available}
                <span class="smartstock-front-badge smartstock-front-badge-out">{l s='Out of stock' mod='ps_smartstock'}</span>
              {elseif $smartstock_row.is_best_value}
                <span class="smartstock-front-badge smartstock-front-badge-best">{l s='Best value' mod='ps_smartstock'} -{$smartstock_row.saving_percent|intval}%</span>
              {elseif $smartstock_row.saving_percent > 0}
                <span class="smartstock-front-badge">-{$smartstock_row.saving_percent|intval}%</span>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {/if}
</div>
