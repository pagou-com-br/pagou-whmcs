<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\{AddonSettings, LateChargeGuard, WhmcsRuntime};
use Pagou\Whmcs\Infrastructure\Persistence\Async\PdoOperationOutbox;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class LateChargeGuardTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY,latefeeoveride INTEGER)');
        $this->pdo->exec('INSERT INTO tblclients VALUES (20,0)');
        $this->pdo->exec('CREATE TABLE tblconfiguration (setting TEXT,value TEXT)');
        $this->pdo->exec("INSERT INTO tblconfiguration VALUES ('InvoiceLateFeeAmount','2.00')");
        $this->pdo->exec('CREATE TABLE tblinvoiceitems (invoiceid INTEGER,type TEXT,amount REAL)');
    }

    public function testUnknownNativeConfigurationNeverAssumesThatTheFineIsDisabled(): void
    {
        $this->pdo->exec('DELETE FROM tblconfiguration');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('conferir a configuração');
        $this->guard()->assertCanIssue('boleto', 15000, 20, 10);
    }

    public function testNativeFineAndPagouFineCannotBothBeApplied(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('única origem');
        $this->guard()->assertCanIssue('boleto', 15000, 20, 10);
    }

    public function testDisablingNativeFineAllowsInstructionsWithoutChangingThem(): void
    {
        $this->pdo->exec("UPDATE tblconfiguration SET value='0'");
        $this->guard()->assertCanIssue('boleto', 15000, 20, 10);
        self::assertSame('0', $this->pdo->query('SELECT value FROM tblconfiguration')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tblinvoiceitems')->fetchColumn());
    }

    public function testExistingNativeFineStillBlocksAfterGlobalSettingIsDisabled(): void
    {
        $this->pdo->exec("UPDATE tblconfiguration SET value='0'");
        $this->pdo->exec("INSERT INTO tblinvoiceitems VALUES (10,'LateFee',2)");
        $this->expectException(\InvalidArgumentException::class);
        $this->guard()->assertCanIssue('boleto', 15000, 20, 10);
    }

    public function testClientExemptionAndInterestOnlyDoNotInventAFineConflict(): void
    {
        $this->guard(['boleto_fine' => '0', 'boleto_interest' => '1'])->assertCanIssue('boleto', 15000, 20, 10);
        $this->pdo->exec('UPDATE tblclients SET latefeeoveride=1');
        $this->guard()->assertCanIssue('boleto', 15000, 20, 10);
        $this->guard(['boleto_respect_late_fees' => '1', 'boleto_fine' => '200'])->assertCanIssue('boleto', 15000, 20, 10);
        self::assertSame('2.00', $this->pdo->query('SELECT value FROM tblconfiguration')->fetchColumn());
    }

    public function testImmediatePixDoesNotSendDueCharges(): void
    {
        $this->guard(['pix_due_enabled' => '0', 'pix_due_interest_type' => 'percentage', 'pix_due_interest_amount' => '1'])->assertCanIssue('pix', 15000, 20, 10);
        $this->addToAssertionCount(1);
    }

    public function testLegacySettingsStopNewPixBeforeAnyRemoteOrClientLookup(): void
    {
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $runtime = new WhmcsRuntime($this->pdo, new AddonSettings([
            'pix_due_enabled' => '1', 'pix_due_interest_type' => 'percentage', 'pix_due_interest_amount' => '1',
        ]), new PdoOperationOutbox($this->pdo), static function (string $command): array {
            self::assertSame('GetInvoice', $command);
            return ['result' => 'success', 'invoiceid' => 10, 'userid' => 20, 'status' => 'Unpaid',
                'paymentmethod' => 'pagou_pix', 'balance' => '150.00', 'duedate' => '2099-09-10', 'items' => ['item' => []]];
        });
        self::assertFalse($runtime->scheduleInvoice(10));
        self::assertSame('failed', $this->pdo->query('SELECT status FROM pagou_payment_attempts')->fetchColumn());
        self::assertSame('late_charges_require_review', $this->pdo->query('SELECT finding_type FROM pagou_reconciliation_findings')->fetchColumn());
    }

    /** @param array<string,string> $values */
    private function guard(array $values = []): LateChargeGuard
    {
        return new LateChargeGuard($this->pdo, new AddonSettings(array_replace([
            'boleto_fine' => '2.00', 'boleto_late_charges_reviewed' => '1',
        ], $values)));
    }
}
