<?php

declare(strict_types=1);

namespace App\Services\Query\Builders;

use App\Models\Visitor;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class VisitorQuery
{
    /**
     * Build a configured Spatie QueryBuilder for Visitor models.
     */
    public static function make(?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for(Visitor::class, $request)
            ->allowedFilters(...[
                AllowedFilter::exact('id'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::exact('homeowner_id'),
                AllowedFilter::exact('is_blocked'),
                AllowedFilter::partial('name'),
                AllowedFilter::partial('contact'),
                AllowedFilter::partial('vehicle'),
                AllowedFilter::partial('id_number'),
                AllowedFilter::partial('homeowner_name'),
                AllowedFilter::scope('expected'),
            ])
            ->allowedSorts(...[
                'id',
                'name',
                'expected_at',
                'checked_in_at',
                'checked_out_at',
                'created_at',
                'status',
            ])
            ->allowedIncludes(...[
                'homeowner',
                'gatePass',
            ])
            ->allowedFields(...[
                'id',
                'name',
                'contact',
                'vehicle',
                'type',
                'status',
                'expected_at',
                'homeowner_id',
                'homeowner_name',
                'checked_in_at',
                'checked_out_at',
                'created_at',
                'homeowner.id',
                'homeowner.name',
                'homeowner.email',
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
            'entity' => 'visitors',
            'model' => Visitor::class,
            'default_sort' => '-created_at',
            'allowed_filters' => [
                'id (exact)',
                'status (exact: EXPECTED, CHECKED_IN, CHECKED_OUT, EXPIRED, CANCELLED)',
                'type (exact)',
                'homeowner_id (exact)',
                'is_blocked (exact: 0 or 1)',
                'name (partial)',
                'contact (partial)',
                'vehicle (partial)',
                'id_number (partial)',
                'homeowner_name (partial)',
                'expected (scope)',
            ],
            'allowed_sorts' => ['id', 'name', 'expected_at', 'checked_in_at', 'checked_out_at', 'created_at', 'status'],
            'allowed_includes' => ['homeowner', 'gatePass'],
            'allowed_fields' => ['id', 'name', 'contact', 'vehicle', 'type', 'status', 'expected_at', 'homeowner_id', 'homeowner_name'],
        ];
    }
}
