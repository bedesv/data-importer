# Re-enabling Akahu mortgage & internal transfer handling

Date: 2026-06-22

## Goal

Re-enable Akahu mortgage payment matching and internal-transfer handling that were
removed in commit `8b4acdd` ("fix(akahu): remove mortgage and internal prefix
handling"), while **keeping** the two refinements introduced afterward in commit
`f59a9eb` ("fix(akahu): align internal transfer handling"):

1. **RoutineManager ordering** — create all new Firefly III accounts *before*
   transforming transactions, so newly created accounts already have ids.
2. **Real-Firefly-id guard** — only apply special transfer/mortgage classification
   when the opposing account resolves to a real Firefly III account id.

This is a **behavior merge**, not a clean two-commit revert: `f59a9eb` and `8b4acdd`
both rewrote `TransactionTransformer::transform()` and `isInternalTransfer()`, so the
two changes cannot be separated mechanically.

## Background

Chronological evolution of the relevant logic:

| Commit | Date | Effect |
|--------|------|--------|
| (pre) | — | Mortgage matching + configurable `internal_account_prefix`; `isInternalTransfer(Transaction, Configuration, bool $isMortgage)` detects via prefix/mortgage; one leg of an internal transfer is skipped to avoid double counting. |
| `8b4acdd` | 2026-05-13 | Removed mortgage matching and prefix config. Simplified `isInternalTransfer(Transaction, ?Account)` to "TRANSFER type + opposing account found". Removed the wrong-side skip. Dropped config/env/schema/getters/setters/tests. |
| `f59a9eb` | 2026-05-31 | Layered on top of the simplified state: tightened internal classification to require the opposing account map to a real Firefly id; reordered `RoutineManager` to create accounts before transforming; switched tests to import **both** transfer legs. |

No other commits have touched the affected files since, so `git revert 8b4acdd`
applies cleanly to every file **except** the two also touched by `f59a9eb`
(`TransactionTransformer.php`, `TransactionTransformerTest.php`).

## Target behavior

For a transaction of type `TRANSFER`, `TransactionTransformer::transform()`:

1. **Detect candidate.** It is an internal-transfer candidate when an opposing account
   is found in the import set (`findOpposingAccount`). A mortgage payment is detected
   separately by the configured `mortgage_payment_pattern` regex; when no opposing
   account is found via the normal path, `findMortgageAccount` attempts to match the
   liability account by reference.

   > **Note on the prefix:** the `internal_account_prefix` config field is restored
   > across the stack (serialization, env, schema, credentials, view) for
   > compatibility, but it is **not** wired into classification. The prefix only ever
   > influenced *detection*, never *which account is the counterparty*; combined with
   > the universal Firefly-id guard below (which requires a resolved + mapped opposing
   > account), a prefix branch could never change an outcome. So we deliberately omit
   > it from `isInternalTransfer` rather than ship a dead branch. (Decision recorded
   > during planning.)

2. **Universal Firefly-id guard.** A detected candidate (internal *or* mortgage) only
   keeps its special classification when its opposing account resolves to a real
   Firefly III id (`is_int($mapped) && $mapped > 0`) in `accountMapping`, keyed by
   `$opposingAccount->getIdentifier()`. Otherwise it falls back to a plain
   `deposit`/`withdrawal` with a name-only counterparty
   (`['id' => null, 'name' => <opposing name>]`).

3. **Type.**
   - mortgage payment (guard satisfied) → `withdrawal` to the matched liability account
   - internal transfer (guard satisfied) → `transfer`
   - otherwise → `deposit` (incoming) / `withdrawal` (outgoing)

4. **Wrong-side skip (restored).** When a candidate is classified special **and** both
   sides are present in the import set, drop the opposing leg so each movement records
   once: skip when `bccomp(rawAmount, '0') >= 0` for mortgage payments, or `<= 0` for
   internal transfers.

5. **Category.** `category_name` is `null` for items classified as internal/mortgage
   (special), otherwise the extracted category.

`RoutineManager::start()` keeps `f59a9eb`'s ordering: iterate the account mapping to
create any new accounts first, then re-read the configuration's account mapping before
building transaction groups.

## Configuration surface (restored)

Reverting `8b4acdd` restores these across the stack:

- `Configuration`: `akahuInternalAccountPrefix`, `akahuMortgagePaymentPattern` fields,
  defaults, `fromArray`/array hydration, `toArray`, getters/setters, request hydration.
- `Credentials`: `internalAccountPrefix`, `mortgagePaymentPattern` constructor params,
  `resolve()` precedence resolution, `apply()`.
- `config/akahu.php`: `internal_account_prefix`, `mortgage_payment_pattern`.
- `.env.example`: `AKAHU_INTERNAL_ACCOUNT_PREFIX`, `AKAHU_MORTGAGE_PAYMENT_PATTERN`.
- `resources/schemas/v3.json`: the two string properties.
- `UploadController`: collect the two form inputs.
- `NewJobDataCollector`: validate the mortgage regex.
- `CollectsSettings::getAkahuSettings()`: surface the two values to the view layer.

## Implementation approach

1. Branch off `dev` (e.g. `revert/akahu-mortgage-internal`).
2. `git revert --no-commit 8b4acdd`. This cleanly restores every non-conflicting file
   and leaves merge conflicts in `TransactionTransformer.php` and
   `TransactionTransformerTest.php`.
3. **Do not modify `RoutineManager.php`** — `f59a9eb`'s ordering stays.
   Resolve the two conflicting files (`TransactionTransformer.php`,
   `TransactionTransformerTest.php`) by keeping the HEAD/`f59a9eb` version for now
   (`git checkout HEAD -- <those two files>`), so the merge logic and tests are applied
   deliberately in the next task rather than via conflict-marker surgery.
4. Overwrite `TransactionTransformer.php` with the merged implementation:
   - Restore helpers `isMortgagePayment`, `findMortgageAccount`,
     `extractMortgageAccountReference`, `accountSuffix`, and the `Log` import.
   - `isInternalTransfer(Transaction, ?Account $opposing, bool $isMortgage)`: TRANSFER
     type AND (`$isMortgage` OR opposing account found). No `Configuration`/prefix.
   - Add `opposingHasFireflyId(?Account, array $accountMapping)` and gate both
     `$isInternal` and `$isMortgagePayment` through it.
   - Restore the wrong-side skip block.
   - Set `opposingFirefly` to the mapped account only when `$isInternal`.
5. Update `TransactionTransformerTest.php` (see below).

## Test reconciliation

- **Update** `RoutineManagerTest::test_routine_manager_creates_all_new_accounts_before_transforming_internal_transfers`
  (decision recorded during execution): the restored wrong-side skip drops the debit
  leg, so the test must supply the credit leg on acc-2 (the surviving side). It still
  asserts the resulting `transfer` resolves to the created ids (source 21 → dest 22),
  preserving its purpose of validating `f59a9eb`'s account-creation ordering. Accepted
  consequence: a one-leg-only (debit) internal transfer is dropped, matching the
  original pre-`8b4acdd` behaviour. `RoutineManager.php` itself is unchanged.
- **Keep unchanged** the HEAD `TransactionTransformerTest` cases — they all already
  match the merged behavior: `..._without_imported_opposing_account...`,
  `..._non_transfer_with_internal_looking_account_number...`,
  `..._unselected_akahu_account...`, `..._without_firefly_id...`,
  `..._incoming_internal_transfer...`, `..._suffix_width_differs...`, and the
  pending/zero/decimal/date cases. Also keep `f59a9eb`'s `RoutineManagerTest`.
- **Change** `test_transfer_between_imported_accounts_imports_both_sides_as_transfers`
  to the keep-credit-side-only assertion (debit leg returns `[]`; credit leg is the
  kept `transfer`), reflecting the restored wrong-side skip. Rename accordingly.
- **Restore** only the two mortgage unit tests deleted by `8b4acdd`:
  `test_mortgage_transfer_keeps_debit_side_only` and
  `test_mortgage_transfer_resolves_loan_from_description_reference_suffix`. (The old
  prefix-only tests are **not** restored — the HEAD cases already cover the same
  scenarios and the prefix no longer affects classification.)
- **Add** `test_mortgage_payment_without_mapped_account_falls_back_to_withdrawal`:
  mortgage regex matches but the resolved loan account has no Firefly id → plain
  withdrawal (guard applies to mortgage too).
- The config/serialization/validator tests restored by reverting `8b4acdd`
  (`ConfigurationAndSerializationTest`, `NewJobDataCollectorTest`,
  `ExistingConfigurationTest`, `AkahuServiceTest`) come back via the clean revert and
  pass once `Configuration`/`Credentials`/validator are restored.
- Verify: `./vendor/bin/phpunit --filter Akahu`.

## Out of scope

- Any unrelated refactoring of the Akahu pipeline.
- Changing the public configuration field names or schema version.
