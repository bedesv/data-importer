<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Models\ImportJob;
use App\Services\Akahu\Model\Account;
use App\Services\Shared\Configuration\Configuration;
use Tests\TestCase;

class ConfigurationAndSerializationTest extends TestCase
{
    public function test_configuration_round_trips_akahu_fields(): void
    {
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
            'akahu_app_token'                => 'config-app',
            'akahu_user_token'               => 'config-user',
            'akahu_internal_account_prefix'  => '12-3456',
            'akahu_mortgage_payment_pattern' => '^DUE',
            'pending_transactions'           => true,
        ]);

        $array = $configuration->toArray();

        // akahu tokens should be removed from the configuration
        $this->assertArrayNotHasKey('akahu_app_token', $array);
        $this->assertArrayNotHasKey('akahu_user_token', $array);
        $this->assertSame('12-3456', $array['akahu_internal_account_prefix']);
        $this->assertSame('^DUE', $array['akahu_mortgage_payment_pattern']);
    }

    public function test_akahu_accounts_round_trip_through_import_job_serialization(): void
    {
        $job           = ImportJob::createNew();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $job->setFlow('akahu');
        $job->setConfiguration($configuration);
        $job->setServiceAccounts([
            Account::fromArray([
                '_id'                   => 'acc-1',
                'name'                  => 'Everyday',
                'formatted_account'     => '12-3456-1234567-00',
                '_connection'           => 'conn-1',
                'connection'            => ['name' => 'Test Bank'],
                'currency'              => 'NZD',
                'status'                => 'active',
                'balance'               => ['current' => '123.45', 'available' => '120.00'],
                'refreshed'             => ['transactions' => '2026-03-14T00:00:00Z'],
            ]),
        ]);

        $restored = ImportJob::fromArray($job->toArray());
        $accounts = $restored->getServiceAccounts();

        $this->assertCount(1, $accounts);
        $this->assertInstanceOf(Account::class, $accounts[0]);
        $this->assertSame('acc-1', $accounts[0]->id);
        $this->assertSame('Test Bank', $accounts[0]->connectionName);
    }
}
