<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use DateTimeImmutable;
use PDO;
use Pagou\Whmcs\Application\Runtime\{InvoiceEmailReview, PaymentAttemptStore};
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class InvoiceEmailReviewTest extends TestCase
{
    private PDO $pdo;
    /** @var list<int> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT)');
        // Invoice 1 was paid; the client of invoice 2 turned invoice emails off; invoice 3 still needs its email.
        $this->pdo->exec("INSERT INTO tblinvoices VALUES (1, 21, 'Paid'), (2, 22, 'Unpaid'), (3, 23, 'Unpaid'), (4, 24, 'Cancelled')");
    }

    public function testAnUncertainEmailNobodyNeedsAnyMoreIsClosedWithItsReason(): void
    {
        $this->operation('op-paid', 1);
        $this->operation('op-off', 2);
        $this->operation('op-on', 3);
        $this->operation('op-recent', 1, '-2 minutes');
        $this->operation('op-cancelled', 4);

        self::assertSame(3, $this->review()->close($this->now()));
        self::assertSame(
            ['op-cancelled' => ['succeeded', 'settled_by_invoice_state'], 'op-off' => ['succeeded', 'invoice_emails_disabled'], 'op-on' => ['uncertain', 'invoice_email_delivery_uncertain'],
                'op-paid' => ['succeeded', 'settled_by_invoice_state'], 'op-recent' => ['uncertain', 'invoice_email_delivery_uncertain']],
            $this->operations(),
        );
        // The preference is read only while the invoice is open, once per invoice.
        self::assertSame([22, 23], $this->asked);
    }

    public function testTheReviewClosesByTheInvoiceOfItsChargeOrOfItsOnlyDelivery(): void
    {
        $attempts = new PaymentAttemptStore($this->pdo);
        foreach ([1, 2, 3] as $invoice) {
            $this->finding('finding-' . $invoice, $attempts->ensureCurrent($invoice, 20 + $invoice, 'boleto', 1000, '2026-10-20')['id']);
        }
        // An email held without a charge is recorded as "none": only one invoice tells which one it was.
        $this->delivery('none', 2);
        $this->finding('finding-none', 'none');
        $this->delivery('none-twice', 1);
        $this->delivery('none-twice', 3);
        $this->finding('finding-twice', 'none-twice');

        self::assertSame(3, $this->review()->close($this->now()));
        self::assertSame(
            ['finding-1' => 'resolved', 'finding-2' => 'resolved', 'finding-3' => 'open', 'finding-none' => 'resolved', 'finding-twice' => 'open'],
            $this->pdo->query('SELECT id, status FROM pagou_reconciliation_findings ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
        );
        self::assertSame('{"reason":"settled_by_invoice_state"}', $this->pdo->query("SELECT resolution_json FROM pagou_reconciliation_findings WHERE id = 'finding-1'")->fetchColumn());
        self::assertSame('{"reason":"invoice_emails_disabled"}', $this->pdo->query("SELECT resolution_json FROM pagou_reconciliation_findings WHERE id = 'finding-none'")->fetchColumn());
        // The delivery records that prevent a second send stay as they were.
        self::assertSame(['sending'], $this->pdo->query('SELECT DISTINCT status FROM pagou_invoice_deliveries')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, $this->review()->close($this->now()));
    }

    private function review(): InvoiceEmailReview
    {
        return new InvoiceEmailReview($this->pdo, function (int $clientId): bool {
            $this->asked[] = $clientId;

            return $clientId === 22;
        });
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function operation(string $id, int $invoice, string $age = '-1 hour'): void
    {
        $at = (new DateTimeImmutable($age, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->pdo->prepare("INSERT INTO pagou_payment_operations (id, operation_type, status, deduplication_key, priority, payload_json, available_at, created_at, updated_at, error_message) VALUES (?, 'deliver_invoice_email', 'uncertain', ?, 50, ?, ?, ?, ?, 'invoice_email_delivery_uncertain')")
            ->execute([$id, hash('sha256', $id), json_encode(['invoice_id' => $invoice, 'deadline' => true]), $at, $at, $at]);
    }

    private function finding(string $id, string $attempt): void
    {
        $this->pdo->prepare("INSERT INTO pagou_reconciliation_findings (id, finding_key, attempt_id, severity, finding_type, status, details_json, detected_at_utc, created_at, updated_at) VALUES (?, ?, ?, 'medium', 'invoice_email_delivery_uncertain', 'open', '{}', '2026-10-14', '2026-10-14', '2026-10-14')")
            ->execute([$id, hash('sha256', $id), $attempt]);
    }

    private function delivery(string $attempt, int $invoice): void
    {
        $this->pdo->prepare("INSERT INTO pagou_invoice_deliveries (delivery_key, invoice_id, attempt_id, status, attach_pdf, created_at, updated_at) VALUES (?, ?, ?, 'sending', 0, '2026-10-14', '2026-10-14')")
            ->execute([hash('sha256', $attempt . $invoice), $invoice, $attempt]);
    }

    /** @return array<string, list<string>> */
    private function operations(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT id, status, error_message FROM pagou_payment_operations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['id']] = [(string) $row['status'], (string) $row['error_message']];
        }

        return $rows;
    }
}
