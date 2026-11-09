<?php

declare(strict_types=1);

namespace Tests\Feature\Akahu;

use App\Http\Controllers\Import\UploadController;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Akahu\AkahuService;
use App\Services\CSV\Mapper\MapperInterface;
use App\Services\CSV\Mapper\OpposingAccounts;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Import\Routine\ApiSubmitter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ExistingConfigurationTest extends TestCase
{
    public function test_category_mapping_survives_upload_configuration_mapping_and_download(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');
        Storage::fake('configurations');
        Storage::fake('jobs');
        $mapping = [0 => ['Old Cafe' => 10], 'category_names' => ['Coffee' => 'Eating out']];
        Storage::disk('configurations')->put('import.json', json_encode([
            'version' => 3, 'flow' => 'akahu', 'mapping' => $mapping,
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('validateCredentials')->once()->andReturn([]);
        app()->instance(AkahuService::class, $service);
        $response = $this->post(route('new-import.post', ['akahu']), ['existing_config' => 'import.json']);
        $response->assertSessionHasNoErrors();
        $identifier = basename((string) $response->headers->get('Location'));
        $repository = new ImportJobRepository();
        $this->assertSame($mapping, $repository->find($identifier)->getConfiguration()->getMapping());

        $this->post(route('configure-import.post', [$identifier]), [
            'date' => 'Y-m-d', 'delimiter' => 'comma', 'rules' => '1', 'webhooks' => '1',
            'duplicate_detection_method' => 'cell', 'date_range' => 'all',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $job = $repository->find($identifier);
        $this->assertSame($mapping, $job->getConfiguration()->getMapping());
        // Conversion precedes the mapping screen; provide its result without contacting Akahu.
        $job->setConvertedTransactions([['transactions' => [[
            'type' => 'withdrawal', 'source_name' => 'Cheque', 'destination_name' => 'New Cafe',
        ]]]]);
        $job->setState('is_converted');
        $repository->saveToDisk($job);
        $mapper = Mockery::mock(MapperInterface::class);
        $mapper->shouldReceive('getMap')->once()->andReturn(['expense' => [20 => 'Dining'], 'revenue' => []]);
        app()->instance(OpposingAccounts::class, $mapper);
        $this->get(route('data-mapping.index', [$identifier]))->assertOk();

        $this->post(route('data-mapping.post', [$identifier]), [
            'values' => [0 => ['New Cafe', 'Employer']], 'mapping' => [0 => ['20', '30']],
        ])->assertRedirect();
        $expected = [0 => ['Employer' => 30, 'New Cafe' => 20, 'Old Cafe' => 10], 'category_names' => ['Coffee' => 'Eating out']];
        $job = $repository->find($identifier);
        $this->assertSame($expected, $job->getConfiguration()->getMapping());
        $submitter = new ApiSubmitter();
        $submitter->setImportJob($job);
        $cleanup = new ReflectionMethod($submitter, 'cleanupLine');
        $line = $cleanup->invoke($submitter, ['transactions' => [
            ['type' => 'withdrawal', 'description' => 'Coffee', 'destination_name' => 'New Cafe', 'category_name' => 'Eating out'],
            ['type' => 'deposit', 'description' => 'Pay', 'source_name' => 'Employer'],
        ]]);
        $this->assertSame(20, $line['transactions'][0]['destination_id']);
        $this->assertSame('Eating out', $line['transactions'][0]['category_name']);
        $this->assertSame(30, $line['transactions'][1]['source_id']);
        $download = $this->get(route('configure-import.download', [$identifier]))->assertOk();
        $this->assertSame($expected, json_decode($download->getContent(), true, 512, JSON_THROW_ON_ERROR)['mapping']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_upload_controller_lists_root_level_json_configurations(): void
    {
        Storage::fake('configurations');
        Storage::disk('configurations')->put('import.json', json_encode([
            'version'          => 3,
            'flow'             => 'akahu',
            'akahu_app_token'  => 'config-app',
            'akahu_user_token' => 'config-user',
        ], JSON_THROW_ON_ERROR));

        $controller = app(UploadController::class);
        $method     = new ReflectionMethod($controller, 'getConfigurations');
        $list       = $method->invoke($controller);

        $this->assertSame(['import.json'], $list);
    }

    public function test_akahu_import_can_start_from_existing_configuration_without_form_tokens(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');
        config()->set('akahu.mortgage_payment_pattern', 'env-pattern');

        Storage::fake('configurations');
        Storage::disk('configurations')->put('import.json', json_encode([
            'version'                         => 3,
            'flow'                            => 'akahu',
            'akahu_app_token'                 => 'config-app',
            'akahu_user_token'                => 'config-user',
            'akahu_mortgage_payment_pattern'  => '^DUE',
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')
            ->once()
            ->with(Mockery::on(fn (Configuration $configuration): bool => 'env-app' === $configuration->getAkahuAppToken()
                && 'env-user' === $configuration->getAkahuUserToken()
                && 'env-pattern' === $configuration->getAkahuMortgagePaymentPattern()));
        $service->shouldReceive('validateCredentials')->once()->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $response = $this->post(route('new-import.post', ['akahu']), [
            'existing_config' => 'import.json',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString('/configure-import/', $response->headers->get('Location'));
    }

    public function test_akahu_upload_partial_does_not_render_sensitive_or_matching_fields(): void
    {
        $html = view('import.003-upload.partials.akahu', [
            'errors'   => new ViewErrorBag(),
            'settings' => [
                'akahu' => [
                    'app_token'                => 'secret-app',
                    'user_token'               => 'secret-user',
                    'mortgage_payment_pattern' => 'secret-pattern',
                ],
            ],
        ])->render();

        $this->assertStringNotContainsString('name="akahu_app_token"', $html);
        $this->assertStringNotContainsString('name="akahu_user_token"', $html);
        $this->assertStringNotContainsString('name="akahu_mortgage_payment_pattern"', $html);
        $this->assertStringNotContainsString('secret-app', $html);
        $this->assertStringNotContainsString('secret-user', $html);
        $this->assertStringNotContainsString('secret-pattern', $html);
    }

    public function test_akahu_configure_partial_does_not_render_matching_fields(): void
    {
        $html = view('import.004-configure.partials.akahu-options', [
            'configuration' => Configuration::fromArray([
                'akahu_mortgage_payment_pattern' => 'secret-pattern',
            ]),
        ])->render();

        $this->assertStringNotContainsString('name="akahu_mortgage_payment_pattern"', $html);
        $this->assertStringNotContainsString('secret-pattern', $html);
    }

    public function test_akahu_upload_partial_offers_a_force_refresh_checkbox(): void
    {
        $html = view('import.003-upload.partials.akahu', [
            'errors'   => new ViewErrorBag(),
            'settings' => ['akahu' => ['app_token' => '', 'user_token' => '', 'mortgage_payment_pattern' => '']],
        ])->render();

        $this->assertStringContainsString('name="akahu_force_refresh"', $html);
    }

    public function test_akahu_upload_stores_the_force_refresh_choice_on_the_import_job(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');

        Storage::fake('configurations');
        Storage::disk('configurations')->put('import.json', json_encode([
            'version' => 3,
            'flow'    => 'akahu',
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('validateCredentials')->once()->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $response   = $this->post(route('new-import.post', ['akahu']), [
            'existing_config'     => 'import.json',
            'akahu_force_refresh' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $identifier = basename((string) $response->headers->get('Location'));

        $this->assertTrue((new ImportJobRepository())->find($identifier)->getAkahuForceRefresh());
    }

    public function test_akahu_upload_leaves_force_refresh_off_when_the_box_is_unchecked(): void
    {
        config()->set('akahu.app_token', 'env-app');
        config()->set('akahu.user_token', 'env-user');

        Storage::fake('configurations');
        Storage::disk('configurations')->put('import.json', json_encode([
            'version' => 3,
            'flow'    => 'akahu',
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')->once();
        $service->shouldReceive('validateCredentials')->once()->andReturn([]);
        app()->instance(AkahuService::class, $service);

        $response   = $this->post(route('new-import.post', ['akahu']), [
            'existing_config' => 'import.json',
        ]);

        $response->assertSessionHasNoErrors();
        $identifier = basename((string) $response->headers->get('Location'));

        $this->assertFalse((new ImportJobRepository())->find($identifier)->getAkahuForceRefresh());
    }
}
