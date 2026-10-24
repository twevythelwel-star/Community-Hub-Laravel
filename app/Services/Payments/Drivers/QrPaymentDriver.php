<?php

namespace App\Services\Payments\Drivers;

use App\Services\QrCodePng;
use Illuminate\Support\Str;

class QrPaymentDriver implements PaymentDriverInterface
{
    public function key(): string
    {
        return 'qr_code';
    }

    public function label(): string
    {
        return 'QR Code Scan to Pay';
    }

    /**
     * The QR carries the payment's transaction ID, the reference the office
     * confirms a QR payment by — the same code the payment center shows.
     *
     * It used to encode url('/p/CH-<random>'): a token stored nowhere, on a
     * route that only resolves saved PaymentLinks, so every scan was a 404.
     * Without a transaction_id there is nothing to encode yet, so no QR.
     *
     * @param  array{amount_minor?: int, currency?: string, transaction_id?: string}  $params
     */
    public function initiate(array $params): array
    {
        $amountMinor = $params['amount_minor'] ?? 0;
        $currency = $params['currency'] ?? 'JMD';
        $reference = $params['transaction_id'] ?? null;

        return [
            'type' => 'qr_code',
            'reference' => $reference,
            'qr_svg_url' => $reference ? $this->qrImage($reference) : null,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'instructions' => $reference
                ? "Scan this code with your banking app, or quote {$reference} as the payment reference. The community office confirms the payment before your statement updates."
                : 'The QR code is shown once the payment starts.',
        ];
    }

    /**
     * The QR as an inline PNG data: URI, or null if it cannot be drawn — the
     * reference in the response still works. A failed render used to fall
     * back to api.qrserver.com, which handed a third party the QR content.
     */
    private function qrImage(string $content): ?string
    {
        try {
            return 'data:image/png;base64,'.base64_encode(app(QrCodePng::class)->render($content, 260, 2));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function settle(array $params): array
    {
        return [
            'success' => true,
            'reference' => 'QR-'.strtoupper(Str::random(10)),
            'notes' => 'Settled via dynamic QR Code scanner.',
        ];
    }
}
