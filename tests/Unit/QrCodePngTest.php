<?php

namespace Tests\Unit;

use App\Services\QrCodePng;
use PHPUnit\Framework\TestCase;

/**
 * The visitor-pass QR image, drawn with GD so it needs no imagick extension.
 *
 * These check the image's structure. That it scans was confirmed by decoding
 * a rendered guest-pass URL with jsQR, which returned the URL exactly.
 */
class QrCodePngTest extends TestCase
{
    private function render(string $content = 'https://hub.test/guest-pass/0123456789abcdef', int $size = 300, int $margin = 2): \GdImage
    {
        $png = (new QrCodePng)->render($content, $size, $margin);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        return imagecreatefromstring($png);
    }

    private function isBlack(\GdImage $image, int $x, int $y): bool
    {
        return (imagecolorat($image, $x, $y) & 0xFFFFFF) === 0x000000;
    }

    public function test_it_renders_a_square_png_no_larger_than_requested(): void
    {
        $image = $this->render(size: 300);

        $this->assertSame(imagesx($image), imagesy($image));
        $this->assertLessThanOrEqual(300, imagesx($image));
        $this->assertGreaterThan(250, imagesx($image));
    }

    public function test_it_has_a_white_quiet_zone_and_a_black_finder_pattern(): void
    {
        $image = $this->render(margin: 2);

        // Version 1-10 codes are 21-57 modules; with a 2-module margin at a
        // scale of at least 4 px, the top-left 8 px are quiet zone...
        $this->assertFalse($this->isBlack($image, 0, 0));
        $this->assertFalse($this->isBlack($image, 7, 7));

        // ...and the finder pattern's outer ring starts right after it.
        $scale = intdiv(imagesx($image), 4 + $this->moduleCount($image));
        $this->assertTrue($this->isBlack($image, 2 * $scale, 2 * $scale));
        $this->assertTrue($this->isBlack($image, imagesx($image) - 2 * $scale - 1, 2 * $scale));
        $this->assertTrue($this->isBlack($image, 2 * $scale, imagesy($image) - 2 * $scale - 1));
    }

    public function test_longer_content_produces_a_denser_code(): void
    {
        $short = $this->moduleCount($this->render('https://hub.test/p/a'));
        $long = $this->moduleCount($this->render('https://hub.test/guest-pass/'.str_repeat('0123456789abcdef', 4)));

        $this->assertGreaterThan($short, $long);
    }

    /** Counts modules across the top finder row: 7 black, then the code's own width. */
    private function moduleCount(\GdImage $image): int
    {
        // The first black pixel on the diagonal is where the code starts.
        for ($start = 0; ! $this->isBlack($image, $start, $start); $start++);

        // The finder pattern's outer ring is 7 modules wide.
        for ($end = $start; $this->isBlack($image, $end, $start); $end++);
        $scale = intdiv($end - $start, 7);

        return intdiv(imagesx($image) - 2 * $start, $scale);
    }
}
