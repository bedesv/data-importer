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
        $service->shouldReceive('ensureFreshAccounts')->once()->with(['acc-1'])->andReturn($serviceAccounts);
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
}
