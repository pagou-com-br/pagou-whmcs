<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Integration\Whmcs;

use DateTimeImmutable;
use Pagou\Whmcs\Domain\Money;
use Pagou\Whmcs\Infrastructure\Whmcs\CsrfGuard;
use Pagou\Whmcs\Infrastructure\Whmcs\PrivateStorage;
use Pagou\Whmcs\Infrastructure\Whmcs\RedactingLogger;
use Pagou\Whmcs\Infrastructure\Whmcs\WhmcsLookup;
use Pagou\Whmcs\Infrastructure\Whmcs\WhmcsPaymentRecorder;
use Pagou\Whmcs\Tests\Support\Whmcs\FakeWhmcsRuntime;
use PHPUnit\Framework\TestCase;

final class WhmcsAdaptersTest extends TestCase
{
    public function testLookupAndPaymentUseCallableWhmcsBoundary(): void
    {
        $runtime = new FakeWhmcsRuntime();
        $runtime->responses['GetInvoice'] = [
            'result' => 'success', 'invoiceid' => '10', 'userid' => '20', 'status' => 'Unpaid',
            'balance' => '12.34', 'duedate' => '2026-08-23', 'paymentmethod' => 'pagou_boleto',
        ];
        $lookup = new WhmcsLookup($runtime->ports());

        self::assertSame(10, $lookup->invoice(10)->id);
        $recorder = new WhmcsPaymentRecorder($runtime->ports());
        self::assertTrue($recorder->record(10, 'remote-transaction-1', Money::fromDecimal('12.34'), 'pagou_boleto', new DateTimeImmutable('2026-08-22T12:00:00Z')));
        self::assertFalse($recorder->record(10, 'remote-transaction-1', Money::fromDecimal('12.34'), 'pagou_boleto', new DateTimeImmutable('2026-08-22T12:00:00Z')));
        self::assertCount(1, $runtime->payments);
        self::assertSame('12.34', $runtime->payments[0]['amount']);
    }

    public function testConfiguredCustomFieldIsTheOnlySourceForCpfOrCnpj(): void
    {
        $runtime = new FakeWhmcsRuntime();
        $runtime->responses['GetClientsDetails'] = [
            'result' => 'success',
            'customfields' => ['customfield' => [
                ['id' => '1', 'value' => 'invalid'],
                ['id' => '9', 'value' => '935.411.347-80'],
            ]],
        ];

        $document = (new WhmcsLookup($runtime->ports()))->clientDocument(4, [9]);
        self::assertNotNull($document);
        self::assertSame('93541134780', $document->digits());
        self::assertNull((new WhmcsLookup($runtime->ports()))->clientDocument(4, [1]));
    }

    public function testCsrfTokensAreBoundToTheSessionAndLogsRedactSensitiveValues(): void
    {
        $session = [];
        $csrf = new CsrfGuard(
            function (string $key) use (&$session): mixed {
                return $session[$key] ?? null;
            },
            function (string $key, mixed $value) use (&$session): void {
                $session[$key] = $value;
            },
        );
        $token = $csrf->token();
        $csrf->assertValid($token);

        $entries = [];
        $logger = new RedactingLogger(function (string $message, array $context) use (&$entries): void {
            $entries[] = compact('message', 'context');
        });
        $logger->info('payment', ['api_key' => 'secret', 'note' => 'card 4111 1111 1111 1111']);
        self::assertSame('[REDACTED]', $entries[0]['context']['api_key']);
        self::assertSame('card [REDACTED-CARD]', $entries[0]['context']['note']);
    }

    public function testPrivateStorageRejectsWebrootAndTraversal(): void
    {
        $base = sys_get_temp_dir() . '/pagou-whmcs-' . bin2hex(random_bytes(4));
        try {
            $storage = new PrivateStorage($base, sys_get_temp_dir() . '/web-root');
            $storage->put('boletos/10.pdf', 'pdf');
            self::assertSame('pdf', $storage->read('boletos/10.pdf'));
            $this->expectException(\InvalidArgumentException::class);
            $storage->path('../public.pdf');
        } finally {
            $file = $base . '/boletos/10.pdf';
            if (is_file($file)) {
                unlink($file);
            }
            if (is_dir($base . '/boletos')) {
                rmdir($base . '/boletos');
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }
}
