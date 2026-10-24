import React from 'react';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import type { PassCategory } from '@/lib/gate-pass-engine/types';
import { formatPassIdForDisplay } from '@/lib/gate-pass-engine/config';
import { GatePassCard } from '@/components/dashboard/gate-pass-card';

/**
 * Preview of a staff member's credential.
 *
 * Two things changed from the original:
 *
 *  1. The category was guessed client-side by checking whether `addedBy`
 *     contained the substring "homeowner", whether the job title was in a
 *     hardcoded list, or whether the property string contained "lot". A
 *     housekeeper registered by an admin, or a resident at "Unit 15B", was
 *     classified wrongly. The server now resolves it from who registered the
 *     staff member and sends it on the row.
 *
 *  2. This rendered a live pass card with a scan shortcut. It cannot any more:
 *     the token endpoint only issues credentials to the signed-in user, so a
 *     third party's pass is shown as a static preview. Verifying a staff member
 *     means scanning the QR on their own device.
 */

export type StaffPassSubject = {
    id: number | string;
    name: string;
    job: string;
    property: string;
    status: 'Active' | 'Inactive' | 'Expired ID';
    photoUrl?: string | null;
    /** Resolved server-side; falls back to community staff if absent. */
    category?: PassCategory;
};

type StaffGatePassDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    staff: StaffPassSubject;
};

export function StaffGatePassDialog({ open, onOpenChange, staff }: StaffGatePassDialogProps) {
    const category: PassCategory = staff.category ?? 'STAFF';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md p-0 overflow-hidden border-0 bg-transparent shadow-none">
                <GatePassCard
                    category={category}
                    userName={staff.name}
                    property={staff.property}
                    // String() guards the id: server rows are numeric, and the
                    // previous code called .replace() straight on it.
                    userId={String(staff.id)}
                    passId={formatPassIdForDisplay(category, String(staff.id))}
                    gate="GATE-01"
                    photoUrl={staff.photoUrl ?? undefined}
                    status={staff.status === 'Active' ? 'ACTIVE' : 'EXPIRED'}
                    isOwnPass={false}
                />
            </DialogContent>
        </Dialog>
    );
}
