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
            'akahu_mortgage_payment_pattern' => '^DUE',
            'pending_transactions'           => true,
        ]);

        $array = $configuration->toArray();

        $this->assertSame('config-app', $array['akahu_app_token']);
        $this->assertSame('config-user', $array['akahu_user_token']);
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

    public function test_akahu_account_serialization_preserves_raw_payload_for_counterpart_matching(): void
    {
        $original = Account::fromArray([
            '_id'               => 'acc-1',
            'name'              => 'Everyday',
            'formatted_account' => '12-3456-1234567-00',
            'account_number'    => '12-3456-1234567-00',
            'meta'              => ['account_number' => '12-3456-1234567-00'],
            'currency'          => 'NZD',
        ]);

        $restored = Account::fromArray($original->toArray());

        $this->assertSame('12-3456-1234567-00', (string) ($restored->raw['account_number'] ?? ''));
        $this->assertSame('12-3456-1234567-00', (string) ($restored->raw['meta']['account_number'] ?? ''));
    }
    public function test_akahu_force_refresh_round_trips_through_import_job_serialization(): void
    {
        $job = ImportJob::createNew();
        $job->setFlow('akahu');
        $job->setConfiguration(Configuration::fromArray(['flow' => 'akahu']));
        $job->setAkahuForceRefresh(true);

        $job->setAkahuForcedRefreshAt('2026-09-12T10:00:00+12:00');

        $restored = ImportJob::fromArray($job->toArray());

        $this->assertTrue($restored->getAkahuForceRefresh());
        $this->assertSame('2026-09-12T10:00:00+12:00', $restored->getAkahuForcedRefreshAt());
    }

    public function test_akahu_force_refresh_stays_out_of_the_downloadable_configuration(): void
    {
        $job = ImportJob::createNew();
        $job->setFlow('akahu');
        $job->setConfiguration(Configuration::fromArray(['flow' => 'akahu']));
        $job->setAkahuForceRefresh(true);

        // The flag is a per-run choice. Writing it into the configuration would make the
        // exported config file force a refresh on every future import.
        $this->assertArrayNotHasKey('akahu_force_refresh', $job->getConfiguration()->toArray());
    }

    public function test_import_job_without_a_stored_force_refresh_flag_defaults_to_false(): void
    {
        $job   = ImportJob::createNew();
        $job->setFlow('akahu');
        $job->setConfiguration(Configuration::fromArray(['flow' => 'akahu']));

        // Jobs written to disk before this flag existed must still restore.
        $array = $job->toArray();
        unset($array['akahu_force_refresh']);

        $this->assertFalse(ImportJob::fromArray($array)->getAkahuForceRefresh());
    }
}
