<?php

declare(strict_types=1);

namespace App\Services\Akahu\Model;

class PendingTransaction extends Transaction
{
    public static function fromArray(array $data): self
    {
        $model      = new self();
        $providedId = isset($data['_id']) && '' !== (string) $data['_id'] ? (string) $data['_id'] : null;
        $id         = $providedId ?? self::syntheticIdentifier($data);
        $model->raw = [
            ...$data,
            '_id'        => $id,
            'hash'       => $id,
            'created_at' => $data['updated_at'] ?? null,
        ];

        return $model;
    }

    public static function syntheticIdentifier(array $data): string
    {
        $amount = number_format((float) ($data['amount'] ?? 0), 2, '.', '');
        $source = sprintf(
            'pending-%s-%s-%s-%s-%s-%s',
            (string) ($data['_account'] ?? ''),
            (string) ($data['_user'] ?? ''),
            (string) ($data['_connection'] ?? ''),
            (string) ($data['date'] ?? ''),
            (string) ($data['description'] ?? ''),
            $amount
        );

        return substr(hash('sha256', $source), 0, 24);
    }

    public function isPending(): bool
    {
        return true;
    }
}
