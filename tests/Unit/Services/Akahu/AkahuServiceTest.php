<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Exceptions\ImporterErrorException;
use App\Services\Akahu\AkahuService;
use App\Services\Shared\Configuration\Configuration;
use Carbon\CarbonImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class AkahuServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('akahu.app_token', '');
        config()->set('akahu.user_token', '');
        config()->set('akahu.mortgage_payment_pattern', '');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function applyRefreshConfig(int $cooldownMinutes = 15, int $waitTimeoutSeconds = 30): void
    {
        config()->set('akahu.connection_timeout', 30);
        config()->set('akahu.always_refresh', true);
        config()->set('akahu.stale_refresh_hours', 2);
        config()->set('akahu.refresh_cooldown_minutes', $cooldownMinutes);
        config()->set('akahu.refresh_poll_seconds', 10);
        config()->set('akahu.refresh_wait_timeout_seconds', $waitTimeoutSeconds);
    }

    /**
     * @param array<int> $sleeps recorded instead of actually sleeping
     */
    private function makeService(ClientInterface $client, array &$sleeps): AkahuService
    {
        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));
        $service->setSleepHandler(static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        return $service;
    }

    private function accountsResponse(string $refreshedTransactions): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'items' => [[
                '_id'       => 'acc-123',
                'name'      => 'Cheque',
                'refreshed' => ['transactions' => $refreshedTransactions],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_refresh_accounts_uses_documented_refresh_endpoint(): void
    {
        config()->set('akahu.connection_timeout', 30);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $path, array $options): bool {
                $this->assertSame('POST', $method);
                $this->assertSame('refresh', $path);
                $this->assertSame('Bearer user-token', $options['headers']['Authorization']);
                $this->assertSame('app-token', $options['headers']['X-Akahu-ID']);

                return true;
            })
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));

        $service->refreshAccounts();
    }

    public function test_fetch_transactions_uses_account_scoped_endpoint(): void
    {
        config()->set('akahu.connection_timeout', 30);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $path, array $options): bool {
                $this->assertSame('GET', $method);
                $this->assertSame('accounts/acc-123/transactions', $path);
                $this->assertArrayHasKey('query', $options);

                return true;
            })
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [
                    [
                        '_id'         => 'tx-1',
                        '_account'    => 'acc-123',
                        'date'        => '2026-03-14T00:00:00Z',
                        'description' => 'Test',
                        'amount'      => 12.34,
                        'type'        => 'EFTPOS',
                    ],
                ],
                'cursor' => ['next' => null],
            ], JSON_THROW_ON_ERROR)));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));

        $transactions = $service->fetchTransactions('acc-123');

        $this->assertCount(1, $transactions);
        $this->assertSame('tx-1', $transactions[0]->getIdentifier());
    }

    /**
     * Akahu's transaction window is exclusive at `start` and inclusive at `end`, so the
     * query has to sit one millisecond outside the local day boundaries. Banks that report
     * date-only transactions (TSB) get stamped at exactly local midnight, so an on-the-nose
     * `start` silently drops every one of them on the first day of the range.
     */
    public function test_date_query_brackets_the_local_day_boundaries(): void
    {
        config()->set('akahu.connection_timeout', 30);
        config()->set('app.timezone', 'Pacific/Auckland');

        $captured = [];
        $client   = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $path, array $options) use (&$captured): bool {
                $captured = $options['query'];

                return true;
            })
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items'  => [],
                'cursor' => ['next' => null],
            ], JSON_THROW_ON_ERROR)));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
            'date_not_before'  => '2026-08-28',
            'date_not_after'   => '2026-08-31',
        ]));

        $service->fetchTransactions('acc-123');

        // 2026-08-28 00:00 NZST is 2026-08-27T12:00:00Z; step back so it is included.
        $this->assertSame('2026-08-27T11:59:59.999Z', $captured['start']);
        // 2026-09-01 00:00 NZST is 2026-08-31T12:00:00Z; step back so the next day is not.
        $this->assertSame('2026-08-31T11:59:59.999Z', $captured['end']);
    }

    public function test_fetch_pending_transactions_uses_account_scoped_endpoint(): void
    {
        config()->set('akahu.connection_timeout', 30);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $path, array $options): bool {
                $this->assertSame('GET', $method);
                $this->assertSame('accounts/acc-123/transactions/pending', $path);
                $this->assertArrayHasKey('query', $options);

                return true;
            })
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [
                    [
                        '_account'    => 'acc-123',
                        '_user'       => 'user-1',
                        '_connection' => 'conn-1',
                        'date'        => '2026-03-14T00:00:00Z',
                        'description' => 'Pending test',
                        'amount'      => -12.34,
                        'type'        => 'EFTPOS',
                        'updated_at'  => '2026-03-14T01:00:00Z',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));

        $transactions = $service->fetchPendingTransactions('acc-123');

        $this->assertCount(1, $transactions);
        $this->assertTrue($transactions[0]->isPending());
    }

    public function test_ensure_fresh_accounts_checks_once_before_first_poll_sleep(): void
    {
        config()->set('akahu.connection_timeout', 30);
        config()->set('akahu.always_refresh', true);
        config()->set('akahu.stale_refresh_hours', 2);
        config()->set('akahu.refresh_cooldown_minutes', 15);
        config()->set('akahu.refresh_poll_seconds', 10);
        config()->set('akahu.refresh_wait_timeout_seconds', 30);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [[
                    '_id'       => 'acc-123',
                    'name'      => 'Cheque',
                    'refreshed' => ['transactions' => '2000-01-01T00:00:00Z'],
                ]],
            ], JSON_THROW_ON_ERROR)));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [[
                    '_id'       => 'acc-123',
                    'name'      => 'Cheque',
                    'refreshed' => ['transactions' => now()->addMinutes(5)->toIso8601String()],
                ]],
            ], JSON_THROW_ON_ERROR)));

        $sleeps  = [];
        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));
        $service->setSleepHandler(static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $sleeps);
    }

    public function test_ensure_fresh_accounts_triggers_refresh_even_when_not_stale(): void
    {
        config()->set('akahu.connection_timeout', 30);
        config()->set('akahu.always_refresh', true);
        config()->set('akahu.stale_refresh_hours', 2);
        config()->set('akahu.refresh_cooldown_minutes', 15);
        config()->set('akahu.refresh_poll_seconds', 10);
        config()->set('akahu.refresh_wait_timeout_seconds', 30);

        // Outside the refresh cooldown but well inside the staleness window.
        $recentBefore = now()->subMinutes(30)->toIso8601String();

        $client = Mockery::mock(ClientInterface::class);
        // First fetch: account is well within the staleness window, so the
        // old behaviour would have returned immediately without refreshing.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [[
                    '_id'       => 'acc-123',
                    'name'      => 'Cheque',
                    'refreshed' => ['transactions' => $recentBefore],
                ]],
            ], JSON_THROW_ON_ERROR)));
        // A refresh must still be triggered.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        // And we must wait until the refreshed timestamp advances past the trigger.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [[
                    '_id'       => 'acc-123',
                    'name'      => 'Cheque',
                    'refreshed' => ['transactions' => now()->addMinutes(5)->toIso8601String()],
                ]],
            ], JSON_THROW_ON_ERROR)));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));
        $service->setSleepHandler(static function (): void {});

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
    }

    public function test_ensure_fresh_accounts_skips_refresh_when_disabled_and_not_stale(): void
    {
        config()->set('akahu.connection_timeout', 30);
        config()->set('akahu.always_refresh', false);
        config()->set('akahu.stale_refresh_hours', 2);
        config()->set('akahu.refresh_cooldown_minutes', 15);
        config()->set('akahu.refresh_poll_seconds', 10);
        config()->set('akahu.refresh_wait_timeout_seconds', 30);

        $client = Mockery::mock(ClientInterface::class);
        // Only a single accounts fetch is expected: recent data + refresh disabled
        // means no POST /refresh and no polling.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [[
                    '_id'       => 'acc-123',
                    'name'      => 'Cheque',
                    'refreshed' => ['transactions' => now()->subMinute()->toIso8601String()],
                ]],
            ], JSON_THROW_ON_ERROR)));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));
        $service->setSleepHandler(static function (): void {});

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
    }

    public function test_ensure_fresh_accounts_skips_refresh_inside_the_cooldown_window(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        // Akahu would decline a refresh this soon after the last one, so the importer
        // must not ask for one: a single accounts fetch and no polling.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->subMinute()->toIso8601String()));

        $sleeps   = [];
        $service  = $this->makeService($client, $sleeps);

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $sleeps);
        $this->assertSame([], $service->getRefreshWarnings());
    }

    public function test_ensure_fresh_accounts_waits_for_the_refresh_triggered_during_account_collection(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        // The single POST belongs to the account-collection step below. Conversion must
        // wait for it to land rather than firing a second one Akahu would decline.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->subHours(3)->toIso8601String()));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->addMinutes(5)->toIso8601String()));

        $sleeps  = [];
        $service = $this->makeService($client, $sleeps);

        // Stands in for the early refresh fired by NewJobDataCollector::collectAccounts().
        $service->refreshAccounts();
        $this->assertTrue($service->refreshTriggeredRecently());

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $service->getRefreshWarnings());
    }

    public function test_ensure_fresh_accounts_continues_with_existing_data_when_the_refresh_never_lands(): void
    {
        // A zero cooldown forces the refresh, a zero wait means the very first check is
        // also the last one.
        $this->applyRefreshConfig(cooldownMinutes: 0, waitTimeoutSeconds: 0);

        $refreshedAt = now()->subMinutes(5)->toIso8601String();
        $client      = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->twice()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse($refreshedAt));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $sleeps   = [];
        $service  = $this->makeService($client, $sleeps);

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
        $this->assertCount(1, $service->getRefreshWarnings());
        $this->assertStringContainsString('did not finish the refresh in time', $service->getRefreshWarnings()[0]);
    }

    public function test_ensure_fresh_accounts_throws_when_the_refresh_never_lands_and_data_is_stale(): void
    {
        $this->applyRefreshConfig(cooldownMinutes: 0, waitTimeoutSeconds: 0);

        $refreshedAt = now()->subHours(5)->toIso8601String();
        $client      = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->twice()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse($refreshedAt));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $sleeps  = [];
        $service = $this->makeService($client, $sleeps);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('too old to import');

        $service->ensureFreshAccounts(['acc-123']);
    }

    public function test_ensure_fresh_accounts_continues_when_akahu_rate_limits_the_refresh(): void
    {
        $this->applyRefreshConfig(cooldownMinutes: 0);

        $refreshedAt = now()->subMinutes(5)->toIso8601String();
        $client      = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse($refreshedAt));
        // Initial attempt plus both retries are refused.
        $client
            ->shouldReceive('request')
            ->times(3)
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andThrow(new RequestException(
                'Too Many Requests',
                new Request('POST', 'refresh'),
                new Response(429, ['Retry-After' => '1'], 'rate limited')
            ));

        $sleeps   = [];
        $service  = $this->makeService($client, $sleeps);

        $accounts = $service->ensureFreshAccounts(['acc-123']);

        $this->assertCount(1, $accounts);
        $this->assertSame([1, 1], $sleeps);
        $this->assertCount(1, $service->getRefreshWarnings());
        $this->assertStringContainsString('declined the refresh request', $service->getRefreshWarnings()[0]);
    }

    public function test_ensure_fresh_accounts_never_sleeps_past_its_deadline(): void
    {
        // Retry-After asks for a minute; the two second budget must win.
        $this->applyRefreshConfig(cooldownMinutes: 0, waitTimeoutSeconds: 2);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->subMinutes(5)->toIso8601String()));
        $client
            ->shouldReceive('request')
            ->times(3)
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andThrow(new RequestException(
                'Too Many Requests',
                new Request('POST', 'refresh'),
                new Response(429, ['Retry-After' => '60'], 'rate limited')
            ));

        $sleeps  = [];
        $service = $this->makeService($client, $sleeps);

        $service->ensureFreshAccounts(['acc-123']);

        $this->assertNotEmpty($sleeps);
        foreach ($sleeps as $seconds) {
            $this->assertLessThanOrEqual(2, $seconds);
        }
    }

    public function test_fetch_accounts_retries_rate_limited_request_after_retry_after_delay(): void
    {
        config()->set('akahu.connection_timeout', 30);

        $request = new Request('GET', 'accounts');
        $client  = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->andThrow(new RequestException('Too Many Requests', $request, new Response(429, ['Retry-After' => '2'], 'rate limited')));
        $client
            ->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'items' => [[
                    '_id'  => 'acc-123',
                    'name' => 'Cheque',
                ]],
            ], JSON_THROW_ON_ERROR)));

        $sleeps  = [];
        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));
        $service->setSleepHandler(static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $accounts = $service->fetchAccounts();

        $this->assertCount(1, $accounts);
        $this->assertSame('acc-123', $accounts[0]->getIdentifier());
        $this->assertSame([2], $sleeps);
    }

    public function test_fetch_accounts_throws_sanitized_message_for_http_failures(): void
    {
        config()->set('akahu.connection_timeout', 30);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->andThrow(new RequestException(
                'Server error: `GET https://api.akahu.io/v1/accounts?token=secret` resulted in a `500` response',
                new Request('GET', 'accounts'),
                new Response(500, ['Content-Type' => 'application/json'], '{"error":"boom"}')
            ));

        $service = new AkahuService($client);
        $service->setConfiguration(Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]));

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('Akahu API request failed with HTTP 500.');

        $service->fetchAccounts();
    }

    public function test_ensure_fresh_accounts_forced_refresh_ignores_the_cooldown_window(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        // Data from inside the cooldown window normally counts as fresh. A forced refresh
        // must ask Akahu anyway and then wait for that answer to land.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->subMinute()->toIso8601String()));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->addMinutes(5)->toIso8601String()));

        $sleeps   = [];
        $service  = $this->makeService($client, $sleeps);

        $accounts = $service->ensureFreshAccounts(['acc-123'], true);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $service->getRefreshWarnings());
    }

    public function test_ensure_fresh_accounts_forced_refresh_waits_for_the_refresh_triggered_during_account_collection(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        // The single POST belongs to the account-collection step below. Forcing must not
        // fire a second one Akahu would decline; it waits for the first to land instead.
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->subMinute()->toIso8601String()));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->addMinutes(5)->toIso8601String()));

        $sleeps  = [];
        $service = $this->makeService($client, $sleeps);

        // Stands in for the forced refresh fired by NewJobDataCollector::collectAccounts().
        $triggeredAt = CarbonImmutable::now();
        $service->refreshAccounts();

        $accounts    = $service->ensureFreshAccounts(['acc-123'], true, $triggeredAt);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $service->getRefreshWarnings());
    }
    public function test_forced_refresh_ignores_an_earlier_trigger_when_this_run_never_fired_one(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        // Two POSTs: the earlier run's, then this run's. Without the second one the wait
        // below would settle against a trigger this run never made and quietly hand back
        // the data Akahu already held.
        $client
            ->shouldReceive('request')
            ->twice()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->addMinute()->toIso8601String()));
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->addMinutes(10)->toIso8601String()));

        $sleeps  = [];
        $service = $this->makeService($client, $sleeps);

        // An earlier import's refresh, still inside the cooldown window.
        $service->refreshAccounts();

        // This run's forced trigger failed, so it has no timestamp to wait on.
        $accounts = $service->ensureFreshAccounts(['acc-123'], true, null);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $service->getRefreshWarnings());
    }

    public function test_forced_refresh_warns_when_its_own_retry_is_declined(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->subMinute()->toIso8601String()));
        // Akahu turns the retry down (initial attempt plus both retries). The user asked
        // for fresh data and is not getting it, so the import must say so rather than
        // settle silently.
        $client
            ->shouldReceive('request')
            ->times(3)
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andThrow(new RequestException(
                'Too Many Requests',
                new Request('POST', 'refresh'),
                new Response(429, ['Retry-After' => '1'], 'rate limited')
            ));

        $sleeps   = [];
        $service  = $this->makeService($client, $sleeps);

        $accounts = $service->ensureFreshAccounts(['acc-123'], true, null);

        $this->assertCount(1, $accounts);
        $this->assertCount(1, $service->getRefreshWarnings());
        $this->assertStringContainsString('declined the refresh request', $service->getRefreshWarnings()[0]);
    }
    public function test_early_refresh_trigger_never_sleeps_past_its_own_budget(): void
    {
        $this->applyRefreshConfig();

        $client = Mockery::mock(ClientInterface::class);
        // Retry-After asks for a minute each time. This trigger runs inside the request
        // that renders the configuration page, so it must not spend that budget: the
        // conversion step retries and warns for us.
        $client
            ->shouldReceive('request')
            ->times(3)
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andThrow(new RequestException(
                'Too Many Requests',
                new Request('POST', 'refresh'),
                new Response(429, ['Retry-After' => '60'], 'rate limited')
            ));

        $sleeps  = [];
        $service = $this->makeService($client, $sleeps);

        $this->expectException(ImporterErrorException::class);

        try {
            $service->triggerRefreshWithin(2);
        } finally {
            $this->assertNotEmpty($sleeps);
            foreach ($sleeps as $seconds) {
                $this->assertLessThanOrEqual(2, $seconds);
            }
        }
    }

    public function test_early_refresh_trigger_clears_its_budget_for_later_calls(): void
    {
        $this->applyRefreshConfig(waitTimeoutSeconds: 30);

        $client = Mockery::mock(ClientInterface::class);
        $client
            ->shouldReceive('request')
            ->once()
            ->withArgs(fn (string $method, string $path): bool => 'POST' === $method && 'refresh' === $path)
            ->andReturn(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        // One fetch to collect, one to confirm the trigger landed.
        $client
            ->shouldReceive('request')
            ->twice()
            ->withArgs(fn (string $method, string $path): bool => 'GET' === $method && 'accounts' === $path)
            ->andReturn($this->accountsResponse(now()->addMinutes(5)->toIso8601String()));

        $sleeps      = [];
        $service     = $this->makeService($client, $sleeps);

        $triggeredAt = CarbonImmutable::now();
        $service->triggerRefreshWithin(2);

        // The short budget belongs to the trigger alone; conversion gets its own.
        $accounts    = $service->ensureFreshAccounts(['acc-123'], true, $triggeredAt);

        $this->assertCount(1, $accounts);
        $this->assertSame([], $sleeps);
    }
}
