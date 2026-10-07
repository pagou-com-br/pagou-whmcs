{if $timeline}
<ol class="pagou-timeline" aria-label="Histórico do pagamento">
    {foreach $timeline as $event}
        <li class="pagou-timeline__item">
            {if $event.at}<time>{$event.at|escape:'html'}</time>{/if}
            <strong>{$event.label|escape:'html'}</strong>
            {if $event.detail}<span>{$event.detail|escape:'html'}</span>{/if}
        </li>
    {/foreach}
</ol>
{/if}
