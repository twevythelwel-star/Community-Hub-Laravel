<?php

namespace Tests\Feature;

use App\Enums\VisitorStatus;
use App\Models\PaymentLink;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\QrCodePng;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gate pass, visitor pass and payment poster PDFs carry their QR code as an
 * image rendered on the server. They used to point at api.qrserver.com, which
 * received each QR's content — for a visitor, the guest-pass link, a bearer
 * credential — and which DomPDF cannot fetch anyway with remote loading off.
 */
class PassPdfQrCodeTest extends TestCase
{
    use RefreshDatabase;

    /** The PDF embeds an image object only if DomPDF actually drew the QR. */
    private function assertEmbedsAnImage(string $pdf): void
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf, 'the QR image should be embedded in the PDF');
    }

    /** Lets the real renderer run while checking what it was asked to encode. */
    private function expectQrFor(string $content, int $size = 180): void
    {
        $this->partialMock(QrCodePng::class, function (MockInterface $qr) use ($content, $size) {
            $qr->shouldReceive('render')->once()->with($content, $size, 4)->passthru();
        });
    }

    public function test_the_visitor_pass_pdf_embeds_a_locally_rendered_qr(): void
    {
        $homeowner = User::factory()->create();
        $visitor = Visitor::create([
            'name' => 'Michael Chang', 'type' => 'One-time',
            'homeowner_id' => $homeowner->id, 'homeowner_name' => $homeowner->name,
            'status' => VisitorStatus::Expected, 'expected_at' => now()->addHours(2),
        ]);

        $this->expectQrFor(route('guest-pass.show', ['token' => $visitor->share_token]));

        $response = $this->get(route('pdf.visitor-pass', ['token' => $visitor->share_token]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertEmbedsAnImage($response->getContent());
    }

    public function test_the_resident_gate_pass_pdf_embeds_a_locally_rendered_qr(): void
    {
        $holder = User::factory()->create();
        $pass = app(GatePassEngine::class)->issuePassFor($holder);

        $this->expectQrFor("GATE-PASS-{$pass->pass_id}");

        $response = $this->actingAs($holder)
            ->get(route('dashboard.gate-pass.pdf', ['gatePass' => $pass->id]))
            ->assertOk();

        $this->assertEmbedsAnImage($response->getContent());
    }

    public function test_the_payment_poster_pdf_embeds_a_locally_rendered_qr(): void
    {
        config(['payments.public_links_enabled' => true]);

        $link = PaymentLink::create([
            'token' => 'poster-qr-1234',
            'title' => 'Perimeter Lighting Fund',
            'amount_minor' => 5000,
            'currency' => 'JMD',
            'active' => true,
        ]);

        // 220 px matches .qr-img in pdf/qr-poster.blade.php.
        $this->expectQrFor($link->publicUrl(), 220);

        $response = $this->get("/pay/{$link->token}/poster")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertEmbedsAnImage($response->getContent());
    }

    /** @return array<string, array{string}> */
    public static function qrTemplates(): array
    {
        return [
            'gate pass' => ['views/pdf/gate-pass.blade.php'],
            'payment poster' => ['views/pdf/qr-poster.blade.php'],
        ];
    }

    #[DataProvider('qrTemplates')]
    public function test_the_template_no_longer_calls_a_third_party(string $path): void
    {
        $template = file_get_contents(resource_path($path));

        // No image is loaded from anywhere off the server (the comment may name the old host).
        $this->assertDoesNotMatchRegularExpression('#src\s*=\s*["\']\s*(https?:)?//#i', $template);
        $this->assertStringContainsString('src="{{ $qrImage }}"', $template);
    }
}
