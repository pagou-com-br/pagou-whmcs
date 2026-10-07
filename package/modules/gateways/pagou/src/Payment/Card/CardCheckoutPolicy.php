<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card;

/** Advanced options remain unavailable until their separate homologation. */
final class CardCheckoutPolicy
{
    public static function assertSupported(int $installments, bool $capture): void
    {
        if (!$capture || $installments !== 1) {
            throw new \InvalidArgumentException(
                'Esta versão aceita cartão à vista com captura automática. Revise as configurações do cartão antes de continuar.'
            );
        }
    }
}
