# Re-enable Akahu Mortgage & Internal Transfer Handling — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore Akahu mortgage-payment matching and internal-transfer handling (removed in `8b4acdd`) while keeping the two refinements from `f59a9eb` — RoutineManager account-creation ordering and the universal real-Firefly-id guard.

**Architecture:** Revert `8b4acdd` to bring back the configuration surface and config/serialization tests cleanly, but keep the HEAD (`f59a9eb`) versions of the two files both commits touched. Then re-write `TransactionTransformer` to merge mortgage detection + wrong-side skip on top of the guarded, opposing-account-based classification. Leave `RoutineManager` (and its test) untouched at the `f59a9eb` state.

**Tech Stack:** PHP 8 (Laravel), PHPUnit ^13.

## Global Constraints

- Branch: all work on `revert/akahu-mortgage-internal` (already created off `dev`).
- Do **not** modify `app/Services/Akahu/Conversion/RoutineManager.php` — `f59a9eb`'s account-creation ordering stays as production code.
- `tests/Unit/Services/Akahu/RoutineManagerTest.php` gets a **minimal** update (decision recorded during execution): the restored wrong-side skip drops the debit leg of an internal transfer, so the ordering test must model **both** legs (debit on acc-1 skipped, credit on acc-2 kept). The kept credit leg still asserts the surviving `transfer` resolves to the created Firefly ids (source 21 → dest 22), preserving the test's purpose of proving accounts are created before transforming. Accepted consequence: an internal transfer Akahu reports on only one account (debit side) is dropped (original pre-`8b4acdd` behaviour).
- The prefix (`internal_account_prefix`) is restored as config surface only; it is **not** wired into transfer classification.
- The Firefly-id guard applies to **all** special classification, including mortgage payments: special type only when the opposing account maps to `is_int($id) && $id > 0`, keyed by `$opposingAccount->getIdentifier()`.
- Account model: `getIdentifier()` returns `$this->id`, so `$accountMapping[$opposing->getIdentifier()]` is the same key `getMappedAccount` uses.
- Test runner: `./vendor/bin/phpunit`.

---

### Task 1: Restore the configuration surface by reverting 8b4acdd (keep transformer at HEAD)

Reverting `8b4acdd` cleanly restores `Configuration`, `Credentials`, `config/akahu.php`, `.env.example`, `resources/schemas/v3.json`, `UploadController`, `NewJobDataCollector`, `CollectsSettings`, and the config/serialization/validator tests. The two files `f59a9eb` also touched conflict; we resolve them by keeping HEAD and merging logic in Task 2.

**Files:**
- Modify (via revert): `app/Services/Shared/Configuration/Configuration.php`, `app/Services/Akahu/Credentials.php`, `config/akahu.php`, `.env.example`, `resources/schemas/v3.json`, `app/Http/Controllers/Import/UploadController.php`, `app/Services/Akahu/Validation/NewJobDataCollector.php`, `app/Support/Http/Upload/CollectsSettings.php`
- Restore (via revert): `tests/Feature/Akahu/ExistingConfigurationTest.php`, `tests/Unit/Services/Akahu/AkahuServiceTest.php`, `tests/Unit/Services/Akahu/ConfigurationAndSerializationTest.php`, `tests/Unit/Services/Akahu/NewJobDataCollectorTest.php`
- Keep at HEAD (conflict resolution): `app/Services/Akahu/Conversion/TransactionTransformer.php`, `tests/Unit/Services/Akahu/TransactionTransformerTest.php`

**Interfaces:**
- Produces (restored on `Configuration`): `getAkahuInternalAccountPrefix(): string`, `setAkahuInternalAccountPrefix(string): void`, `getAkahuMortgagePaymentPattern(): string`, `setAkahuMortgagePaymentPattern(string): void`, and `akahu_internal_account_prefix` / `akahu_mortgage_payment_pattern` keys in `fromArray`/`toArray`/request hydration.
- Produces (restored on `Credentials`): constructor params `internalAccountPrefix` and `mortgagePaymentPattern`.

- [ ] **Step 1: Confirm starting point is clean and on the right branch**

Run:
```bash
git -C /Users/bedesv/code/akahu-importer-codex/data-importer rev-parse --abbrev-ref HEAD
git -C /Users/bedesv/code/akahu-importer-codex/data-importer status --short
```
Expected: branch `revert/akahu-mortgage-internal`, no uncommitted changes (the spec/plan commits aside).

- [ ] **Step 2: Start the revert of 8b4acdd (will report conflicts)**

Run:
```bash
git -C /Users/bedesv/code/akahu-importer-codex/data-importer revert --no-commit 8b4acdd120a33eda85f797b976dd1cecca78428b
```
Expected: output naming conflicts (`CONFLICT (content): Merge conflict in app/Services/Akahu/Conversion/TransactionTransformer.php` and `.../TransactionTransformerTest.php`). Exit status non-zero is expected here. All non-conflicting files are now staged/modified.

- [ ] **Step 3: Resolve the two conflicts by keeping HEAD versions**

Discard the conflicted revert hunks for the transformer and its test, keeping the current `f59a9eb` content (merge happens in Task 2):
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
git checkout HEAD -- app/Services/Akahu/Conversion/TransactionTransformer.php tests/Unit/Services/Akahu/TransactionTransformerTest.php
git add app/Services/Akahu/Conversion/TransactionTransformer.php tests/Unit/Services/Akahu/TransactionTransformerTest.php
```
Expected: no output; both files now contain no conflict markers. Confirm with:
```bash
grep -rn '<<<<<<<\|>>>>>>>\|=======' app/Services/Akahu/Conversion/TransactionTransformer.php tests/Unit/Services/Akahu/TransactionTransformerTest.php || echo "no conflict markers"
```
Expected: `no conflict markers`.

- [ ] **Step 4: Verify the restored config surface compiles and the restored tests pass**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
php -l app/Services/Shared/Configuration/Configuration.php
php -l app/Services/Akahu/Credentials.php
./vendor/bin/phpunit --filter 'ConfigurationAndSerialization|NewJobDataCollector|ExistingConfiguration|AkahuService'
```
Expected: `No syntax errors detected` for both lint checks; the filtered suite passes (these restored tests exercise the prefix/pattern getters, the mortgage-regex validator, and config round-trips). The transformer is still at `f59a9eb`, which these tests do not touch.

- [ ] **Step 5: Conclude the revert as a commit**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
git revert --continue --no-edit
```
Expected: a new commit "Revert \"fix(akahu): remove mortgage and internal prefix handling\"". Confirm `RoutineManager.php` was untouched:
```bash
git show --stat HEAD | grep RoutineManager || echo "RoutineManager not in revert (correct)"
```
Expected: `RoutineManager not in revert (correct)`.

---

### Task 2: Merge mortgage detection + wrong-side skip into TransactionTransformer (TDD)

Write the new/updated tests first, watch them fail against the HEAD transformer, then overwrite the transformer with the merged implementation.

**Files:**
- Modify: `tests/Unit/Services/Akahu/TransactionTransformerTest.php`
- Modify: `app/Services/Akahu/Conversion/TransactionTransformer.php`

**Interfaces:**
- Consumes: `Configuration::getAkahuMortgagePaymentPattern()` (restored in Task 1).
- Produces (private to the transformer): `isMortgagePayment(Transaction, Configuration): bool`, `findMortgageAccount(Transaction, array): ?Account`, `extractMortgageAccountReference(string): ?string`, `accountSuffix(string): string`, `opposingHasFireflyId(?Account, array): bool`, and `isInternalTransfer(Transaction, ?Account, bool): bool`. The public `transform(...)` signature is unchanged.

- [ ] **Step 1: Update the both-sides test to the keep-credit-side-only assertion**

In `tests/Unit/Services/Akahu/TransactionTransformerTest.php`, replace the method `test_transfer_between_imported_accounts_imports_both_sides_as_transfers` (the first test in the class) with this version (the restored wrong-side skip drops the debit leg):

```php
    public function test_transfer_between_imported_accounts_keeps_credit_side_only(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray(['flow' => 'akahu']);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'formatted_account' => '12-3456-1111111-00', 'currency' => 'NZD', 'status' => 'active']);
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
            '_account'    => 'acc-2',
            'date'        => '2026-03-10T09:00:00Z',
            'description' => 'TRANSFER FROM 12-3456-1111111-00',
            'amount'      => 50.12,
            'type'        => 'TRANSFER',
            'meta'        => ['other_account' => '12-3456-1111111-00'],
            'category'    => ['name' => 'Transfers'],
        ]);

        $debitResult  = $transformer->transform($debit, $account, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);
        $creditResult = $transformer->transform($credit, $opposing, $configuration, ['acc-1' => 10, 'acc-2' => 11], [], [$account, $opposing]);

        $this->assertSame([], $debitResult);
        $this->assertSame('transfer', $creditResult['type']);
        $this->assertNull($creditResult['category_name']);
        $this->assertSame(10, $creditResult['source_id']);
        $this->assertSame('Cheque', $creditResult['source_name']);
        $this->assertSame(11, $creditResult['destination_id']);
        $this->assertSame('Savings', $creditResult['destination_name']);
    }
```

- [ ] **Step 2: Add the three restored/new mortgage tests**

In the same test file, add these three methods (e.g. immediately after the test from Step 1):

```php
    public function test_mortgage_transfer_keeps_debit_side_only(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
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
        $this->assertNull($result['category_name']);
    }

    public function test_mortgage_transfer_resolves_loan_from_description_reference_suffix(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
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

    public function test_mortgage_payment_without_mapped_account_falls_back_to_withdrawal(): void
    {
        $transformer   = new TransactionTransformer();
        $configuration = Configuration::fromArray([
            'flow'                           => 'akahu',
            'akahu_mortgage_payment_pattern' => '^DUE \\d{4} (TO|FR) \\d{7}-\\d{2}$',
        ]);
        $account       = Account::fromArray(['_id' => 'acc-1', 'name' => 'Cheque', 'currency' => 'NZD', 'status' => 'active']);
        $loan          = Account::fromArray(['_id' => 'acc-loan', 'name' => 'Fixed Loan', 'formatted_account' => '02-1248-0022275-91', 'currency' => 'NZD', 'status' => 'active']);
        $transaction   = Transaction::fromArray([
            '_id'         => 'mortgage-unmapped',
            '_account'    => 'acc-1',
            'date'        => '2026-04-24T09:00:00Z',
            'description' => 'DUE 2404 TO 7960942-91',
            'amount'      => -1450,
            'type'        => 'TRANSFER',
        ]);

        // Loan account resolved by description but NOT mapped to a Firefly id (=> guard fails).
        $result = $transformer->transform($transaction, $account, $configuration, ['acc-1' => 10, 'acc-loan' => 0], [], [$account, $loan]);

        $this->assertSame('withdrawal', $result['type']);
        $this->assertSame(10, $result['source_id']);
        $this->assertNull($result['destination_id']);
    }
```

- [ ] **Step 3: Run the transformer tests to confirm the new/changed ones fail**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
./vendor/bin/phpunit --filter TransactionTransformerTest
```
Expected: FAIL. `test_transfer_between_imported_accounts_keeps_credit_side_only` fails (HEAD transformer imports both legs, so `$debitResult` is not `[]`); the two mortgage tests fail (HEAD transformer has no mortgage handling, so type is `transfer`/`withdrawal-to-name-only` rather than the mortgage outcome). The unchanged HEAD tests still pass.

- [ ] **Step 4: Overwrite TransactionTransformer.php with the merged implementation**

Replace the entire contents of `app/Services/Akahu/Conversion/TransactionTransformer.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services\Akahu\Conversion;

use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Model\Transaction;
use App\Services\CSV\Converter\Amount;
use App\Services\Shared\Configuration\Configuration;
use Illuminate\Support\Facades\Log;

final class TransactionTransformer
{
    public function transform(Transaction $transaction, Account $account, Configuration $configuration, array $accountMapping, array $newAccountConfig, array $serviceAccounts = []): array
    {
        $isMortgagePayment = $this->isMortgagePayment($transaction, $configuration);
        $opposingAccount   = $this->findOpposingAccount($transaction, $serviceAccounts);
        if (null === $opposingAccount && $isMortgagePayment) {
            $opposingAccount = $this->findMortgageAccount($transaction, $serviceAccounts);
        }

        // Only classify as a transfer/mortgage payment when the opposing account maps
        // to a real Firefly III account id; otherwise fall back to deposit/withdrawal.
        $opposingHasFireflyId = $this->opposingHasFireflyId($opposingAccount, $accountMapping);
        $isMortgagePayment    = $isMortgagePayment && $opposingHasFireflyId;
        $isInternal           = $this->isInternalTransfer($transaction, $opposingAccount, $isMortgagePayment) && $opposingHasFireflyId;

        $rawAmount = $transaction->getAmount();
        if (0 === bccomp('0', $rawAmount, 12)) {
            return [];
        }

        // Skip the "wrong side" of internal transfers only when both sides are being imported.
        // If the opposing account is absent from the import set, keep this transaction as-is.
        if ($isInternal && null !== $opposingAccount) {
            $compareToZero = bccomp($rawAmount, '0', 12);
            if ($isMortgagePayment ? $compareToZero >= 0 : $compareToZero <= 0) {
                return [];
            }
        }

        $amount           = bcadd(Amount::positive($rawAmount), '0', 12);
        $isIncoming       = -1 === bccomp('0', $rawAmount, 12);
        $fireflyAccount    = $this->getMappedAccount($account, $accountMapping, $newAccountConfig);
        $opposingName      = $this->extractOpposingName($transaction);
        $opposingFirefly   = $isInternal ? $this->getMappedAccount($opposingAccount, $accountMapping, $newAccountConfig) : ['id' => null, 'name' => $opposingName];
        $source            = $isIncoming ? $opposingFirefly : $fireflyAccount;
        $destination       = $isIncoming ? $fireflyAccount : $opposingFirefly;

        return [
            'type'               => $isMortgagePayment ? 'withdrawal' : ($isInternal ? 'transfer' : ($isIncoming ? 'deposit' : 'withdrawal')),
            'date'               => $this->formatDate($transaction),
            'amount'             => $amount,
            'description'        => $transaction->getDescription(),
            'source_id'          => $source['id'] ?? null,
            'source_name'        => $source['name'] ?? null,
            'destination_id'     => $destination['id'] ?? null,
            'destination_name'   => $destination['name'] ?? null,
            'currency_code'      => '' !== $account->currency ? $account->currency : config('akahu.default_currency', 'NZD'),
            'category_name'      => $isInternal ? null : $this->extractCategory($transaction),
            'reconciled'         => false,
            'notes'              => $this->buildNotes($transaction),
            'tags'               => $this->buildTags($transaction),
            'internal_reference' => $transaction->getIdentifier(),
            'external_id'        => $transaction->getIdentifier(),
            'book_date'          => $this->formatDate($transaction),
            'process_date'       => $this->formatDate($transaction),
        ];
    }

    private function getMappedAccount(Account $account, array $accountMapping, array $newAccountConfig): array
    {
        $mappedId = $accountMapping[$account->id] ?? null;
        $name     = $newAccountConfig[$account->id]['name'] ?? $account->getDisplayName();

        return [
            'id'   => is_int($mappedId) && $mappedId > 0 ? $mappedId : null,
            'name' => $name,
        ];
    }

    private function formatDate(Transaction $transaction): string
    {
        return $transaction->getDate()
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d');
    }

    private function opposingHasFireflyId(?Account $opposingAccount, array $accountMapping): bool
    {
        if (null === $opposingAccount) {
            return false;
        }
        $mappedId = $accountMapping[$opposingAccount->getIdentifier()] ?? null;

        return is_int($mappedId) && $mappedId > 0;
    }

    private function isInternalTransfer(Transaction $transaction, ?Account $opposingAccount, bool $isMortgagePayment): bool
    {
        if ('TRANSFER' !== $transaction->getType()) {
            return false;
        }
        if ($isMortgagePayment) {
            return true;
        }

        return null !== $opposingAccount;
    }

    private function isMortgagePayment(Transaction $transaction, Configuration $configuration): bool
    {
        $pattern = $configuration->getAkahuMortgagePaymentPattern();
        if ('' === $pattern || 'TRANSFER' !== $transaction->getType()) {
            return false;
        }

        $result = @preg_match(sprintf('~%s~', str_replace('~', '\~', $pattern)), $transaction->getDescription());
        if (false === $result) {
            Log::warning(sprintf('Akahu mortgage payment pattern "%s" is invalid; skipping mortgage classification.', $pattern));

            return false;
        }

        return 1 === $result;
    }

    private function extractOpposingName(Transaction $transaction): string
    {
        $raw = $transaction->toArray();
        if (array_key_exists('merchant', $raw) && is_array($raw['merchant']) && array_key_exists('name', $raw['merchant']) && '' !== (string) $raw['merchant']['name']) {
            return (string) $raw['merchant']['name'];
        }
        if (array_key_exists('meta', $raw) && is_array($raw['meta']) && array_key_exists('other_account', $raw['meta']) && '' !== (string) $raw['meta']['other_account']) {
            return (string) $raw['meta']['other_account'];
        }

        $description = $transaction->getDescription();
        foreach (['EFTPOS ', 'POS ', 'DIRECT DEBIT ', 'DIRECT CREDIT ', 'AUTOMATIC PAYMENT ', 'BILL PAYMENT ', 'INTERNET BANKING '] as $prefix) {
            if (str_starts_with(strtoupper($description), $prefix)) {
                return trim(substr($description, strlen($prefix)));
            }
        }

        return trim($description);
    }

    private function extractCategory(Transaction $transaction): ?string
    {
        $category = $transaction->toArray()['category'] ?? null;
        if (is_string($category) && '' !== $category) {
            return $category;
        }
        if (is_array($category)) {
            foreach (['name', 'group', 'label'] as $key) {
                if (array_key_exists($key, $category) && '' !== (string) $category[$key]) {
                    return (string) $category[$key];
                }
            }
        }

        return null;
    }

    private function buildNotes(Transaction $transaction): ?string
    {
        $raw   = $transaction->toArray();
        $notes = [];
        if ('' !== $transaction->getType()) {
            $notes[] = $transaction->getType();
        }
        foreach (['particulars' => 'Particulars', 'code' => 'Code', 'reference' => 'Ref', 'card_suffix' => 'Card'] as $key => $label) {
            $value = $raw['meta'][$key] ?? null;
            if (null !== $value && '' !== (string) $value) {
                $notes[] = sprintf('%s: %s', $label, $value);
            }
        }

        return 0 === count($notes) ? null : implode(' | ', $notes);
    }

    private function buildTags(Transaction $transaction): array
    {
        $tags = [];
        if ('' !== $transaction->getType()) {
            $tags[] = $transaction->getType();
        }
        if ($transaction->isPending()) {
            $tags[] = 'pending';
        }

        return array_values(array_unique($tags));
    }

    /**
     * Attempts to match the opposing account number to an existing Firefly III account
     */
    private function findOpposingAccount(Transaction $transaction, array $serviceAccounts): ?Account
    {
        $raw          = $transaction->toArray();
        $otherAccount = (string) (($raw['meta']['other_account'] ?? '') ?: '');
        if ('' === $otherAccount) {
            return null;
        }

        $normalizedOther = $this->normalizeAccountNumber($otherAccount);
        foreach ($serviceAccounts as $serviceAccount) {
            if (!$serviceAccount instanceof Account) {
                continue;
            }
            if ($serviceAccount->getIdentifier() === $transaction->getAccountId()) {
                continue;
            }
            $candidates = [
                $serviceAccount->formattedAccount,
                (string) ($serviceAccount->raw['account_number'] ?? ''),
                (string) ($serviceAccount->raw['meta']['account_number'] ?? ''),
            ];
            foreach ($candidates as $candidate) {
                if ('' === $candidate) {
                    continue;
                }
                if ($this->normalizeAccountNumber($candidate) === $normalizedOther) {
                    return $serviceAccount;
                }
            }
        }

        return null;
    }

    private function findMortgageAccount(Transaction $transaction, array $serviceAccounts): ?Account
    {
        $reference = $this->extractMortgageAccountReference($transaction->getDescription());
        if (null === $reference) {
            return null;
        }

        $normalizedReference = $this->normalizeAccountNumber($reference);
        $referenceSuffix     = $this->accountSuffix($reference);
        $matches             = [];
        foreach ($serviceAccounts as $serviceAccount) {
            if (!$serviceAccount instanceof Account) {
                continue;
            }
            if ($serviceAccount->getIdentifier() === $transaction->getAccountId()) {
                continue;
            }
            $candidates = [
                $serviceAccount->formattedAccount,
                (string) ($serviceAccount->raw['account_number'] ?? ''),
                (string) ($serviceAccount->raw['meta']['account_number'] ?? ''),
            ];
            foreach ($candidates as $candidate) {
                if ('' === $candidate) {
                    continue;
                }
                if ($this->normalizeAccountNumber($candidate) === $normalizedReference || $this->accountSuffix($candidate) === $referenceSuffix) {
                    $matches[$serviceAccount->getIdentifier()] = $serviceAccount;
                    break;
                }
            }
        }

        return 1 === count($matches) ? array_values($matches)[0] : null;
    }

    private function extractMortgageAccountReference(string $description): ?string
    {
        if (1 === preg_match('/\b(?:TO|FR)\s+([0-9-]+)\b/i', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function accountSuffix(string $value): string
    {
        preg_match_all('/\d+/', $value, $matches);
        $parts = $matches[0] ?? [];
        if ([] === $parts) {
            return '';
        }
        $suffix = ltrim((string) end($parts), '0');

        return '' === $suffix ? '0' : $suffix;
    }

    private function normalizeAccountNumber(string $value): string
    {
        preg_match_all('/\d+/', $value, $matches);
        $parts = $matches[0] ?? [];
        if (count($parts) >= 4) {
            $suffix = ltrim($parts[3], '0');
            if ('' === $suffix) {
                $suffix = '0';
            }

            return implode('-', [$parts[0], $parts[1], $parts[2], $suffix]);
        }

        return preg_replace('/\D+/', '', $value) ?? $value;
    }
}
```

- [ ] **Step 5: Run the transformer tests to confirm they pass**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
php -l app/Services/Akahu/Conversion/TransactionTransformer.php
./vendor/bin/phpunit --filter TransactionTransformerTest
```
Expected: `No syntax errors detected`; all `TransactionTransformerTest` cases pass (the changed both-sides test, the three mortgage tests, and all unchanged HEAD cases).

- [ ] **Step 6: Commit**

```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
git add app/Services/Akahu/Conversion/TransactionTransformer.php tests/Unit/Services/Akahu/TransactionTransformerTest.php
git commit -m "fix(akahu): re-enable mortgage handling with universal firefly-id guard"
```

---

### Task 3: Full Akahu suite verification

Confirm the whole Akahu surface — transformer, RoutineManager ordering, config/serialization, validator — passes together.

**Files:** none modified (verification only).

- [ ] **Step 1: Run the full Akahu suite**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
./vendor/bin/phpunit --filter Akahu
```
Expected: all tests pass, including `RoutineManagerTest` (unchanged `f59a9eb` ordering test) and the restored config/serialization/validator tests.

- [ ] **Step 2: Run the full test suite to catch any cross-cutting regressions**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
./vendor/bin/phpunit
```
Expected: green. If unrelated pre-existing failures appear, note them but do not fix in this plan.

- [ ] **Step 3: Confirm the restored config surface end-to-end**

Run:
```bash
cd /Users/bedesv/code/akahu-importer-codex/data-importer
grep -n 'AKAHU_INTERNAL_ACCOUNT_PREFIX\|AKAHU_MORTGAGE_PAYMENT_PATTERN' .env.example
grep -n 'internal_account_prefix\|mortgage_payment_pattern' config/akahu.php resources/schemas/v3.json
grep -n 'getAkahuMortgagePaymentPattern\|getAkahuInternalAccountPrefix' app/Services/Shared/Configuration/Configuration.php
```
Expected: each grep returns the restored lines (env vars, config keys, schema properties, and Configuration getters).

---

## Self-Review

**Spec coverage:**
- Restore mortgage matching + config surface → Task 1 (revert) + Task 2 (transformer helpers). ✓
- Keep RoutineManager ordering → Global Constraints + Task 1 Step 5 confirms it's untouched. ✓
- Universal Firefly-id guard (incl. mortgage) → Task 2 `opposingHasFireflyId` gating both `$isInternal` and `$isMortgagePayment`; `test_mortgage_payment_without_mapped_account_falls_back_to_withdrawal`. ✓
- Wrong-side skip restored → Task 2 transform() skip block; `test_transfer_between_imported_accounts_keeps_credit_side_only`. ✓
- Prefix restored as surface only, not in classification → Task 1 revert restores surface; Task 2 `isInternalTransfer` omits the prefix branch; spec note. ✓
- Test reconciliation (keep HEAD cases, change both-sides, restore 2 mortgage tests, add guard test) → Task 2 Steps 1-2. ✓

**Placeholder scan:** No TBD/TODO; every code step contains full code; every command has expected output. ✓

**Type consistency:** `isInternalTransfer(Transaction, ?Account, bool)`, `opposingHasFireflyId(?Account, array)`, and the mortgage helpers match between the Interfaces blocks, the implementation in Task 2 Step 4, and the tests. `getIdentifier()`/`->id` equivalence noted in Global Constraints. ✓
