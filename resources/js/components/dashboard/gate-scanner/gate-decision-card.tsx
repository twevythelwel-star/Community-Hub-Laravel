import React from 'react';
import { 
  Scan, 
  ShieldCheck, 
  ShieldAlert, 
  CheckCircle2, 
  XCircle, 
  RotateCcw, 
  KeyRound, 
  AlertTriangle, 
  Shield, 
  User, 
  MapPin, 
  FileCheck2 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { ScanOutcome } from './types';

interface GateDecisionCardProps {
  outcome: ScanOutcome | null;
  isConfirming: boolean;
  onConfirmAdmission: () => void;
  onOpenOverrideModal: () => void;
  onReset: () => void;
}

export function GateDecisionCard({
  outcome,
  isConfirming,
  onConfirmAdmission,
  onOpenOverrideModal,
  onReset,
}: GateDecisionCardProps) {
  if (!outcome) {
    return (
      <Card className="border-dashed border-2 border-border/80 bg-muted/20 text-center py-16">
        <div className="max-w-md mx-auto space-y-3 px-4">
          <div className="h-16 w-16 mx-auto rounded-full bg-primary/10 text-primary flex items-center justify-center">
            <Scan className="h-8 w-8 animate-pulse" />
          </div>
          <h3 className="text-lg font-bold text-foreground">Waiting for Next Pass Scan</h3>
          <p className="text-xs text-muted-foreground">
            Direct your optical scanner at the visitor's mobile screen or printed pass, or type their 6-digit offline PIN above.
          </p>
        </div>
      </Card>
    );
  }

  return (
    <Card
      className={cn(
        'border-2 shadow-lg transition-all duration-300',
        outcome.decision === 'REJECT'
          ? outcome.isPassbackViolation
            ? 'border-amber-500 bg-amber-500/5 dark:bg-amber-950/20'
            : 'border-rose-500 bg-rose-500/5 dark:bg-rose-950/20'
          : 'border-emerald-500 bg-emerald-500/5 dark:bg-emerald-950/20'
      )}
    >
      <CardHeader className="pb-4 border-b border-border/40">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            {outcome.decision === 'REJECT' ? (
              outcome.isPassbackViolation ? (
                <div className="h-12 w-12 rounded-xl bg-amber-500/20 text-amber-500 flex items-center justify-center">
                  <AlertTriangle className="h-7 w-7 animate-pulse" />
                </div>
              ) : (
                <div className="h-12 w-12 rounded-xl bg-rose-500/20 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                  <XCircle className="h-7 w-7" />
                </div>
              )
            ) : (
              <div className="h-12 w-12 rounded-xl bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                <CheckCircle2 className="h-7 w-7" />
              </div>
            )}
            <div>
              <CardTitle className="text-xl font-bold tracking-tight">
                {outcome.decision === 'CHECK_IN' && 'ACCESS GRANTED — CHECK IN'}
                {outcome.decision === 'CHECK_OUT' && 'ACCESS GRANTED — CHECK OUT'}
                {outcome.decision === 'REJECT' && (
                  outcome.isPassbackViolation
                    ? 'ANTI-PASSBACK VIOLATION DETECTED'
                    : 'ACCESS REFUSED / FORBIDDEN'
                )}
              </CardTitle>
              <CardDescription className="font-mono text-xs">
                Evaluated at {outcome.occurredAt} &middot; Pass ID: {outcome.report.passId}
              </CardDescription>
            </div>
          </div>

          <Badge
            variant={outcome.decision === 'REJECT' ? (outcome.isPassbackViolation ? 'outline' : 'destructive') : 'default'}
            className={cn(
              'text-xs px-3 py-1 font-bold',
              outcome.isPassbackViolation && 'border-amber-500/50 text-amber-400 bg-amber-500/10'
            )}
          >
            {outcome.isPassbackViolation ? 'ANTI-PASSBACK' : outcome.decision}
          </Badge>
        </div>
      </CardHeader>

      <CardContent className="pt-6 space-y-6">
        {/* Holder Information Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="space-y-1 bg-card/60 p-3.5 rounded-xl border border-border/50">
            <span className="text-xs text-muted-foreground flex items-center gap-1.5">
              <User className="h-3.5 w-3.5" /> Person Name
            </span>
            <p className="text-lg font-bold text-foreground">{outcome.report.userName}</p>
          </div>

          <div className="space-y-1 bg-card/60 p-3.5 rounded-xl border border-border/50">
            <span className="text-xs text-muted-foreground flex items-center gap-1.5">
              <MapPin className="h-3.5 w-3.5" /> Property / Destination
            </span>
            <p className="text-lg font-bold text-foreground">
              {outcome.report.property || 'Community Common Area'}
            </p>
          </div>

          <div className="space-y-1 bg-card/60 p-3.5 rounded-xl border border-border/50">
            <span className="text-xs text-muted-foreground">Category</span>
            <p className="text-sm font-semibold text-foreground uppercase tracking-wide">
              {outcome.report.category}
            </p>
          </div>

          <div className="space-y-1 bg-card/60 p-3.5 rounded-xl border border-border/50">
            <span className="text-xs text-muted-foreground">Status Flag</span>
            <p className="text-sm font-semibold text-foreground">{outcome.status}</p>
          </div>
        </div>

        {/* Refusal / Warning Reason */}
        {outcome.decision === 'REJECT' && (
          outcome.isPassbackViolation ? (
            /* Anti-Passback High-Impact Security Banner */
            <div className="p-4 rounded-xl bg-amber-500/10 border-2 border-amber-500/40 text-amber-100 dark:bg-amber-950/40 dark:border-amber-500/50 space-y-3 shadow-md">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2 font-bold text-base text-amber-400">
                  <AlertTriangle className="h-5 w-5 text-amber-400 animate-pulse" />
                  <span>⚠️ Already Checked In — Anti-Passback Violation</span>
                </div>
                <Badge variant="outline" className="border-amber-500/50 text-amber-300 bg-amber-500/20 text-[10px] font-mono">
                  PASSBACK RULE ACTIVE
                </Badge>
              </div>
              <p className="text-xs leading-relaxed text-amber-200/90 font-medium">
                {outcome.report.primaryReason || 'Passholder is recorded inside property. Duplicate entry without prior departure prohibited.'}
              </p>
              <div className="pt-1 flex flex-wrap items-center gap-3">
                <Button
                  type="button"
                  onClick={onOpenOverrideModal}
                  className="bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs h-9 px-4 gap-2 shadow-xs"
                >
                  <Shield className="w-4 h-4" />
                  Investigate & Authorize Override
                </Button>
                <span className="text-[11px] text-amber-300/70">
                  Prevents credential sharing and duplicate ingress without egress.
                </span>
              </div>
            </div>
          ) : (
            <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-800 dark:text-rose-300 space-y-1">
              <div className="flex items-center gap-2 font-bold text-sm">
                <ShieldAlert className="h-4 w-4" />
                <span>Security Advisory:</span>
              </div>
              <p className="text-xs">
                {outcome.report.primaryReason || 'Cryptographic signature mismatch or expired token window.'}
              </p>
            </div>
          )
        )}

        {/* One-Tap Admission Action */}
        <div className="flex flex-col sm:flex-row items-center gap-3 pt-2">
          {outcome.scanId && outcome.decision !== 'REJECT' ? (
            <Button
              type="button"
              size="lg"
              disabled={isConfirming}
              onClick={onConfirmAdmission}
              className="w-full h-14 text-base font-bold bg-emerald-600 hover:bg-emerald-700 text-white gap-2 shadow-md"
            >
              <ShieldCheck className="h-5 w-5" />
              {isConfirming
                ? 'Admitting Guest...'
                : outcome.decision === 'CHECK_IN'
                  ? 'Admit Guest & Open Barrier'
                  : 'Confirm Exit & Log Departure'}
            </Button>
          ) : (
            <>
              {outcome.isPassbackViolation && (
                <Button
                  type="button"
                  size="lg"
                  onClick={onOpenOverrideModal}
                  className="w-full sm:w-1/2 h-14 text-sm font-bold bg-amber-500 hover:bg-amber-400 text-slate-950 gap-2 shadow-md"
                >
                  <KeyRound className="h-5 w-5" />
                  Authorize Officer Override
                </Button>
              )}
              <Button
                type="button"
                variant="outline"
                size="lg"
                onClick={onReset}
                className={cn('w-full h-14 text-sm font-medium gap-2', outcome.isPassbackViolation && 'sm:w-1/2')}
              >
                <RotateCcw className="h-4 w-4" />
                Reset for Next Visitor
              </Button>
            </>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

interface AntiPassbackOverrideModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  outcome: ScanOutcome | null;
  overrideReason: string;
  onOverrideReasonChange: (reason: string) => void;
  isOverriding: boolean;
  onOverrideSubmit: () => void;
  guardName?: string;
}

export function AntiPassbackOverrideModal({
  open,
  onOpenChange,
  outcome,
  overrideReason,
  onOverrideReasonChange,
  isOverriding,
  onOverrideSubmit,
  guardName,
}: AntiPassbackOverrideModalProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg border-border bg-card">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-500 flex items-center justify-center">
              <Shield className="w-5 h-5" />
            </div>
            <div>
              <DialogTitle className="text-lg font-bold text-foreground">
                Security Override Admission
              </DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground">
                Passholder is flagged as already inside. Provide official justification to authorize re-entry.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {outcome && (
            <div className="bg-muted/40 p-3 rounded-xl border border-border/60 text-xs space-y-1.5">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Passholder:</span>
                <span className="font-bold text-foreground">{outcome.report.userName}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Credential ID:</span>
                <span className="font-mono text-foreground font-semibold">{outcome.report.passId}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Destination:</span>
                <span className="text-foreground">{outcome.report.property || 'Community Area'}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Current Census Status:</span>
                <span className="text-amber-500 font-semibold">Active Ingress (Checked In)</span>
              </div>
            </div>
          )}

          <div className="space-y-2">
            <label className="text-xs font-semibold text-foreground uppercase tracking-wider block">
              Quick Justification Preset
            </label>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
              {[
                'Exited without badge scan (Tailgate/Escort)',
                'Turnstile/barrier mechanical fault',
                'Supervisor verbal verification',
                'Short errand / resident re-entry',
              ].map((preset) => (
                <button
                  key={preset}
                  type="button"
                  onClick={() => onOverrideReasonChange(preset)}
                  className={cn(
                    'p-2 rounded-lg border text-left text-xs transition-colors',
                    overrideReason === preset
                      ? 'border-amber-500/80 bg-amber-500/15 text-amber-300 font-medium'
                      : 'border-border/60 bg-muted/30 text-muted-foreground hover:text-foreground hover:bg-muted/60'
                  )}
                >
                  {preset}
                </button>
              ))}
            </div>
          </div>

          <div className="space-y-1.5">
            <label htmlFor="override-reason-input" className="text-xs font-semibold text-foreground block">
              Officer Override Notes / Log Reason:
            </label>
            <Input
              id="override-reason-input"
              type="text"
              value={overrideReason}
              onChange={(e) => onOverrideReasonChange(e.target.value)}
              placeholder="Enter justification for access log audit..."
              className="h-10 text-xs"
            />
            <span className="text-[11px] text-muted-foreground block">
              This note will be permanently logged in the Immutable Access Audit Trail under Officer {guardName || 'Security'}.
            </span>
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isOverriding}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={onOverrideSubmit}
            disabled={isOverriding || !overrideReason.trim()}
            className="bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold gap-2"
          >
            <FileCheck2 className="w-4 h-4" />
            {isOverriding ? 'Recording Override...' : 'Confirm Override & Admit'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
