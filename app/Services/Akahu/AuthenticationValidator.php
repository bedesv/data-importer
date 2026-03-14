<?php

declare(strict_types=1);

namespace App\Services\Akahu;

use App\Services\Enums\AuthenticationStatus;
use App\Services\Shared\Authentication\AuthenticationValidatorInterface;
use App\Services\Shared\Configuration\Configuration;

class AuthenticationValidator implements AuthenticationValidatorInterface
{
    public function validate(): AuthenticationStatus
    {
        $configuration = Configuration::make();
        $credentials   = Credentials::resolve($configuration);
        if ('' === $credentials->appToken || '' === $credentials->userToken) {
            return AuthenticationStatus::NODATA;
        }

        $credentials->apply($configuration);
        /** @var AkahuService $service */
        $service = app(AkahuService::class);
        $service->setConfiguration($configuration);

        try {
            $service->validateCredentials();
        } catch (\Throwable) {
            return AuthenticationStatus::ERROR;
        }

        return AuthenticationStatus::AUTHENTICATED;
    }

    public function getData(): array
    {
        return [
            'app_token'  => (string) config('akahu.app_token', ''),
            'user_token' => (string) config('akahu.user_token', ''),
        ];
    }

    public function setData(array $data): void {}
}
