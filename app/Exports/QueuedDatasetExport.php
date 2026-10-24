<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class QueuedDatasetExport implements FromQuery, ShouldAutoSize, ShouldQueue, WithChunkReading, WithCustomChunkSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        protected string $dataset = 'residents',
        protected int $chunkSize = 1000
    ) {}

    public function query(): Builder
    {
        return User::query()->orderBy('id');
    }

    public function headings(): array
    {
        return [
            'ID',
            'UID',
            'Full Name',
            'Email Address',
            'Role',
            'Lot',
            'Street',
            'Status',
            'Registered At',
        ];
    }

    /**
     * @param  User  $user
     */
    public function map($user): array
    {
        return [
            $user->id,
            $user->uid,
            $user->name,
            $user->email,
            is_object($user->role) ? $user->role->value : (string) $user->role,
            $user->lot ?? 'N/A',
            $user->street ?? 'N/A',
            $user->status ?? 'Active',
            $user->created_at?->toIso8601String() ?? 'N/A',
        ];
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }
}
