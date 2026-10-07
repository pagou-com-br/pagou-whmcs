<section class="pagou-payment pagou-payment--boleto" aria-labelledby="pagou-boleto-title">
    <header class="pagou-payment__header">
        <h3 id="pagou-boleto-title">{$payment.method|escape:'html'}</h3>
        <span class="pagou-status pagou-status--{$payment.stateTone|escape:'html'}">{$payment.stateLabel|escape:'html'}</span>
    </header>
    {if $payment.amount}<p class="pagou-payment__amount">{$payment.amount|escape:'html'}</p>{/if}
    {if $payment.expiresAt}<p class="pagou-payment__expiry">Vencimento: {$payment.expiresAt|escape:'html'}</p>{/if}
    {if $payment.digitableLine}
        <label class="pagou-copy-label" for="pagou-boleto-line">Linha digitável</label>
        <textarea id="pagou-boleto-line" class="pagou-copy" readonly>{$payment.digitableLine|escape:'html'}</textarea>
    {/if}
    {if $payment.pdfUrl}<p><a class="pagou-action" href="{$payment.pdfUrl|escape:'html'}">Baixar boleto em PDF</a></p>{/if}
    {if $payment.qrCodeImageUrl}<img class="pagou-qr" src="{$payment.qrCodeImageUrl|escape:'html'}" alt="QR Code Pix disponível neste boleto" />{/if}
    {include file="timeline.tpl" timeline=$payment.timeline}
</section>
