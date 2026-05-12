<?php

declare(strict_types=1);

namespace App\Services\Akahu\Conversion;

use App\Services\Akahu\Model\Account;
use App\Services\Akahu\Model\Transaction;
use App\Services\Shared\Configuration\Configuration;

final class TransactionTransformer
{
    public function transform(Transaction $transaction, Account $account, Configuration $configuration, array $accountMapping, array $newAccountConfig, array $serviceAccounts = []): array
    {
        if ($this->shouldSkip($transaction, $configuration)) {
            return [];
        }

        $amount            = (string) abs((float) $transaction->getAmount());
        $isIncoming        = (float) $transaction->getAmount() > 0;
        $isInternal        = $this->isInternalTransfer($transaction, $configuration);
        $isMortgagePayment = $this->isMortgagePayment($transaction, $configuration);
        $fireflyAccount    = $this->getMappedAccount($account, $accountMapping, $newAccountConfig);
        $opposingName      = $this->extractOpposingName($transaction);
        $opposingAccount   = $this->findOpposingAccount($transaction, $serviceAccounts);
        if (null === $opposingAccount && $isMortgagePayment) {
            $opposingAccount = $this->findMortgageAccount($transaction, $serviceAccounts);
        }
        $opposingFirefly   = null !== $opposingAccount ? $this->getMappedAccount($opposingAccount, $accountMapping, $newAccountConfig) : ['id' => null, 'name' => $opposingName];
        $source            = $isIncoming ? $opposingFirefly : $fireflyAccount;
        $destination       = $isIncoming ? $fireflyAccount : $opposingFirefly;

        return [
            'type'               => $isMortgagePayment ? 'withdrawal' : ($isInternal ? 'transfer' : ($isIncoming ? 'deposit' : 'withdrawal')),
            'date'               => $this->formatDate($transaction),
            'amount'             => number_format((float) $amount, 12, '.', ''),
            'description'        => $transaction->getDescription(),
            'source_id'          => $source['id'] ?? null,
            'source_name'        => $source['name'] ?? null,
            'destination_id'     => $destination['id'] ?? null,
            'destination_name'   => $destination['name'] ?? null,
            'currency_code'      => '' !== $account->currency ? $account->currency : 'NZD',
            'category_name'      => $isInternal ? null : $this->extractCategory($transaction),
            'reconciled'         => false,
            'notes'              => $this->buildNotes($transaction),
            'tags'               => $this->buildTags($transaction),
            'internal_reference' => $transaction->getIdentifier(),
            'external_id'        => $transaction->getIdentifier(),
            'book_date'          => $this->formatDate($transaction),
            'process_date'       => $this->formatDate($transaction),
        ];
    }

    private function getMappedAccount(Account $account, array $accountMapping, array $newAccountConfig): array
    {
        $mappedId = $accountMapping[$account->id] ?? null;
        $name     = $newAccountConfig[$account->id]['name'] ?? $account->getDisplayName();

        return [
            'id'   => is_int($mappedId) && $mappedId > 0 ? $mappedId : null,
            'name' => $name,
        ];
    }

    private function formatDate(Transaction $transaction): string
    {
        return $transaction->getDate()
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d');
    }

    private function shouldSkip(Transaction $transaction, Configuration $configuration): bool
    {
        if (!$this->isInternalTransfer($transaction, $configuration)) {
            return false;
        }

        $amount = (float) $transaction->getAmount();
        if ($this->isMortgagePayment($transaction, $configuration)) {
            return $amount >= 0;
        }

        return $amount <= 0;
    }

    private function isInternalTransfer(Transaction $transaction, Configuration $configuration): bool
    {
        $prefix = $configuration->getAkahuInternalAccountPrefix();
        if ('' === $prefix || 'TRANSFER' !== $transaction->getType()) {
            return false;
        }
        if ($this->isMortgagePayment($transaction, $configuration)) {
            return true;
        }

        $raw          = $transaction->toArray();
        $otherAccount = (string) (($raw['meta']['other_account'] ?? '') ?: '');
        if ('' !== $otherAccount && str_starts_with($otherAccount, $prefix)) {
            return true;
        }

        return str_contains($transaction->getDescription(), $prefix);
    }

    private function isMortgagePayment(Transaction $transaction, Configuration $configuration): bool
    {
        $pattern = $configuration->getAkahuMortgagePaymentPattern();
        if ('' === $pattern || 'TRANSFER' !== $transaction->getType()) {
            return false;
        }

        return 1 === @preg_match(sprintf('/%s/', trim($pattern, '/')), $transaction->getDescription());
    }

    private function extractOpposingName(Transaction $transaction): string
    {
        $raw = $transaction->toArray();
        if (array_key_exists('merchant', $raw) && is_array($raw['merchant']) && array_key_exists('name', $raw['merchant']) && '' !== (string) $raw['merchant']['name']) {
            return (string) $raw['merchant']['name'];
        }
        if (array_key_exists('meta', $raw) && is_array($raw['meta']) && array_key_exists('other_account', $raw['meta']) && '' !== (string) $raw['meta']['other_account']) {
            return (string) $raw['meta']['other_account'];
        }

        $description = $transaction->getDescription();
        foreach (['EFTPOS ', 'POS ', 'DIRECT DEBIT ', 'DIRECT CREDIT ', 'AUTOMATIC PAYMENT ', 'BILL PAYMENT ', 'INTERNET BANKING '] as $prefix) {
            if (str_starts_with(strtoupper($description), $prefix)) {
                return trim(substr($description, strlen($prefix)));
            }
        }

        return trim($description);
    }

    private function extractCategory(Transaction $transaction): ?string
    {
        $category = $transaction->toArray()['category'] ?? null;
        if (is_string($category) && '' !== $category) {
            return $category;
        }
        if (is_array($category)) {
            foreach (['name', 'group', 'label'] as $key) {
                if (array_key_exists($key, $category) && '' !== (string) $category[$key]) {
                    return (string) $category[$key];
                }
            }
        }

        return null;
    }

    private function buildNotes(Transaction $transaction): ?string
    {
        $raw   = $transaction->toArray();
        $notes = [];
        if ('' !== $transaction->getType()) {
            $notes[] = $transaction->getType();
        }
        foreach (['particulars' => 'Particulars', 'code' => 'Code', 'reference' => 'Ref', 'card_suffix' => 'Card'] as $key => $label) {
            $value = $raw['meta'][$key] ?? null;
            if (null !== $value && '' !== (string) $value) {
                $notes[] = sprintf('%s: %s', $label, $value);
            }
        }

        return 0 === count($notes) ? null : implode(' | ', $notes);
    }

    private function buildTags(Transaction $transaction): array
    {
        $tags = [];
        if ('' !== $transaction->getType()) {
            $tags[] = $transaction->getType();
        }
        if ($transaction->isPending()) {
            $tags[] = 'pending';
        }

        return array_values(array_unique($tags));
    }

    private function findOpposingAccount(Transaction $transaction, array $serviceAccounts): ?Account
    {
        $raw          = $transaction->toArray();
        $otherAccount = (string) (($raw['meta']['other_account'] ?? '') ?: '');
        if ('' === $otherAccount) {
            return null;
        }

        $normalizedOther = $this->normalizeAccountNumber($otherAccount);
        foreach ($serviceAccounts as $serviceAccount) {
            if (!$serviceAccount instanceof Account) {
                continue;
            }
            if ($serviceAccount->getIdentifier() === $transaction->getAccountId()) {
                continue;
            }
            $candidates = [
                $serviceAccount->formattedAccount,
                (string) ($serviceAccount->raw['account_number'] ?? ''),
                (string) ($serviceAccount->raw['meta']['account_number'] ?? ''),
            ];
            foreach ($candidates as $candidate) {
                if ('' === $candidate) {
                    continue;
                }
                if ($this->normalizeAccountNumber($candidate) === $normalizedOther) {
                    return $serviceAccount;
                }
            }
        }

        return null;
    }

    private function findMortgageAccount(Transaction $transaction, array $serviceAccounts): ?Account
    {
        $reference = $this->extractMortgageAccountReference($transaction->getDescription());
        if (null === $reference) {
            return null;
        }

        $normalizedReference = $this->normalizeAccountNumber($reference);
        $referenceSuffix     = $this->accountSuffix($reference);
        $matches             = [];
        foreach ($serviceAccounts as $serviceAccount) {
            if (!$serviceAccount instanceof Account) {
                continue;
            }
            if ($serviceAccount->getIdentifier() === $transaction->getAccountId()) {
                continue;
            }
            $candidates = [
                $serviceAccount->formattedAccount,
                (string) ($serviceAccount->raw['account_number'] ?? ''),
                (string) ($serviceAccount->raw['meta']['account_number'] ?? ''),
            ];
            foreach ($candidates as $candidate) {
                if ('' === $candidate) {
                    continue;
                }
                if ($this->normalizeAccountNumber($candidate) === $normalizedReference || $this->accountSuffix($candidate) === $referenceSuffix) {
                    $matches[$serviceAccount->getIdentifier()] = $serviceAccount;
                    break;
                }
            }
        }

        return 1 === count($matches) ? array_values($matches)[0] : null;
    }

    private function extractMortgageAccountReference(string $description): ?string
    {
        if (1 === preg_match('/\b(?:TO|FR)\s+([0-9-]+)\b/i', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function accountSuffix(string $value): string
    {
        preg_match_all('/\d+/', $value, $matches);
        $parts = $matches[0] ?? [];
        if ([] === $parts) {
            return '';
        }
        $suffix = ltrim((string) end($parts), '0');

        return '' === $suffix ? '0' : $suffix;
    }

    private function normalizeAccountNumber(string $value): string
    {
        preg_match_all('/\d+/', $value, $matches);
        $parts = $matches[0] ?? [];
        if (count($parts) >= 4) {
            $suffix = ltrim($parts[3], '0');
            if ('' === $suffix) {
                $suffix = '0';
            }

            return implode('-', [$parts[0], $parts[1], $parts[2], $suffix]);
        }

        return preg_replace('/\D+/', '', $value) ?? $value;
    }
}
