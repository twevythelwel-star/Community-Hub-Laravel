<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each request carries one correlation ID: the caller's when it looks like
 * an ID, a fresh UUID otherwise. It used to take any header value, of any
 * length, into every log line.
 */
class CorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_correlation-probe', fn () => [
            'context' => Context::get('correlation_id'),
            'request_id' => request()->header('X-Request-ID'),
        ]);
    }

    public function test_a_request_without_one_gets_a_uuid(): void
    {
        $response = $this->get('/_correlation-probe')->assertOk();

        $id = $response->headers->get('X-Correlation-ID');
        $this->assertTrue(Str::isUuid($id));
        $response->assertExactJson(['context' => $id, 'request_id' => $id]);
    }

    /** @return array<string, array{string}> */
    public static function acceptableIds(): array
    {
        return [
            'uuid' => ['3f0c2a1e-9b7d-4c55-8a10-2b6f1d9e7c42'],
            'nginx $request_id' => ['9f86d081884c7d659a2feaa0c55ad015'],
            'dotted and coloned' => ['web-01.req:000123'],
        ];
    }

    #[DataProvider('acceptableIds')]
    public function test_a_well_formed_caller_id_is_kept(string $id): void
    {
        $this->withHeader('X-Correlation-ID', $id)->get('/_correlation-probe')
            ->assertHeader('X-Correlation-ID', $id)
            ->assertExactJson(['context' => $id, 'request_id' => $id]);
    }

    /** @return array<string, array{string}> */
    public static function rejectedIds(): array
    {
        return [
            'too long' => [str_repeat('a', 129)],
            'too short' => ['abc'],
            'spaces' => ['not an id at all'],
            'markup' => ['<script>alert(1)</script>'],
            'log forging' => ['abc123def"} level=error msg="forged'],
        ];
    }

    #[DataProvider('rejectedIds')]
    public function test_anything_else_is_replaced(string $id): void
    {
        $response = $this->withHeader('X-Correlation-ID', $id)->get('/_correlation-probe');

        $kept = $response->headers->get('X-Correlation-ID');
        $this->assertNotSame($id, $kept);
        $this->assertTrue(Str::isUuid($kept));
        $this->assertSame($kept, $response->json('context'));
    }

    public function test_a_load_balancers_x_request_id_is_used_when_there_is_no_correlation_id(): void
    {
        $this->withHeader('X-Request-ID', 'lb-7f3a9c2e5d10')->get('/_correlation-probe')
            ->assertHeader('X-Correlation-ID', 'lb-7f3a9c2e5d10');
    }

    public function test_api_responses_report_the_same_id(): void
    {
        // ApiResponse used to mint its own X-Request-ID when the caller sent none.
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSame(
            $response->headers->get('X-Correlation-ID'),
            $response->headers->get('X-Request-ID'),
        );
    }
}
