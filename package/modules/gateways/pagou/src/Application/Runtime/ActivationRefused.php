<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Application\Runtime;

use Pagou\Whmcs\Infrastructure\Http\ApiException;

/**
 * Why a gateway cannot be activated, or the card is not ready, in words the merchant can act
 * on. Only this message reaches the screen: any other failure stays generic, and the text
 * received from Pagou is never shown.
 */
final class ActivationRefused extends \RuntimeException
{
    /** The connection check with the configured credential failed. */
    public static function connection(\Throwable $exception): self
    {
        return new self(self::rejected($exception)
            ? 'A Pagou recusou a chave de API configurada. Confira a credencial nas configurações do módulo.'
            : 'Não foi possível falar com a Pagou agora. Tente de novo em alguns minutos.', 0, $exception);
    }

    /** The card check with the configured credential failed. */
    public static function card(\Throwable $exception): self
    {
        return new self(self::rejected($exception)
            ? 'A conta Pagou ainda não liberou o cartão para esta chave de API. Confira a habilitação do cartão no aplicativo Pagou.'
            : 'A Pagou não respondeu à verificação do cartão. Tente de novo em alguns minutos.', 0, $exception);
    }

    private static function rejected(\Throwable $exception): bool
    {
        return $exception instanceof ApiException && in_array($exception->statusCode, [401, 403, 404], true);
    }
}
