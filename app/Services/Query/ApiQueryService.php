<?php

declare(strict_types=1);

namespace App\Services\Query;

use App\Services\Query\Builders\GatePassQuery;
use App\Services\Query\Builders\TransactionQuery;
use App\Services\Query\Builders\UserQuery;
use App\Services\Query\Builders\VisitorQuery;
use App\Services\Query\Builders\WarningQuery;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Spatie\QueryBuilder\QueryBuilder;

class ApiQueryService
{
    /**
     * Map of supported entities to their query builder classes.
     *
     * @var array<string, class-string>
     */
    protected array $builders = [
        'gate_passes' => GatePassQuery::class,
        'visitors' => VisitorQuery::class,
        'transactions' => TransactionQuery::class,
        'users' => UserQuery::class,
        'warnings' => WarningQuery::class,
    ];

    /**
     * Build QueryBuilder for GatePass models.
     */
    public function forGatePasses(?Request $request = null): QueryBuilder
    {
        return GatePassQuery::make($request);
    }

    /**
     * Build QueryBuilder for Visitor models.
     */
    public function forVisitors(?Request $request = null): QueryBuilder
    {
        return VisitorQuery::make($request);
    }

    /**
     * Build QueryBuilder for Transaction models.
     */
    public function forTransactions(?Request $request = null): QueryBuilder
    {
        return TransactionQuery::make($request);
    }

    /**
     * Build QueryBuilder for User models.
     */
    public function forUsers(?Request $request = null): QueryBuilder
    {
        return UserQuery::make($request);
    }

    /**
     * Build QueryBuilder for Warning models.
     */
    public function forWarnings(?Request $request = null): QueryBuilder
    {
        return WarningQuery::make($request);
    }

    /**
     * Dynamically resolve QueryBuilder by entity key.
     */
    public function forEntity(string $entity, ?Request $request = null): QueryBuilder
    {
        $builderClass = $this->builders[$entity] ?? null;

        if (! $builderClass || ! method_exists($builderClass, 'make')) {
            throw new InvalidArgumentException("Unsupported query builder entity: {$entity}. Available: ".implode(', ', array_keys($this->builders)));
        }

        return $builderClass::make($request);
    }

    /**
     * Get metadata and allowed parameters for all registered query entities.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getEntitiesMeta(): array
    {
        $meta = [];
        foreach ($this->builders as $key => $class) {
            if (method_exists($class, 'getMeta')) {
                $meta[$key] = $class::getMeta();
            }
        }

        return $meta;
    }

    /**
     * Get list of supported query entity keys.
     *
     * @return list<string>
     */
    public function getSupportedEntities(): array
    {
        return array_keys($this->builders);
    }
}
