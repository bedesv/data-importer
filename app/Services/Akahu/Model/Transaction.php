<?php

declare(strict_types=1);

namespace App\Services\Akahu\Model;

use Carbon\Carbon;

class Transaction
{
    public array $raw = [];

    public static function fromArray(array $data): self
    {
        $model      = new self();
        $model->raw = $data;

        return $model;
    }

    public function toArray(): array
    {
        return $this->raw;
    }

    public function getIdentifier(): string
    {
        return (string) ($this->raw['_id'] ?? '');
    }

    public function getAccountId(): string
    {
        return (string) ($this->raw['_account'] ?? '');
    }

    public function getAmount(): string
    {
        return (string) ($this->raw['amount'] ?? '0');
    }

    public function getDate(): Carbon
    {
        return Carbon::parse((string) ($this->raw['date'] ?? 'now'));
    }

    public function getDescription(): string
    {
        return (string) ($this->raw['description'] ?? '(no description)');
    }

    public function getType(): string
    {
        return (string) ($this->raw['type'] ?? '');
    }

    public function isPending(): bool
    {
        return false;
    }
}
