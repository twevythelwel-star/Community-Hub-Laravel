<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A fallback in env('REVERB_APP_SECRET', '…') is a secret published in the
 * repository: any deployment that forgets to set its own runs on it, and
 * anyone holding it can sign broadcasts and private-channel auth. The key
 * and app ID have no fallbacks either, so an unconfigured deployment fails
 * plainly instead of half-working on shared credentials.
 */
class ReverbSecretConfigTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function settings(): array
    {
        $cases = [];

        foreach (['reverb.php' => 'server', 'broadcasting.php' => 'client'] as $file => $side) {
            foreach (['REVERB_APP_SECRET', 'REVERB_APP_KEY', 'REVERB_APP_ID'] as $variable) {
                $cases["{$side} {$variable}"] = [$file, $variable];
            }
        }

        return $cases;
    }

    #[DataProvider('settings')]
    public function test_reverb_credentials_have_no_fallback(string $file, string $variable): void
    {
        $source = file_get_contents(config_path($file));

        $this->assertStringContainsString("env('{$variable}')", $source);
        $this->assertDoesNotMatchRegularExpression("/env\(\s*'{$variable}'\s*,/", $source);
    }

    public function test_the_browser_client_has_no_fallback_key(): void
    {
        $source = file_get_contents(resource_path('js/echo.ts'));

        $this->assertDoesNotMatchRegularExpression('/VITE_REVERB_APP_KEY\s*(\?\?|\|\|)/', $source);
        $this->assertStringNotContainsString('community_hub_reverb', $source);
    }
}
