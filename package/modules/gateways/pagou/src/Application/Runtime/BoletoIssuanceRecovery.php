<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use PDO;
use Pagou\Whmcs\Payment\Boleto\Api\BoletoListFilter;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoCharge;

/**
 * Decides what to do with a boleto issuance that got no answer. The Pagou charges
 * API does not honour idempotency keys, so a blind retry could issue a second
 * boleto; instead the boletos created around the request are listed and matched by
 * the invoice reference and the amount sent with it.
 */
final class BoletoIssuanceRecovery
{
    /** Pagou may take a moment to list a boleto it has just created. */
    public const WINDOW_AFTER = 'PT15M';
    private const PAGE = 500;

    /** @param \Closure(BoletoListFilter): list<BoletoCharge> $list */
    public function __construct(private readonly PDO $pdo, private readonly \Closure $list)
    {
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array{decision: 'adopt', charge: BoletoCharge}|array{decision: 'retry'|'wait'|'ambiguous'}
     */
    public function decide(array $attempt, \DateTimeImmutable $requestedAt, \DateTimeImmutable $now): array
    {
        $utc = new \DateTimeZone('UTC');
        $from = $requestedAt->setTimezone($utc)->modify('-2 minutes');
        $until = $requestedAt->setTimezone($utc)->add(new \DateInterval(self::WINDOW_AFTER));
        $to = $until < $now ? $until : $now->setTimezone($utc);
        $reference = 'whmcs-invoice-' . (string) $attempt['invoice_id'];
        $known = $this->knownCharges((int) $attempt['invoice_id']);
        $matches = [];
        for ($page = 0; $page < 10; $page++) {
            $charges = ($this->list)(new BoletoListFilter(
                createdAtFrom: $from->format('Y-m-d\TH:i:s\Z'),
                createdAtTo: $to->format('Y-m-d\TH:i:s\Z'),
                limit: self::PAGE,
                offset: $page * self::PAGE,
            ));
            foreach ($charges as $charge) {
                if (
                    ($charge->raw['customer_code'] ?? null) === $reference
                    && $charge->amountCentavos === (int) $attempt['amount_cents']
                    && !isset($known[$charge->remoteId])
                ) {
                    $matches[$charge->remoteId] = $charge;
                }
            }
            if (count($charges) < self::PAGE) {
                break;
            }
        }
        if (count($matches) === 1) {
            return ['decision' => 'adopt', 'charge' => array_values($matches)[0]];
        }
        if ($matches !== []) {
            return ['decision' => 'ambiguous'];
        }

        // Nothing is listed: issuing again is safe only once Pagou could have listed it.
        return ['decision' => $now >= $until ? 'retry' : 'wait'];
    }

    /** @return array<string, true> Boletos the module already tracks for this invoice. */
    private function knownCharges(int $invoiceId): array
    {
        $statement = $this->pdo->prepare("SELECT remote_id FROM pagou_payment_attempts WHERE invoice_id = :invoice AND remote_id IS NOT NULL AND remote_id <> ''");
        $statement->execute(['invoice' => $invoiceId]);
        $known = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $remoteId) {
            $known[(string) $remoteId] = true;
        }

        return $known;
    }
}
