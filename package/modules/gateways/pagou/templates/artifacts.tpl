{if $artifacts}
<section class="pagou-artifacts" aria-label="Documentos da cobrança">
    <h4>Documentos da cobrança</h4>
    <ul class="pagou-artifacts__list">
        {foreach $artifacts as $artifact}
            <li>{if $artifact.available && $artifact.url}<a href="{$artifact.url|escape:'html'}">{$artifact.name|escape:'html'}</a>{else}<span>{$artifact.name|escape:'html'} indisponível</span>{/if}</li>
        {/foreach}
    </ul>
</section>
{/if}
