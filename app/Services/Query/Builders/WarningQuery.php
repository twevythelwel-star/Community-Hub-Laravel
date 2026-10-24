<?php

declare(strict_types=1);

namespace App\Services\Query\Builders;

use App\Models\Warning;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WarningQuery
{
    /**
     * Build a configured Spatie QueryBuilder for Warning models.
     */
    public static function make(?Request $request = null): QueryBuilder
    {
        return QueryBuilder::for(Warning::class, $request)
            ->allowedFilters(...[
                AllowedFilter::exact('id'),
                AllowedFilter::exact('author_id'),
                AllowedFilter::partial('title'),
                AllowedFilter::partial('description'),
                AllowedFilter::partial('author_name'),
            ])
            ->allowedSorts(...[
                'id',
                'title',
                'issued_at',
                'created_at',
            ])
            ->allowedIncludes(...[
                'author',
                'responses',
                'media',
            ])
            ->allowedFields(...[
                'id',
                'title',
                'description',
                'author_id',
                'author_name',
                'issued_at',
                'created_at',
                'author.id',
                'author.name',
                'author.email',
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
            'entity' => 'warnings',
            'model' => Warning::class,
            'default_sort' => '-created_at',
            'allowed_filters' => [
                'id (exact)',
                'author_id (exact)',
                'title (partial)',
                'description (partial)',
                'author_name (partial)',
            ],
            'allowed_sorts' => ['id', 'title', 'issued_at', 'created_at'],
            'allowed_includes' => ['author', 'responses', 'media'],
            'allowed_fields' => ['id', 'title', 'description', 'author_id', 'author_name', 'issued_at'],
        ];
    }
}
