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
        public readonly string $internalAccountPrefix,
        public readonly string $mortgagePaymentPattern,
    ) {}

    public static function resolve(Configuration $configuration, array $input = []): self
    {
        $appToken = self::firstNonEmpty(
            (string) config('akahu.app_token', ''),
            $configuration->getAkahuAppToken()
        );
        $userToken = self::firstNonEmpty(
            (string) config('akahu.user_token', ''),
            $configuration->getAkahuUserToken()
        );
        $internalPrefix = self::firstNonEmpty(
            (string) config('akahu.internal_account_prefix', ''),
            $configuration->getAkahuInternalAccountPrefix()
        );
        $mortgagePattern = self::firstNonEmpty(
            (string) config('akahu.mortgage_payment_pattern', ''),
            $configuration->getAkahuMortgagePaymentPattern()
        );

        return new self($appToken, $userToken, $internalPrefix, $mortgagePattern);
    }

    public function apply(Configuration $configuration): void
    {
        $configuration->setAkahuAppToken($this->appToken);
        $configuration->setAkahuUserToken($this->userToken);
        $configuration->setAkahuInternalAccountPrefix($this->internalAccountPrefix);
        $configuration->setAkahuMortgagePaymentPattern($this->mortgagePaymentPattern);
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
