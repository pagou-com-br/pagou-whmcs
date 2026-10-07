<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Payment\Card\RemoteInput;

use Pagou\Whmcs\Payment\Card\ThreeDs\ThreeDsReturnValidator;

/**
 * Stateless adapter for a WHMCS gateway entrypoint. The outer entrypoint only
 * needs to render `url` or show the returned safe error message.
 */
final class RemoteInputEntrypoint
{
    private function __construct(private readonly ?RemoteInputBridge $bridge, private readonly ?ThreeDsReturnValidator $threeDs)
    {
    }

    /** @param array<string,string|false>|null $environment */
    public static function fromEnvironment(?array $environment = null): self
    {
        try {
            $policy = RemoteInputPolicy::fromEnvironment($environment);
            $environment ??= $_ENV + $_SERVER;
            $secret = $environment['PAGOU_CARD_3DS_RETURN_SECRET'] ?? false;
            if (!is_string($secret) || strlen($secret) < 32) {
                return new self(null, null);
            }
            return new self(new RemoteInputBridge($policy), new ThreeDsReturnValidator($secret));
        } catch (\Throwable) {
            return new self(null, null);
        }
    }

    /** @return array{enabled:bool,url:?string,error:?string} */
    public function begin(RemoteInputSession $session): array
    {
        if ($this->bridge === null || $this->threeDs === null) {
            return ['enabled' => false, 'url' => null, 'error' => 'Card payment is temporarily unavailable.'];
        }
        try {
            return ['enabled' => true, 'url' => $this->bridge->inputUrl($session, $this->threeDs->sign($session->id, $session->customerReference)), 'error' => null];
        } catch (\Throwable) {
            return ['enabled' => false, 'url' => null, 'error' => 'Card payment is temporarily unavailable.'];
        }
    }

    /** @param array<string,mixed> $return */
    public function acceptsThreeDsReturn(RemoteInputSession $session, array $return): bool
    {
        if ($this->threeDs === null || $session->expired(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            return false;
        }
        $signature = $return['signature'] ?? null;
        $sessionId = $return['session_id'] ?? null;
        return is_string($signature) && is_string($sessionId) && hash_equals($session->id, $sessionId) && $this->threeDs->isValid($session->id, $session->customerReference, $signature);
    }
}
