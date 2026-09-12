<?php

declare(strict_types=1);

namespace App\Services\Akahu;

use App\Exceptions\ImporterErrorException;
use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Model\PendingTransaction;
use App\Services\Akahu\Model\Transaction;
use App\Services\Shared\Configuration\Configuration;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use SensitiveParameter;

class AkahuService
{
    private const int MAX_RATE_LIMIT_RETRIES = 2;

    private Configuration $configuration;
    private ClientInterface $client;
    /** @var null|callable */
    private $sleepHandler              = null;
    private ?CarbonImmutable $deadline = null;
    /** @var array<string> */
    private array $refreshWarnings     = [];

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim((string) config('akahu.base_url', 'https://api.akahu.io/v1'), '/').'/',
            'timeout'  => (float) config('akahu.connection_timeout', 30),
            'verify'   => config('importer.connection.verify'),
        ]);
    }

    public function setConfiguration(Configuration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function setSleepHandler(callable $sleepHandler): void
    {
        $this->sleepHandler = $sleepHandler;
    }

    /**
     * @return array<Account>
     * @throws ImporterErrorException
     */
    public function validateCredentials(): array
    {
        return $this->fetchAccounts();
    }

    /**
     * @return array<Account>
     * @throws ImporterErrorException
     */
    public function fetchAccounts(): array
    {
        $json     = $this->requestJson('GET', 'accounts');
        $accounts = $json['items'] ?? $json;

        if (!is_array($accounts)) {
            throw new ImporterErrorException('Akahu did not return a valid accounts payload.');
        }

        return array_map(static fn (array $account): Account => Account::fromArray($account), array_values($accounts));
    }

    /**
     * Warnings collected during the last ensureFreshAccounts() call, for instance when
     * Akahu declined the refresh and the import continued on the data it already held.
     *
     * @return array<string>
     */
    public function getRefreshWarnings(): array
    {
        return $this->refreshWarnings;
    }

    /**
     * @return array<Account>
     * @throws ImporterErrorException
     */
    public function ensureFreshAccounts(array $selectedAccountIds, bool $force = false, ?CarbonImmutable $forcedTriggerAt = null): array
    {
        $this->refreshWarnings = [];
        // This runs inside the conversion request, so bound the whole operation well
        // below the web server's own timeout rather than letting a stalled refresh hold
        // the request open until nginx returns a gateway timeout.
        $this->deadline        = CarbonImmutable::now()->addSeconds((int) config('akahu.refresh_wait_timeout_seconds', 45));

        try {
            return $this->collectFreshAccounts($selectedAccountIds, $force, $forcedTriggerAt);
        } finally {
            $this->deadline = null;
        }
    }

    /**
     * Whether a refresh was triggered recently enough that Akahu would decline another.
     */
    public function refreshTriggeredRecently(): bool
    {
        $triggeredAt = $this->lastRefreshTriggeredAt();

        return $triggeredAt instanceof CarbonImmutable && $triggeredAt->gte($this->cooldownStart());
    }

    /**
     * @return array<Account>
     * @throws ImporterErrorException
     */
    private function collectFreshAccounts(array $selectedAccountIds, bool $force = false, ?CarbonImmutable $forcedTriggerAt = null): array
    {
        $accounts      = $this->fetchAccounts();
        $alwaysRefresh = $force || (bool) config('akahu.always_refresh', true);

        // When always_refresh is disabled, fall back to the staleness heuristic and
        // skip the refresh entirely when the selected accounts are recent enough.
        if (!$alwaysRefresh && !$this->needsRefresh($accounts, $selectedAccountIds)) {
            return $accounts;
        }

        // Akahu ignores a refresh that arrives too soon after the previous one. Asking
        // anyway produces a no-op we would then poll on until the deadline, so data from
        // inside the cooldown window counts as already fresh. A forced refresh skips this
        // shortcut: the user asked for new data, so old-but-recent data will not do.
        if (!$force && $this->refreshedSince($accounts, $selectedAccountIds, $this->cooldownStart())) {
            Log::debug('Akahu: selected accounts were refreshed within the cooldown window, no refresh needed.');

            return $accounts;
        }

        // A forced run may only wait on a trigger it fired itself: the cooldown marker is
        // shared between imports, so trusting it here would settle against an older run's
        // refresh and hand back the stale data the user forced a refresh to avoid.
        $triggeredAt   = $force ? $forcedTriggerAt : $this->lastRefreshTriggeredAt();
        if ($triggeredAt instanceof CarbonImmutable && $triggeredAt->gte($this->cooldownStart())) {
            // A refresh fired during account collection has not landed yet. Wait for that
            // one instead of asking Akahu for a second refresh it would only decline.
            Log::debug('Akahu: waiting for the refresh triggered during account collection.');
        } else {
            $triggeredAt = CarbonImmutable::now();

            try {
                $this->refreshAccounts();
            } catch (ImporterErrorException $e) {
                Log::warning('Akahu: refresh request was declined.', ['error' => $e->getMessage()]);

                return $this->useExistingData($accounts, $selectedAccountIds, 'Akahu declined the refresh request');
            }
        }

        return $this->waitForRefresh($selectedAccountIds, $alwaysRefresh, $triggeredAt);
    }

    /**
     * @return array<Account>
     * @throws ImporterErrorException
     */
    private function waitForRefresh(array $selectedAccountIds, bool $alwaysRefresh, CarbonImmutable $triggeredAt): array
    {
        $deadline     = $this->deadline ?? CarbonImmutable::now();
        $pollInterval = max(1, (int) config('akahu.refresh_poll_seconds', 10));

        while (true) {
            $accounts = $this->fetchAccounts();
            // When forcing a refresh we wait for the newly triggered refresh to land
            // (each account refreshed at/after the trigger); otherwise the staleness
            // window is enough to consider the data fresh.
            $settled  = $alwaysRefresh
                ? $this->refreshedSince($accounts, $selectedAccountIds, $triggeredAt)
                : !$this->needsRefresh($accounts, $selectedAccountIds);
            if ($settled) {
                return $accounts;
            }
            if (CarbonImmutable::now()->gte($deadline)) {
                return $this->useExistingData($accounts, $selectedAccountIds, 'Akahu did not finish the refresh in time');
            }
            $this->pause($pollInterval);
        }
    }

    /**
     * A refresh we could not complete is only fatal when the data Akahu already holds is
     * too old to import. Otherwise the import continues and the user is warned.
     *
     * @param array<Account> $accounts
     * @return array<Account>
     * @throws ImporterErrorException
     */
    private function useExistingData(array $accounts, array $selectedAccountIds, string $reason): array
    {
        if ($this->needsRefresh($accounts, $selectedAccountIds)) {
            throw new ImporterErrorException(sprintf('%s, and the data it already holds is too old to import.', $reason));
        }

        $warning                 = sprintf(
            '%s. Continuing with the account data Akahu already holds, which is less than %d hour(s) old.',
            $reason,
            (int) config('akahu.stale_refresh_hours', 2)
        );
        Log::warning($warning);
        $this->refreshWarnings[] = $warning;

        return $accounts;
    }

    private function cooldownStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes(max(0, (int) config('akahu.refresh_cooldown_minutes', 15)));
    }

    /**
     * Whether every selected account has been refreshed at or after $since.
     *
     * @param array<Account> $accounts
     */
    private function refreshedSince(array $accounts, array $selectedAccountIds, CarbonImmutable $since): bool
    {
        $byId = [];
        foreach ($accounts as $account) {
            $byId[$account->getIdentifier()] = $account;
        }

        foreach ($selectedAccountIds as $selectedId) {
            $account = $byId[$selectedId] ?? null;
            if (null === $account) {
                return false;
            }
            $refreshed = $account->getRefreshedTransactions();
            if (null === $refreshed || $refreshed->lt($since)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<Transaction>
     * @throws ImporterErrorException
     */
    public function fetchTransactions(string $accountId): array
    {
        $query        = $this->buildDateQuery();
        $transactions = [];

        do {
            $json      = $this->requestJson('GET', sprintf('accounts/%s/transactions', rawurlencode($accountId)), $query);
            $items     = $json['items'] ?? [];
            $cursor    = $json['cursor']['next'] ?? null;
            foreach ($items as $item) {
                $transactions[] = Transaction::fromArray($item);
            }
            $query['cursor'] = $cursor;
        } while (null !== $query['cursor'] && '' !== $query['cursor']);

        return $transactions;
    }

    /**
     * @return array<PendingTransaction>
     * @throws ImporterErrorException
     */
    public function fetchPendingTransactions(string $accountId): array
    {
        $json    = $this->requestJson('GET', sprintf('accounts/%s/transactions/pending', rawurlencode($accountId)));
        $items   = $json['items'] ?? $json;
        $pending = [];

        foreach ($items as $item) {
            $pending[] = PendingTransaction::fromArray($item);
        }

        return $pending;
    }

    /**
     * @param array<Account> $accounts
     */
    public function needsRefresh(array $accounts, array $selectedAccountIds): bool
    {
        $cutoff      = CarbonImmutable::now()->subHours((int) config('akahu.stale_refresh_hours', 2));
        $returnedIds = array_map(static fn (Account $a): string => $a->getIdentifier(), $accounts);

        foreach ($selectedAccountIds as $selectedId) {
            if (!in_array($selectedId, $returnedIds, true)) {
                return true;
            }
        }

        foreach ($accounts as $account) {
            if (!in_array($account->getIdentifier(), $selectedAccountIds, true)) {
                continue;
            }
            $refreshed = $account->getRefreshedTransactions();
            if (null === $refreshed || $refreshed->lt($cutoff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ImporterErrorException
     */
    public function refreshAccounts(): void
    {
        $this->requestJson('POST', 'refresh');
        $this->rememberRefreshTrigger();
    }

    /**
     * Fire a refresh under a short budget. The eager trigger runs inside the request that
     * renders the configuration page, and a rate-limited attempt would otherwise spend two
     * Retry-After waits of up to a minute each holding that page open. Failing fast is
     * cheap here: conversion retries the refresh and warns if that is declined too.
     *
     * @throws ImporterErrorException
     */
    public function triggerRefreshWithin(int $seconds): void
    {
        $this->deadline = CarbonImmutable::now()->addSeconds(max(0, $seconds));

        try {
            $this->refreshAccounts();
        } finally {
            $this->deadline = null;
        }
    }

    private function lastRefreshTriggeredAt(): ?CarbonImmutable
    {
        $value = Cache::get($this->refreshTriggerCacheKey());
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function rememberRefreshTrigger(): void
    {
        $minutes = max(1, (int) config('akahu.refresh_cooldown_minutes', 15));
        Cache::put($this->refreshTriggerCacheKey(), CarbonImmutable::now()->toIso8601String(), $minutes * 60);
    }

    /**
     * Scoped to the user token so separate Akahu users never share a cooldown. The token
     * is hashed so it does not reach the cache store in the clear.
     */
    private function refreshTriggerCacheKey(): string
    {
        $credentials = Credentials::resolve($this->configuration);

        return sprintf('akahu-refresh-triggered-%s', hash('sha256', $credentials->userToken));
    }

    /**
     * @throws ImporterErrorException
     */
    private function requestJson(string $method, string $path, array $query = []): array
    {
        $credentials = Credentials::resolve($this->configuration);
        if ('' === $credentials->appToken || '' === $credentials->userToken) {
            throw new ImporterErrorException('Akahu credentials are incomplete.');
        }

        $rateLimitRetries = 0;
        while (true) {
            $options = [
                'headers' => $this->getHeaders($credentials->appToken, $credentials->userToken),
                'query'   => array_filter($query, static fn ($value): bool => null !== $value && '' !== $value),
            ];
            // Without this the client's own timeout governs each attempt, so a stalled
            // Akahu holds the caller far past the deadline it asked us to respect.
            $timeout = $this->requestTimeout();
            if (null !== $timeout) {
                $options['timeout'] = $timeout;
            }

            try {
                $response = $this->client->request($method, ltrim($path, '/'), $options);
                break;
            } catch (RequestException $e) {
                $response = $e->getResponse();
                if (null === $response) {
                    throw new ImporterErrorException('Failed to connect to Akahu.', 0, $e);
                }

                $this->logHttpFailure($method, $path, $response->getStatusCode(), (string) $response->getBody());
                // A retry is optional; the deadline is not. Once it has passed, the caller
                // is out of time and another attempt would only spend budget it no longer
                // has. The first attempt is always made, however spent the budget already
                // is, so callers still get one honest answer.
                if (429 === $response->getStatusCode() && $rateLimitRetries < self::MAX_RATE_LIMIT_RETRIES && !$this->deadlineHasPassed()) {
                    ++$rateLimitRetries;
                    $this->pause($this->retryAfterSeconds($response->getHeaderLine('Retry-After')));
                    continue;
                }

                throw new ImporterErrorException(sprintf('Akahu API request failed with HTTP %d.', $response->getStatusCode()), 0, $e);
            } catch (GuzzleException $e) {
                throw new ImporterErrorException('Failed to connect to Akahu.', 0, $e);
            }
        }

        $body = (string) $response->getBody();
        if ('' === trim($body)) {
            return [];
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ImporterErrorException('Akahu returned invalid JSON.', 0, $e);
        }

        if (isset($json['success']) && false === $json['success']) {
            $message = (string) (($json['message'] ?? $json['error'] ?? 'Unknown Akahu error'));
            throw new ImporterErrorException(sprintf('Akahu request failed: %s', $message));
        }
        if (isset($json['item']) && is_array($json['item'])) {
            return $json['item'];
        }

        return $json;
    }

    /**
     * Akahu treats the window as (start, end]: `start` is exclusive, `end` is inclusive.
     * Banks that report date-only transactions (TSB, for one) get stamped at exactly local
     * midnight, so a `start` sitting on the boundary drops every one of them on the first
     * day, and an `end` on the next day's boundary pulls in a day that was not asked for.
     * Stepping both bounds back one millisecond turns the window into the [start, end] the
     * rest of the importer assumes.
     */
    private function buildDateQuery(): array
    {
        $query = [];

        if ('' !== $this->configuration->getDateNotBefore()) {
            $query['start'] = self::exclusiveBound(
                CarbonImmutable::parse($this->configuration->getDateNotBefore(), config('app.timezone'))
                    ->startOfDay()
            );
        }
        if ('' !== $this->configuration->getDateNotAfter()) {
            $query['end'] = self::exclusiveBound(
                CarbonImmutable::parse($this->configuration->getDateNotAfter(), config('app.timezone'))
                    ->addDay()
                    ->startOfDay()
            );
        }

        return $query;
    }

    /**
     * Millisecond precision matters here: Akahu compares the instant, and its own timestamps
     * carry milliseconds, so a whole-second bound would still land on the boundary.
     */
    private static function exclusiveBound(CarbonImmutable $moment): string
    {
        return $moment->utc()->subMilliseconds(1)->format('Y-m-d\TH:i:s.v\Z');
    }

    private function getHeaders(#[SensitiveParameter] string $appToken, #[SensitiveParameter] string $userToken): array
    {
        return [
            'Accept'        => 'application/json',
            'Authorization' => sprintf('Bearer %s', $userToken),
            'X-Akahu-ID'    => $appToken,
            'User-Agent'    => sprintf('FF3-data-importer/%s', config('importer.version')),
        ];
    }

    private function retryAfterSeconds(string $retryAfter): int
    {
        $retryAfter = trim($retryAfter);
        if ('' === $retryAfter) {
            return 1;
        }
        if (ctype_digit($retryAfter)) {
            return max(1, min((int) $retryAfter, 60));
        }

        try {
            $seconds = CarbonImmutable::parse($retryAfter)->diffInSeconds(CarbonImmutable::now(), false) * -1;

            return max(1, min((int) $seconds, 60));
        } catch (\Throwable) {
            return 1;
        }
    }

    private function logHttpFailure(string $method, string $path, int $statusCode, string $body): void
    {
        Log::warning('Akahu HTTP request failed.', [
            'method' => $method,
            'path'   => ltrim($path, '/'),
            'status' => $statusCode,
            'body'   => substr($body, 0, 1000),
        ]);
    }

    private function deadlineHasPassed(): bool
    {
        return $this->deadline instanceof CarbonImmutable && CarbonImmutable::now()->gte($this->deadline);
    }

    /**
     * How long a single HTTP attempt may take, or null when no deadline is in force. Never
     * returns zero: Guzzle reads that as "no timeout", and a doomed attempt should still be
     * given the second it needs to fail honestly rather than hang.
     */
    private function requestTimeout(): ?float
    {
        if (!$this->deadline instanceof CarbonImmutable) {
            return null;
        }
        $remaining = (float) CarbonImmutable::now()->diffInSeconds($this->deadline, false);

        return max(1.0, min((float) config('akahu.connection_timeout', 30), $remaining));
    }

    private function pause(int $seconds): void
    {
        if ($this->deadline instanceof CarbonImmutable) {
            // Never sleep past the deadline; the caller is holding an HTTP request open.
            // This also caps the Retry-After waits of a rate-limited request.
            $seconds = min($seconds, (int) CarbonImmutable::now()->diffInSeconds($this->deadline, false));
        }
        if ($seconds < 1) {
            return;
        }
        if (is_callable($this->sleepHandler)) {
            call_user_func($this->sleepHandler, $seconds);

            return;
        }
        Log::debug(sprintf('Pause %d second(s) while waiting for Akahu refresh.', $seconds));
        sleep($seconds);
    }
}
