<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\UI\Admin;

use Pagou\Payments\Admin\Controller;
use Pagou\Payments\Admin\Http\AdminRequest;
use PHPUnit\Framework\TestCase;

final class ControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['adminid'], $_SESSION['tkval']);
    }

    public function testSuccessfulCommandRefreshesProjectionAndUsesGetWithOneTimeNotice(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $calls = 0;
        $providerCalls = 0;
        $controller = new Controller(static function () use (&$calls): array {
            $calls++;
            return ['notice' => 'Atualização concluída'];
        }, static function () use (&$providerCalls): array {
            $providerCalls++;
            return [];
        }, true);
        $html = $controller->handle(new AdminRequest(['view' => 'operations', 'invoice' => '10'], ['action' => 'resume-queue', 'token' => 'safe-token'], ['REQUEST_METHOD' => 'POST']));
        // The redirected POST neither reads nor renders the page; the following GET does it once.
        self::assertSame(0, $providerCalls);
        self::assertStringContainsString('data-pagou-post-redirect', $html);
        self::assertStringContainsString('invoice=10', $html);
        preg_match('/result=([a-f0-9]{24})/', $html, $match);
        $get = new AdminRequest(['view' => 'operations', 'result' => $match[1]], [], ['REQUEST_METHOD' => 'GET']);
        self::assertStringContainsString('Atualização concluída', $controller->handle($get));
        self::assertSame(1, $providerCalls);
        self::assertStringNotContainsString('Atualização concluída', $controller->handle($get));
        self::assertSame(1, $calls);
    }

    public function testPdfInstallerRequiresAdminAndCsrfAndReceivesOnlyPreviewIdentity(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $calls = [];
        $controller = new Controller(static function (string $action, array $input) use (&$calls): array {
            $calls[] = [$action, $input];
            return [];
        }, static fn (): array => []);
        $post = ['view' => 'diagnostics', 'action' => 'install-pdf-template', 'theme' => 'custom', 'template_hash' => str_repeat('a', 64), 'arbitrary_path' => '/etc/passwd'];
        $controller->handle(new AdminRequest([], $post + ['token' => 'wrong'], ['REQUEST_METHOD' => 'POST']));
        self::assertSame([], $calls);
        $controller->handle(new AdminRequest([], $post + ['token' => 'safe-token'], ['REQUEST_METHOD' => 'POST']));
        self::assertSame([['install-pdf-template', ['theme' => 'custom', 'template_hash' => str_repeat('a', 64)]]], $calls);
        unset($_SESSION['adminid']);
        $controller->handle(new AdminRequest([], $post + ['token' => 'safe-token'], ['REQUEST_METHOD' => 'POST']));
        self::assertCount(1, $calls);
    }

    public function testGatewayActivationPassesOnlyTheRequestedGatewayAfterAdminAndCsrfChecks(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $received = [];
        $controller = new Controller(
            static function (string $action, array $input) use (&$received): array {
                $received = compact('action', 'input');

                return ['notice' => 'Gateway ativado.', 'noticeTone' => 'success'];
            },
            static fn (string $_page): array => [],
        );
        $request = new AdminRequest([], [
            'view' => 'settings',
            'action' => 'activate-gateway',
            'gateway' => 'pagou_pix',
            'token' => 'safe-token',
        ], ['REQUEST_METHOD' => 'POST']);

        $html = $controller->handle($request);

        self::assertSame('activate-gateway', $received['action']);
        self::assertSame(['gateway' => 'pagou_pix'], $received['input']);
        self::assertStringContainsString('Gateway ativado.', $html);
    }

    public function testGatewayActivationIsRejectedWithoutAnAdministrativeSession(): void
    {
        $_SESSION['tkval'] = 'safe-token';
        $called = false;
        $controller = new Controller(
            static function (string $_action, array $_input) use (&$called): array {
                $called = true;

                return [];
            },
            static fn (string $_page): array => [],
        );
        $request = new AdminRequest([], [
            'view' => 'settings',
            'action' => 'activate-gateway',
            'gateway' => 'pagou_pix',
            'token' => 'safe-token',
        ], ['REQUEST_METHOD' => 'POST']);

        $html = $controller->handle($request);

        self::assertFalse($called);
        self::assertStringContainsString('Não foi possível concluir a ação.', $html);
    }

    public function testResetPassesOnlyAValidatedSectionToTheCommand(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $received = [];
        $controller = new Controller(
            static function (string $action, array $input) use (&$received): array {
                $received = compact('action', 'input');

                return ['notice' => 'Padrões restaurados.', 'noticeTone' => 'success'];
            },
            static fn (string $_page): array => [],
        );
        $request = new AdminRequest([], [
            'view' => 'settings',
            'action' => 'reset-settings',
            'settings_section' => 'boleto',
            'credential' => 'must-not-pass',
            'pix_fee_fixed' => '999.00',
            'token' => 'safe-token',
        ], ['REQUEST_METHOD' => 'POST']);

        $html = $controller->handle($request);

        self::assertSame('reset-settings', $received['action']);
        self::assertSame(['settings_section' => 'boleto'], $received['input']);
        self::assertStringContainsString('Padrões restaurados.', $html);
    }

    public function testPixValidityTypedInDaysIsSavedInSecondsAndOutOfRangeIsExplained(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $received = [];
        $controller = new Controller(
            static function (string $action, array $input) use (&$received): array {
                $received = $input;

                return ['notice' => 'Configurações do Pix salvas.', 'noticeTone' => 'success'];
            },
            static fn (string $_page): array => [],
        );
        $post = static fn (string $amount, string $unit): AdminRequest => new AdminRequest([], [
            'view' => 'settings', 'action' => 'save-settings', 'settings_section' => 'pix', 'token' => 'safe-token',
            'pix_expiration_seconds' => '3600', 'pix_expiration_amount' => $amount, 'pix_expiration_unit' => $unit,
        ], ['REQUEST_METHOD' => 'POST']);

        $controller->handle($post('30', 'days'));
        self::assertSame('2592000', $received['pix_expiration_seconds']);
        self::assertArrayNotHasKey('pix_expiration_amount', $received);
        self::assertArrayNotHasKey('pix_expiration_unit', $received);

        $received = [];
        $html = $controller->handle($post('45', 'days'));
        self::assertSame([], $received);
        self::assertStringContainsString('A validade do Pix imediato deve ficar entre 1 minuto e 30 dias.', $html);
    }

    public function testResetRejectsAnUnknownSectionBeforeCallingTheCommand(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $called = false;
        $controller = new Controller(
            static function (string $_action, array $_input) use (&$called): array {
                $called = true;

                return [];
            },
            static fn (string $_page): array => [],
        );
        $request = new AdminRequest([], [
            'view' => 'settings',
            'action' => 'reset-settings',
            'settings_section' => 'unknown',
            'token' => 'safe-token',
        ], ['REQUEST_METHOD' => 'POST']);

        $html = $controller->handle($request);

        self::assertFalse($called);
        self::assertStringContainsString('A seção de configurações informada é inválida.', $html);
    }

    public function testTabCountsAreReadForEveryRenderAndAFailureNeverBreaksThePage(): void
    {
        $counts = 0;
        $controller = new Controller(null, static fn (): array => [], false, static function () use (&$counts): array {
            $counts++;
            return ['findings' => 2];
        });
        $html = $controller->handle(new AdminRequest(['view' => 'payments'], [], ['REQUEST_METHOD' => 'GET']));
        self::assertSame(1, $counts);
        self::assertStringContainsString('pagou-tab-count', $html);

        $failing = new Controller(null, static fn (): array => [], false, static function (): array {
            throw new \RuntimeException('Banco indisponível.');
        });
        $html = $failing->handle(new AdminRequest(['view' => 'payments'], [], ['REQUEST_METHOD' => 'GET']));
        self::assertStringNotContainsString('pagou-tab-count', $html);
        self::assertStringContainsString('Cobranças e tentativas', $html);
    }

    public function testPostWithoutRedirectReadsThePageOnceAfterTheCommand(): void
    {
        $_SESSION['adminid'] = 42;
        $_SESSION['tkval'] = 'safe-token';
        $order = [];
        $controller = new Controller(static function () use (&$order): array {
            $order[] = 'command';
            return ['notice' => 'Pronto', 'data' => ['override' => true]];
        }, static function () use (&$order): array {
            $order[] = 'provider';
            return ['payments' => []];
        });
        $html = $controller->handle(new AdminRequest(['view' => 'payments'], ['action' => 'resume-queue', 'token' => 'safe-token'], ['REQUEST_METHOD' => 'POST']));
        self::assertSame(['command', 'provider'], $order);
        self::assertStringContainsString('Pronto', $html);
    }
}
