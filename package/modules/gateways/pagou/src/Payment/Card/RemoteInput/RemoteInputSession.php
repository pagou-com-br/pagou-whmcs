<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\RemoteInput;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** A short lived server-side session. Its browser URL contains no sensitive payment data. */
final class RemoteInputSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $customerReference,
        public readonly string $returnUrl,
        public readonly DateTimeImmutable $expiresAt,
    ) {
        if ($id === '' || $customerReference === '' || !filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid remote input session.');
        }
    }

    public static function issue(string $id, string $customerReference, string $returnUrl, int $ttlSeconds = 900): self
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 3600) {
            throw new InvalidArgumentException('Remote input TTL must be between one minute and one hour.');
        }
        return new self($id, $customerReference, $returnUrl, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT' . $ttlSeconds . 'S')));
    }

    public function expired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
