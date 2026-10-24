<?php

namespace Tests\Concerns;

/**
 * Load a config file as `php artisan config:cache` would, with some
 * environment variables changed for the duration.
 *
 * Laravel reads $_SERVER and $_ENV before getenv(), and .env (copied from
 * .env.example in CI) may already set the variable, so all three are set and
 * then restored.
 */
trait LoadsConfigWithEnv
{
    /**
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    protected function configFileWith(string $file, array $vars): array
    {
        $saved = [];
        foreach ($vars as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return require config_path($file);
        } finally {
            foreach ($saved as $name => [$server, $env, $getenv]) {
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                putenv($getenv === false ? $name : "{$name}={$getenv}");
            }
        }
    }
}
