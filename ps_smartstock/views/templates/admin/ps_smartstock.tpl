<form method="post" action="{$current_url}">
    <input type="hidden" name="submitSmartStock" value="1" />

{if isset($confirmation) && $confirmation}
    <div class="alert alert-success">{$confirmation}</div>
{/if}


    <table class="table">
    {var_dump($combination)}
        <thead>
            <tr>
                <th>{l s='ID'}</th>
                <th>{l s='Product Name'}</th>
                <th>{l s='Stock'}</th>
                <th>{l s='Use Common Stock'}</th>
            </tr>
        </thead>
<tbody>
    {foreach from=$products item=product}
        <tr>
            <td>{$product.id_product}</td>
            <td>
                {if $product.image_link}
                    <img src="{$product.image_link}" alt="{$product.name}" style="width: 98px; height: auto;" />
                {else}
                    <span>{l s='No Image'}</span>
                {/if}
                {$product.name}
            </td>
            <td>{$product.stock}</td>
            <td>
                <input type="hidden" name="products[{$product.id_product}][id_product]" value="{$product.id_product}" />
                <input type="checkbox" name="products[{$product.id_product}][use_common_stock]" {if $product.use_common_stock}checked{/if} />
            </td>
        </tr>
        {if $product.use_common_stock == 1}
            {foreach from=$product.combinations item=combination}
                <tr>
                    <td colspan="4">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{l s='Combination ID'}</th>
                                    <th>{l s='Name'}</th>
                                    <th>{l s='Stock Deduction'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{$combination.id_product_attribute}</td>
                                    <td>
                                        {if $product.image_link}
                                            <img src="{$product.image_link}" alt="{$product.name} - {$combination.name}" style="width: 50px; height: auto;" />
                                        {/if}
                                        {$product.name} - {$combination.name}
                                    </td>
                                    <td>
                                        <input type="text" name="products[{$product.id_product}][{$combination.id_product_attribute}][stock_deduction]" value="{$combination.stock_deduction}">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            {/foreach}
        {/if}
    {/foreach}
</tbody>

    </table>

    <button type="submit" class="btn btn-primary">{l s='Save'}</button>
</form>