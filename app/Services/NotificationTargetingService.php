<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port of src/ai/flows/generate-targeted-notifications.ts.
 *
 * Note on current behaviour: that flow was stubbed. Despite configuring Genkit
 * with googleai/gemini-2.5-flash in src/ai/genkit.ts, generateTargetedNotifications()
 * never called a model — it slept 1500ms and returned four hardcoded strings,
 * one of which was just the first 50 characters of the input. The Genkit client
 * was imported but unused.
 *
 * This service keeps that exact fallback so nothing regresses when no key is
 * configured, and performs a real Gemini call when GOOGLE_AI_API_KEY is set.
 * Because the call now happens server-side, the API key is never exposed to the
 * browser — which is also why the original could not have called the model
 * safely from client code in the first place.
 */
class NotificationTargetingService
{
    /**
     * @return array{notificationItems: array<int, string>, source: string}
     */
    public function generate(string $document, string $community): array
    {
        if (blank(config('services.googleai.key'))) {
            return [
                'notificationItems' => $this->stubItems($document, $community),
                'source' => 'stub',
            ];
        }

        try {
            return [
                'notificationItems' => $this->callGemini($document, $community),
                'source' => 'gemini',
            ];
        } catch (Throwable $e) {
            Log::warning('Gemini targeting call failed; using deterministic fallback.', [
                'error' => $e->getMessage(),
            ]);

            return [
                'notificationItems' => $this->stubItems($document, $community),
                'source' => 'stub-fallback',
            ];
        }
    }

    /** @return array<int, string> */
    private function callGemini(string $document, string $community): array
    {
        $model = config('services.googleai.model');
        $endpoint = rtrim(config('services.googleai.endpoint'), '/');

        $prompt = <<<PROMPT
        You are drafting notices for a gated residential community called "{$community}".

        Summarise the document below into a short list of clear, actionable notification
        items for residents. Each item must be one sentence, under 140 characters, written
        in plain language, and must not invent facts that are not in the document.

        Return ONLY a JSON object of the form {"notificationItems": ["...", "..."]}.

        DOCUMENT:
        {$document}
        PROMPT;

        $response = Http::timeout(20)
            ->asJson()
            ->post("{$endpoint}/models/{$model}:generateContent?key=".config('services.googleai.key'), [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'responseMimeType' => 'application/json',
                ],
            ])
            ->throw();

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $decoded = json_decode((string) $text, true);

        $items = data_get($decoded, 'notificationItems');

        if (! is_array($items) || $items === []) {
            throw new \RuntimeException('Model returned no usable notification items.');
        }

        return array_values(array_map(
            fn ($item) => mb_substr((string) $item, 0, 200),
            array_slice($items, 0, 10),
        ));
    }

    /**
     * The original stub output, preserved verbatim in shape.
     *
     * @return array<int, string>
     */
    private function stubItems(string $document, string $community): array
    {
        return [
            "Important Update for {$community}",
            'Please review the new maintenance schedules.',
            'Ensure all vehicles are registered by Friday.',
            'Summary: '.mb_substr($document, 0, 50).'...',
        ];
    }
}
