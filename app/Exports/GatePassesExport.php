<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\GatePass;
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

class GatePassesExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithCustomChunkSize, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    public function __construct(
        protected ?string $status = null,
        protected ?string $category = null,
        protected int $chunkSize = 500
    ) {}

    public function query(): Builder
    {
        $query = GatePass::query()->latest('created_at');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->category) {
            $query->where('category', $this->category);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Pass Identifier',
            'Pass Holder Name',
            'Category',
            'Property / Lot',
            'Access Zone',
            'Designated Gate',
            'Status',
            'Valid From',
            'Valid Until',
            'Single Entry Only',
            'Checked-In At',
        ];
    }

    /**
     * @param  GatePass  $pass
     */
    public function map($pass): array
    {
        return [
            $pass->pass_id,
            $pass->holder_name,
            is_object($pass->category) ? $pass->category->value : (string) $pass->category,
            $pass->property ?? 'N/A',
            $pass->access_zone ?? 'Full Access',
            is_object($pass->designated_gate) ? $pass->designated_gate->value : (string) ($pass->designated_gate ?? 'All Gates'),
            is_object($pass->status) ? $pass->status->value : (string) $pass->status,
            $pass->valid_from?->format('Y-m-d H:i') ?? 'N/A',
            $pass->valid_until?->format('Y-m-d H:i') ?? 'N/A',
            $pass->single_entry ? 'YES' : 'NO',
            $pass->checked_in_at?->format('Y-m-d H:i') ?? 'Pending Check-In',
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
                    'startColor' => ['argb' => 'FF4338CA'], // Indigo 700
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}
