<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\CropPosition;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\Unit;
use Spatie\Image\Image;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\OptimizerChainFactory;

/**
 * Image processing on local files: resize, crop, compress, convert,
 * thumbnail, watermark and optimize.
 *
 * Every method reads `$source` and writes `$destination`, creating its
 * directory, and returns the path written; the source is left untouched
 * unless the two paths are the same. Each load first applies the photo's
 * EXIF orientation, so phone pictures come out the right way up, and every
 * re-encode drops EXIF metadata — a resident's photo does not carry the GPS
 * location it was taken at into the estate's storage.
 *
 * The driver (`IMAGE_DRIVER`, GD by default) and the optimizer settings are
 * shared with Spatie Media Library, in config/media-library.php. optimize()
 * relies on command-line tools (jpegoptim, pngquant, optipng, gifsicle,
 * cwebp, avifenc); any that are not installed are skipped, leaving the file
 * as it was.
 */
class ImageService
{
    /** Formats GD and Imagick can both write. */
    public const FORMATS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(private ?OptimizerChain $optimizer = null) {}

    /**
     * Scale to fit within the given box, keeping the aspect ratio and never
     * enlarging. Pass one dimension to constrain only that side.
     */
    public function resize(string $source, string $destination, ?int $width = null, ?int $height = null, Fit $fit = Fit::Max): string
    {
        if ($width === null && $height === null) {
            throw new InvalidArgumentException('Give a width, a height, or both.');
        }

        $this->assertPositive(array_filter(['width' => $width, 'height' => $height], fn (?int $value) => $value !== null));

        return $this->save($this->open($source)->fit($fit, $width, $height), $destination);
    }

    /** Cut out exactly `$width` × `$height`, anchored at `$position`. */
    public function crop(string $source, string $destination, int $width, int $height, CropPosition $position = CropPosition::Center): string
    {
        $this->assertPositive(['width' => $width, 'height' => $height]);

        return $this->save($this->open($source)->crop($width, $height, $position), $destination);
    }

    /**
     * Re-encode at a lower quality (1–100). Applies to JPEG, WebP and AVIF
     * directly; for PNG it sets the zlib compression level instead.
     */
    public function compress(string $source, string $destination, int $quality = 80): string
    {
        $this->assertQuality($quality);

        return $this->save($this->open($source)->quality($quality), $destination);
    }

    /**
     * Re-encode in another format. The destination's extension is replaced
     * with the new format's, and the returned path reflects that.
     */
    public function convert(string $source, string $destination, string $format, int $quality = 85): string
    {
        $format = strtolower($format);

        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException("Cannot convert to [{$format}]; supported: ".implode(', ', self::FORMATS).'.');
        }

        $this->assertQuality($quality);

        $destination = preg_replace('/\.[^.\/\\\\]+$/', '', $destination).'.'.$format;

        return $this->save($this->open($source)->format($format)->quality($quality), $destination);
    }

    /** A square thumbnail, cropped to fill rather than letterboxed. */
    public function thumbnail(string $source, string $destination, int $size = 300, int $quality = 80): string
    {
        $this->assertPositive(['size' => $size]);
        $this->assertQuality($quality);

        return $this->save($this->open($source)->fit(Fit::Crop, $size, $size)->quality($quality), $destination);
    }

    /**
     * Stamp `$watermark` (an image file, typically a transparent PNG) onto
     * the picture, sized to `$widthPercent` of its width.
     */
    public function watermark(
        string $source,
        string $destination,
        string $watermark,
        AlignPosition $position = AlignPosition::BottomRight,
        int $padding = 16,
        int $widthPercent = 20,
        int $opacity = 60,
    ): string {
        if (! is_file($watermark)) {
            throw new InvalidArgumentException("Watermark image [{$watermark}] does not exist.");
        }

        if ($widthPercent < 1 || $widthPercent > 100 || $opacity < 0 || $opacity > 100 || $padding < 0) {
            throw new InvalidArgumentException('Width must be 1–100%, opacity 0–100 and padding at least 0.');
        }

        return $this->save(
            $this->open($source)->watermark(
                $watermark,
                $position,
                paddingX: $padding,
                paddingY: $padding,
                width: $widthPercent,
                widthUnit: Unit::Percent,
                alpha: $opacity,
            ),
            $destination,
        );
    }

    /**
     * Losslessly shrink the file with whichever optimizer tools are
     * installed. With no destination the file is optimized in place.
     */
    public function optimize(string $path, ?string $destination = null): string
    {
        $this->assertReadable($path);

        if ($destination !== null) {
            File::ensureDirectoryExists(dirname($destination));
        }

        $this->optimizer()->optimize($path, $destination);

        return $destination ?? $path;
    }

    private function open(string $source): Image
    {
        $this->assertReadable($source);

        // loadFile() applies the EXIF orientation itself; calling
        // orientation() as well would rotate the picture a second time.
        return Image::useImageDriver(config('media-library.image_driver', 'gd'))
            ->loadFile($source);
    }

    private function save(Image $image, string $destination): string
    {
        File::ensureDirectoryExists(dirname($destination));

        $image->save($destination);

        return $destination;
    }

    private function optimizer(): OptimizerChain
    {
        return $this->optimizer ??= OptimizerChainFactory::create(config('media-library.image_optimizers', []));
    }

    private function assertReadable(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Image [{$path}] does not exist or cannot be read.");
        }
    }

    private function assertQuality(int $quality): void
    {
        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException("Quality must be between 1 and 100, not {$quality}.");
        }
    }

    /** @param  array<string, int>  $dimensions */
    private function assertPositive(array $dimensions): void
    {
        foreach ($dimensions as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("The {$name} must be at least 1 pixel, not {$value}.");
            }
        }
    }
}
