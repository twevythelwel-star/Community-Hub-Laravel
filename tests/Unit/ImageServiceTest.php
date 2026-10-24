<?php

namespace Tests\Unit;

use App\Services\Media\ImageService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Mockery;
use Spatie\Image\Enums\CropPosition;
use Spatie\ImageOptimizer\OptimizerChain;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    private string $dir;

    private ImageService $images;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/images-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        $this->images = new ImageService;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** A noisy picture, so compression has something to throw away. */
    private function photo(int $width = 800, int $height = 400, string $name = 'photo.jpg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);
        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }

        $path = "{$this->dir}/{$name}";
        str_ends_with($name, '.png') ? imagepng($image, $path) : imagejpeg($image, $path, 95);

        return $path;
    }

    /** One flat colour, so a watermark's position can be read back by pixel. */
    private function plain(int $width, int $height, array $rgb, string $name): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        $path = "{$this->dir}/{$name}";
        imagepng($image, $path);

        return $path;
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $path): array
    {
        [$width, $height] = getimagesize($path);

        return [$width, $height];
    }

    private function rgbAt(string $path, int $x, int $y): array
    {
        $image = imagecreatefromstring(file_get_contents($path));
        $color = imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    // ── Resize ──────────────────────────────────────────────────────

    public function test_resize_fits_the_box_and_keeps_the_aspect_ratio(): void
    {
        $out = $this->images->resize($this->photo(800, 400), "{$this->dir}/out/resized.jpg", 200, 200);

        $this->assertSame([200, 100], $this->dimensions($out));
    }

    public function test_resize_by_width_alone(): void
    {
        $out = $this->images->resize($this->photo(800, 400), "{$this->dir}/w.jpg", width: 400);

        $this->assertSame([400, 200], $this->dimensions($out));
    }

    public function test_resize_never_enlarges(): void
    {
        $out = $this->images->resize($this->photo(100, 50), "{$this->dir}/small.jpg", 400, 400);

        $this->assertSame([100, 50], $this->dimensions($out));
    }

    public function test_resize_needs_a_dimension_and_a_positive_one(): void
    {
        $source = $this->photo();

        $this->assertThrows(fn () => $this->images->resize($source, "{$this->dir}/x.jpg"), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->images->resize($source, "{$this->dir}/x.jpg", 0), InvalidArgumentException::class);
    }

    public function test_the_source_is_left_untouched(): void
    {
        $source = $this->photo();
        $before = md5_file($source);

        $this->images->resize($source, "{$this->dir}/copy.jpg", 100);

        $this->assertSame($before, md5_file($source));
    }

    // ── Crop ────────────────────────────────────────────────────────

    public function test_crop_cuts_the_exact_size(): void
    {
        $out = $this->images->crop($this->photo(800, 400), "{$this->dir}/crop.jpg", 300, 150, CropPosition::TopLeft);

        $this->assertSame([300, 150], $this->dimensions($out));
    }

    // ── Compress ────────────────────────────────────────────────────

    public function test_compress_makes_the_file_smaller(): void
    {
        $source = $this->photo();

        $high = $this->images->compress($source, "{$this->dir}/q95.jpg", 95);
        $low = $this->images->compress($source, "{$this->dir}/q30.jpg", 30);

        $this->assertLessThan(filesize($high), filesize($low));
        $this->assertSame($this->dimensions($source), $this->dimensions($low));
    }

    public function test_compress_rejects_a_quality_out_of_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->images->compress($this->photo(), "{$this->dir}/bad.jpg", 101);
    }

    // ── Convert ─────────────────────────────────────────────────────

    public function test_convert_writes_the_new_format_and_extension(): void
    {
        $out = $this->images->convert($this->photo(name: 'photo.png'), "{$this->dir}/converted.png", 'webp');

        $this->assertStringEndsWith('converted.webp', $out);
        $this->assertSame('image/webp', getimagesize($out)['mime']);
        $this->assertFileDoesNotExist("{$this->dir}/converted.png");
    }

    public function test_convert_rejects_an_unsupported_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->images->convert($this->photo(), "{$this->dir}/x.bmp", 'bmp');
    }

    // ── Thumbnail ───────────────────────────────────────────────────

    public function test_thumbnail_is_a_filled_square(): void
    {
        $out = $this->images->thumbnail($this->photo(800, 400), "{$this->dir}/thumb.jpg", 150);

        $this->assertSame([150, 150], $this->dimensions($out));
    }

    // ── Watermark ───────────────────────────────────────────────────

    public function test_watermark_is_stamped_in_the_chosen_corner(): void
    {
        $base = $this->plain(400, 200, [255, 255, 255], 'white.png');
        $mark = $this->plain(100, 100, [255, 0, 0], 'red.png');

        $out = $this->images->watermark($base, "{$this->dir}/stamped.png", $mark, padding: 0, widthPercent: 25, opacity: 100);

        $this->assertSame([255, 0, 0], $this->rgbAt($out, 395, 195), 'bottom-right should carry the mark');
        $this->assertSame([255, 255, 255], $this->rgbAt($out, 5, 5), 'top-left should be untouched');
        $this->assertSame([400, 200], $this->dimensions($out));
    }

    public function test_watermark_needs_an_existing_mark(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->images->watermark($this->photo(), "{$this->dir}/x.jpg", "{$this->dir}/missing.png");
    }

    // ── Optimize ────────────────────────────────────────────────────

    public function test_optimize_runs_the_optimizer_chain_into_the_destination(): void
    {
        $source = $this->photo();
        $destination = "{$this->dir}/optimized/photo.jpg";

        $chain = Mockery::mock(OptimizerChain::class);
        $chain->shouldReceive('optimize')->once()->with($source, $destination);

        $this->assertSame($destination, (new ImageService($chain))->optimize($source, $destination));
        $this->assertDirectoryExists(dirname($destination));
    }

    public function test_optimize_in_place_without_a_destination(): void
    {
        $source = $this->photo();

        $chain = Mockery::mock(OptimizerChain::class);
        $chain->shouldReceive('optimize')->once()->with($source, null);

        $this->assertSame($source, (new ImageService($chain))->optimize($source));
    }

    public function test_optimize_with_no_tools_installed_leaves_a_valid_image(): void
    {
        $source = $this->photo();

        $out = $this->images->optimize($source, "{$this->dir}/real-chain.jpg");

        $this->assertSame($this->dimensions($source), $this->dimensions($out));
    }

    // ── EXIF ────────────────────────────────────────────────────────

    /**
     * A JPEG the way a phone saves a portrait shot: landscape pixels with an
     * EXIF Orientation of 6 ("rotate 90° clockwise to display"). GD cannot
     * write EXIF, so the APP1 segment is spliced in by hand.
     */
    private function portraitPhoneShot(): string
    {
        $path = $this->photo(200, 100, 'phone.jpg');

        $tiff = "II*\x00\x08\x00\x00\x00"        // little-endian TIFF header, IFD0 at offset 8
            ."\x01\x00"                          // one entry
            ."\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" // Orientation (0x0112), SHORT, 1, value 6
            ."\x00\x00\x00\x00";                 // no further IFD
        $payload = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        $jpeg = file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return $path;
    }

    public function test_exif_orientation_is_applied_and_metadata_is_dropped(): void
    {
        $source = $this->portraitPhoneShot();
        $this->assertSame(6, exif_read_data($source)['Orientation'] ?? null, 'fixture should carry EXIF orientation');

        $out = $this->images->compress($source, "{$this->dir}/upright.jpg", 90);

        $this->assertSame([100, 200], $this->dimensions($out), 'portrait shot should come out portrait');
        $this->assertArrayNotHasKey('Orientation', exif_read_data($out) ?: []);
    }

    // ── Errors ──────────────────────────────────────────────────────

    public function test_a_missing_source_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->images->thumbnail("{$this->dir}/nope.jpg", "{$this->dir}/t.jpg");
    }
}
