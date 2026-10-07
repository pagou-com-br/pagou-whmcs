<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';
    case Card = 'card';
}
