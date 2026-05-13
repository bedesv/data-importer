<?php

declare(strict_types=1);

namespace App\Services\Akahu\Validation;

use App\Models\ImportJob;
use App\Repository\ImportJob\ImportJobRepository;
use App\Services\Akahu\AkahuService;
use App\Services\Akahu\Credentials;
use App\Services\Shared\Configuration\Configuration;
use App\Services\Shared\Validation\NewJobDataCollectorInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;

final class NewJobDataCollector implements NewJobDataCollectorInterface
{
    public array $input = [];
    private ImportJob $importJob;
    private ImportJobRepository $repository;

    public function __construct()
    {
        $this->repository = new ImportJobRepository();
    }

    public function validate(): MessageBag
    {
        $this->importJob->refreshInstanceIdentifier();
        $errors        = new MessageBag();
        $configuration = $this->prepareConfiguration();
        $credentials   = Credentials::resolve($configuration, $this->input);

        if ('' === $credentials->appToken) {
            $errors->add('akahu_app_token', 'Akahu app token is required.');
        }
        if ('' === $credentials->userToken) {
            $errors->add('akahu_user_token', 'Akahu user token is required.');
        }

        if ($errors->count() > 0) {
            return $errors;
        }

        /** @var AkahuService $service */
        $service = app(AkahuService::class);
        $service->setConfiguration($configuration);

        try {
            $service->validateCredentials();
        } catch (\Throwable $e) {
            Log::error('Akahu credential validation failed.', ['error' => $e->getMessage()]);
            $errors->add('connection', sprintf('Failed to connect to Akahu: %s', $e->getMessage()));
        }

        return $errors;
    }

    public function collectAccounts(): MessageBag
    {
        $this->importJob->refreshInstanceIdentifier();
        $errors        = new MessageBag();
        $configuration = $this->prepareConfiguration();
        /** @var AkahuService $service */
        $service       = app(AkahuService::class);
        $service->setConfiguration($configuration);

        try {
            $accounts = $service->fetchAccounts();
        } catch (\Throwable $e) {
            Log::error('Akahu account collection failed.', ['error' => $e->getMessage()]);
            $errors->add('connection', sprintf('Failed to connect to Akahu: %s', $e->getMessage()));

            return $errors;
        }

        // Trigger a background refresh now so data is ready by the time conversion runs.
        // We don't wait here — RoutineManager::start() will wait if needed.
        $allIds = array_map(static fn ($a) => $a->getIdentifier(), $accounts);
        if ($service->needsRefresh($accounts, $allIds)) {
            try {
                $service->refreshAccounts();
                Log::debug('Akahu: triggered early account refresh during account collection.');
            } catch (\Throwable $e) {
                Log::warning('Akahu: early refresh trigger failed, will retry at conversion.', ['error' => $e->getMessage()]);
            }
        }

        $this->importJob->setServiceAccounts($accounts);
        $this->repository->saveToDisk($this->importJob);

        return $errors;
    }

    public function getFlowName(): string
    {
        return 'akahu';
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    public function setImportJob(ImportJob $importJob): void
    {
        $this->importJob = $importJob;
    }

    private function prepareConfiguration(): Configuration
    {
        $configuration = $this->importJob->getConfiguration();
        $credentials   = Credentials::resolve($configuration, $this->input);
        $credentials->apply($configuration);
        $this->importJob->setConfiguration($configuration);
        $this->repository->saveToDisk($this->importJob);

        return $configuration;
    }
}
