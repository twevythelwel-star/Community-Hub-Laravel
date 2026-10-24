import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  AlertTriangle,
  Ban,
  History,
  RefreshCw,
  ShieldAlert,
} from 'lucide-react';
import { AuthorizedPerson } from './delegation-types';

interface EmergencyModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  activePerson: AuthorizedPerson | null;
  reason: string;
  setReason: (val: string) => void;
  durationDays: number;
  setDurationDays: (val: number) => void;
  notes: string;
  setNotes: (val: string) => void;
  onSubmit: (e: React.FormEvent) => void;
}

export function DelegationEmergencyModal({
  isOpen,
  onOpenChange,
  activePerson,
  reason,
  setReason,
  durationDays,
  setDurationDays,
  notes,
  setNotes,
  onSubmit,
}: EmergencyModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md bg-card/95 border-red-500/40">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base text-red-400">
            <AlertTriangle className="w-5 h-5 text-red-500" />
            <span>Declare Emergency Access: {activePerson?.name}</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Temporarily unlocks elevated property privileges and sends real-time emergency broadcast to community security.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} className="space-y-3 pt-2">
          <div className="space-y-1">
            <Label className="text-xs">Emergency Reason *</Label>
            <Input
              required
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Medical emergency requiring caregiver access"
              className="h-8 text-xs"
            />
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Override Duration (Days)</Label>
            <Select
              value={String(durationDays)}
              onValueChange={(val) => setDurationDays(Number(val))}
            >
              <SelectTrigger className="h-8 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="3">3 Days</SelectItem>
                <SelectItem value="7">7 Days (Standard)</SelectItem>
                <SelectItem value="14">14 Days</SelectItem>
                <SelectItem value="30">30 Days</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Notes for Estate Security</Label>
            <Textarea
              rows={2}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Brief details to display on guard gate scanner..."
              className="text-xs"
            />
          </div>

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm" className="bg-red-600 hover:bg-red-700 text-white">
              Declare Emergency
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

interface RevokeModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  activePerson: AuthorizedPerson | null;
  reason: string;
  setReason: (val: string) => void;
  onSubmit: (e: React.FormEvent) => void;
}

export function DelegationRevokeModal({
  isOpen,
  onOpenChange,
  activePerson,
  reason,
  setReason,
  onSubmit,
}: RevokeModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md bg-card/95 border-red-500/40">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base text-red-400">
            <Ban className="w-5 h-5 text-red-500" />
            <span>Revoke Access for {activePerson?.name}</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Immediately revokes all QR credentials, blocks scanner admission, and archives record under Former Occupants.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} className="space-y-3 pt-2">
          <div className="space-y-1">
            <Label className="text-xs">Revocation Reason</Label>
            <Input
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Tenancy ended, Service completed, Permission removed"
              className="h-8 text-xs"
            />
          </div>

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Keep Access
            </Button>
            <Button type="submit" size="sm" className="bg-red-600 hover:bg-red-700 text-white">
              Confirm Revocation
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

interface AuditModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  activePerson: AuthorizedPerson | null;
  loading: boolean;
  events: any[];
}

export function DelegationAuditModal({
  isOpen,
  onOpenChange,
  activePerson,
  loading,
  events,
}: AuditModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg bg-card/95 border-border/60">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base">
            <History className="w-5 h-5 text-primary" />
            <span>Audit Trail: {activePerson?.name}</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Complete tamper-proof timeline of access authorization, scanner events, and modifications.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[60vh] overflow-y-auto space-y-3 pt-2 pr-1">
          {loading ? (
            <div className="text-center py-8 text-xs text-muted-foreground flex items-center justify-center gap-2">
              <RefreshCw className="w-4 h-4 animate-spin text-primary" />
              <span>Loading audit log...</span>
            </div>
          ) : events.length === 0 ? (
            <p className="text-xs text-center py-6 text-muted-foreground">
              No recorded events yet for this authorization.
            </p>
          ) : (
            events.map((evt) => (
              <div key={evt.id} className="p-3 rounded-lg border border-border/30 bg-muted/20 space-y-1 text-xs">
                <div className="flex items-center justify-between">
                  <span className="font-semibold text-foreground uppercase text-[10px] tracking-wider text-primary">
                    {evt.event_type.replace('_', ' ')}
                  </span>
                  <span className="text-[10px] text-muted-foreground">
                    {new Date(evt.occurred_at).toLocaleString()}
                  </span>
                </div>
                <p className="text-muted-foreground">{evt.description}</p>
                {evt.actor && (
                  <p className="text-[10px] text-muted-foreground/70">
                    Actor: {evt.actor.name} ({evt.actor.email})
                  </p>
                )}
              </div>
            ))
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

interface ContinuityModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  authorizedPeople: AuthorizedPerson[];
  planForm: any;
  setPlanForm: React.Dispatch<React.SetStateAction<any>>;
  continuityConditions: Record<string, string>;
  continuityActions: Record<string, string>;
  onSubmit: (e: React.FormEvent) => void;
}

export function DelegationContinuityModal({
  isOpen,
  onOpenChange,
  authorizedPeople,
  planForm,
  setPlanForm,
  continuityConditions,
  continuityActions,
  onSubmit,
}: ContinuityModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl max-h-[90vh] overflow-y-auto bg-card/95 border-border/60">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base">
            <ShieldAlert className="w-5 h-5 text-amber-500" />
            <span>Emergency & Incapacity Continuity Plan</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Configure emergency succession triggers and delegate authorities if you become incapacitated or unreachable.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} className="space-y-4 pt-2">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label className="text-xs font-medium">Primary Succession Delegate</Label>
              <Select
                value={planForm.primary_delegate_id}
                onValueChange={(val) => setPlanForm({ ...planForm, primary_delegate_id: val })}
              >
                <SelectTrigger className="h-9 text-xs">
                  <SelectValue placeholder="Select Delegate" />
                </SelectTrigger>
                <SelectContent>
                  {authorizedPeople
                    .filter((p) => p.sourceType === 'delegation')
                    .map((p) => (
                      <SelectItem key={p.id} value={String(p.sourceId)}>
                        {p.name} ({p.relationship})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label className="text-xs font-medium">Secondary Backup Delegate</Label>
              <Select
                value={planForm.secondary_delegate_id}
                onValueChange={(val) => setPlanForm({ ...planForm, secondary_delegate_id: val })}
              >
                <SelectTrigger className="h-9 text-xs">
                  <SelectValue placeholder="Select Backup" />
                </SelectTrigger>
                <SelectContent>
                  {authorizedPeople
                    .filter((p) => p.sourceType === 'delegation')
                    .map((p) => (
                      <SelectItem key={p.id} value={String(p.sourceId)}>
                        {p.name} ({p.relationship})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-2">
            <Label className="text-xs font-semibold">Activation Conditions</Label>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
              {Object.entries(continuityConditions).map(([key, label]) => (
                <label key={key} className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 cursor-pointer">
                  <Checkbox
                    checked={planForm.activation_conditions.includes(key)}
                    onCheckedChange={(checked) => {
                      const updated = checked
                        ? [...planForm.activation_conditions, key]
                        : planForm.activation_conditions.filter((k: string) => k !== key);
                      setPlanForm({ ...planForm, activation_conditions: updated });
                    }}
                  />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="space-y-2">
            <Label className="text-xs font-semibold">Authorized Representative Actions</Label>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
              {Object.entries(continuityActions).map(([key, label]) => (
                <label key={key} className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 cursor-pointer">
                  <Checkbox
                    checked={planForm.authorized_actions.includes(key)}
                    onCheckedChange={(checked) => {
                      const updated = checked
                        ? [...planForm.authorized_actions, key]
                        : planForm.authorized_actions.filter((k: string) => k !== key);
                      setPlanForm({ ...planForm, authorized_actions: updated });
                    }}
                  />
                  <span>{label}</span>
                </label>
              ))}
            </div>
          </div>

          <div className="space-y-1">
            <Label className="text-xs">Special Emergency Instructions</Label>
            <Textarea
              rows={2}
              value={planForm.special_instructions}
              onChange={(e) => setPlanForm({ ...planForm, special_instructions: e.target.value })}
              placeholder="e.g. Contact family attorney at law firm prior to property delegation..."
              className="text-xs"
            />
          </div>

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm" className="bg-amber-600 hover:bg-amber-700 text-white">
              Save Continuity Plan
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
