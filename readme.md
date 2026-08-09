# Firefly III Data Importer with Akahu support

This is a personal, unofficial fork of the [Firefly III Data Importer](https://github.com/firefly-iii/data-importer). It adds direct imports from [Akahu](https://www.akahu.nz/) for New Zealand bank accounts, along with a small number of optional behaviours that suit how I use Firefly III.

I keep these changes in a fork because they were developed with AI assistance, while the upstream project does not accept AI-generated contributions. The fork lets me continue using and improving them without asking the upstream maintainers to support them.

I intend to keep the fork reasonably close to upstream, but updates may lag behind new upstream releases. It is primarily maintained for my own use, so fork-specific features may reflect my banking and budgeting setup. Features of that kind will be optional wherever practical.

## Start with the upstream documentation

Unless this README says otherwise, installation, configuration and use are the same as the upstream Data Importer. See the [official Firefly III Data Importer documentation](https://docs.firefly-iii.org/how-to/data-importer/) for the general setup and workflow.

This README only documents behaviour added or changed by this fork.

## What this fork adds

- Akahu as an import provider, including account discovery and mapping.
- Imports of settled transactions, with the option to include pending transactions.
- Fresh account data requested from Akahu before an import.
- Detection of transfers between selected Akahu accounts.
- Optional matching for mortgage payments whose bank descriptions follow a specific pattern.
- Akahu transaction identifiers used for duplicate detection.

## Akahu configuration

Create an [Akahu personal app](https://developers.akahu.nz/docs/personal-apps) with access to the accounts you want to import, then provide its tokens to the importer:

```dotenv
AKAHU_APP_TOKEN=replace-with-your-app-token
AKAHU_USER_TOKEN=replace-with-your-user-token
```

Treat the user token as a password. Akahu credentials are read from the environment, are not displayed by the web importer, and are removed from downloaded configuration files. Scheduled imports therefore also need the tokens in their environment.

After starting the importer using the normal upstream instructions, choose **Akahu** as the import provider and map the Akahu accounts you want to import to Firefly III accounts.

### Akahu-specific environment variables

| Variable | Default | Purpose |
|---|---|---|
| `AKAHU_APP_TOKEN` | Empty | Akahu application token; required. |
| `AKAHU_USER_TOKEN` | Empty | Akahu user token; required. |
| `AKAHU_MORTGAGE_PAYMENT_PATTERN` | Empty | Optional regular expression for identifying mortgage-payment descriptions. |
| `AKAHU_ALWAYS_REFRESH` | `true` | Request fresh account data for every import. |
| `AKAHU_STALE_REFRESH_HOURS` | `2` | Maximum data age when `AKAHU_ALWAYS_REFRESH` is disabled. |
| `AKAHU_REFRESH_POLL_SECONDS` | `10` | Delay between refresh-status checks. |
| `AKAHU_REFRESH_WAIT_TIMEOUT_SECONDS` | `180` | Maximum time to wait for a refresh. |
| `AKAHU_CONNECTION_TIMEOUT` | `30` | Akahu HTTP request timeout in seconds. |
| `AKAHU_BASE_URL` | `https://api.akahu.io/v1` | Alternative Akahu API URL, mainly for testing or proxies. |
| `AKAHU_DEFAULT_CURRENCY` | `NZD` | Fallback when Akahu does not provide an account currency. |

Environment values take precedence over values stored in an import configuration.

## Fork-specific transaction behaviour

### Internal transfers

When Akahu identifies a transaction as a transfer, the importer compares its opposing account number with the other selected Akahu accounts. If both accounts are mapped to Firefly III accounts, the transaction is imported as an internal transfer and only one side is kept.

If the other account is not selected or mapped, the transaction remains an ordinary deposit or withdrawal so that it is not silently lost.

### Optional mortgage-payment matching

This works like internal-transfer detection, except it keeps the debit side of the transaction instead of the credit side. The result is recorded as a withdrawal from the paying account to the mapped mortgage account.

I use this because of how my bank describes mortgage payments; you probably do not need it. To enable it, set `AKAHU_MORTGAGE_PAYMENT_PATTERN` to a regular expression that matches those descriptions. Leave it empty to disable the feature.

The matching description must refer to a selected, mapped mortgage account. Invalid regular expressions are rejected during configuration validation.

### Pending transactions

Akahu does not assign identifiers to pending transactions, so the importer generates one from the available transaction data and adds a `pending` tag.

When the transaction settles, Akahu assigns it a different identifier. Duplicate detection cannot link the settled transaction to the pending one, so both may be imported into Firefly III. I personally don't use this, but you can enable pending transactions if you are comfortable reconciling those duplicates yourself.

### Refresh behaviour

By default, each import requests a fresh update from Akahu and waits for all selected accounts to finish refreshing. The import fails instead of silently using old data if the refresh does not complete within the configured timeout.

Set `AKAHU_ALWAYS_REFRESH=false` to request an update only when the account data is older than `AKAHU_STALE_REFRESH_HOURS`.

### Duplicate detection

Akahu transaction identifiers are stored as Firefly III external and internal references. New Akahu configurations use the external identifier for duplicate detection.

## Support and upstream updates

Use the [upstream documentation](https://docs.firefly-iii.org/how-to/data-importer/) for standard Data Importer questions. Report problems specific to Akahu or the behaviour above in this repository rather than to the Firefly III maintainers.

Upstream changes are tracked, but they are reviewed and integrated manually. There may be a delay before this fork includes a new upstream release.

## Licence

This fork remains licensed under the [GNU Affero General Public License v3](LICENSE). The original Firefly III Data Importer is maintained by the Firefly III project and its contributors.
