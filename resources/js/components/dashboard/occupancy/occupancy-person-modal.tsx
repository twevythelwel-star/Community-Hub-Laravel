import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { KeyRound, Check, AlertTriangle } from 'lucide-react';
import { Occupant } from './occupancy-types';

interface OccupancyPersonModalProps {
  person: Occupant | null;
  onClose: () => void;
}

export function OccupancyPersonModal({ person, onClose }: OccupancyPersonModalProps) {
  return (
    <Dialog open={!!person} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-[520px]">
        <DialogHeader className="border-b pb-3">
          <div className="flex items-center gap-2 text-xs font-mono text-muted-foreground">
            <Badge variant="outline" className="text-[10px] font-bold text-purple-600 dark:text-purple-400 bg-purple-500/10 border-purple-500/20">
              {person?.categoryLabel || person?.role || 'Legacy Contact'}
            </Badge>
            <span>•</span>
            <span>Authorized for: <strong>{person?.property || person?.unit || 'Not recorded'}</strong></span>
          </div>
          <DialogTitle className="text-xl font-black text-foreground mt-1 flex items-center gap-2">
            <span>{person?.name}</span>
            <span className="text-muted-foreground font-normal text-base">→</span>
            <span className="text-primary">{person?.role || person?.categoryLabel || 'Legacy Contact'}</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Underlying access relationship, credential clearance, gate telemetry, and boundary limits.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2 text-xs">
          {/* Telemetry Detail Grid Matching Specification */}
          <div className="grid grid-cols-2 gap-2.5 p-3.5 rounded-xl bg-neutral-950 text-neutral-100 border border-neutral-800 font-mono text-xs">
            <div>
              <span className="text-neutral-400 block text-[10px] uppercase tracking-wider">Authorized By</span>
              <span className="font-bold text-white text-sm">
                {person?.authorizedBy || person?.host || 'Not recorded'}
              </span>
            </div>
            <div>
              <span className="text-neutral-400 block text-[10px] uppercase tracking-wider">Access Type</span>
              <span className="font-bold text-white text-sm">
                {person?.accessType || 'Emergency'}
              </span>
            </div>
            <div>
              <span className="text-neutral-400 block text-[10px] uppercase tracking-wider">QR</span>
              <span className="font-bold text-emerald-400 text-sm">
                {person?.qrStatus || 'Active'}
              </span>
            </div>
            <div>
              <span className="text-neutral-400 block text-[10px] uppercase tracking-wider">Check-in</span>
              <span className="font-bold text-white text-sm">
                {person?.checkedInTime || '10:42 AM'}
              </span>
            </div>
            <div>
              <span className="text-neutral-400 block text-[10px] uppercase tracking-wider">Gate</span>
              <span className="font-bold text-white text-sm">
                {person?.gate || 'Main Gate'}
              </span>
            </div>
            <div>
              <span className="text-neutral-400 block text-[10px] uppercase tracking-wider">Expiration</span>
              <span className="font-bold text-white text-sm">
                {person?.expiration || 'Oct 14, 2026'}
              </span>
            </div>
            <div className="col-span-2 pt-1 border-t border-border/50 flex items-center justify-between">
              <div>
                <span className="text-muted-foreground block text-[10px]">Credential Expiration</span>
                <span className="font-bold text-foreground">
                  {person?.expiration || 'Active'}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block text-[10px]">QR Credential</span>
                <span className="font-bold text-emerald-600 dark:text-emerald-400">
                  {person?.qrStatus || 'Active'}
                </span>
              </div>
            </div>
          </div>

          {/* Visual Relationship Chain Flow Diagram */}
          <div className="p-3.5 rounded-xl bg-card border space-y-2">
            <div className="text-[10px] font-bold text-muted-foreground uppercase tracking-wider flex items-center justify-between">
              <span>Underlying Relationship Flow</span>
              <KeyRound className="w-3.5 h-3.5 text-primary" />
            </div>
            <div className="flex flex-col gap-1.5 text-xs font-mono">
              <div className="flex items-center gap-2">
                <span className="px-2 py-0.5 rounded bg-primary/10 text-primary font-bold">
                  {person?.name}
                </span>
                <span className="text-muted-foreground">↓</span>
              </div>
              <div className="flex items-center gap-2 pl-3">
                <span className="px-2 py-0.5 rounded bg-purple-500/10 text-purple-600 dark:text-purple-400 font-semibold">
                  {person?.categoryLabel || person?.role || 'Legacy Contact'}
                </span>
                <span className="text-muted-foreground">↓</span>
              </div>
              <div className="flex items-center gap-2 pl-6">
                <span className="px-2 py-0.5 rounded bg-blue-500/10 text-blue-600 dark:text-blue-400 font-semibold">
                  {person?.property || person?.unit || 'Not recorded'}
                </span>
                <span className="text-muted-foreground">↓</span>
              </div>
              <div className="flex items-center gap-2 pl-9">
                <span className="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-semibold">
                  Authorized by {person?.authorizedBy || person?.host || 'Not recorded'}
                </span>
                <span className="text-muted-foreground">↓</span>
              </div>
              <div className="flex items-center gap-2 pl-12">
                <span className="px-2 py-0.5 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400 font-semibold">
                  {person?.accessType || 'Emergency Access'}
                </span>
              </div>
            </div>
          </div>

          {/* Explicit Boundary Limits & Permissions */}
          <div className="grid grid-cols-2 gap-2">
            <div className="p-2.5 rounded-lg border border-emerald-500/30 bg-emerald-500/5 space-y-1">
              <div className="font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1 text-[11px]">
                <Check className="w-3.5 h-3.5" /> Can (Permitted)
              </div>
              <ul className="space-y-1 text-[11px] text-muted-foreground">
                {(person?.relationshipChain?.can || [
                  'Enter property',
                  'Receive emergency notifications',
                  'Authorize emergency visitors',
                ]).map((c, i) => (
                  <li key={i} className="flex items-start gap-1">
                    <span className="text-emerald-500 font-bold">✓</span>
                    <span>{c}</span>
                  </li>
                ))}
              </ul>
            </div>

            <div className="p-2.5 rounded-lg border border-rose-500/30 bg-rose-500/5 space-y-1">
              <div className="font-bold text-rose-600 dark:text-rose-400 flex items-center gap-1 text-[11px]">
                <AlertTriangle className="w-3.5 h-3.5" /> Cannot (Restricted)
              </div>
              <ul className="space-y-1 text-[11px] text-muted-foreground">
                {(person?.relationshipChain?.cannot || [
                  'View community directory',
                  'View other properties',
                  'Modify ownership/settings',
                ]).map((c, i) => (
                  <li key={i} className="flex items-start gap-1">
                    <span className="text-rose-500 font-bold">✗</span>
                    <span>{c}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>

        <DialogFooter className="border-t pt-3">
          <Button type="button" variant="outline" size="sm" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
