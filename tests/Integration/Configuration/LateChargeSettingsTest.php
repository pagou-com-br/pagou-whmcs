<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Configuration;

use PDO;
use Pagou\Whmcs\Configuration\CentralSettingsStore;
use Pagou\Whmcs\Infrastructure\Persistence\MigrationRunner;
use Pagou\Whmcs\Payment\LateChargeRules;
use PHPUnit\Framework\TestCase;

final class LateChargeSettingsTest extends TestCase
{
    private PDO $pdo;
    private CentralSettingsStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        (new MigrationRunner($this->pdo))->migrate(require dirname(__DIR__, 3) . '/package/modules/addons/pagou_payments/migrations.php');
        $this->store = new CentralSettingsStore($this->pdo);
    }

    public function testLegacyValuesRemainUntouchedUntilExplicitReview(): void
    {
        $this->legacy('boleto_fine', '2.50');
        self::assertTrue(LateChargeRules::needsReview($this->store->values(), 'boleto'));
        $this->store->save(['worker_max_jobs' => '30']);
        self::assertSame('2.50', $this->store->values()['boleto_fine']);
        self::assertSame('0', $this->store->values()['boleto_late_charges_reviewed']);
        try {
            $this->store->save(['boleto_fine' => '2.50']);
            self::fail('Legacy value silently acknowledged');
        } catch (\InvalidArgumentException) {
            self::assertSame('0', $this->store->values()['boleto_late_charges_reviewed']);
        }
        $values = $this->store->save(['boleto_fine' => '2.50', 'boleto_late_charges_reviewed' => '1']);
        self::assertSame('2.50', $values['boleto_fine']);
        self::assertFalse(LateChargeRules::needsReview($values, 'boleto'));
        // An absent checkbox on the next save does not clear acknowledgement.
        $values = $this->store->save(['boleto_fine' => '3.00', 'boleto_late_charges_reviewed' => '']);
        self::assertSame('1', $values['boleto_late_charges_reviewed']);
    }

    public function testLegacyPixPercentageRequiresAnExplicitPeriod(): void
    {
        $this->legacy('pix_due_interest_type', 'percentage');
        $this->legacy('pix_due_interest_amount', '1.00');
        $this->store->save(['boleto_email_details' => '0']);
        self::assertSame('percentage', $this->store->values()['pix_due_interest_type']);
        try {
            $this->store->save(['pix_due_interest_type' => 'percentage', 'pix_late_charges_reviewed' => '1']);
            self::fail('Legacy percentage silently converted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('dia ou ao mês', $error->getMessage());
        }
        $values = $this->store->save(['pix_due_interest_type' => 'percentage_month_calendar_days', 'pix_late_charges_reviewed' => '1']);
        self::assertFalse(LateChargeRules::needsReview($values, 'pix'));
        self::assertSame('1.00', $values['pix_due_interest_amount']);
    }

    public function testInvalidBoletoCannotPartiallySaveAndZeroResetNeedsNoAcknowledgement(): void
    {
        try {
            $this->store->save(['boleto_interest' => '0,01', 'worker_max_jobs' => '50']);
            self::fail('Invalid percentage saved');
        } catch (\InvalidArgumentException) {
            self::assertSame('25', $this->store->values()['worker_max_jobs']);
        }
        $this->legacy('boleto_fine', '2.50');
        $values = $this->store->resetSection('boleto');
        self::assertSame('0.00', $values['boleto_fine']);
        self::assertFalse(LateChargeRules::needsReview($values, 'boleto'));
    }

    public function testNewExplicitConfigurationAcceptsCommaAndDoesNotNeedLegacyConfirmation(): void
    {
        $values = $this->store->save(['pix_due_interest_type' => 'percentage_calendar_days', 'pix_due_interest_amount' => '0,05', 'boleto_interest' => '1,25']);
        self::assertSame('0.05', $values['pix_due_interest_amount']);
        self::assertSame('1.25', $values['boleto_interest']);
        self::assertFalse(LateChargeRules::needsReview($values, 'pix'));
        self::assertFalse(LateChargeRules::needsReview($values, 'boleto'));
    }

    private function legacy(string $key, string $value): void
    {
        $statement = $this->pdo->prepare('INSERT INTO pagou_settings (setting_key,setting_value,is_secret,updated_at) VALUES (?,?,0,?)');
        $statement->execute([$key, $value, gmdate('Y-m-d H:i:s')]);
    }
}
