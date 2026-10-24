<?php

declare(strict_types=1);

namespace App\Services\Query\Builders;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TransactionQuery
{
    /**
     * Build a configured Spatie QueryBuilder for Transaction models.
     */
    public static function make(?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for(Transaction::class, $request)
            ->allowedFilters(...[
                AllowedFilter::exact('id'),
                AllowedFilter::exact('transaction_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('currency'),
                AllowedFilter::exact('provider'),
                AllowedFilter::exact('payment_channel'),
                AllowedFilter::exact('property_code'),
                AllowedFilter::exact('user_code'),
                AllowedFilter::exact('user_id'),
                AllowedFilter::partial('purpose'),
                AllowedFilter::partial('reference'),
                AllowedFilter::partial('receipt_number'),
            ])
            ->allowedSorts(...[
                'id',
                'transaction_id',
                'amount_minor',
                'status',
                'created_at',
                'settled_at',
            ])
            ->allowedIncludes(...[
                'user',
                'invoice',
                'paymentMethod',
            ])
            ->allowedFields(...[
                'id',
                'transaction_id',
                'user_code',
                'property_code',
                'purpose',
                'amount_minor',
                'fee_minor',
                'currency',
                'status',
                'payment_method',
                'payment_channel',
                'reference',
                'receipt_number',
                'created_at',
                'user.id',
                'user.name',
                'user.email',
            ])
            ->defaultSort('-created_at');
    }

    /**
     * Get array of configuration metadata for documentation or explorer UI.
     *
     * @return array<string, mixed>
     */
    public static function getMeta(): array
    {
        return [
            'entity' => 'transactions',
            'model' => Transaction::class,
            'default_sort' => '-created_at',
            'allowed_filters' => [
                'id (exact)',
                'transaction_id (exact)',
                'status (exact: settled, pending, failed, refunded)',
                'currency (exact: USD, JMD, EUR)',
                'provider (exact: stripe, wipay, manual)',
                'payment_channel (exact)',
                'property_code (exact)',
                'user_code (exact)',
                'user_id (exact)',
                'purpose (partial)',
                'reference (partial)',
                'receipt_number (partial)',
            ],
            'allowed_sorts' => ['id', 'transaction_id', 'amount_minor', 'status', 'created_at', 'settled_at'],
            'allowed_includes' => ['user', 'invoice', 'paymentMethod'],
            'allowed_fields' => ['id', 'transaction_id', 'user_code', 'property_code', 'purpose', 'amount_minor', 'currency', 'status'],
        ];
    }
}
