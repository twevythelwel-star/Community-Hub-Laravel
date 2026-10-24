<?php

namespace App\Services\Credentials;

use App\Enums\PassCategory;
use App\Models\GatePass;

class GatePassPolicyResolver
{
    /**
     * The colour a pass carries. Chosen at random from the approved palette at
     * issue and stored on the pass. Older rows stored the colour's name, and
     * rows with neither fall back to the deterministic assignment.
     *
     * @return array{id: string, name: string, hex: string, accent_hex: string, contrast_ratio: float, wcag_pass: bool}
     */
    public function variantFor(GatePass $pass): array
    {
        $palette = config('gatepass.palettes.'.$pass->category->value) ?? [];

        foreach ($palette as $variant) {
            if ($pass->color_variant !== null && ($variant['id'] === $pass->color_variant || $variant['name'] === $pass->color_variant)) {
                return $variant;
            }
        }

        return $this->assignedColorVariant($pass->category, $pass->pass_id, $pass->rotation_seq);
    }

    /**
     * Deterministic colour assignment: the same seed and sequence always give
     * the same variant. The fallback for passes issued before colours were
     * stored.
     *
     * @return array{id: string, name: string, hex: string, accent_hex: string, contrast_ratio: float, wcag_pass: bool}
     */
    public function assignedColorVariant(PassCategory $category, ?string $seed = null, int $rotationSeq = 0): array
    {
        $palette = config('gatepass.palettes.'.$category->value)
            ?? config('gatepass.palettes.HOMEOWNER');

        if (empty($palette)) {
            $cfg = config('gatepass.categories.'.$category->value);

            return [
                'id' => 'default',
                'name' => 'Default Class Theme',
                'hex' => $cfg['theme_color'],
                'accent_hex' => $cfg['accent_color'],
                'contrast_ratio' => 6.5,
                'wcag_pass' => true,
            ];
        }

        $seedStr = $seed ?: 'default-seed';
        $seedNum = 0;
        foreach (str_split($seedStr) as $char) {
            $seedNum = ($seedNum * 31 + ord($char)) & 0xFFFFFFFF;
        }

        return $palette[($seedNum + $rotationSeq) % count($palette)];
    }

    /**
     * Picks a random approved variant, optionally excluding ids already in use.
     *
     * @param  list<string>  $excludeColorIds
     * @return array{id: string, name: string, hex: string, accent_hex: string, contrast_ratio: float, wcag_pass: bool}
     */
    public function randomApprovedColor(PassCategory $category, array $excludeColorIds = []): array
    {
        $palette = config('gatepass.palettes.'.$category->value) ?? [];

        $candidates = array_values(array_filter(
            $palette,
            fn (array $c) => ! in_array($c['id'], $excludeColorIds, true),
        ));

        if ($candidates === []) {
            $candidates = $palette;
        }

        return $candidates[random_int(0, count($candidates) - 1)];
    }

    /**
     * Gives a pass a new random approved colour and advances its sequence.
     * Tokens carry the sequence, so every code minted before the rotation is
     * rejected as superseded.
     *
     * @return array{next_seq: int, variant: array<string, mixed>, rotated_at: mixed}
     */
    public function rotateVisualIdentity(GatePass $pass): array
    {
        $variant = $this->randomApprovedColor($pass->category, [$this->variantFor($pass)['id']]);
        $nextSeq = $pass->rotation_seq + 1;

        $pass->update([
            'rotation_seq' => $nextSeq,
            'color_variant' => $variant['id'],
            'last_rotated_at' => now(),
        ]);

        return [
            'next_seq' => $nextSeq,
            'variant' => $variant,
            'rotated_at' => $pass->last_rotated_at,
        ];
    }

    /** Standard pass ID formatter, e.g. GP-HO-0042. */
    public function formatPassId(PassCategory $category, string $rawId): string
    {
        $digits = preg_replace('/\D/', '', $rawId) ?: '0';

        return sprintf('%s-%04d', $category->passIdPrefix(), (int) $digits);
    }

    /**
     * A pass ID not yet on the registry. A re-issued pass (after revocation,
     * or a second visit) gets a suffix instead of colliding with the old one.
     */
    public function uniquePassId(PassCategory $category, string $rawId): string
    {
        $base = $this->formatPassId($category, $rawId);
        $candidate = $base;

        for ($n = 2; GatePass::where('pass_id', $candidate)->exists(); $n++) {
            $candidate = "{$base}-{$n}";
        }

        return $candidate;
    }

    public function zoneOf(GatePass $pass): string
    {
        return $pass->access_zone ?: $pass->category->defaultZone();
    }

    /** @return array<string, mixed> */
    public function policyFor(PassCategory $category): array
    {
        return config('gatepass.policies.'.$category->value)
            ?? config('gatepass.policies.HOMEOWNER');
    }

    /** @return array<string, mixed> */
    public function categoryConfig(PassCategory $category): array
    {
        return config('gatepass.categories.'.$category->value)
            ?? config('gatepass.categories.HOMEOWNER');
    }
}
