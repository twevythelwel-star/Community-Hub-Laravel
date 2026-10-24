<?php

namespace Tests\Feature\Architecture;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Architecture ratchet — enforces the controller standard from the
 * architecture baseline:
 *
 *   Request → FormRequest → Policy/Gate → Action → Model → Response
 *
 * Existing violations are recorded in architecture-baseline.json. A test
 * fails when:
 *   - a NEW violation appears (code added that breaks the standard), or
 *   - a recorded violation has been FIXED but is still listed (so the
 *     baseline can only ever shrink — delete the entry in the same PR).
 *
 * Regenerate after an intentional cleanup:
 *   ARCH_BASELINE_UPDATE=1 php artisan test --filter=ArchitectureBaselineTest
 * then review the JSON diff: it must only contain removals.
 */
class ArchitectureBaselineTest extends TestCase
{
    /** Longest an action method may be before it must delegate to an Action/service. */
    private const MAX_ACTION_LOC = 25;

    /** Longest a route closure may be before it must become a controller. */
    private const MAX_CLOSURE_LOC = 5;

    /** Vendor / framework routes that are not ours to police. */
    private const IGNORED_URI = '#^(_boost|_ignition|_scramble|livewire|sanctum|filament|storage|horizon|telescope|up$)#';

    private const BASELINE = __DIR__.'/architecture-baseline.json';

    private static ?array $current = null;

    public function test_write_routes_validate_through_a_form_request(): void
    {
        $this->assertRatchet('writes_without_form_request', 'POST/PUT/PATCH route must type-hint a FormRequest (validation + authorize()) instead of $request->validate()');
    }

    public function test_controller_actions_stay_thin(): void
    {
        $this->assertRatchet('fat_actions', 'Controller action exceeds '.self::MAX_ACTION_LOC.' LOC — move the logic into an Action or domain service', growthAllowed: false);
    }

    public function test_route_files_contain_no_logic_closures(): void
    {
        $this->assertRatchet('logic_closures', 'Route closure exceeds '.self::MAX_CLOSURE_LOC.' LOC — move it to a controller (closures also break route:cache)');
    }

    public function test_authorization_is_not_hand_rolled_in_controllers(): void
    {
        $this->assertRatchet('adhoc_authz', 'Hand-rolled authorization (abort(403)/abort_if/role->is*()) in controller — use a Policy, Gate or can: middleware', growthAllowed: false);
    }

    public function test_no_new_classes_in_services_root(): void
    {
        $this->assertRatchet('services_root_files', 'New class in app/Services root — place it in its domain folder (Access, People, Properties, Credentials, Visitors, Safety, Security, Billing, Communications, Reporting, Administration)');
    }

    // ------------------------------------------------------------------ core

    /**
     * @param  bool  $growthAllowed  for count-valued rules, false means the
     *                               recorded number may not increase either.
     */
    private function assertRatchet(string $rule, string $why, bool $growthAllowed = true): void
    {
        $current = $this->current()[$rule];

        if (getenv('ARCH_BASELINE_UPDATE')) {
            $this->writeBaseline();
            $this->markTestSkipped('Baseline regenerated — review the diff; it should only remove entries.');
        }

        $baseline = $this->baseline()[$rule] ?? [];

        $new = array_diff_key($current, $baseline);
        $fixed = array_diff_key($baseline, $current);
        $grew = [];
        if (! $growthAllowed) {
            foreach (array_intersect_key($current, $baseline) as $key => $value) {
                if (is_int($value) && $value > $baseline[$key]) {
                    $grew[$key] = "{$baseline[$key]} → {$value}";
                }
            }
        }

        $messages = [];
        if ($new) {
            $messages[] = "NEW violations ({$why}):\n  - ".implode("\n  - ", array_map(
                fn ($k, $v) => is_int($v) ? "{$k} ({$v})" : $k, array_keys($new), $new));
        }
        if ($grew) {
            $messages[] = "Existing violations GREW ({$why}):\n  - ".implode("\n  - ", array_map(
                fn ($k, $v) => "{$k}: {$v}", array_keys($grew), $grew));
        }
        if ($fixed) {
            $messages[] = "Fixed — remove these from tests/Feature/Architecture/architecture-baseline.json [{$rule}] so they cannot regress:\n  - "
                .implode("\n  - ", array_keys($fixed));
        }

        $this->assertSame([], $messages, implode("\n\n", $messages));
    }

    private function baseline(): array
    {
        return file_exists(self::BASELINE)
            ? json_decode(file_get_contents(self::BASELINE), true, flags: JSON_THROW_ON_ERROR)
            : [];
    }

    private function writeBaseline(): void
    {
        $data = $this->current();
        foreach ($data as &$entries) {
            ksort($entries);
        }
        file_put_contents(self::BASELINE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** Scans the app once per test run. */
    private function current(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $writes = $fat = $closures = $adhoc = [];
        $seenMethods = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes() as $route) {
            $uri = $route->uri();
            if (preg_match(self::IGNORED_URI, $uri)) {
                continue;
            }
            $verbs = array_values(array_diff($route->methods(), ['HEAD']));
            $key = implode('|', $verbs).' '.$uri;
            $action = $route->getActionName();

            if ($action === 'Closure') {
                $fn = new ReflectionFunction($route->getAction('uses'));
                if (! str_starts_with(str_replace('\\', '/', $fn->getFileName()), str_replace('\\', '/', base_path('vendor')))) {
                    $loc = $fn->getEndLine() - $fn->getStartLine() + 1;
                    if ($loc > self::MAX_CLOSURE_LOC) {
                        $closures[$key] = $loc;
                    }
                }

                continue;
            }

            [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
            if (! str_starts_with($class, 'App\\Http\\Controllers\\') || ! method_exists($class, $method)) {
                continue; // Livewire full-page components, vendor controllers
            }
            $ref = new ReflectionMethod($class, $method);
            $short = str_replace('App\\Http\\Controllers\\', '', $class).'@'.$method;

            if (array_intersect($verbs, ['POST', 'PUT', 'PATCH']) && ! $this->hasFormRequest($ref)) {
                $writes[$key] = $short;
            }

            if (! isset($seenMethods[$short])) {
                $seenMethods[$short] = true;
                $loc = $ref->getEndLine() - $ref->getStartLine() + 1;
                if ($loc > self::MAX_ACTION_LOC) {
                    $fat[$short] = $loc;
                }
            }
        }

        foreach (glob(app_path('Http/Controllers').'/{,*/,*/*/}*.php', GLOB_BRACE) as $file) {
            $count = preg_match_all('/abort\(\s*403|abort_(?:if|unless)\(|->role->is\w+\(/', file_get_contents($file));
            if ($count > 0) {
                $adhoc[str_replace([app_path('Http/Controllers').DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file)] = $count;
            }
        }

        $root = [];
        foreach (glob(app_path('Services').'/*.php') as $file) {
            $root[basename($file)] = true;
        }

        return self::$current = [
            'writes_without_form_request' => $writes,
            'fat_actions' => $fat,
            'logic_closures' => $closures,
            'adhoc_authz' => $adhoc,
            'services_root_files' => $root,
        ];
    }

    private function hasFormRequest(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()
                && is_subclass_of($type->getName(), FormRequest::class)) {
                return true;
            }
        }

        return false;
    }
}
