<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Runtime;

use PDO;
use Pagou\Whmcs\Application\Runtime\GatewayActivationService;
use PHPUnit\Framework\TestCase;

final class GatewayActivationServiceTest extends TestCase
{
    public function testActivatesPixThroughLocalApiWithSafeDefaultsAndKeepsItHidden(): void
    {
        $pdo = $this->pdo();
        $calls = [];
        $service = new GatewayActivationService(
            $pdo,
            function (string $command, array $parameters) use ($pdo, &$calls): array {
                $calls[] = compact('command', 'parameters');
                $this->insertGateway($pdo, (string) $parameters['moduleName'], false);

                return ['result' => 'success'];
            },
        );

        $result = $service->activate('pagou_pix');

        self::assertSame('activated_hidden', $result['outcome']);
        self::assertSame('ActivateModule', $calls[0]['command']);
        self::assertSame('gateway', $calls[0]['parameters']['moduleType']);
        self::assertSame('pagou_pix', $calls[0]['parameters']['moduleName']);
        self::assertSame(['visible' => ''], $calls[0]['parameters']['parameters']);
        self::assertSame('', $pdo->query(
            "SELECT value FROM tblpaymentgateways WHERE gateway = 'pagou_pix' AND setting = 'visible'"
        )->fetchColumn());
    }

    public function testExistingHiddenGatewayIsIdempotentAndNeverChangesItsConfiguration(): void
    {
        $pdo = $this->pdo();
        $this->insertGateway($pdo, 'pagou_boleto', false);
        $calls = 0;
        $service = new GatewayActivationService(
            $pdo,
            static function (string $_command, array $_parameters) use (&$calls): array {
                $calls++;

                return ['result' => 'success'];
            },
        );

        $result = $service->activate('pagou_boleto');

        self::assertSame('already_active', $result['outcome']);
        self::assertSame(0, $calls);
        self::assertSame('', $pdo->query(
            "SELECT value FROM tblpaymentgateways WHERE gateway = 'pagou_boleto' AND setting = 'visible'"
        )->fetchColumn());
    }

    public function testExistingVisibleGatewayIsRecoveredAndKeptActive(): void
    {
        $pdo = $this->pdo();
        $this->insertGateway($pdo, 'pagou_pix', true);
        $calls = 0;
        $service = new GatewayActivationService(
            $pdo,
            static function (string $_command, array $_parameters) use (&$calls): array {
                $calls++;

                return ['result' => 'success'];
            },
        );

        $result = $service->activate('pagou_pix');

        self::assertSame('visibility_corrected', $result['outcome']);
        self::assertSame(0, $calls);
        self::assertSame('', $pdo->query(
            "SELECT value FROM tblpaymentgateways WHERE gateway = 'pagou_pix' AND setting = 'visible'"
        )->fetchColumn());
    }

    public function testUnexpectedVisibilityAfterActivationIsCorrectedWithoutDeactivatingTheGateway(): void
    {
        $pdo = $this->pdo();
        $calls = [];
        $service = new GatewayActivationService(
            $pdo,
            function (string $command, array $parameters) use ($pdo, &$calls): array {
                $calls[] = $command;
                $gateway = (string) $parameters['moduleName'];
                $this->insertGateway($pdo, $gateway, true);

                return ['result' => 'success'];
            },
        );

        $result = $service->activate('pagou_pix');

        self::assertSame('activated_hidden', $result['outcome']);
        self::assertSame(['ActivateModule'], $calls);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM tblpaymentgateways')->fetchColumn());
        self::assertSame('', $pdo->query(
            "SELECT value FROM tblpaymentgateways WHERE gateway = 'pagou_pix' AND setting = 'visible'"
        )->fetchColumn());
    }

    public function testCardCanBeActivatedHiddenAfterReadinessHasBeenConfirmed(): void
    {
        $pdo = $this->pdo();
        $service = new GatewayActivationService(
            $pdo,
            function (string $_command, array $parameters) use ($pdo): array {
                $this->insertGateway($pdo, (string) $parameters['moduleName'], false);

                return ['result' => 'success'];
            },
        );

        self::assertSame('activated_hidden', $service->activate('pagou_creditcard')['outcome']);
        self::assertSame('', $pdo->query(
            "SELECT value FROM tblpaymentgateways WHERE gateway = 'pagou_creditcard' AND setting = 'visible'"
        )->fetchColumn());
    }

    public function testUnknownGatewayCannotUseAssistedActivation(): void
    {
        $service = new GatewayActivationService(
            $this->pdo(),
            static fn (string $_command, array $_parameters): array => ['result' => 'success'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->activate('unknown_gateway');
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)');

        return $pdo;
    }

    private function insertGateway(PDO $pdo, string $gateway, bool $visible): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES (:gateway, :setting, :value)'
        );
        $statement->execute(['gateway' => $gateway, 'setting' => 'name', 'value' => $gateway]);
        $statement->execute(['gateway' => $gateway, 'setting' => 'visible', 'value' => $visible ? 'on' : '']);
    }
}
