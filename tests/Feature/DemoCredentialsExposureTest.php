<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The seed password is every seeded account's, the System Admin's included.
 * The sign-in page printed it into its script in every environment — only the
 * quick-fill buttons were hidden in production — so viewing source there
 * handed out an administrator's password.
 */
class DemoCredentialsExposureTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_PASSWORD = 'Seeded-Only-For-This-Test-41';

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.seed_password' => self::SEED_PASSWORD]);
    }

    public function test_the_sign_in_page_shows_the_quick_fill_in_testing(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Quick-Fill Demo Credentials')
            ->assertSee('data-demo-password="'.self::SEED_PASSWORD.'"', false);
    }

    /** @return array<string, array{string}> */
    public static function deployedEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('deployedEnvironments')]
    public function test_a_deployed_sign_in_page_never_carries_the_seed_password(string $environment): void
    {
        $this->app['env'] = $environment;

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SEED_PASSWORD, $html);
        $this->assertStringNotContainsString('ChangeMe!2026', $html);
        $this->assertStringNotContainsString('Quick-Fill Demo Credentials', $html);
    }

    public function test_the_seeder_reads_the_password_from_config(): void
    {
        // env() returns null once config is cached; config() does not.
        $this->seed(UserSeeder::class);

        $admin = User::where('email', 'alexander.wright@communityhub.org')->firstOrFail();
        $this->assertTrue(Hash::check(self::SEED_PASSWORD, $admin->password));
    }

    public function test_no_app_code_view_or_seeder_reads_env_directly(): void
    {
        $offenders = [];

        foreach ([app_path(), resource_path('views'), database_path('seeders')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (preg_match('/(?<![\w>:])env\(/', file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Read settings through config(): env() is null under config:cache');
    }
}
