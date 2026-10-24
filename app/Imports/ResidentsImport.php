<?php

declare(strict_types=1);

namespace App\Imports;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsErrors;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ResidentsImport implements SkipsEmptyRows, SkipsOnError, SkipsOnFailure, ToModel, WithBatchInserts, WithChunkReading, WithHeadingRow, WithValidation
{
    use Importable, SkipsErrors, SkipsFailures;

    protected int $importedCount = 0;

    /**
     * @param  array<string, mixed>  $row
     */
    public function model(array $row): ?User
    {
        // Resolve email - check if user already exists
        $email = trim((string) ($row['email_address'] ?? $row['email'] ?? ''));
        if (! $email) {
            return null;
        }

        $existing = User::where('email', $email)->first();
        if ($existing) {
            // Update attributes
            $existing->update([
                'name' => $row['full_legal_name'] ?? $row['name'] ?? $existing->name,
                'lot' => $row['property_lot'] ?? $row['lot'] ?? $existing->lot,
                'street' => $row['street_address'] ?? $row['street'] ?? $existing->street,
                'phone' => $row['contact_phone'] ?? $row['phone'] ?? $existing->phone,
            ]);
            $this->importedCount++;

            return null;
        }

        $roleString = strtolower(trim((string) ($row['assigned_role'] ?? $row['role'] ?? 'homeowner')));
        $role = match ($roleString) {
            'admin', 'administrator' => UserRole::Admin,
            'security' => UserRole::Security,
            'staff' => UserRole::Staff,
            'temporary homeowner', 'renter' => UserRole::TemporaryHomeowner,
            default => UserRole::Homeowner,
        };

        $this->importedCount++;

        return new User([
            'uid' => (string) Str::uuid(),
            'name' => trim((string) ($row['full_legal_name'] ?? $row['name'] ?? 'New Resident')),
            'display_name' => trim((string) ($row['display_name'] ?? $row['name'] ?? 'New Resident')),
            'email' => $email,
            'phone' => $row['contact_phone'] ?? $row['phone'] ?? null,
            'role' => $role,
            'lot' => $row['property_lot'] ?? $row['lot'] ?? null,
            'street' => $row['street_address'] ?? $row['street'] ?? null,
            'status' => 'Active',
            'password' => Hash::make(Str::random(16)),
        ]);
    }

    public function rules(): array
    {
        return [
            '*.email_address' => ['sometimes', 'email'],
            '*.email' => ['sometimes', 'email'],
            '*.full_legal_name' => ['sometimes', 'string'],
            '*.name' => ['sometimes', 'string'],
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }
}
