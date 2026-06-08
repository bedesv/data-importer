<?php

declare(strict_types=1);

namespace App\Services\Akahu\Model;

use Carbon\CarbonImmutable;

class Account
{
    public string $id = '';
    public string $name = '';
    public string $formattedAccount = '';
    public string $connectionId = '';
    public string $connectionName = '';
    public string $currency = '';
    public string $status = '';
    public ?string $balance = null;
    public ?string $availableBalance = null;
    public ?string $refreshedTransactions = null;
    public array $raw = [];

    public static function fromArray(array $data): self
    {
        $model                        = new self();
        $model->id                    = (string) ($data['_id'] ?? $data['id'] ?? '');
        $model->name                  = (string) ($data['name'] ?? '');
        $model->formattedAccount      = (string) ($data['formatted_account'] ?? '');
        $model->connectionId          = (string) ($data['_connection'] ?? $data['connection_id'] ?? '');
        $model->connectionName        = (string) ($data['connection']['name'] ?? ($data['connection_name'] ?? ''));
        $model->currency              = (string) ($data['currency'] ?? '');
        $model->status                = (string) ($data['status'] ?? '');
        $model->balance               = isset($data['balance']['current']) ? (string) $data['balance']['current'] : (isset($data['balance']) && is_scalar($data['balance']) ? (string) $data['balance'] : null);
        $model->availableBalance      = isset($data['balance']['available']) ? (string) $data['balance']['available'] : (isset($data['available_balance']) && is_scalar($data['available_balance']) ? (string) $data['available_balance'] : null);
        $model->refreshedTransactions = isset($data['refreshed']['transactions']) ? (string) $data['refreshed']['transactions'] : ($data['refreshed_transactions'] ?? null);
        $model->raw                   = $data['raw'] ?? $data;

        return $model;
    }

    public function toArray(): array
    {
        return [
            'class'                  => self::class,
            '_id'                    => $this->id,
            'name'                   => $this->name,
            'formatted_account'      => $this->formattedAccount,
            '_connection'            => $this->connectionId,
            'connection_name'        => $this->connectionName,
            'currency'               => $this->currency,
            'status'                 => $this->status,
            'balance'                => $this->balance,
            'available_balance'      => $this->availableBalance,
            'refreshed_transactions' => $this->refreshedTransactions,
            'raw'                    => $this->raw,
        ];
    }

    public function getIdentifier(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        if ('' !== $this->name) {
            return $this->name;
        }
        if ('' !== $this->formattedAccount) {
            return $this->formattedAccount;
        }

        return $this->id;
    }

    public function getRefreshedTransactions(): ?CarbonImmutable
    {
        if (null === $this->refreshedTransactions || '' === $this->refreshedTransactions) {
            return null;
        }

        return CarbonImmutable::parse($this->refreshedTransactions);
    }
}
