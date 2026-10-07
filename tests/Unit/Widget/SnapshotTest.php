<?php

declare(strict_types=1);

namespace Pagou\Payments\Tests\Unit\Widget;

use DateTimeImmutable;
use Pagou\Payments\Admin\Widget\Snapshot;
use Pagou\Payments\Admin\Widget\View;
use PHPUnit\Framework\TestCase;

final class SnapshotTest extends TestCase
{
    public function testInitialPageDefersRemoteReadsButLoadsLocalFindings(): void
    {
        $cache = [];
        $calls = 0;
        $balance = static function () use (&$calls): array {
            $calls++;
            return ['balance' => 15];
        };
        $service = new Snapshot();
        $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
        $first = $service->read($cache, 'account', $balance, static function (): array {
            throw new \LogicException('Must defer receipts');
        }, $now, false, false, static fn (): array => ['pending' => 2]);
        self::assertSame(0, $calls);
        self::assertTrue($first['balance']['pending']);
        self::assertTrue($first['summary']['pending']);
        self::assertArrayNotHasKey('value', $first['summary']);
        self::assertSame(2, $first['findings']['value']['pending']);
        $loaded = $service->read($cache, 'account', $balance, static fn (): array => [], $now, true);
        self::assertSame(1, $calls);
        self::assertArrayNotHasKey('pending', $loaded['balance']);
        self::assertSame($loaded['balance'], $service->read($cache, 'account', $balance, static fn (): array => [], $now->modify('+1 second'), false, false)['balance']);
        self::assertSame(1, $calls);
        $pending = $service->read($cache, 'account', $balance, static fn (): array => [], $now->modify('+3 minutes'), false, false);
        self::assertSame($loaded['balance']['value'], $pending['balance']['value']);
        self::assertSame($loaded['balance']['asOf'], $pending['balance']['asOf']);
        $failed = $service->read($cache, 'account', static function (): array {
            throw new \RuntimeException();
        }, static fn (): array => [], $now->modify('+3 minutes'), true);
        self::assertArrayNotHasKey('pending', $failed['balance']);
        self::assertTrue($failed['balance']['error']);
    }

    public function testCachesAndRefreshesWithoutExposingIdentity(): void
    {
        $cache = [];
        $calls = 0;
        $balance = static function () use (&$calls): array {
            $calls++;
            return ['balance' => 1377.35, 'hold' => 20];
        };
        $summary = static fn (): array => ['today_amount' => 100];
        $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
        $service = new Snapshot();
        $first = $service->read($cache, 'account-a', $balance, $summary, $now);
        self::assertSame(137735, $first['balance']['value']['available']);
        self::assertSame(2000, $first['balance']['value']['held']);
        self::assertArrayNotHasKey('identity', $first);
        $cached = $service->read($cache, 'account-a', $balance, $summary, $now->modify('+119 seconds'));
        self::assertSame($first['balance'], $cached['balance']);
        self::assertSame($first['summary'], $cached['summary']);
        self::assertSame(1, $calls);
        $service->read($cache, 'account-a', $balance, $summary, $now->modify('+120 seconds'));
        self::assertSame(2, $calls);
        $service->read($cache, 'account-a', $balance, $summary, $now->modify('+121 seconds'), true);
        self::assertSame(3, $calls);
    }

    public function testFailureKeepsLastBalanceButNeverLeaksItToANewIdentity(): void
    {
        $cache = [];
        $service = new Snapshot();
        $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
        $service->read($cache, 'old-credential', static fn (): array => ['balance' => 10], static fn (): array => ['pending' => 1], $now);
        $calls = 0;
        $failure = static function () use (&$calls): array {
            $calls++;
            throw new \RuntimeException('private transport detail');
        };
        $stale = $service->read($cache, 'old-credential', $failure, static fn (): array => ['pending' => 2], $now->modify('+2 minutes'));
        self::assertTrue($stale['balance']['error']);
        self::assertSame(1000, $stale['balance']['value']['available']);
        self::assertSame($now->getTimestamp(), $stale['balance']['asOf']);
        self::assertSame(2, $stale['summary']['value']['pending']);
        $service->read($cache, 'old-credential', $failure, static fn (): array => [], $now->modify('+121 seconds'));
        self::assertSame(1, $calls);
        $new = $service->read($cache, 'new-credential', $failure, static fn (): array => [], $now->modify('+122 seconds'));
        self::assertArrayNotHasKey('value', $new['balance']);
        self::assertStringNotContainsString('private', json_encode($new));
    }

    public function testNewSaoPauloDayDoesNotReuseYesterdaysTotalsWhenDatabaseFails(): void
    {
        $cache = [];
        $service = new Snapshot();
        $now = new DateTimeImmutable('2026-09-18T02:59:59Z');
        $service->read($cache, 'same', static fn (): array => ['balance' => 10], static fn (): array => ['today_amount' => 12000], $now);
        $data = $service->read($cache, 'same', static fn (): array => ['balance' => 20], static function (): array {
            throw new \RuntimeException();
        }, $now->modify('+1 second'));
        self::assertArrayNotHasKey('value', $data['summary']);
        self::assertSame(1000, $data['balance']['value']['available']);
    }

    public function testReceiptsKeepApiTimestampOnFailureAndClearAtMidnightBeforeDeferredRefresh(): void
    {
        $cache = [];
        $now = new DateTimeImmutable('2026-09-18T02:59:00Z');
        $service = new Snapshot();
        $balance = static fn (): array => ['balance' => 10];
        $findings = static fn (): array => ['pending' => 1];
        $service->read($cache, 'same', $balance, static fn (): array => ['today_amount' => 100, 'as_of' => $now->getTimestamp() - 1], $now, false, true, $findings);
        $failed = $service->read($cache, 'same', $balance, static function (): array {
            throw new \RuntimeException();
        }, $now->modify('+30 seconds'), true, true, $findings);
        self::assertSame(100, $failed['summary']['value']['today_amount']);
        self::assertSame($now->getTimestamp() - 1, $failed['summary']['asOf']);
        self::assertTrue($failed['summary']['error']);
        self::assertSame(1, $failed['findings']['value']['pending']);
        $next = $service->read($cache, 'same', $balance, static fn (): array => [], $now->modify('+60 seconds'), false, false, $findings);
        self::assertArrayNotHasKey('value', $next['summary']);
        self::assertTrue($next['summary']['pending']);
        $other = $service->read($cache, 'other', $balance, static function (): array {
            throw new \RuntimeException();
        }, $now);
        self::assertArrayNotHasKey('value', $other['summary']);
    }

    public function testOldLocalTotalsCannotBePresentedAsAccountReceipts(): void
    {
        $now = new DateTimeImmutable('2026-09-18T15:00:00Z');
        $cache = ['identity' => 'same', 'summary' => ['value' => ['today_amount' => 12300], 'day' => '2026-09-18', 'retryAt' => $now->getTimestamp() + 120]];
        $data = (new Snapshot())->read($cache, 'same', static fn (): array => [], static fn (): array => [], $now, false, false);
        self::assertArrayNotHasKey('value', $data['summary']);
        self::assertTrue($data['summary']['pending']);
    }

    public function testMalformedBalanceNeverBecomesZeroAndOutputEscapesEnvironment(): void
    {
        foreach ([[], ['balance' => null], ['balance' => true], ['balance' => -1], ['balance' => 'NaN'], ['balance' => 1.234], ['balance' => '1,00'], ['balance' => 2, 'hold' => []]] as $payload) {
            $cache = [];
            $data = (new Snapshot())->read($cache, 'one', static fn (): array => $payload, static fn (): array => [], new DateTimeImmutable());
            self::assertTrue($data['balance']['error']);
            self::assertArrayNotHasKey('value', $data['balance']);
        }
        self::assertSame(['available' => 0, 'held' => 0], Snapshot::balance(['balance' => 0]));
        $html = (new View())->render(['environment' => '<script>x</script>', 'configured' => true, 'balance' => ['error' => true]]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Indisponível', $html);
        self::assertStringNotContainsString('R$ 0,00', $html);
    }
}
