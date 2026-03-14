<?php

declare(strict_types=1);

namespace App\Services\Akahu\Model;

class PendingTransaction extends Transaction
{
    public static function fromArray(array $data): self
    {
        $model      = new self();
        $synthetic  = self::syntheticIdentifier($data);
        $model->raw = [
            ...$data,
            '_id'        => $synthetic,
            'hash'       => $synthetic,
            'created_at' => $data['updated_at'] ?? null,
        ];

        return $model;
    }

    public static function syntheticIdentifier(array $data): string
    {
        $source = sprintf(
            'pending-%s-%s-%s-%s',
            (string) ($data['_account'] ?? ''),
            (string) ($data['date'] ?? ''),
            (string) ($data['description'] ?? ''),
            (string) ($data['amount'] ?? '')
        );

        return substr(hash('sha256', $source), 0, 24);
    }

    public function isPending(): bool
    {
        return true;
    }
}
