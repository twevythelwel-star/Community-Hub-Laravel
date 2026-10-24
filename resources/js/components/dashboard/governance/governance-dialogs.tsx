import React from 'react';
import { ShieldAlert, FileText, Lock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { RiskIncident } from './governance-types';

interface GovernanceDialogsProps {
  isLockdownDialogOpen: boolean;
  setIsLockdownDialogOpen: (open: boolean) => void;
  selectedIncident: RiskIncident | null;
  lockdownReason: string;
  setLockdownReason: (reason: string) => void;
  onLockdown: () => void;
  isAttachingDoc: boolean;
  setIsAttachingDoc: (open: boolean) => void;
  newDocTitle: string;
  setNewDocTitle: (title: string) => void;
  newDocType: string;
  setNewDocType: (type: string) => void;
  newDocHolder: string;
  setNewDocHolder: (holder: string) => void;
  newDocExpires: string;
  setNewDocExpires: (expires: string) => void;
  onCreateDocument: (e: React.FormEvent) => void;
}

export function GovernanceDialogs({
  isLockdownDialogOpen,
  setIsLockdownDialogOpen,
  selectedIncident,
  lockdownReason,
  setLockdownReason,
  onLockdown,
  isAttachingDoc,
  setIsAttachingDoc,
  newDocTitle,
  setNewDocTitle,
  newDocType,
  setNewDocType,
  newDocHolder,
  setNewDocHolder,
  newDocExpires,
  setNewDocExpires,
  onCreateDocument,
}: GovernanceDialogsProps) {
  return (
    <>
      {/* Modal: Enforce Lockdown */}
      <Dialog open={isLockdownDialogOpen} onOpenChange={setIsLockdownDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-red-600">
              <ShieldAlert className="w-5 h-5" /> Enforce Immediate Credential Lockdown
            </DialogTitle>
            <DialogDescription className="text-xs">
              This will immediately suspend pass #{selectedIncident?.pass_id} across all gates, preventing all barrier access and alerting on-duty security guards.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2">
            <div className="p-2.5 rounded-lg bg-red-500/10 border border-red-500/20 text-xs text-red-900 dark:text-red-300">
              <span className="font-bold">Incident Flag: </span>
              {selectedIncident?.title}
            </div>

            <div className="space-y-1">
              <Label htmlFor="lockdown-reason" className="text-xs font-semibold">
                Quarantine / Lockdown Reason
              </Label>
              <Input
                id="lockdown-reason"
                placeholder="e.g. Credential cloning detected between Main and Service Gate"
                value={lockdownReason}
                onChange={(e) => setLockdownReason(e.target.value)}
                className="text-xs"
              />
            </div>
          </div>

          <DialogFooter className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsLockdownDialogOpen(false)}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={onLockdown}
              className="gap-1.5 font-bold"
            >
              <Lock className="w-3.5 h-3.5" /> Execute Lockdown
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Modal: Attach Compliance Document */}
      <Dialog open={isAttachingDoc} onOpenChange={setIsAttachingDoc}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <FileText className="w-5 h-5 text-primary" /> Attach Compliance Document
            </DialogTitle>
            <DialogDescription className="text-xs">
              Upload verified digital documents (leases, letters of authorization, ID verifications, insurance policies).
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={onCreateDocument} className="space-y-3 py-2">
            <div className="space-y-1">
              <Label htmlFor="doc-title" className="text-xs font-semibold">
                Document Title
              </Label>
              <Input
                id="doc-title"
                required
                placeholder="e.g. Residential Lease Agreement 2026-2027"
                value={newDocTitle}
                onChange={(e) => setNewDocTitle(e.target.value)}
                className="text-xs"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label htmlFor="doc-type" className="text-xs font-semibold">
                  Document Type
                </Label>
                <Select value={newDocType} onValueChange={setNewDocType}>
                  <SelectTrigger className="text-xs">
                    <SelectValue placeholder="Select type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="lease">Residential Lease</SelectItem>
                    <SelectItem value="authorization_letter">Authorization Letter</SelectItem>
                    <SelectItem value="id_verification">Photo ID Verification</SelectItem>
                    <SelectItem value="insurance">Liability Insurance</SelectItem>
                    <SelectItem value="contractor_certificate">Contractor Certificate</SelectItem>
                    <SelectItem value="other">Other Compliance</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <Label htmlFor="doc-holder" className="text-xs font-semibold">
                  Holder Name
                </Label>
                <Input
                  id="doc-holder"
                  required
                  placeholder="e.g. Marcus Vance"
                  value={newDocHolder}
                  onChange={(e) => setNewDocHolder(e.target.value)}
                  className="text-xs"
                />
              </div>
            </div>

            <div className="space-y-1">
              <Label htmlFor="doc-expires" className="text-xs font-semibold">
                Expiration Date
              </Label>
              <Input
                id="doc-expires"
                type="date"
                required
                value={newDocExpires}
                onChange={(e) => setNewDocExpires(e.target.value)}
                className="text-xs"
              />
            </div>

            <DialogFooter className="pt-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setIsAttachingDoc(false)}
              >
                Cancel
              </Button>
              <Button type="submit" size="sm" className="font-semibold">
                Save & Verify Document
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
}
