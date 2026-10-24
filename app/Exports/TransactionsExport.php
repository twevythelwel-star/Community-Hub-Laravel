<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionsExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithCustomChunkSize, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    public function __construct(
        protected ?string $provider = null,
        protected ?string $status = null,
        protected int $chunkSize = 500
    ) {}

    public function query(): Builder
    {
        $query = Transaction::query()->latest('created_at');

        if ($this->provider) {
            $query->where('provider', $this->provider);
        }

        if ($this->status) {
            $query->where('provider_status', $this->status);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Transaction UUID',
            'Payment Reference',
            'Provider',
            'Provider Status',
            'Payment Purpose',
            'Payment Method',
            'Amount (Gross)',
            'Fee Minor',
            'Net Amount',
            'Currency',
            'Settled Timestamp',
        ];
    }

    /**
     * @param  Transaction  $transaction
     */
    public function map($transaction): array
    {
        $gross = number_format(($transaction->amount_minor ?? 0) / 100, 2);
        $fee = number_format(($transaction->fee_minor ?? 0) / 100, 2);
        $net = number_format(($transaction->net_amount_minor ?? 0) / 100, 2);

        return [
            $transaction->transaction_id ?? (string) $transaction->id,
            $transaction->provider_reference ?? 'N/A',
            ucfirst((string) ($transaction->provider ?? 'System')),
            ucfirst((string) ($transaction->provider_status ?? 'completed')),
            $transaction->purpose ?? 'General Assessment',
            $transaction->payment_method ?? 'card',
            $gross,
            $fee,
            $net,
            strtoupper((string) ($transaction->currency ?? 'USD')),
            $transaction->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
        ];
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['argb' => 'FFFFFFFF'],
                    'size' => 11,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF0F766E'], // Teal 700
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}
