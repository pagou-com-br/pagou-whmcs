<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

/** The channel that settled an already-issued payment attempt. */
enum SettlementRail: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';
    case Card = 'card';
    case PixEmbeddedInBoleto = 'pix_embedded_in_boleto';
}
