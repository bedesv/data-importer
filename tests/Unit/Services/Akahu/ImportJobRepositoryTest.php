<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Akahu\AkahuService;
use App\Services\Akahu\Model\Account;
use App\Services\CSV\Mapper\TransactionCurrencies;
use App\Services\Shared\Configuration\Configuration;
use Mockery;
use Tests\TestCase;

class ImportJobRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_parse_import_job_initializes_akahu_accounts_and_duplicate_detection(): void
    {
        $akahu = Mockery::mock(AkahuService::class);
        $akahu->shouldReceive('setConfiguration')->once();
        $akahu->shouldReceive('fetchAccounts')->once()->andReturn([
            Account::fromArray([
                '_id'      => 'acc-1',
                'name'     => 'Cheque',
                'currency' => 'NZD',
                'status'   => 'active',
            ]),
        ]);
        app()->instance(AkahuService::class, $akahu);

        $currencies = Mockery::mock(TransactionCurrencies::class);
        $currencies->shouldReceive('getMap')->once()->andReturn([]);
        app()->instance(TransactionCurrencies::class, $currencies);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'app-token',
            'akahu_user_token' => 'user-token',
        ]);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $repository = new ImportJobRepository();
        $messages   = $repository->parseImportJob($job);
        $reloaded   = $repository->find($job->identifier);

        $this->assertCount(0, $messages);
        $this->assertSame('cell', $reloaded->getConfiguration()->getDuplicateDetectionMethod());
        $this->assertCount(1, $reloaded->getServiceAccounts());
        $this->assertTrue($reloaded->isInitialized());
    }
}
