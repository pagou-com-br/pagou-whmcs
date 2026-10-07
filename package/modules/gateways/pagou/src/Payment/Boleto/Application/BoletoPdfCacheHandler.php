<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Boleto\Application;

use Pagou\Whmcs\Payment\Boleto\Contracts\BoletoAttemptRepository;
use Pagou\Whmcs\Payment\Boleto\Domain\BoletoAttempt;

final class BoletoPdfCacheHandler
{
    public function __construct(private readonly BoletoAttemptRepository $attempts, private readonly SecurePdfFetcher $fetcher)
    {
    }

    public function handle(string $attemptId): BoletoAttempt
    {
        $attempt = $this->attempts->get($attemptId);
        if ($attempt === null || $attempt->remoteId === null || $attempt->artifacts?->pdfUrl === null) {
            throw new \LogicException('Boleto attempt has no cacheable PDF.');
        }
        $key = $this->fetcher->fetchAndCache($attempt->remoteId, $attempt->artifacts->pdfUrl);
        $attempt = $attempt->withArtifacts($attempt->artifacts->withLocalPdf($key));
        $this->attempts->save($attempt);
        return $attempt;
    }
}
