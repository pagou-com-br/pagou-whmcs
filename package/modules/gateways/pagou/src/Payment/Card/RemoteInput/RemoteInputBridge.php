<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\RemoteInput;

use InvalidArgumentException;

/**
 * Builds the hosted input URL. The browser talks to the approved hosted input;
 * the WHMCS module accepts only the opaque result token after the return.
 */
final class RemoteInputBridge
{
    public function __construct(private readonly RemoteInputPolicy $policy)
    {
    }

    public function inputUrl(RemoteInputSession $session, string $returnSignature): string
    {
        if ($session->expired(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            throw new InvalidArgumentException('Remote input session expired.');
        }
        return rtrim($this->policy->baseUrl, '/') . '/card-input?' . http_build_query([
            'session_id' => $session->id,
            'return_url' => $session->returnUrl,
            'signature' => $returnSignature,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string,mixed> $return */
    public function extractOpaqueToken(array $return): string
    {
        foreach (['token', 'card_token', 'card_id'] as $key) {
            if (isset($return[$key]) && is_string($return[$key]) && trim($return[$key]) !== '') {
                return $return[$key];
            }
        }
        throw new InvalidArgumentException('Remote input response did not contain an opaque card token.');
    }
}
