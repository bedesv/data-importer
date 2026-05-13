<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Akahu;

use App\Exceptions\ImporterErrorException;
use App\Services\Akahu\Conversion\TransactionTransformer;
use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Model\PendingTransaction;
use App\Services\Akahu\Model\Transaction;
use App\Services\Shared\Configuration\Configuration;
use Tests\TestCase;

class TransactionTransformerTest extends TestCase
{
    public function test_regular_internal_transfer_keeps_credit_side_only(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                          => 'akahu',
            'akahu_internal_account_prefix' => '12-3456',
        ]);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $opposing      = Account::fromArray(['_id' => 'acc-2', 'name' => 'Savings', 'formatted_account' => '12-3456-9999999-00', 'currency' => 'NZD', 'status' => 'active']);
        $debit         = Transaction::fromArray([
            '_id'         => 'tx-debit',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'TRANSFER TO 12-3456-9999999-00',
            'amount'      => -50.12,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '12-3456-9999999-00'],
            'category'    => ['name' => 'Transfers'],
        ]);
        $credit        = Transaction::fromArray([
            '_id'         => 'tx-credit',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'TRANSFER FROM 12-3456-9999999-00',
            'amount'      => 50.12,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '12-3456-9999999-00'],
            'category'    => ['name' => 'Transfers'],
        ]);

        $debitResult  = $transformer->transform($debit, $account, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);
        $creditResult = $transformer->transform($credit, $account, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);

        $this->assertSame([], $debitResult);
        $this->assertSame('transfer', $creditResult['type']);
        $this->assertNull($creditResult['category_name']);
        $this->assertSame(11, $creditResult['source_id']);
        $this->assertSame('Savings', $creditResult['source_name']);
        $this->assertSame(10, $creditResult['destination_id']);
    }

    public function test_internal_transfer_keeps_debit_side_when_opposing_account_is_not_imported(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                          => 'akahu',
            'akahu_internal_account_prefix' => '12-3456',
        ]);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $transaction   = Transaction::fromArray([
            '_id'         => 'tx-debit-only',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'TRANSFER TO 12-3456-9999999-00',
            'amount'      => -50.12,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '12-3456-9999999-00'],
        ]);

        $result = $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10], [], [$account]);

        $this->assertSame('transfer', $result['type']);
        $this->assertSame('50.120000000000', $result['amount']);
        $this->assertSame(10, $result['source_id']);
        $this->assertSame('Cheque', $result['source_name']);
        $this->assertNull($result['destination_id']);
        $this->assertSame('12-3456-9999999-00', $result['destination_name']);
    }

    public function test_non_transfer_with_internal_looking_account_number_is_not_classified_as_internal_transfer(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                          => 'akahu',
            'akahu_internal_account_prefix' => '12-3456',
        ]);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $opposing      = Account::fromArray(['_id' => 'acc-2', 'name' => 'Savings', 'formatted_account' => '12-3456-9999999-00', 'currency' => 'NZD', 'status' => 'active']);
        $transaction   = Transaction::fromArray([
            '_id'         => 'external-payment',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Payment to external payee',
            'amount'      => -50.12,
            'type'        => 'AUTOMATIC_PAYMENT',
            'meta'        => ['other_account' => '12-3456-9999999-00'],
        ]);

        $result = $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);

        $this->assertSame('withdrawal', $result['type']);
        $this->assertSame(10, $result['source_id']);
        $this->assertSame(11, $result['destination_id']);
        $this->assertSame('Savings', $result['destination_name']);
    }

    public function test_incoming_internal_transfer_is_imported_as_transfer_with_internal_account_as_source_name(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                          => 'akahu',
            'akahu_internal_account_prefix' => '12-3456',
        ]);
        $account       = Account::fromArray([
            '_id'      => 'acc-1',
            'name'     => 'Main Account',
            'currency' => 'NZD',
            'status'   => 'active',
        ]);
        $opposing      = Account::fromArray([
            '_id'               => 'acc-2',
            'name'              => 'Joint Account',
            'formatted_account' => '12-3456-9999999-00',
            'currency'          => 'NZD',
            'status'            => 'active',
        ]);
        $transaction   = Transaction::fromArray([
            '_id'         => 'tx-internal-credit',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'From Joint Account (007)',
            'amount'      => 3000,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '12-3456-9999999-00'],
        ]);

        $result = $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);

        $this->assertSame('transfer', $result['type']);
        $this->assertSame(11, $result['source_id']);
        $this->assertSame('Joint Account', $result['source_name']);
        $this->assertSame(10, $result['destination_id']);
        $this->assertSame('Main Account', $result['destination_name']);
        $this->assertNull($result['category_name']);
    }

    public function test_mortgage_transfer_keeps_debit_side_only(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
            'akahu_internal_account_prefix'  => '12-3456',
            'akahu_mortgage_payment_pattern' => '^DUE \\d{4} (TO|FR) \\d{7}-\\d{2}$',
        ]);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $opposing      = Account::fromArray(['_id' => 'acc-2', 'name' => 'Mortgage', 'formatted_account' => '12-3456-1234567-00', 'currency' => 'NZD', 'status' => 'active']);
        $transaction   = Transaction::fromArray([
            '_id'         => 'mortgage-1',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'DUE 2026 TO 1234567-00',
            'amount'      => -1000,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '12-3456-1234567-00'],
        ]);

        $result = $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);

        $this->assertSame('withdrawal', $result['type']);
        $this->assertSame('mortgage-1', $result['external_id']);
        $this->assertSame(['TRANSFER'], $result['tags']);
        $this->assertSame(10, $result['source_id']);
        $this->assertSame(11, $result['destination_id']);
        $this->assertSame('Mortgage', $result['destination_name']);
    }

    public function test_mortgage_transfer_resolves_loan_from_description_reference_suffix(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
            'akahu_internal_account_prefix'  => '02-1248',
            'akahu_mortgage_payment_pattern' => '^DUE \\d{4} (TO|FR) \\d{7}-\\d{2}$',
        ]);
        $account       = Account::fromArray([
            '_id'               => 'acc-main',
            'name'              => 'Main Account',
            'formatted_account' => '02-1248-0022275-02',
            'currency'          => 'NZD',
            'status'            => 'active',
        ]);
        $loan          = Account::fromArray([
            '_id'               => 'acc-loan',
            'name'              => 'Fixed Loan',
            'formatted_account' => '02-1248-0022275-91',
            'currency'          => 'NZD',
            'status'            => 'active',
        ]);
        $transaction   = Transaction::fromArray([
            '_id'         => 'mortgage-description-reference',
            '_account'    => 'acc-main',
            'date'        => '2026-04-24T09:00:00Z',
            'description' => 'DUE 2404 TO 7960942-91',
            'amount'      => -1450,
            'type'        => 'TRANSFER',
        ]);

        $result = $transformer->transform(
            $transaction,
            $account,
            $configuration,
            ['acc-main' => 10, 'acc-loan' => 91],
            [],
            [$account, $loan]
        );

        $this->assertSame('withdrawal', $result['type']);
        $this->assertSame(10, $result['source_id']);
        $this->assertSame(91, $result['destination_id']);
        $this->assertSame('Fixed Loan', $result['destination_name']);
        $this->assertNull($result['category_name']);
    }

    public function test_internal_transfer_resolves_account_numbers_when_suffix_width_differs(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                          => 'akahu',
            'akahu_internal_account_prefix' => '02-1248',
        ]);
        $account       = Account::fromArray([
            '_id'               => 'acc-main',
            'name'              => 'Main',
            'formatted_account' => '02-1248-0022275-002',
            'currency'          => 'NZD',
            'status'            => 'active',
        ]);
        $opposing      = Account::fromArray([
            '_id'               => 'acc-bills',
            'name'              => 'Bills',
            'formatted_account' => '02-1248-0022275-008',
            'currency'          => 'NZD',
            'status'            => 'active',
        ]);
        $transaction   = Transaction::fromArray([
            '_id'         => 'tx-suffix',
            '_account'    => 'acc-main',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'From Bills (008)',
            'amount'      => 420,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '02-1248-0022275-08'],
        ]);

        $result = $transformer->transform(
            $transaction,
            $account,
            $configuration,
            ['acc-main' => 10, 'acc-bills' => 12],
            [],
            [$account, $opposing]
        );

        $this->assertSame('transfer', $result['type']);
        $this->assertSame(12, $result['source_id']);
        $this->assertSame('Bills', $result['source_name']);
        $this->assertSame(10, $result['destination_id']);
    }

    public function test_pending_transactions_get_stable_synthetic_ids_and_pending_tag(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $pendingA      = PendingTransaction::fromArray([
            '_account'    => 'acc-1',
            '_user'       => 'user-1',
            '_connection' => 'conn-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Coffee',
            'amount'      => -5.50,
            'type'        => 'EFTPOS',
            'updated_at'  => '2026-03-10T09:00:00Z',
        ]);
        $pendingB      = PendingTransaction::fromArray([
            '_account'    => 'acc-1',
            '_user'       => 'user-1',
            '_connection' => 'conn-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Coffee',
            'amount'      => -5.50,
            'type'        => 'EFTPOS',
            'updated_at'  => '2026-03-10T10:00:00Z',
        ]);

        $resultA = $transformer->transform($pendingA, $account, $configuration, ['acc-1' => 10], []);
        $resultB = $transformer->transform($pendingB, $account, $configuration, ['acc-1' => 10], []);

        $this->assertSame($pendingA->getIdentifier(), $pendingB->getIdentifier());
        $this->assertSame($resultA['external_id'], $resultB['external_id']);
        $this->assertContains('pending', $resultA['tags']);
    }

    public function test_pending_transactions_preserve_provider_identifier_when_present(): void
    {
        $pending = PendingTransaction::fromArray([
            '_id'         => 'provider-pending-id',
            '_account'    => 'acc-1',
            '_user'       => 'user-1',
            '_connection' => 'conn-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Coffee',
            'amount'      => -5.50,
            'type'        => 'EFTPOS',
            'updated_at'  => '2026-03-10T09:00:00Z',
        ]);

        $this->assertSame('provider-pending-id', $pending->getIdentifier());
        $this->assertSame('provider-pending-id', $pending->toArray()['hash']);
    }

    public function test_transform_preserves_decimal_amount_strings_without_float_rounding(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $transaction   = Transaction::fromArray([
            '_id'         => 'large-decimal',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Large decimal',
            'amount'      => '-123456789012345.67',
            'type'        => 'EFTPOS',
        ]);

        $result = $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10], []);

        $this->assertSame('withdrawal', $result['type']);
        $this->assertSame('123456789012345.670000000000', $result['amount']);
    }

    public function test_transform_skips_zero_amount_transactions(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $transaction   = Transaction::fromArray([
            '_id'         => 'zero-amount',
            '_account'    => 'acc-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Zero amount',
            'amount'      => '0.00',
            'type'        => 'EFTPOS',
        ]);

        $this->assertSame([], $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10], []));
    }

    public function test_pending_synthetic_identifier_normalizes_amount_and_includes_stable_scope_fields(): void
    {
        $base = [
            '_account'    => 'acc-1',
            '_user'       => 'user-1',
            '_connection' => 'conn-1',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'Coffee',
            'type'        => 'EFTPOS',
        ];

        $this->assertSame(
            PendingTransaction::syntheticIdentifier([...$base, 'amount' => -5.5]),
            PendingTransaction::syntheticIdentifier([...$base, 'amount' => '-5.50'])
        );
        $this->assertNotSame(
            PendingTransaction::syntheticIdentifier([...$base, 'amount' => -5.5]),
            PendingTransaction::syntheticIdentifier([...$base, '_connection' => 'conn-2', 'amount' => -5.5])
        );
    }

    public function test_transaction_date_requires_explicit_date(): void
    {
        $transaction = Transaction::fromArray([
            '_id'         => 'missing-date',
            '_account'    => 'acc-1',
            'description' => 'Missing date',
            'amount'      => '-1.23',
            'type'        => 'EFTPOS',
        ]);

        $this->expectException(ImporterErrorException::class);
        $this->expectExceptionMessage('Akahu transaction missing-date has no date.');

        $transaction->getDate();
    }
}
