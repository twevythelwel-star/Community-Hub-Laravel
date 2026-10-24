<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** White-label branding, shared across all users instead of per-browser localStorage. */
class BrandingSetting extends Model
{
    use HasFactory;

    protected $table = 'branding_settings';

    protected $fillable = ['community_id', 'app_name', 'logo_url', 'primary_color', 'accent_color', 'background_color', 'default_theme', 'theme_tokens'];

    protected function casts(): array
    {
        return ['theme_tokens' => 'array'];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public const THEME_PRESETS = [
        'triovo' => [
            'name' => 'Triovo',
            'primary' => '221.2 83.2% 53.3%',     // Royal Sapphire Blue (#2563eb)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '160 84% 39%',             // Rich Emerald
            'accent_foreground' => '0 0% 100%',
        ],
        'classic' => [
            'name' => 'Classic',
            'primary' => '215 25% 27%',            // Deep Navy (#334155)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '221.2 83.2% 53.3%',
            'accent_foreground' => '210 40% 98%',
        ],
        'ocean' => [
            'name' => 'Ocean',
            'primary' => '199 89% 48%',            // Marine Sky Blue (#0284c7)
            'primary_foreground' => '0 0% 100%',    // Pure White
            'accent' => '173 80% 40%',             // Deep Sea Teal
            'accent_foreground' => '0 0% 100%',
        ],
        'sunset' => [
            'name' => 'Sunset',
            'primary' => '16 90% 50%',             // Warm Terracotta
            'primary_foreground' => '0 0% 100%',    // Pure White
            'accent' => '38 92% 50%',              // Amber
            'accent_foreground' => '222.2 84% 4.9%',
        ],
        'brutalist' => [
            'name' => 'Brutalist',
            'primary' => '222.2 47.4% 11.2%',      // Obsidian Slate (#0f172a)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '215 16.3% 46.9%',         // Slate Gray
            'accent_foreground' => '210 40% 98%',
        ],
        'emerald' => [
            'name' => 'Emerald',
            'primary' => '152 76% 36%',            // Forest Emerald Green (#14804a)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '43 86% 54%',              // Warm Gold Champagne
            'accent_foreground' => '222.2 84% 4.9%',
        ],
        'amethyst' => [
            'name' => 'Amethyst',
            'primary' => '262 83% 50%',            // Royal Velvet Purple (#7c3aed)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '336 80% 58%',             // Rose Quartz
            'accent_foreground' => '0 0% 100%',
        ],
        'crimson' => [
            'name' => 'Crimson',
            'primary' => '346 84% 42%',            // Heritage Ruby Crimson (#c5113d)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '35 92% 51%',              // Polished Warm Amber
            'accent_foreground' => '222.2 84% 4.9%',
        ],
        'dunes' => [
            'name' => 'Dunes',
            'primary' => '28 85% 44%',             // Caribbean Sand / Rich Cognac (#cc6214)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '174 78% 41%',             // Coastal Turquoise
            'accent_foreground' => '0 0% 100%',
        ],
        'nordic' => [
            'name' => 'Nordic',
            'primary' => '205 65% 38%',            // Arctic Steel Blue (#22699f)
            'primary_foreground' => '210 40% 98%',  // Crisp White
            'accent' => '188 86% 45%',             // Glacier Ice Cyan
            'accent_foreground' => '222.2 84% 4.9%',
        ],
        'sage' => [
            'name' => 'Sage',
            'primary' => '158 42% 30%',            // Deep Botanical Sage Olive (#2d6b4f)
            'primary_foreground' => '210 40% 98%',  // Crisp White (6.6:1)
            'accent' => '84 81% 44%',              // Lush Meadow Lime (#65a30d)
            'accent_foreground' => '0 0% 100%',
        ],
        'copper' => [
            'name' => 'Copper',
            'primary' => '24 80% 40%',             // Burnished Copper (#b84f14)
            'primary_foreground' => '210 40% 98%',  // Crisp White (5.5:1)
            'accent' => '178 84% 38%',             // Verdigris Patina Turquoise (#0f9f92)
            'accent_foreground' => '0 0% 100%',
        ],
        'rose' => [
            'name' => 'Rose',
            'primary' => '338 76% 42%',            // Imperial Velvet Rose (#be185d)
            'primary_foreground' => '210 40% 98%',  // Crisp White (5.8:1)
            'accent' => '42 90% 55%',              // Champagne Gold (#eab308)
            'accent_foreground' => '222.2 84% 4.9%',
        ],
        'slate' => [
            'name' => 'Slate',
            'primary' => '218 36% 22%',            // Deep Slate Graphite (#232f3e)
            'primary_foreground' => '210 40% 98%',  // Crisp White (10.4:1)
            'accent' => '217 91% 60%',             // Vibrant Electric Cobalt (#3b82f6)
            'accent_foreground' => '210 40% 98%',
        ],
        'espresso' => [
            'name' => 'Espresso',
            'primary' => '25 50% 20%',             // Dark Roast Espresso (#4c2b19)
            'primary_foreground' => '210 40% 98%',  // Crisp White (10.8:1)
            'accent' => '36 96% 53%',              // Warm Toffee Amber (#f59e0b)
            'accent_foreground' => '222.2 84% 4.9%',
        ],
    ];

    public static function isValidPreset(?string $preset): bool
    {
        return is_string($preset) && isset(self::THEME_PRESETS[$preset]);
    }

    public static function getPresetColors(string $preset): array
    {
        return self::THEME_PRESETS[$preset] ?? self::THEME_PRESETS['triovo'];
    }

    /** Whether an administrator has chosen one of THEME_PRESETS for the community. */
    public function hasThemePreset(): bool
    {
        return self::isValidPreset($this->theme_tokens['themePreset'] ?? null);
    }

    public function getActiveThemePreset(): string
    {
        return $this->hasThemePreset() ? $this->theme_tokens['themePreset'] : 'triovo';
    }

    public function getThemePresetColors(): array
    {
        return self::THEME_PRESETS[$this->getActiveThemePreset()];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['app_name' => 'Community Hub']);
    }
}
