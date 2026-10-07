<section class="pagou-payment pagou-payment--pix" aria-labelledby="pagou-pix-title">
    <header class="pagou-payment__header">
        <h3 id="pagou-pix-title">{$payment.method|escape:'html'}</h3>
        <span class="pagou-status pagou-status--{$payment.stateTone|escape:'html'}">{$payment.stateLabel|escape:'html'}</span>
    </header>
    {if $payment.amount}<p class="pagou-payment__amount">{$payment.amount|escape:'html'}</p>{/if}
    {if $payment.expiresAt}<p class="pagou-payment__expiry">Vencimento: {$payment.expiresAt|escape:'html'}</p>{/if}
    {if $payment.qrCodeImageUrl}<img class="pagou-qr" src="{$payment.qrCodeImageUrl|escape:'html'}" alt="QR Code Pix" />{/if}
    {if $payment.copyPaste}
        <label class="pagou-copy-label" for="pagou-pix-copy">Pix copia e cola</label>
        <div class="pagou-copy-row">
            <input id="pagou-pix-copy" class="pagou-copy-value" type="text" readonly value="{$payment.copyPaste|escape:'html'}" aria-label="Pix copia e cola" />
            <button class="pagou-copy-button" type="button" data-pagou-copy-target="pagou-pix-copy">Copiar código Pix</button>
        </div>
        <span class="pagou-copy-feedback" aria-live="polite"></span>
    {/if}
    {include file="timeline.tpl" timeline=$payment.timeline}
</section>
