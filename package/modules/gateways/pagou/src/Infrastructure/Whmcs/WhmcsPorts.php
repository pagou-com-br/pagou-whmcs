<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Infrastructure\Whmcs;

use Closure;

/**
 * Boundary for the WHMCS global functions used by this module.
 *
 * Entrypoints compose this object with closures around localAPI(),
 * addInvoicePayment(), checkCbTransID() and sendMessage().  Keeping these
 * functions outside the application code makes the module deterministic in
 * tests and prevents hidden access to the WHMCS runtime.
 */
final class WhmcsPorts
{
    /** @var Closure(string, array<string, mixed>): array<string, mixed> */
    private readonly Closure $localApi;

    /** @var Closure(int,string,string,string,string):void */
    private readonly Closure $addInvoicePayment;

    /** @var Closure(string):bool */
    private readonly Closure $transactionExists;

    /** @var Closure(string,int,array<string,mixed>):bool */
    private readonly Closure $sendEmail;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $localApi
     * @param callable(int,string,string,string,string):void $addInvoicePayment
     * @param callable(string):bool $transactionExists
     * @param callable(string,int,array<string,mixed>):bool $sendEmail
     */
    public function __construct(callable $localApi, callable $addInvoicePayment, callable $transactionExists, callable $sendEmail)
    {
        $this->localApi = Closure::fromCallable($localApi);
        $this->addInvoicePayment = Closure::fromCallable($addInvoicePayment);
        $this->transactionExists = Closure::fromCallable($transactionExists);
        $this->sendEmail = Closure::fromCallable($sendEmail);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function localApi(string $command, array $parameters): array
    {
        $result = ($this->localApi)($command, $parameters);
        return $result;
    }

    public function addInvoicePayment(int $invoiceId, string $transactionId, string $amount, string $gateway, string $paymentDate): void
    {
        ($this->addInvoicePayment)($invoiceId, $transactionId, $amount, $gateway, $paymentDate);
    }

    public function transactionExists(string $transactionId): bool
    {
        return (bool) ($this->transactionExists)($transactionId);
    }

    /** @param array<string,mixed> $mergeFields */
    public function sendEmail(string $template, int $clientId, array $mergeFields = []): bool
    {
        return (bool) ($this->sendEmail)($template, $clientId, $mergeFields);
    }
}
