<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\GatePass;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Warning;
use App\Support\EntityAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class UniversalSearchService
{
    /**
     * Map of supported entity keys to their model classes.
     *
     * @var array<string, class-string>
     */
    protected array $searchableModels = [
        'users' => User::class,
        'gate_passes' => GatePass::class,
        'warnings' => Warning::class,
        'transactions' => Transaction::class,
        'visitors' => Visitor::class,
    ];

    /**
     * Supported search engine drivers in Laravel Scout.
     *
     * @var list<string>
     */
    protected array $supportedDrivers = [
        'database',
        'meilisearch',
        'algolia',
        'typesense',
        'collection',
    ];

    /**
     * Perform a universal multi-entity search across configured Scout models.
     *
     * @param  string  $query  Search query
     * @param  array<int, string>  $types  Entity keys to search (empty for all)
     * @param  int  $limit  Max results per entity type
     * @return array{
     *     query: string,
     *     total: int,
     *     driver: string,
     *     results: array<string, array<int, array<string, mixed>>>,
     *     flattened: array<int, array<string, mixed>>
     * }
     */
    public function search(string $query, array $types = [], int $limit = 10, ?User $user = null): array
    {
        $trimmed = trim($query);
        $driver = $this->getDriver();

        $emptyResponse = [
            'query' => $trimmed,
            'total' => 0,
            'driver' => $driver,
            'results' => [
                'users' => [],
                'gate_passes' => [],
                'warnings' => [],
                'transactions' => [],
                'visitors' => [],
            ],
            'flattened' => [],
        ];

        if ($trimmed === '') {
            return $emptyResponse;
        }

        // No user, no results: a caller that forgets to pass one must not see everything.
        $permitted = $this->searchableTypesFor($user);

        $activeTypes = empty($types)
            ? $permitted
            : array_values(array_intersect($types, $permitted));

        $results = $emptyResponse['results'];
        $flattened = [];

        foreach ($activeTypes as $type) {
            $formatted = match ($type) {
                'users' => $this->formatUserResults($this->searchUsers($trimmed, $limit)),
                'gate_passes' => $this->formatGatePassResults($this->searchGatePasses($trimmed, $limit)),
                'warnings' => $this->formatWarningResults($this->searchWarnings($trimmed, $limit)),
                'transactions' => $this->formatTransactionResults($this->searchTransactions($trimmed, $limit)),
                'visitors' => $this->formatVisitorResults($this->searchVisitors($trimmed, $limit)),
                default => [],
            };

            $results[$type] = $formatted;
            foreach ($formatted as $item) {
                $flattened[] = $item;
            }
        }

        return [
            'query' => $trimmed,
            'total' => count($flattened),
            'driver' => $driver,
            'results' => $results,
            'flattened' => $flattened,
        ];
    }

    /**
     * The entity types this user may search; see EntityAccess.
     *
     * @return list<string>
     */
    public function searchableTypesFor(?User $user): array
    {
        return EntityAccess::visibleTo($user);
    }

    /**
     * Search Users/Residents using Scout.
     */
    public function searchUsers(string $query, int $limit = 10): Collection
    {
        return User::search($query)->take($limit)->get();
    }

    /**
     * Search Gate Passes using Scout.
     */
    public function searchGatePasses(string $query, int $limit = 10): Collection
    {
        return GatePass::search($query)->take($limit)->get();
    }

    /**
     * Search Warnings using Scout.
     */
    public function searchWarnings(string $query, int $limit = 10): Collection
    {
        return Warning::search($query)->take($limit)->get();
    }

    /**
     * Search Transactions using Scout.
     */
    public function searchTransactions(string $query, int $limit = 10): Collection
    {
        return Transaction::search($query)->take($limit)->get();
    }

    /**
     * Search Visitors using Scout.
     */
    public function searchVisitors(string $query, int $limit = 10): Collection
    {
        return Visitor::search($query)->take($limit)->get();
    }

    /**
     * Format User models to normalized search result objects.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatUserResults(Collection $models): array
    {
        return $models->map(function (User $user) {
            $userRoleStr = $user->role instanceof \BackedEnum ? $user->role->value : (string) ($user->role ?? 'resident');
            $roleLabel = ucfirst($userRoleStr);
            $subtitle = $user->email ?? '';
            if (! empty($user->phone)) {
                $subtitle .= ' • '.$user->phone;
            }
            if (! empty($user->lot)) {
                $subtitle .= ' (Lot '.$user->lot.')';
            }

            $url = Route::has('dashboard.directory')
                ? route('dashboard.directory', ['search' => $user->email])
                : '/dashboard/directory';

            return [
                'id' => $user->id,
                'type' => 'user',
                'category' => 'Residents & Staff',
                'title' => $user->name,
                'subtitle' => $subtitle,
                'badge' => $roleLabel,
                'badge_color' => $userRoleStr === 'admin' ? 'purple' : ($userRoleStr === 'guard' ? 'blue' : 'green'),
                'icon' => 'user',
                'url' => $url,
                'metadata' => [
                    'role' => $userRoleStr,
                    'lot' => $user->lot,
                    'street' => $user->street,
                ],
            ];
        })->values()->all();
    }

    /**
     * Format GatePass models to normalized search result objects.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatGatePassResults(Collection $models): array
    {
        return $models->map(function (GatePass $pass) {
            $statusVal = $pass->status instanceof \BackedEnum ? $pass->status->value : (string) $pass->status;
            $title = 'Pass #'.($pass->pass_id ?? (string) $pass->id);
            if (! empty($pass->holder_name)) {
                $title .= ' — '.$pass->holder_name;
            }

            $categoryStr = $pass->category instanceof \BackedEnum ? $pass->category->value : (string) ($pass->category ?? '');

            $subtitleParts = array_filter([
                $pass->property ? 'Property: '.$pass->property : null,
                $categoryStr !== '' ? 'Type: '.ucfirst(strtolower($categoryStr)) : null,
            ]);

            $url = Route::has('dashboard.gate-pass')
                ? route('dashboard.gate-pass', ['pass' => $pass->pass_id])
                : '/dashboard/gate-pass';

            return [
                'id' => $pass->id,
                'type' => 'gate_pass',
                'category' => 'Gate Passes',
                'title' => $title,
                'subtitle' => implode(' • ', $subtitleParts),
                'badge' => ucfirst($statusVal),
                'badge_color' => in_array($statusVal, ['active', 'checked_in'], true) ? 'emerald' : 'amber',
                'icon' => 'shield',
                'url' => $url,
                'metadata' => [
                    'pass_id' => $pass->pass_id,
                    'status' => $statusVal,
                ],
            ];
        })->values()->all();
    }

    /**
     * Format Warning models to normalized search result objects.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatWarningResults(Collection $models): array
    {
        return $models->map(function (Warning $warning) {
            $url = Route::has('dashboard.warnings')
                ? route('dashboard.warnings', ['id' => $warning->id])
                : '/dashboard/warnings';

            return [
                'id' => $warning->id,
                'type' => 'warning',
                'category' => 'Community Warnings',
                'title' => $warning->title,
                'subtitle' => Str::limit($warning->description, 70).' • By '.($warning->author_name ?? 'Staff'),
                'badge' => 'Alert',
                'badge_color' => 'rose',
                'icon' => 'alert-triangle',
                'url' => $url,
                'metadata' => [
                    'author' => $warning->author_name,
                ],
            ];
        })->values()->all();
    }

    /**
     * Format Transaction models to normalized search result objects.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatTransactionResults(Collection $models): array
    {
        return $models->map(function (Transaction $tx) {
            $formattedAmount = '$'.number_format(($tx->amount_minor ?? 0) / 100, 2).' '.strtoupper((string) ($tx->currency ?? 'USD'));
            $title = 'TX #'.($tx->transaction_id ?? (string) $tx->id);
            if (! empty($tx->purpose)) {
                $title .= ' — '.$tx->purpose;
            }

            $subtitleParts = array_filter([
                $formattedAmount,
                $tx->reference ? 'Ref: '.$tx->reference : null,
                $tx->property_code ? 'Lot: '.$tx->property_code : null,
            ]);

            $url = Route::has('dashboard.billing')
                ? route('dashboard.billing', ['tx' => $tx->transaction_id])
                : '/dashboard/billing';

            return [
                'id' => $tx->id,
                'type' => 'transaction',
                'category' => 'Billing & Ledger',
                'title' => $title,
                'subtitle' => implode(' • ', $subtitleParts),
                'badge' => ucfirst((string) $tx->status),
                'badge_color' => $tx->status === 'settled' ? 'emerald' : ($tx->status === 'failed' ? 'rose' : 'blue'),
                'icon' => 'credit-card',
                'url' => $url,
                'metadata' => [
                    'transaction_id' => $tx->transaction_id,
                    'amount_minor' => $tx->amount_minor,
                ],
            ];
        })->values()->all();
    }

    /**
     * Format Visitor models to normalized search result objects.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatVisitorResults(Collection $models): array
    {
        return $models->map(function (Visitor $visitor) {
            $statusVal = $visitor->status instanceof \BackedEnum ? $visitor->status->value : (string) $visitor->status;
            $subtitleParts = array_filter([
                $visitor->contact,
                $visitor->vehicle ? 'Vehicle: '.$visitor->vehicle : null,
                $visitor->homeowner_name ? 'Host: '.$visitor->homeowner_name : null,
            ]);

            $url = Route::has('dashboard.visitors')
                ? route('dashboard.visitors', ['search' => $visitor->name])
                : '/dashboard/visitors';

            return [
                'id' => $visitor->id,
                'type' => 'visitor',
                'category' => 'Visitors & Guests',
                'title' => $visitor->name,
                'subtitle' => implode(' • ', $subtitleParts),
                'badge' => ucfirst($statusVal),
                'badge_color' => $statusVal === 'checked_in' ? 'emerald' : 'blue',
                'icon' => 'user-check',
                'url' => $url,
                'metadata' => [
                    'contact' => $visitor->contact,
                    'vehicle' => $visitor->vehicle,
                ],
            ];
        })->values()->all();
    }

    /**
     * Get the active Scout search driver.
     */
    public function getDriver(): string
    {
        return (string) config('scout.driver', 'database');
    }

    /**
     * Get list of supported Scout drivers.
     *
     * @return list<string>
     */
    public function getSupportedDrivers(): array
    {
        return $this->supportedDrivers;
    }

    /**
     * Get comprehensive search engine diagnostics and driver information.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(): array
    {
        $driver = $this->getDriver();

        return [
            'active_driver' => $driver,
            'is_external_engine' => in_array($driver, ['meilisearch', 'algolia', 'typesense'], true),
            'queue_indexing' => (bool) config('scout.queue', false),
            'index_prefix' => (string) config('scout.prefix', ''),
            'soft_deletes' => (bool) config('scout.soft_delete', false),
            'searchable_models' => array_keys($this->searchableModels),
            'supported_drivers' => $this->supportedDrivers,
            'driver_configurations' => [
                'database' => [
                    'configured' => true,
                    'description' => 'Built-in SQL full-text / like matching without external dependencies.',
                ],
                'meilisearch' => [
                    'configured' => ! empty(config('scout.meilisearch.host')),
                    'host' => config('scout.meilisearch.host', 'http://localhost:7700'),
                    'description' => 'Ultra-fast typo-tolerant open-source search engine.',
                ],
                'algolia' => [
                    'configured' => ! empty(config('scout.algolia.id')),
                    'description' => 'Hosted enterprise search API with global CDN acceleration.',
                ],
                'typesense' => [
                    'configured' => ! empty(config('scout.typesense.client-settings.nodes.0.host')),
                    'description' => 'In-memory, typo-tolerant search engine optimized for developer experience.',
                ],
            ],
        ];
    }

    /**
     * Reindex records for a model class.
     *
     * @return array{exit_code: int, output: string}
     */
    public function reindex(string $modelClass): array
    {
        $exitCode = Artisan::call('scout:import', ['model' => $modelClass]);

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }

    /**
     * Flush all index records for a model class.
     *
     * @return array{exit_code: int, output: string}
     */
    public function flushIndex(string $modelClass): array
    {
        $exitCode = Artisan::call('scout:flush', ['model' => $modelClass]);

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }

    /**
     * Get registered searchable models map.
     *
     * @return array<string, class-string>
     */
    public function getSearchableModels(): array
    {
        return $this->searchableModels;
    }
}
