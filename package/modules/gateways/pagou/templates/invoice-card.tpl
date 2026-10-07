<section class="pagou-payment pagou-payment--card" aria-labelledby="pagou-card-title">
    <header class="pagou-payment__header">
        <h3 id="pagou-card-title">{$payment.method|escape:'html'}</h3>
        <span class="pagou-status pagou-status--{$payment.stateTone|escape:'html'}">{$payment.stateLabel|escape:'html'}</span>
    </header>
    {if $payment.amount}<p class="pagou-payment__amount">{$payment.amount|escape:'html'}</p>{/if}
    {if $payment.installments}<p>Parcelamento: {$payment.installments|escape:'html'}</p>{/if}
    {if $payment.cardBrand || $payment.maskedNumber}<p class="pagou-card-summary">{$payment.cardBrand|escape:'html'} {$payment.maskedNumber|escape:'html'}</p>{/if}
    <p class="pagou-payment__notice">Os dados completos do cartão não são armazenados pelo módulo.</p>
    {include file="timeline.tpl" timeline=$payment.timeline}
</section>
