<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | resources/views/app.blade.php calls @vite, which reads
         | public/build/manifest.json. Any test that renders the page rather
         | than asking for the Inertia JSON would otherwise fail on a machine
         | that has not run `npm run build` — which is a fact about the asset
         | pipeline, not about the code under test.
         */
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Mark the session's password as just confirmed, for tests of an action
     * behind `password.confirm` that are about the action, not the prompt
     * (that is PasswordConfirmationTest).
     */
    protected function confirmPassword(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }
}
