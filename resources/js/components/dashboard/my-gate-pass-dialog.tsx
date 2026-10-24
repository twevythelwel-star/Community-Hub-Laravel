
import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useAuth } from '@/context/auth-context';
import type { UserRole } from '@/types';
import type { PassCategory } from '@/lib/gate-pass-engine/types';
import { GatePassCard } from '@/components/dashboard/gate-pass-card';
import { GateScannerDialog } from '@/components/dashboard/gate-scanner-dialog';

type MyGatePassDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

function mapRoleToCategory(role: UserRole): PassCategory {
  switch (role) {
    case 'System Admin':
    case 'Admin':
      return 'ADMIN';
    case 'Homeowner':
      return 'HOMEOWNER';
    case 'Temporary Homeowner':
      return 'RENTER';
    case 'Security':
      return 'SECURITY';
    case 'Staff':
      return 'STAFF';
    default:
      return 'HOMEOWNER';
  }
}

export function MyGatePassDialog({ open, onOpenChange }: MyGatePassDialogProps) {
  const { user } = useAuth();
  const [scannerToken, setScannerToken] = useState<string | null>(null);

  if (!user) {
    return null;
  }

  const category = mapRoleToCategory(user.role);
  const propertyName = user.lot 
    ? `${user.lot}${user.street ? `, ${user.street}` : ''}` 
    : 'Assigned Community Unit';

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-md p-0 overflow-hidden border-0 bg-transparent shadow-none">
          <GatePassCard
            category={category}
            userName={user.displayName || user.name}
            property={propertyName}
            userId={user.uid}
            gate="GATE-ANY"
            onScanInVerifier={(token) => {
              setScannerToken(token);
              onOpenChange(false);
            }}
          />
        </DialogContent>
      </Dialog>

      {/* Linked Gate Security Scanner Verification Console */}
      <GateScannerDialog
        open={!!scannerToken}
        onOpenChange={(isOpen) => !isOpen && setScannerToken(null)}
        initialToken={scannerToken || undefined}
      />
    </>
  );
}
