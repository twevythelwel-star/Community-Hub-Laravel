<?php

declare(strict_types=1);

namespace App\Services\Query\Builders;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserQuery
{
    /**
     * Build a configured Spatie QueryBuilder for User models.
     */
    public static function make(?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for(User::class, $request)
            ->allowedFilters(...[
                AllowedFilter::exact('id'),
                AllowedFilter::exact('role'),
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
                AllowedFilter::partial('phone'),
                AllowedFilter::partial('lot'),
                AllowedFilter::partial('street'),
            ])
            ->allowedSorts(...[
                'id',
                'name',
                'email',
                'role',
                'lot',
                'created_at',
            ])
            ->allowedIncludes(...[
                'media',
            ])
            ->allowedFields(...[
                'id',
                'name',
                'email',
                'phone',
                'role',
                'lot',
                'street',
                'created_at',
            ])
            ->defaultSort('name');
    }

    /**
     * Get array of configuration metadata for documentation or explorer UI.
     *
     * @return array<string, mixed>
     */
    public static function getMeta(): array
    {
        return [
            'entity' => 'users',
            'model' => User::class,
            'default_sort' => 'name',
            'allowed_filters' => [
                'id (exact)',
                'role (exact: System Admin, Admin, Homeowner, Temporary Homeowner, Security, Staff)',
                'name (partial)',
                'email (partial)',
                'phone (partial)',
                'lot (partial)',
                'street (partial)',
            ],
            'allowed_sorts' => ['id', 'name', 'email', 'role', 'lot', 'created_at'],
            'allowed_includes' => ['media'],
            'allowed_fields' => ['id', 'name', 'email', 'phone', 'role', 'lot', 'street'],
        ];
    }
}
