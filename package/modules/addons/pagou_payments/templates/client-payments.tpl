<section class="pagou-client-payments" aria-labelledby="pagou-client-payments-title">
    <h2 id="pagou-client-payments-title">Meus pagamentos Pagou</h2>
    {if $payments}
        <div class="pagou-client-payments__table-wrap">
            <table class="pagou-client-payments__table">
                <thead><tr><th>Fatura</th><th>Forma de pagamento</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Ação</th></tr></thead>
                <tbody>
                    {foreach $payments as $payment}
                        <tr>
                            <td>{$payment.invoiceNumber|escape:'html'}</td>
                            <td>{$payment.method|escape:'html'}</td>
                            <td>{$payment.amount|escape:'html'}</td>
                            <td>{$payment.dueDate|escape:'html'}</td>
                            <td>{$payment.status|escape:'html'}</td>
                            <td>{if $payment.actionUrl}<a class="pagou-action" href="{$payment.actionUrl|escape:'html'}">Ver cobrança</a>{else}<span>Indisponível</span>{/if}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <p class="pagou-empty">Nenhum pagamento Pagou encontrado.</p>
    {/if}
    <nav aria-label="Páginas de pagamentos">
        {if $paymentPage > 1}<a href="index.php?m=pagou_payments&amp;page={$paymentPage-1}">Anterior</a>{/if}
        <span>Página {$paymentPage|escape:'html'}</span>
        {if $paymentHasNext}<a href="index.php?m=pagou_payments&amp;page={$paymentPage+1}">Próxima</a>{/if}
    </nav>
</section>
