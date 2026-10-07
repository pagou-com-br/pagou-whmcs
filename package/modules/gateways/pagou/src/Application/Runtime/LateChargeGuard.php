<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Payment\LateChargeRules;

final class LateChargeGuard
{
    public function __construct(private readonly PDO $pdo, private readonly AddonSettings $settings)
    {
    }

    public function assertCanIssue(string $method, int $principalCents, int $clientId, int $invoiceId): void
    {
        if ($method === 'pix' && !$this->settings->boolean('pix_due_enabled')) {
            return;
        }
        if ($this->settings->lateChargesNeedReview($method)) {
            throw new \InvalidArgumentException('Revise os encargos antigos em Configurações do ' . ($method === 'pix' ? 'Pix' : 'boleto') . ' e confirme as unidades antes de emitir.');
        }
        $prefix = $method === 'pix' ? 'pix_due' : 'boleto';
        $fine = $this->settings->decimal($prefix . ($method === 'pix' ? '_fine_amount' : '_fine'));
        $interest = $this->settings->decimal($prefix . ($method === 'pix' ? '_interest_amount' : '_interest'));
        if ($method === 'pix') {
            $fine = $this->settings->string('pix_due_fine_type', 'none') === 'none' ? 0.0 : $fine;
            $interest = $this->settings->string('pix_due_interest_type', 'none') === 'none' ? 0.0 : $interest;
        }
        if ($fine == 0.0 && $interest == 0.0) {
            return;
        }
        $statement = $this->pdo->prepare('SELECT latefeeoveride FROM tblclients WHERE id = :id');
        $statement->execute(['id' => $clientId]);
        $override = $statement->fetchColumn();
        if ($override === false) {
            throw new \InvalidArgumentException('Não foi possível conferir a política de multa do cliente.');
        }
        $exempt = (int) $override === 1;
        if ($exempt && $this->settings->boolean($prefix . '_respect_late_fees')) {
            return;
        }
        if ($method === 'boleto') {
            LateChargeRules::boleto($fine, $principalCents);
            LateChargeRules::boleto($interest, $principalCents);
        } else {
            foreach (['fine' => $fine, 'interest' => $interest] as $kind => $amount) {
                if ($amount != 0.0) {
                    LateChargeRules::pix(['type' => $this->settings->string('pix_due_' . $kind . '_type'), 'amount' => $amount], $kind === 'interest', $principalCents);
                }
            }
        }
        if ($fine <= 0) {
            return;
        }
        $native = $this->pdo->query("SELECT value FROM tblconfiguration WHERE setting = 'InvoiceLateFeeAmount'");
        $nativeValue = $native === false ? false : $native->fetchColumn();
        if ($nativeValue === false || !is_numeric($nativeValue)) {
            throw new \InvalidArgumentException('Não foi possível conferir a configuração de multa nativa do WHMCS.');
        }
        $nativeAmount = (float) $nativeValue;
        $items = $this->pdo->prepare("SELECT COUNT(*) FROM tblinvoiceitems WHERE invoiceid = :id AND type = 'LateFee' AND amount > 0");
        $items->execute(['id' => $invoiceId]);
        if ((!$exempt && $nativeAmount > 0) || (int) $items->fetchColumn() > 0) {
            throw new \InvalidArgumentException('Há multa nativa do WHMCS aplicável ou já incluída nesta fatura. Escolha uma única origem de multa antes de emitir; nenhum valor foi removido automaticamente.');
        }
    }
}
