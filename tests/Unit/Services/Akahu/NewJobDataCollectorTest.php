<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Models\ImportJob;
use App\Services\Akahu\AkahuService;
use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Validation\NewJobDataCollector;
use App\Services\Shared\Configuration\Configuration;
use Mockery;
use Tests\TestCase;

class NewJobDataCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_form_input_takes_precedence_over_config_and_env_defaults(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')
            ->once()
            ->with(Mockery::on(function (Configuration $configuration): bool {
                return 'form-app' === $configuration->getAkahuAppToken()
                    && 'form-user' === $configuration->getAkahuUserToken()
                    && '12-3456' === $configuration->getAkahuInternalAccountPrefix()
                    && '^DUE' === $configuration->getAkahuMortgagePaymentPattern();
            }));
        $service->shouldReceive('validateCredentials')->once()->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
            'akahu_app_token'                => 'config-app',
            'akahu_user_token'               => 'config-user',
            'akahu_internal_account_prefix'  => 'config-prefix',
            'akahu_mortgage_payment_pattern' => 'config-pattern',
        ]);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $collector        = new NewJobDataCollector();
        $collector->setImportJob($job);
        $collector->input = [
            'akahu_app_token'                => 'form-app',
            'akahu_user_token'               => 'form-user',
            'akahu_internal_account_prefix'  => '12-3456',
            'akahu_mortgage_payment_pattern' => '^DUE',
        ];

        $errors = $collector->validate();

        $this->assertCount(0, $errors);
        $this->assertSame('form-app', $collector->getImportJob()->getConfiguration()->getAkahuAppToken());
        $this->assertSame('form-user', $collector->getImportJob()->getConfiguration()->getAkahuUserToken());
    }

    public function test_collect_accounts_uses_environment_defaults_when_config_is_empty(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')
            ->once()
            ->with(Mockery::on(fn (Configuration $configuration): bool => 'env-app' === $configuration->getAkahuAppToken() && 'env-user' === $configuration->getAkahuUserToken()));
        $service->shouldReceive('fetchAccounts')
            ->once()
            ->andReturn([
                Account::fromArray([
                    '_id'       => 'acc-1',
                    'name'      => 'Cheque',
                    'currency'  => 'NZD',
                    'status'    => 'active',
                ]),
            ]);
        app()->instance(AkahuService::class, $service);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $collector = new NewJobDataCollector();
        $collector->setImportJob($job);

        $errors = $collector->collectAccounts();

        $this->assertCount(0, $errors);
        $this->assertCount(1, $collector->getImportJob()->getServiceAccounts());
    }
}
