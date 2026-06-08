<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Http\Controllers\Import\MapController;
use App\Models\ImportJob;
use App\Services\CSV\Mapper\MapperInterface;
use App\Services\CSV\Mapper\OpposingAccounts;
use App\Services\Shared\Configuration\Configuration;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class MapControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_akahu_flow_can_build_data_mapping_information_from_converted_transactions(): void
    {
        $mapper = Mockery::mock(MapperInterface::class);
        $mapper->shouldReceive('getMap')
            ->once()
            ->andReturn([
                'expense' => [
                    10 => 'Groceries',
                ],
                'revenue' => [
                    20 => 'Salary',
                ],
            ]);
        app()->instance(OpposingAccounts::class, $mapper);

        $importJob = ImportJob::createNew();
        $importJob->setFlow('akahu');
        $importJob->setConfiguration(Configuration::fromArray([
            'flow' => 'akahu',
            'mapping' => [
                0 => [
                    'Countdown' => 10,
                ],
            ],
        ]));
        $importJob->setConvertedTransactions([
            [
                'transactions' => [
                    [
                        'type'             => 'withdrawal',
                        'source_name'      => 'Main Account',
                        'destination_name' => 'Countdown',
                    ],
                ],
            ],
            [
                'transactions' => [
                    [
                        'type'             => 'deposit',
                        'source_name'      => 'Employer Ltd',
                        'destination_name' => 'Main Account',
                    ],
                ],
            ],
        ]);

        $controller = app(MapController::class);
        $method     = new ReflectionMethod($controller, 'getImporterMapInformation');
        $data       = $method->invoke($controller, $importJob);

        $this->assertCount(1, $data);
        $this->assertSame('opposing-name', $data[0]['role']);
        $this->assertSame(['Countdown', 'Main Account', 'Employer Ltd'], array_values($data[0]['values']));
        $this->assertSame(10, $data[0]['mapped']['Countdown']);
        $this->assertSame('Groceries', $data[0]['mapping_data']['expense'][10]);
    }
}
