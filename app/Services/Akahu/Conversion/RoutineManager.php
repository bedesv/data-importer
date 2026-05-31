<?php

declare(strict_types=1);

namespace App\Services\Akahu\Conversion;

use App\Exceptions\ImporterErrorException;
use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Akahu\AkahuService;
use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Model\Transaction;
use App\Services\Shared\Conversion\CreatesAccounts;
use App\Services\Shared\Conversion\RoutineManagerInterface;
use Illuminate\Support\Facades\Log;
use Override;

final class RoutineManager implements RoutineManagerInterface
{
    use CreatesAccounts;

    private readonly AkahuService $service;
    private readonly TransactionTransformer $transformer;
    protected ImportJobRepository $repository;
    private ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob    = $importJob;
        $this->repository   = new ImportJobRepository();
        $this->service      = app(AkahuService::class);
        $this->transformer  = new TransactionTransformer();
        $this->importJob->refreshInstanceIdentifier();
        $this->service->setConfiguration($this->importJob->getConfiguration());
    }

    #[Override]
    public function getServiceAccounts(): array
    {
        return $this->importJob->getServiceAccounts();
    }

    /**
     * @throws ImporterErrorException
     */
    public function start(): array
    {
        $this->existingServiceAccounts = $this->getServiceAccounts();
        $configuration                 = $this->importJob->getConfiguration();
        $accountMapping                = $configuration->getAccounts();
        $selectedIds                   = array_keys($accountMapping);

        $freshAccounts                 = $this->service->ensureFreshAccounts($selectedIds);
        if (0 !== count($freshAccounts)) {
            $this->importJob->setServiceAccounts($freshAccounts);
            $this->existingServiceAccounts = $freshAccounts;
            $this->repository->saveToDisk($this->importJob);
        }

        foreach ($accountMapping as $serviceAccountId => $applicationAccountId) {
            if (0 === $applicationAccountId) {
                $this->createNewAccount($serviceAccountId);
            }
        }

        $configuration                 = $this->importJob->getConfiguration();
        $accountMapping                = $configuration->getAccounts();

        $transactionGroups            = [];
        foreach ($accountMapping as $serviceAccountId => $applicationAccountId) {
            $account = $this->findAccount($serviceAccountId);
            if (!$account instanceof Account) {
                Log::warning(sprintf('Cannot find Akahu account "%s" in import job.', $serviceAccountId));

                continue;
            }

            $transactions = $this->service->fetchTransactions($serviceAccountId);
            if ($configuration->getPendingTransactions()) {
                $transactions = [...$transactions, ...$this->service->fetchPendingTransactions($serviceAccountId)];
            }

            foreach ($transactions as $transaction) {
                $converted = $this->transformer->transform(
                    $transaction,
                    $account,
                    $configuration,
                    $configuration->getAccounts(),
                    $configuration->getNewAccounts(),
                    $this->existingServiceAccounts
                );
                if ([] === $converted) {
                    continue;
                }
                $transactionGroups[] = [
                    'error_if_duplicate_hash' => $configuration->isIgnoreDuplicateTransactions(),
                    'apply_rules'             => $configuration->isRules(),
                    'fire_webhooks'           => $configuration->isWebhooks(),
                    'group_title'             => null,
                    'transactions'            => [$converted],
                ];
            }
        }

        return $transactionGroups;
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    private function findAccount(string $serviceAccountId): ?Account
    {
        foreach ($this->existingServiceAccounts as $account) {
            if ($account instanceof Account && $account->getIdentifier() === $serviceAccountId) {
                return $account;
            }
        }

        return null;
    }
}
