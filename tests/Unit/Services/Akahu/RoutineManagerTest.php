<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Services\Akahu\AkahuService;
use App\Services\Akahu\Conversion\RoutineManager;
use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Model\PendingTransaction;
use App\Services\Akahu\Model\Transaction;
use App\Services\Shared\Configuration\Configuration;
use Carbon\CarbonImmutable;
use GrumpyDictator\FFIIIApiSupport\Model\Account as FireflyAccount;
use Mockery;
use Tests\TestCase;

class RoutineManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_routine_manager_refreshes_accounts_and_includes_pending_transactions(): void
    {
        $serviceAccounts = [
            Account::fromArray([
                '_id'       => 'acc-1',
                'name'      => 'Cheque',
                'currency'  => 'NZD',
                'status'    => 'active',
            ]),
        ];
        $service         = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('ensureFreshAccounts')->once()->with(['acc-1'], false, null)->andReturn($serviceAccounts);
        $service->shouldReceive('getRefreshWarnings')->andReturn([]);
        $service->shouldReceive('fetchTransactions')->once()->with('acc-1')->andReturn([
            Transaction::fromArray([
                '_id'         => 'tx-1',
                '_account'    => 'acc-1',
                'date'        => '2026-03-10T09:00:00Z',
                'description' => 'Salary',
                'amount'      => 100,
                'type'        => 'TRANSFER',
            ]),
        ]);
        $service->shouldReceive('fetchPendingTransactions')->once()->with('acc-1')->andReturn([
            PendingTransaction::fromArray([
                '_account'    => 'acc-1',
                '_user'       => 'user-1',
                '_connection' => 'conn-1',
                'date'        => '2026-03-10T10:00:00Z',
                'description' => 'Lunch',
                'amount'      => -12,
                'type'        => 'EFTPOS',
                'updated_at'  => '2026-03-10T10:00:00Z',
            ]),
        ]);
        app()->instance(AkahuService::class, $service);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray([
            'flow'                 => 'akahu',
            'accounts'             => ['acc-1' => 10],
            'pending_transactions' => true,
        ]);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);
        $job->setServiceAccounts($serviceAccounts);

        $manager      = new RoutineManager($job);
        $transactions = $manager->start();

        $this->assertCount(2, $transactions);
        $this->assertSame('tx-1', $transactions[0]['transactions'][0]['external_id']);
        $this->assertContains('pending', $transactions[1]['transactions'][0]['tags']);
    }

    public function test_routine_manager_fails_clearly_when_refresh_times_out(): void
    {
        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('ensureFreshAccounts')->once()->andThrow(new ImporterErrorException('Akahu account refresh did not complete within the configured timeout.'));
        $service->shouldReceive('getRefreshWarnings')->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray([
            'flow'     => 'akahu',
            'accounts' => ['acc-1' => 10],
        ]);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);
        $job->setServiceAccounts([
            Account::fromArray([
                '_id'      => 'acc-1',
                'name'     => 'Cheque',
                'currency' => 'NZD',
                'status'   => 'active',
            ]),
        ]);

        $manager = new RoutineManager($job);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('Akahu account refresh did not complete within the configured timeout.');

        $manager->start();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_routine_manager_creates_all_new_accounts_before_transforming_internal_transfers(): void
    {
        $serviceAccounts = [
            Account::fromArray([
                '_id'               => 'acc-1',
                'name'              => 'Cheque',
                'formatted_account' => '12-3456-1111111-00',
                'currency'          => 'NZD',
                'status'            => 'active',
            ]),
            Account::fromArray([
                '_id'               => 'acc-2',
                'name'              => 'Savings',
                'formatted_account' => '12-3456-9999999-00',
                'currency'          => 'NZD',
                'status'            => 'active',
            ]),
        ];
        $service         = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('ensureFreshAccounts')->once()->with(['acc-1', 'acc-2'], false, null)->andReturn($serviceAccounts);
        $service->shouldReceive('getRefreshWarnings')->andReturn([]);
        $service->shouldReceive('fetchTransactions')->once()->with('acc-1')->andReturn([
            Transaction::fromArray([
                '_id'         => 'tx-transfer',
                '_account'    => 'acc-1',
                'date'        => '2026-03-10T09:00:00Z',
                'description' => 'TRANSFER TO 12-3456-9999999-00',
                'amount'      => -50.12,
                'type'        => 'TRANSFER',
                'meta'        => ['other_account' => '12-3456-9999999-00'],
            ]),
        ]);
        $service->shouldReceive('fetchTransactions')->once()->with('acc-2')->andReturn([
            Transaction::fromArray([
                '_id'         => 'tx-transfer-credit',
                '_account'    => 'acc-2',
                'date'        => '2026-03-10T09:00:00Z',
                'description' => 'TRANSFER FROM 12-3456-1111111-00',
                'amount'      => 50.12,
                'type'        => 'TRANSFER',
                'meta'        => ['other_account' => '12-3456-1111111-00'],
            ]),
        ]);
        $service->shouldReceive('fetchPendingTransactions')->never();
        app()->instance(AkahuService::class, $service);

        $mapper = Mockery::mock('overload:App\Services\Shared\Conversion\AccountMapper');
        $mapper->shouldReceive('createFireflyIIIAccount')->andReturnUsing(static function (mixed $importServiceAccount, array $config): FireflyAccount {
            $isCheque = 'Cheque' === (string) $config['name'];

            return FireflyAccount::fromArray([
                'id'                   => $isCheque ? 21 : 22,
                'name'                 => $isCheque ? 'Cheque' : 'Savings',
                'type'                 => 'asset',
                'iban'                 => null,
                'account_number'       => null,
                'bic'                  => null,
                'currency_code'        => 'NZD',
                'current_balance'      => null,
                'current_balance_date' => null,
            ]);
        });
        $mapper->shouldReceive('findMatchingFireflyIIIAccount')->never();

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray([
            'flow'                 => 'akahu',
            'accounts'             => ['acc-1' => 0, 'acc-2' => 0],
            'new_accounts'         => [
                'acc-1' => ['name' => 'Cheque', 'type' => 'asset', 'currency' => 'NZD', 'opening_balance' => ''],
                'acc-2' => ['name' => 'Savings', 'type' => 'asset', 'currency' => 'NZD', 'opening_balance' => ''],
            ],
            'pending_transactions' => false,
        ]);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);
        $job->setServiceAccounts($serviceAccounts);

        $manager      = new RoutineManager($job);
        $transactions = $manager->start();

        $this->assertSame(['acc-1' => 21, 'acc-2' => 22], $manager->getImportJob()->getConfiguration()->getAccounts());
        $this->assertSame('transfer', $transactions[0]['transactions'][0]['type']);
        $this->assertSame(21, $transactions[0]['transactions'][0]['source_id']);
        $this->assertSame(22, $transactions[0]['transactions'][0]['destination_id']);
    }
    public function test_routine_manager_passes_the_forced_refresh_flag_to_the_service(): void
    {
        $serviceAccounts = [
            Account::fromArray([
                '_id'      => 'acc-1',
                'name'     => 'Cheque',
                'currency' => 'NZD',
                'status'   => 'active',
            ]),
        ];
        $service         = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        // Stored as an ISO 8601 string on the job, so sub-second precision is not kept.
        $triggeredAt     = CarbonImmutable::now()->subMinutes(2)->startOfSecond();
        $service->shouldReceive('ensureFreshAccounts')
            ->once()
            ->with(['acc-1'], true, Mockery::on(fn (?CarbonImmutable $at): bool => $at instanceof CarbonImmutable && $at->toIso8601String() === $triggeredAt->toIso8601String()))
            ->andReturn($serviceAccounts);
        $service->shouldReceive('getRefreshWarnings')->andReturn([]);
        $service->shouldReceive('fetchTransactions')->once()->with('acc-1')->andReturn([]);
        $service->shouldReceive('fetchPendingTransactions')->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $job = ImportJob::createNew();
        $job->setFlow('akahu');
        $job->setConfiguration(Configuration::fromArray([
            'flow'     => 'akahu',
            'accounts' => ['acc-1' => 11],
        ]));
        $job->setServiceAccounts($serviceAccounts);
        $job->setAkahuForceRefresh(true);
        $job->setAkahuForcedRefreshAt($triggeredAt->toIso8601String());

        $manager = new RoutineManager($job);
        $manager->start();
    }
}
