<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Execute composer install antes de rodar os testes.');
}

require_once $autoload;
require_once dirname(__DIR__) . '/package/modules/gateways/pagou/bootstrap.php';

date_default_timezone_set('UTC');

if (!function_exists('encrypt')) {
    function encrypt(string $value): string
    {
        return 'test-cipher:' . base64_encode(strrev($value));
    }
}

if (!function_exists('decrypt')) {
    function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'test-cipher:')) {
            return '';
        }

        $decoded = base64_decode(substr($value, 12), true);

        return is_string($decoded) ? strrev($decoded) : '';
    }
}
