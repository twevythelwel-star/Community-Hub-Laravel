import React, { useState } from 'react';
import type { GateId, PassCategory } from '@/lib/gate-pass-engine/types';
import type { AccessPolicy, ColorVariant } from '@/lib/gate-pass-engine/config';
import { GatePassCard } from '@/components/dashboard/gate-pass-card';
import { GateScannerDialog } from '@/components/dashboard/gate-scanner-dialog';

/**
 * Renders the signed-in user's own gate pass.
 *
 * Previously this derived the pass category from the user's role with a local
 * `mapRoleToCategory` helper, which had two defects: `System Admin` fell through
 * to `ADMIN` (so a sysadmin saw the octagon badge instead of the 8-point star),
 * and `HOMEOWNER_STAFF` was unreachable, so household staff were shown the
 * community-staff diamond. The category now comes from the server, where
 * User::passCategory() resolves it — including the household-staff case, which
 * depends on whether the staff member is attached to a specific residence.
 */

export type OwnPass = {
    /** Primary key, used by the PDF download route. */
    id: number;
    passId: string;
    category: PassCategory;
    holderName: string;
    property: string;
    accessZone: string | null;
    gate: GateId;
    rotationSeq: number;
    status: string;
};

type Props = {
    pass: OwnPass;
    variant?: ColorVariant;
    policy?: AccessPolicy;
    windowSeconds?: number;
    /** Whether the viewer may open the verification console. */
    canScan?: boolean;
    photoUrl?: string | null;
};

export function GatePassDisplay({
    pass,
    variant,
    policy,
    windowSeconds = 30,
    canScan = false,
    photoUrl,
}: Props) {
    const [scannerToken, setScannerToken] = useState<string | null>(null);

    return (
        <>
            <GatePassCard
                category={pass.category}
                userName={pass.holderName}
                property={pass.property}
                passId={pass.passId}
                gate={pass.gate}
                photoUrl={photoUrl ?? undefined}
                status={pass.status === 'Revoked' ? 'REVOKED' : 'ACTIVE'}
                initialColorVariant={variant}
                initialRotationSeq={pass.rotationSeq}
                policy={policy}
                windowSeconds={windowSeconds}
                isOwnPass
                // Only a scanner-permitted role gets the verify shortcut; for
                // everyone else the endpoint would return 403 anyway.
                onScanInVerifier={canScan ? (token) => setScannerToken(token) : undefined}
            />

            {canScan && (
                <GateScannerDialog
                    open={!!scannerToken}
                    onOpenChange={(isOpen) => !isOpen && setScannerToken(null)}
                    initialToken={scannerToken || undefined}
                />
            )}
        </>
    );
}
