<?php

namespace App\Services;

use App\Models\MusterRollCall;
use App\Models\MusterSession;
use App\Models\User;
use App\Services\Occupancy\OccupancyCensusQueryService;
use App\Support\Csv;
use DomainException;
use Illuminate\Support\Facades\DB;

class EmergencyMusterService
{
    public function __construct(
        private readonly OccupancyCensusQueryService $census,
    ) {}

    /**
     * Retrieve the currently active muster session, if any.
     */
    public function getActiveSession(): ?MusterSession
    {
        return MusterSession::with(['rollCalls.marker', 'initiator'])
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * Start a new Emergency Muster / Evacuation session based on everyone currently inside.
     */
    public function startSession(
        string $incidentType,
        string $title,
        ?string $assemblyPoint,
        ?string $notes,
        User $initiator
    ): MusterSession {
        $assemblyPoint = trim((string) ($assemblyPoint ?: config('occupancy.assembly_point')));
        if ($assemblyPoint === '') {
            // It used to fall back to an invented "Central Park East Muster Field".
            throw new DomainException('Name the assembly point: there is no default configured (OCCUPANCY_ASSEMBLY_POINT).');
        }

        return DB::transaction(function () use ($incidentType, $title, $assemblyPoint, $notes, $initiator) {
            // A second muster used to resolve the first silently, so a drill
            // started mid-incident ended the real roll call.
            if (MusterSession::where('status', 'active')->lockForUpdate()->exists()) {
                throw new DomainException('A muster is already in progress. Resolve it before starting another.');
            }

            $session = MusterSession::create([
                'incident_type' => $incidentType,
                'title' => $title,
                'status' => 'active',
                'assembly_point' => $assemblyPoint,
                'notes' => $notes,
                'initiated_by' => $initiator->id,
                'started_at' => now(),
            ]);

            // Everyone checked in. A stale check-in is listed as unverified, not
            // missing: they have probably left, but cannot be ruled out.
            foreach ($this->census->getAllCurrentOccupants(includeStale: true) as $occupant) {
                MusterRollCall::create($this->rollCallRow($session, $occupant) + [
                    'status' => ($occupant['stale'] ?? false) ? 'unverified' : 'missing',
                ]);
            }

            return $session->load(['rollCalls', 'initiator']);
        });
    }

    /**
     * Update an occupant's muster status.
     * Allowed statuses: safe, missing, evacuated, needs_assistance, checked_out.
     */
    public function updateStatus(
        MusterSession $session,
        string $occupantId,
        string $status,
        ?string $notes,
        User $marker
    ): MusterRollCall {
        $allowed = ['safe', 'missing', 'evacuated', 'needs_assistance', 'checked_out'];
        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid muster status: {$status}");
        }

        // A resolved roll call is a record of what happened; it is not edited.
        $this->ensureActive($session);

        $record = MusterRollCall::where('muster_session_id', $session->id)
            ->where('occupant_id', $occupantId)
            ->first();

        if (! $record) {
            // Someone who arrived after the muster started. Only a real
            // occupant: any other id used to become a nameless "Occupant" row.
            $match = $this->census->getAllCurrentOccupants(includeStale: true)->firstWhere('id', $occupantId);
            if (! $match) {
                throw new DomainException('That person is not on the property roll.');
            }

            return MusterRollCall::create($this->rollCallRow($session, $match) + [
                'status' => $status,
                'notes' => $notes,
                'marked_by' => $marker->id,
                'marked_at' => now(),
            ]);
        }

        $updateData = [
            'status' => $status,
            'marked_by' => $marker->id,
            'marked_at' => now(),
        ];

        if ($notes !== null) {
            $updateData['notes'] = $notes;
        }

        $record->update($updateData);

        return $record;
    }

    /**
     * Bulk update muster status for multiple occupants (e.g. all people in Unit 14).
     */
    public function bulkUpdateStatus(
        MusterSession $session,
        array $occupantIds,
        string $status,
        User $marker
    ): int {
        $this->ensureActive($session);

        return MusterRollCall::where('muster_session_id', $session->id)
            ->whereIn('occupant_id', $occupantIds)
            ->update([
                'status' => $status,
                'marked_by' => $marker->id,
                'marked_at' => now(),
            ]);
    }

    /**
     * Mark an active muster session as resolved.
     */
    public function resolveSession(MusterSession $session, User $resolver, ?string $notes = null): MusterSession
    {
        $update = [
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $resolver->id,
        ];

        if ($notes) {
            $update['notes'] = ($session->notes ? $session->notes."\n\nResolution: " : '').$notes;
        }

        $session->update($update);

        return $session->fresh();
    }

    /**
     * Generate an Emergency Evacuation Roster CSV for First Responders.
     */
    public function exportCsv(MusterSession $session): string
    {
        $records = $session->rollCalls()->with('marker')->get();

        $csv = "Unit,Name,Category,Muster_Status,Contact,Vehicle,Pass_ID,Notes,Marked_At,Marked_By\n";

        foreach ($records as $item) {
            // Csv::row neutralises formulas: names and notes come from residents and visitors.
            $csv .= Csv::row([
                $item->unit ?? 'Unassigned',
                $item->occupant_name,
                ucfirst(str_replace('_', ' ', $item->category)),
                $item->statusLabel(),
                $item->contact ?? 'N/A',
                $item->vehicle ?? 'N/A',
                $item->pass_id ?? 'N/A',
                $item->notes ?? '',
                $item->marked_at?->format('Y-m-d H:i') ?? 'Pending',
                $item->marker?->name ?? 'Unmarked',
            ]);
        }

        return $csv;
    }

    private function ensureActive(MusterSession $session): void
    {
        if ($session->status !== 'active') {
            throw new DomainException('This muster has been resolved; its roll call can no longer be changed.');
        }
    }

    /**
     * A roll-call row for one occupant, each value cut to its column so one
     * long email cannot fail the whole muster on a strict database.
     *
     * @param  array<string, mixed>  $occupant
     * @return array<string, mixed>
     */
    private function rollCallRow(MusterSession $session, array $occupant): array
    {
        $fit = fn ($value, int $length) => $value === null ? null : mb_substr((string) $value, 0, $length);

        return [
            'muster_session_id' => $session->id,
            'occupant_id' => $fit($occupant['id'], 64),
            'occupant_name' => $fit($occupant['name'] ?? 'Occupant', 255),
            'pass_id' => $fit($occupant['passId'] ?? null, 64),
            'unit' => $fit($occupant['unit'] ?? 'Unassigned', 64),
            'category' => $fit($occupant['categoryKey'] ?? 'visitors', 40),
            'contact' => $fit($occupant['contact'] ?? null, 255),
            'vehicle' => $fit($occupant['vehicle'] ?? null, 64),
        ];
    }
}
