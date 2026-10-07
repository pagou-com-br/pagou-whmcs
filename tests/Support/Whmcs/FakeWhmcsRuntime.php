<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Support\Whmcs;

use Pagou\Whmcs\Infrastructure\Whmcs\WhmcsPorts;

final class FakeWhmcsRuntime
{
    /** @var array<string,array<string,mixed>> */
    public array $responses = [];

    /** @var list<array{invoiceId:int,transactionId:string,amount:string,gateway:string,paymentDate:string}> */
    public array $payments = [];

    /** @var list<array{template:string,clientId:int,mergeFields:array<string,mixed>}> */
    public array $emails = [];

    /** @var array<string,true> */
    public array $knownTransactions = [];

    public function ports(): WhmcsPorts
    {
        return new WhmcsPorts(
            fn (string $command, array $parameters): array => $this->responses[$command] ?? ['result' => 'error'],
            function (int $invoiceId, string $transactionId, string $amount, string $gateway, string $paymentDate): void {
                $this->payments[] = compact('invoiceId', 'transactionId', 'amount', 'gateway', 'paymentDate');
                $this->knownTransactions[$transactionId] = true;
            },
            fn (string $transactionId): bool => isset($this->knownTransactions[$transactionId]),
            function (string $template, int $clientId, array $mergeFields): bool {
                $this->emails[] = compact('template', 'clientId', 'mergeFields');
                return true;
            },
        );
    }
}
