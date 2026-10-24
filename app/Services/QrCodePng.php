<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * Renders a QR code as a PNG with GD.
 *
 * simple-qrcode 4.x only writes PNG through the imagick extension, and the
 * bacon-qr-code 2.x it pins has no GD backend. imagick is a heavy native
 * extension that Herd's PHP does not ship, while GD is already required here
 * by dompdf. So bacon-qr-code does the encoding (the part that is actually
 * hard) and GD draws the modules.
 */
class QrCodePng
{
    /**
     * @param  int  $size  Target width and height in pixels. The result is the
     *                     largest whole-pixel scale that fits, so it can come out
     *                     a few pixels smaller; modules are never blurred.
     * @param  int  $margin  Quiet zone, in modules. Scanners need at least 1; 4 is the standard.
     */
    public function render(string $content, int $size = 300, int $margin = 2): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required to render QR codes.');
        }

        // Medium error correction: survives a phone screen with some glare.
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING)->getMatrix();

        $modules = $matrix->getWidth();
        $scale = max(1, intdiv($size, $modules + 2 * $margin));
        $pixels = ($modules + 2 * $margin) * $scale;

        $image = imagecreatetruecolor($pixels, $pixels);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $margin) * $scale;
                    $top = ($y + $margin) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
