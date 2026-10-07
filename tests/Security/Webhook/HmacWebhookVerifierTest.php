<?php

declare(strict_types=1);

namespace Pagou\Whmcs\Tests\Security\Webhook;

use Pagou\Whmcs\Application\Webhook\HmacWebhookVerifier;
use Pagou\Whmcs\Application\Webhook\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

final class HmacWebhookVerifierTest extends TestCase
{
    private const SECRET = 'webhook-secret-for-tests';

    public function testItAcceptsAnHmacOfTimestampAndUnchangedBody(): void
    {
        $timestamp = '1720000000';
        $body = '{"event":"pix.paid","data":{"id":"pix_1"}}';
        $signature = hash_hmac('sha256', $timestamp . $body, self::SECRET);

        (new HmacWebhookVerifier(self::SECRET))->assertValid(
            $body,
            $timestamp,
            'sha256=' . $signature,
            new \DateTimeImmutable('@1720000000'),
        );

        self::assertTrue(true);
    }

    public function testItRejectsAReplayOutsideTheConfiguredWindow(): void
    {
        $timestamp = '1720000000';
        $body = '{}';
        $signature = hash_hmac('sha256', $timestamp . $body, self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        (new HmacWebhookVerifier(self::SECRET))->assertValid(
            $body,
            $timestamp,
            $signature,
            new \DateTimeImmutable('@1720000301'),
        );
    }

    public function testItRejectsAnyBodyChangeAfterSigning(): void
    {
        $timestamp = '1720000000';
        $signature = hash_hmac('sha256', $timestamp . '{"amount":1}', self::SECRET);

        $this->expectException(WebhookVerificationException::class);
        (new HmacWebhookVerifier(self::SECRET))->assertValid(
            '{"amount":2}',
            $timestamp,
            $signature,
            new \DateTimeImmutable('@1720000000'),
        );
    }
}
