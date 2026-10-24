<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\UserRole;
use App\Models\User;
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

class ResidentsExport implements FromQuery, ShouldAutoSize, WithChunkReading, WithCustomChunkSize, WithHeadings, WithMapping, WithStyles
{
    use Exportable;

    public function __construct(
        protected ?string $role = null,
        protected ?string $status = null,
        protected int $chunkSize = 500
    ) {}

    public function query(): Builder
    {
        $query = User::query()->orderBy('name');

        if ($this->role) {
            $query->where('role', $this->role);
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'System UID',
            'Full Legal Name',
            'Display Name',
            'Email Address',
            'Contact Phone',
            'Assigned Role',
            'Property Lot',
            'Street Address',
            'Account Status',
            'Created Date',
        ];
    }

    /**
     * @param  User  $user
     */
    public function map($user): array
    {
        return [
            $user->uid,
            $user->name,
            $user->display_name ?? $user->name,
            $user->email,
            $user->phone ?? 'N/A',
            $user->role instanceof UserRole ? $user->role->value : (string) $user->role,
            $user->lot ?? 'N/A',
            $user->street ?? 'N/A',
            $user->status ?? 'Active',
            $user->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
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
                    'startColor' => ['argb' => 'FF1E293B'], // Slate 800
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}
