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

    public function test_environment_defaults_take_precedence_over_config_and_form_input(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')
            ->once()
            ->with(Mockery::on(function (Configuration $configuration): bool {
                return 'env-app' === $configuration->getAkahuAppToken()
                    && 'env-user' === $configuration->getAkahuUserToken();
            }));
        $service->shouldReceive('validateCredentials')->once()->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray([
            'flow'             => 'akahu',
            'akahu_app_token'  => 'config-app',
            'akahu_user_token' => 'config-user',
        ]);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $collector        = new NewJobDataCollector();
        $collector->setImportJob($job);
        $collector->input = [
            'akahu_app_token'  => 'form-app',
            'akahu_user_token' => 'form-user',
        ];

        $errors = $collector->validate();

        $this->assertCount(0, $errors);
        $this->assertSame('env-app', $collector->getImportJob()->getConfiguration()->getAkahuAppToken());
        $this->assertSame('env-user', $collector->getImportJob()->getConfiguration()->getAkahuUserToken());
    }

    public function test_validate_returns_errors_when_both_tokens_missing(): void
    {
        config()->set('akahu.app_token', '');
        config()->set('akahu.user_token', '');

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $collector        = new NewJobDataCollector();
        $collector->setImportJob($job);
        $collector->input = [];

        $errors = $collector->validate();

        $this->assertCount(2, $errors);
        $this->assertTrue($errors->has('akahu_app_token'));
        $this->assertTrue($errors->has('akahu_user_token'));
    }

    public function test_validate_returns_error_when_only_app_token_missing(): void
    {
        config()->set('akahu.app_token', '');
        config()->set('akahu.user_token', 'env-user');

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $collector        = new NewJobDataCollector();
        $collector->setImportJob($job);
        $collector->input = [];

        $errors = $collector->validate();

        $this->assertCount(1, $errors);
        $this->assertTrue($errors->has('akahu_app_token'));
        $this->assertFalse($errors->has('akahu_user_token'));
    }

    public function test_validate_returns_connection_error_when_credentials_throw(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('validateCredentials')->once()->andThrow(new \RuntimeException('401 Unauthorized'));
        app()->instance(AkahuService::class, $service);

        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);

        $collector        = new NewJobDataCollector();
        $collector->setImportJob($job);
        $collector->input = [];

        $errors = $collector->validate();

        $this->assertCount(1, $errors);
        $this->assertTrue($errors->has('connection'));
        $this->assertStringContainsString('401 Unauthorized', $errors->first('connection'));
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
        $service->shouldReceive('needsRefresh')->once()->andReturn(false);
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
