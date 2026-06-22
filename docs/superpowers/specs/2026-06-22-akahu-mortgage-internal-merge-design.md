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

1. **Detect candidate (union).** It is an internal-transfer candidate when *either*:
   - a configured `internal_account_prefix` matches the opposing account number or
     appears in the description, **or**
   - an opposing account is found in the import set (`findOpposingAccount`).

   A mortgage payment is detected separately by the configured
   `mortgage_payment_pattern` regex; when no opposing account is found via the normal
   path, `findMortgageAccount` attempts to match the liability account by reference.

2. **Universal Firefly-id guard.** A detected candidate (internal *or* mortgage) only
   keeps its special classification when its opposing account resolves to a real
   Firefly III id (`is_int($mapped) && $mapped > 0`) in `accountMapping`. Otherwise it
   falls back to a plain `deposit`/`withdrawal` with a name-only counterparty
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
4. Resolve `TransactionTransformer.php` by hand to implement the target behavior:
   - Restore helpers `isMortgagePayment`, `findMortgageAccount`,
     `extractMortgageAccountReference`, `accountSuffix`, and the `Log` import.
   - Extend `isInternalTransfer` to the union (prefix/mortgage **or** opposing account
     found), taking both `Configuration` and the found `?Account`.
   - Add a shared guard helper (e.g. `opposingHasFireflyId(?Account, array $accountMapping)`)
     and gate both `$isInternal` and `$isMortgagePayment` through it.
   - Restore the wrong-side skip block.
   - Set `opposingFirefly` to the mapped account only when classified special.
5. Resolve `TransactionTransformerTest.php` and reconcile the wider test suite
   (see below).

## Test reconciliation

- **Keep** from `f59a9eb`:
  - `RoutineManagerTest::test_routine_manager_creates_all_new_accounts_before_transforming_internal_transfers`
  - `TransactionTransformerTest::test_transfer_to_unselected_akahu_account_is_imported_as_regular_withdrawal`
  - `TransactionTransformerTest::test_transfer_to_selected_account_without_firefly_id_is_imported_as_regular_withdrawal`
  (both consistent with the universal guard)
- **Revert** `test_transfer_between_imported_accounts_imports_both_sides_as_transfers`
  back to the keep-credit-side-only assertion (wrong-side skip restored).
- **Restore** the mortgage/prefix unit and feature tests deleted by `8b4acdd`
  (in `TransactionTransformerTest`, `ExistingConfigurationTest`, `AkahuServiceTest`,
  `ConfigurationAndSerializationTest`, `NewJobDataCollectorTest`).
- **Add** coverage for the new union + guard interaction: a prefix-matched transfer
  whose opposing account has no Firefly id falls back to a plain withdrawal.
- Verify: `./vendor/bin/phpunit --filter Akahu`.

## Out of scope

- Any unrelated refactoring of the Akahu pipeline.
- Changing the public configuration field names or schema version.
