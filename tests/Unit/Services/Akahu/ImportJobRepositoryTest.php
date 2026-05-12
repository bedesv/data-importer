<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Akahu\AkahuService;
use App\Services\Akahu\Model\Account;
use App\Services\CSV\Mapper\MapperInterface;
use App\Services\CSV\Mapper\TransactionCurrencies;
use App\Services\Session\Constants;
use App\Services\Shared\Configuration\Configuration;
use GrumpyDictator\FFIIIApiSupport\Model\Account as FireflyAccount;
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
        $akahu->shouldReceive('needsRefresh')->once()->andReturn(false);
        app()->instance(AkahuService::class, $akahu);

        $currencies = Mockery::mock(MapperInterface::class);
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

        // Pre-populate Firefly III accounts to prevent live network calls in parseImportJob.
        $fireflyAccount = FireflyAccount::fromArray([
            'id'                   => 1,
            'name'                 => 'Checking',
            'type'                 => 'asset',
            'iban'                 => null,
            'account_number'       => null,
            'bic'                  => null,
            'currency_code'        => 'NZD',
            'current_balance'      => null,
            'current_balance_date' => null,
        ]);
        $job->setApplicationAccounts([Constants::ASSET_ACCOUNTS => [1 => $fireflyAccount], Constants::LIABILITIES => []]);

        $repository = new ImportJobRepository();
        $messages   = $repository->parseImportJob($job);
        $reloaded   = $repository->find($job->identifier);

        $this->assertCount(0, $messages);
        $this->assertSame('cell', $reloaded->getConfiguration()->getDuplicateDetectionMethod());
        $this->assertCount(1, $reloaded->getServiceAccounts());
        $this->assertTrue($reloaded->isInitialized());
    }
}
