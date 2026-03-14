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
use Illuminate\Support\Facades\Log;
use JsonException;
use SensitiveParameter;

class AkahuService
{
    private Configuration $configuration;
    private ClientInterface $client;
    /** @var null|callable */
    private $sleepHandler = null;

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
     * @throws ImporterErrorException
     */
    public function ensureFreshAccounts(array $selectedAccountIds): array
    {
        $accounts = $this->fetchAccounts();
        if (!$this->needsRefresh($accounts, $selectedAccountIds)) {
            return $accounts;
        }

        $this->refreshAccounts();
        $timeoutAt    = CarbonImmutable::now()->addSeconds((int) config('akahu.refresh_wait_timeout_seconds', 180));
        $pollInterval = max(1, (int) config('akahu.refresh_poll_seconds', 10));

        while (CarbonImmutable::now()->lte($timeoutAt)) {
            $this->pause($pollInterval);
            $accounts = $this->fetchAccounts();
            if (!$this->needsRefresh($accounts, $selectedAccountIds)) {
                return $accounts;
            }
        }

        throw new ImporterErrorException('Akahu account refresh did not complete within the configured timeout.');
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
        $cutoff = CarbonImmutable::now()->subHours((int) config('akahu.stale_refresh_hours', 2));

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

        try {
            $response = $this->client->request($method, ltrim($path, '/'), [
                'headers' => $this->getHeaders($credentials->appToken, $credentials->userToken),
                'query'   => array_filter($query, static fn ($value): bool => null !== $value && '' !== $value),
            ]);
        } catch (GuzzleException $e) {
            throw new ImporterErrorException(sprintf('Failed to connect to Akahu: %s', $e->getMessage()), 0, $e);
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

    private function buildDateQuery(): array
    {
        $query = [];

        if ('' !== $this->configuration->getDateNotBefore()) {
            $query['start'] = CarbonImmutable::parse($this->configuration->getDateNotBefore(), config('app.timezone'))
                ->startOfDay()
                ->utc()
                ->toIso8601String();
        }
        if ('' !== $this->configuration->getDateNotAfter()) {
            $query['end'] = CarbonImmutable::parse($this->configuration->getDateNotAfter(), config('app.timezone'))
                ->addDay()
                ->startOfDay()
                ->utc()
                ->toIso8601String();
        }

        return $query;
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

    private function pause(int $seconds): void
    {
        if (is_callable($this->sleepHandler)) {
            call_user_func($this->sleepHandler, $seconds);

            return;
        }
        Log::debug(sprintf('Pause %d second(s) while waiting for Akahu refresh.', $seconds));
        sleep($seconds);
    }
}
