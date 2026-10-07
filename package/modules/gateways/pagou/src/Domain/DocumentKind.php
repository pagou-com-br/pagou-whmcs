<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Domain;

enum DocumentKind: string
{
    case Cpf = 'cpf';
    case Cnpj = 'cnpj';
}
