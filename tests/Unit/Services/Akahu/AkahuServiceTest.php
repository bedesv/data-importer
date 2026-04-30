<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Services\Akahu\AkahuService;
use App\Services\Shared\Configuration\Configuration;
use GuzzleHttp\ClientInterface;
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
        config()->set('akahu.internal_account_prefix', '');
        config()->set('akahu.mortgage_payment_pattern', '');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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
}
