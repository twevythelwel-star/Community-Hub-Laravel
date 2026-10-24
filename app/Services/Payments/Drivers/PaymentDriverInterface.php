<?php

namespace App\Services\Payments\Drivers;

interface PaymentDriverInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * Prepare or initiate a payment intent.
     * Returns payload for frontend or provider redirect.
     */
    public function initiate(array $params): array;

    /**
     * Verify or settle a payment attempt.
     * Returns array with 'success' => bool, 'reference' => string, 'notes' => ?string.
     */
    public function settle(array $params): array;
}
