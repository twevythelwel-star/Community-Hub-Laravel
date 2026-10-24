<?php

namespace Tests\Feature;

use App\Services\Payments\Drivers\QrPaymentDriver;
use App\Services\QrCodePng;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The scan-to-pay QR carries the payment's transaction ID, drawn on the
 * server. It used to encode /p/CH-<random>, a token stored nowhere, so every
 * scan was a 404; and a failed render fell back to api.qrserver.com.
 */
class QrPaymentDriverTest extends TestCase
{
    private const REFERENCE = 'CH-2026-0000012847';

    public function test_the_qr_encodes_the_transaction_id(): void
    {
        $this->partialMock(QrCodePng::class, function (MockInterface $qr) {
            $qr->shouldReceive('render')->once()->with(self::REFERENCE, 260, 2)->passthru();
        });

        $intent = (new QrPaymentDriver)->initiate(['amount_minor' => 5000, 'currency' => 'JMD', 'transaction_id' => self::REFERENCE]);

        $this->assertSame(self::REFERENCE, $intent['reference']);
        $this->assertStringContainsString(self::REFERENCE, $intent['instructions']);
        $this->assertStringStartsWith('data:image/png;base64,', $intent['qr_svg_url']);

        $png = base64_decode(substr($intent['qr_svg_url'], strlen('data:image/png;base64,')), true);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_it_no_longer_hands_out_a_payment_url_that_cannot_resolve(): void
    {
        $intent = (new QrPaymentDriver)->initiate(['amount_minor' => 5000, 'transaction_id' => self::REFERENCE]);

        $this->assertArrayNotHasKey('payment_url', $intent);
        $this->assertArrayNotHasKey('qr_token', $intent);
        $this->assertStringNotContainsString('/p/', json_encode($intent, JSON_UNESCAPED_SLASHES));
    }

    public function test_without_a_transaction_there_is_no_qr_yet(): void
    {
        $this->mock(QrCodePng::class, function (MockInterface $qr) {
            $qr->shouldNotReceive('render');
        });

        $intent = (new QrPaymentDriver)->initiate(['amount_minor' => 5000]);

        $this->assertNull($intent['reference']);
        $this->assertNull($intent['qr_svg_url']);
    }

    public function test_a_failed_render_omits_the_image_instead_of_calling_a_third_party(): void
    {
        $this->mock(QrCodePng::class, function (MockInterface $qr) {
            $qr->shouldReceive('render')->once()->andThrow(new RuntimeException('GD unavailable'));
        });

        $intent = (new QrPaymentDriver)->initiate(['amount_minor' => 5000, 'transaction_id' => self::REFERENCE]);

        $this->assertNull($intent['qr_svg_url']);
        $this->assertSame(self::REFERENCE, $intent['reference']);
        $this->assertStringNotContainsString('qrserver', json_encode($intent));
    }

    public function test_no_page_loads_a_qr_from_a_third_party(): void
    {
        $offenders = [];

        foreach ([resource_path('js'), resource_path('views')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                // A URL, not a mention: comments may name the old host.
                if (preg_match('#//api\.qrserver\.com#i', file_get_contents($file->getPathname()))) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'QR codes must be drawn locally, not fetched from api.qrserver.com');
    }
}
