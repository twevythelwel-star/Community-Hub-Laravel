<?php

declare(strict_types=1);

namespace App\Services\Query\Builders;

use App\Models\GatePass;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class GatePassQuery
{
    /**
     * Build a configured Spatie QueryBuilder for GatePass models.
     */
    public static function make(?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for(GatePass::class, $request)
            ->allowedFilters(...[
                AllowedFilter::exact('id'),
                AllowedFilter::exact('pass_id'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('category'),
                AllowedFilter::exact('user_id'),
                AllowedFilter::exact('visitor_id'),
                AllowedFilter::partial('holder_name'),
                AllowedFilter::partial('property'),
                AllowedFilter::scope('active'),
            ])
            ->allowedSorts(...[
                'id',
                'pass_id',
                'holder_name',
                'created_at',
                'valid_from',
                'valid_until',
                'status',
            ])
            ->allowedIncludes(...[
                'user',
                'visitor',
                'revoker',
                'transitions',
            ])
            ->allowedFields(...[
                'id',
                'pass_id',
                'holder_name',
                'property',
                'status',
                'category',
                'user_id',
                'visitor_id',
                'valid_from',
                'valid_until',
                'created_at',
                'updated_at',
                'user.id',
                'user.name',
                'user.email',
                'visitor.id',
                'visitor.name',
                'visitor.contact',
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
            'entity' => 'gate_passes',
            'model' => GatePass::class,
            'default_sort' => '-created_at',
            'allowed_filters' => [
                'id (exact)',
                'pass_id (exact)',
                'status (exact: ACTIVE, CHECKED_IN, EXPIRED, REVOKED)',
                'category (exact: VISITOR, CONTRACTOR, HOMEOWNER, etc.)',
                'user_id (exact)',
                'visitor_id (exact)',
                'holder_name (partial)',
                'property (partial)',
                'active (scope)',
            ],
            'allowed_sorts' => ['id', 'pass_id', 'holder_name', 'created_at', 'valid_from', 'valid_until', 'status'],
            'allowed_includes' => ['user', 'visitor', 'revoker', 'transitions'],
            'allowed_fields' => ['id', 'pass_id', 'holder_name', 'property', 'status', 'category', 'user_id', 'visitor_id', 'valid_from', 'valid_until'],
        ];
    }
}
