<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Unit;

use Pagou\Whmcs\Application\Runtime\ActivationRefused;
use Pagou\Whmcs\Infrastructure\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class ActivationRefusedTest extends TestCase
{
    public function testAFailedCheckSaysWhatToDoWithoutEverShowingThePagouText(): void
    {
        $rejected = new ApiException('provider says: forbidden scope', 'http', 403);
        $unreachable = new ApiException('provider says: upstream timeout', 'transport', 0);

        self::assertSame('A Pagou recusou a chave de API configurada. Confira a credencial nas configurações do módulo.', ActivationRefused::connection(new ApiException('x', 'http', 401))->getMessage());
        self::assertSame('Não foi possível falar com a Pagou agora. Tente de novo em alguns minutos.', ActivationRefused::connection($unreachable)->getMessage());
        self::assertSame('A conta Pagou ainda não liberou o cartão para esta chave de API. Confira a habilitação do cartão no aplicativo Pagou.', ActivationRefused::card($rejected)->getMessage());
        self::assertSame('A Pagou não respondeu à verificação do cartão. Tente de novo em alguns minutos.', ActivationRefused::card(new \RuntimeException('boom'))->getMessage());
        foreach ([ActivationRefused::card($rejected), ActivationRefused::connection($unreachable)] as $refusal) {
            self::assertStringNotContainsString('provider says', $refusal->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $refusal);
        }
    }
}
