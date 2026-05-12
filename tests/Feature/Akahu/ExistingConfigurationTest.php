<?php

declare(strict_types=1);

namespace Tests\Feature\Akahu;

use App\Http\Controllers\Import\UploadController;
use App\Services\Akahu\AkahuService;
use App\Services\Shared\Configuration\Configuration;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ExistingConfigurationTest extends TestCase
{
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
        config()->set('akahu.internal_account_prefix', 'env-prefix');
        config()->set('akahu.mortgage_payment_pattern', 'env-pattern');

        Storage::fake('configurations');
        Storage::disk('configurations')->put('import.json', json_encode([
            'version'                         => 3,
            'flow'                            => 'akahu',
            'akahu_app_token'                 => 'config-app',
            'akahu_user_token'                => 'config-user',
            'akahu_internal_account_prefix'   => '02-1248',
            'akahu_mortgage_payment_pattern'  => '^DUE',
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(AkahuService::class);
        $service->shouldReceive('setConfiguration')
            ->once()
            ->with(Mockery::on(fn (Configuration $configuration): bool => 'env-app' === $configuration->getAkahuAppToken()
                && 'env-user' === $configuration->getAkahuUserToken()
                && 'env-prefix' === $configuration->getAkahuInternalAccountPrefix()
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
                    'internal_account_prefix'  => 'secret-prefix',
                    'mortgage_payment_pattern' => 'secret-pattern',
                ],
            ],
        ])->render();

        $this->assertStringNotContainsString('name="akahu_app_token"', $html);
        $this->assertStringNotContainsString('name="akahu_user_token"', $html);
        $this->assertStringNotContainsString('name="akahu_internal_account_prefix"', $html);
        $this->assertStringNotContainsString('name="akahu_mortgage_payment_pattern"', $html);
        $this->assertStringNotContainsString('secret-app', $html);
        $this->assertStringNotContainsString('secret-user', $html);
        $this->assertStringNotContainsString('secret-prefix', $html);
        $this->assertStringNotContainsString('secret-pattern', $html);
    }

    public function test_akahu_configure_partial_does_not_render_matching_fields(): void
    {
        $html = view('import.004-configure.partials.akahu-options', [
            'configuration' => Configuration::fromArray([
                'akahu_internal_account_prefix'  => 'secret-prefix',
                'akahu_mortgage_payment_pattern' => 'secret-pattern',
            ]),
        ])->render();

        $this->assertStringNotContainsString('name="akahu_internal_account_prefix"', $html);
        $this->assertStringNotContainsString('name="akahu_mortgage_payment_pattern"', $html);
        $this->assertStringNotContainsString('secret-prefix', $html);
        $this->assertStringNotContainsString('secret-pattern', $html);
    }
}
