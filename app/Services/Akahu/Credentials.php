<?php

declare(strict_types=1);

namespace App\Services\Akahu;

use App\Services\Shared\Configuration\Configuration;
use SensitiveParameter;

class Credentials
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $appToken,
        #[SensitiveParameter]
        public readonly string $userToken,
    ) {}

    public static function resolve(Configuration $configuration, array $input = []): self
    {
        $appToken = self::firstNonEmpty(
            (string) config('akahu.app_token', ''),
            $input['akahu_app_token'] ?? '',
            $configuration->getAkahuAppToken(),
        );
        $userToken = self::firstNonEmpty(
            (string) config('akahu.user_token', ''),
            $input['akahu_user_token'] ?? '',
            $configuration->getAkahuUserToken(),
        );
        return new self($appToken, $userToken);
    }

    public function apply(Configuration $configuration): void
    {
        $configuration->setAkahuAppToken($this->appToken);
        $configuration->setAkahuUserToken($this->userToken);
    }

    private static function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ('' !== trim($value)) {
                return trim($value);
            }
        }

        return '';
    }
}
