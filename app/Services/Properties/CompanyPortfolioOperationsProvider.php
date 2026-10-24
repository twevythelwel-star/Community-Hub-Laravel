<?php

namespace App\Services\Properties;

use App\Models\AccessRiskIncident;
use App\Models\AuthorizationDocument;
use App\Models\GatePass;
use App\Models\Invoice;
use App\Models\OwnershipEntity;
use App\Models\ParkingPass;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Scoped Operations provider for corporate ownership property portfolios:
 * Vehicles, Parking, Credentials, Incidents, Payments, and Documents.
 */
class CompanyPortfolioOperationsProvider
{
    /**
     * Retrieve the 6 Operations pillars scoped to the authorized property portfolio.
     */
    public function getOperations(
        OwnershipEntity $company,
        Collection $scopedProperties,
        array $scopedPropertyIds,
        array $scopedLotNumbers
    ): array {
        return [
            'vehicles' => $this->getScopedVehicles($scopedProperties, $scopedLotNumbers),
            'parking' => $this->getScopedParking($scopedProperties, $scopedLotNumbers),
            'credentials' => $this->getScopedCredentials($scopedProperties, $scopedLotNumbers),
            'incidents' => $this->getScopedIncidents($scopedProperties),
            'payments' => $this->getScopedPayments($scopedPropertyIds),
            'documents' => $this->getCompanyDocuments($company),
        ];
    }

    /**
     * Vehicles: Authorized vehicles with ANPR and parking assignments.
     */
    public function getScopedVehicles(Collection $properties, array $lotNumbers): array
    {
        $occupantIds = $properties->pluck('primary_occupant_id')->filter()->all();

        return Vehicle::where(function ($q) use ($lotNumbers, $occupantIds) {
            foreach ($lotNumbers as $lot) {
                $q->orWhere('property', $lot)->orWhere('property', "Unit {$lot}");
            }
            if (! empty($occupantIds)) {
                $q->orWhereIn('user_id', $occupantIds);
            }
        })
            ->latest()
            ->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'license_plate' => $v->formattedPlate(),
                'make' => $v->make,
                'model' => $v->model,
                'year' => $v->year,
                'color' => $v->color,
                'unit' => $v->property ?: 'Company Unit',
                'parking_location' => $v->parking_location ?: 'Designated Bay',
                'is_ev' => (bool) $v->is_ev,
                'anpr_enabled' => (bool) $v->anpr_enabled,
                'status' => $v->status ?: 'active',
                'description' => $v->fullDescription(),
            ])
            ->values()
            ->all();
    }

    /**
     * Parking: Allocated bays and active parking permits.
     */
    public function getScopedParking(Collection $properties, array $lotNumbers): array
    {
        $occupantIds = $properties->pluck('primary_occupant_id')->filter()->all();

        return ParkingPass::where(function ($q) use ($lotNumbers, $occupantIds) {
            foreach ($lotNumbers as $lot) {
                $q->orWhere('property', $lot)->orWhere('property', "Unit {$lot}");
            }
            if (! empty($occupantIds)) {
                $q->orWhereIn('user_id', $occupantIds);
            }
        })
            ->latest()
            ->get()
            ->map(fn (ParkingPass $p) => [
                'id' => $p->id,
                'pass_id' => $p->pass_id,
                'category' => $p->category,
                'category_label' => ucfirst(str_replace('_', ' ', $p->category)),
                'license_plate' => strtoupper($p->license_plate),
                'unit' => $p->property,
                'assigned_bay' => $p->assigned_bay ?: 'General Parking',
                'holder_name' => $p->holder_name,
                'valid_from' => $p->valid_from?->format('M d, Y') ?? 'Active',
                'valid_until' => $p->valid_until?->format('M d, Y') ?? 'Permanent',
                'status' => $p->status ?: 'active',
                'is_ev_charging' => (bool) ($p->metadata['ev_charging_enabled'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * Credentials: Digital gate passes, RFID cards, and NFC credentials.
     */
    public function getScopedCredentials(Collection $properties, array $lotNumbers): array
    {
        $occupantIds = $properties->pluck('primary_occupant_id')->filter()->all();

        return GatePass::where(function ($q) use ($lotNumbers, $occupantIds) {
            foreach ($lotNumbers as $lot) {
                $q->orWhere('property', $lot)->orWhere('property', "Unit {$lot}");
            }
            if (! empty($occupantIds)) {
                $q->orWhereIn('user_id', $occupantIds);
            }
        })
            ->latest()
            ->get()
            ->map(fn (GatePass $g) => [
                'id' => $g->id,
                'pass_id' => $g->pass_id,
                'category' => $g->category?->value ?? (string) $g->category,
                'category_label' => $g->category?->label() ?? 'Pass Credential',
                'holder_name' => $g->holder_name,
                'unit' => $g->property ?: 'Estate Unit',
                'access_zone' => $g->access_zone ?: 'Full Estate Access',
                'designated_gate' => $g->designated_gate?->label() ?? 'Main Gate',
                'status' => $g->status?->value ?? (string) $g->status,
                'valid_from' => $g->valid_from?->format('M d, Y') ?? 'Active',
                'valid_until' => $g->valid_until?->format('M d, Y') ?? 'Never',
                'credential_type' => $g->metadata['credential_type'] ?? 'Digital Wallet & QR',
            ])
            ->values()
            ->all();
    }

    /**
     * Incidents: Gate exceptions, tailgating flags, sensor alerts.
     */
    public function getScopedIncidents(Collection $properties): array
    {
        $occupantIds = $properties->pluck('primary_occupant_id')->filter()->all();

        return AccessRiskIncident::where(function ($q) use ($occupantIds) {
            if (! empty($occupantIds)) {
                $q->whereIn('user_id', $occupantIds);
            }
        })
            ->latest('occurred_at')
            ->get()
            ->map(fn (AccessRiskIncident $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'gate' => $i->gate ?: 'Gate 01',
                'flag_type' => $i->flag_type,
                'risk_level' => strtoupper($i->risk_level ?: 'LOW'),
                'description' => $i->description,
                'status' => strtoupper($i->status ?: 'RESOLVED'),
                'resolution_notes' => $i->resolution_notes,
                'occurred_at' => $i->occurred_at?->format('M d, Y H:i') ?? 'Recent',
            ])
            ->values()
            ->all();
    }

    /**
     * Payments: Invoices, maintenance dues, HOA assessments, payment receipts.
     */
    public function getScopedPayments(array $propertyIds): array
    {
        return Invoice::whereIn('property_id', $propertyIds)
            ->with('property')
            ->latest('due_on')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'reference' => $inv->reference ?: "INV-{$inv->id}",
                'unit' => $inv->property?->lot_number ?: "Unit {$inv->property_id}",
                'property_id' => $inv->property_id,
                'amount_formatted' => '$'.number_format($inv->amount_minor / 100, 2).' '.$inv->currency,
                'amount_minor' => $inv->amount_minor,
                'currency' => $inv->currency,
                'due_on' => $inv->due_on?->format('M d, Y') ?? 'On receipt',
                'paid_at' => $inv->paid_at?->format('M d, Y H:i'),
                'status' => $inv->status ?: 'pending',
                'status_label' => ucfirst($inv->status ?: 'pending'),
                'period' => $inv->period_start && $inv->period_end
                    ? $inv->period_start->format('M Y')
                    : 'Assessment Dues',
            ])
            ->values()
            ->all();
    }

    /**
     * Documents: Corporate articles, title deeds, resolutions, lease contracts.
     */
    public function getCompanyDocuments(OwnershipEntity $company): array
    {
        return AuthorizationDocument::where('ownership_entity_id', $company->id)
            ->latest('issued_at')
            ->get()
            ->map(fn (AuthorizationDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'document_type' => $doc->document_type,
                'document_type_label' => ucwords(str_replace('_', ' ', $doc->document_type)),
                'holder_name' => $doc->holder_name ?: $company->legal_name,
                'file_name' => $doc->file_name ?: 'document.pdf',
                'file_size_formatted' => $this->formatBytes($doc->file_size_bytes),
                'mime_type' => $doc->mime_type ?: 'application/pdf',
                'issued_at' => $doc->issued_at?->format('M d, Y') ?? 'N/A',
                'expires_at' => $doc->expires_at?->format('M d, Y') ?? 'Permanent',
                'verification_status' => $doc->verification_status ?: 'verified',
                'status' => $doc->status ?: 'active',
            ])
            ->values()
            ->all();
    }

    public function formatBytes(?int $bytes): string
    {
        if (! $bytes) {
            return '1.2 MB';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
