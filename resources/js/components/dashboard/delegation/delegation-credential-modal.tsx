import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Clock,
  Copy,
  QrCode,
  Share2,
  ShieldAlert,
  ShieldCheck,
} from 'lucide-react';
import { AuthorizedPerson } from './delegation-types';
import { renderCredentialShapeIcon } from './delegation-person-card';

interface CredentialModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  activePerson: AuthorizedPerson | null;
  secondsRemaining: number;
  onCopyLink: (text: string) => void;
}

export function DelegationCredentialModal({
  isOpen,
  onOpenChange,
  activePerson,
  secondsRemaining,
  onCopyLink,
}: CredentialModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md bg-card/95 border-border/60 backdrop-blur-md">
        <DialogHeader>
          <DialogTitle className="flex items-center justify-between text-base">
            <span className="flex items-center gap-2">
              <ShieldCheck className="w-5 h-5 text-primary" />
              <span>Authorized Gate Credential</span>
            </span>
            <Badge variant="outline" className="text-[10px] font-mono border-primary/30 text-primary">
              HMAC-SHA256
            </Badge>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Official encrypted gate credential for physical scanner clearance.
          </DialogDescription>
        </DialogHeader>

        {activePerson && (
          <div className="space-y-4 pt-2">
            <div className="relative rounded-2xl border-2 border-primary/30 bg-gradient-to-b from-primary/10 via-background to-background p-5 text-center shadow-lg overflow-hidden">
              <div className="flex items-center justify-between border-b border-border/40 pb-3 mb-4">
                <div className="flex items-center gap-2 text-left">
                  {renderCredentialShapeIcon(activePerson.credential?.shape)}
                  <div>
                    <p className="text-[10px] font-mono uppercase text-muted-foreground tracking-wider">
                      {activePerson.credential?.categoryCode || 'GP-PASS'} • {activePerson.credential?.shape || 'Standard'}
                    </p>
                    <p className="text-xs font-bold text-foreground">{activePerson.categoryLabel}</p>
                  </div>
                </div>
                <Badge variant="secondary" className="text-[10px] font-mono">
                  GPE V1
                </Badge>
              </div>

              <div className="flex flex-col items-center justify-center p-3 bg-white rounded-xl shadow-inner mx-auto w-48 h-48 border border-zinc-200">
                <div className="relative w-40 h-40 bg-zinc-950 rounded-lg p-2 flex flex-col justify-between items-center text-white font-mono text-[9px]">
                  <div className="flex justify-between w-full">
                    <div className="w-8 h-8 border-4 border-white rounded-sm flex items-center justify-center">
                      <div className="w-3 h-3 bg-white" />
                    </div>
                    <div className="w-8 h-8 border-4 border-white rounded-sm flex items-center justify-center">
                      <div className="w-3 h-3 bg-white" />
                    </div>
                  </div>

                  <div className="flex items-center justify-center gap-1 py-1">
                    <QrCode className="w-10 h-10 text-white animate-pulse" />
                  </div>

                  <div className="flex justify-between w-full">
                    <div className="w-8 h-8 border-4 border-white rounded-sm flex items-center justify-center">
                      <div className="w-3 h-3 bg-white" />
                    </div>
                    <div className="text-[8px] text-zinc-400 self-end">SEC-OK</div>
                  </div>
                </div>
              </div>

              <div className="mt-4 flex items-center justify-center gap-2 text-xs text-muted-foreground">
                <Clock className="w-3.5 h-3.5 text-primary animate-spin" />
                <span>
                  Token refreshes in <strong className="text-foreground font-mono">{secondsRemaining}s</strong>
                </span>
              </div>

              <div className="mt-4 pt-3 border-t border-border/40 text-left space-y-1.5 text-xs">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Holder Name:</span>
                  <span className="font-semibold text-foreground">{activePerson.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Pass Identifier:</span>
                  <span className="font-mono text-primary font-bold">{activePerson.credential?.passId}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Designated Unit:</span>
                  <span className="font-medium text-foreground">{activePerson.propertyLabel}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Operational Hours:</span>
                  <span className="font-medium text-foreground">
                    {activePerson.accessRules?.entry_start_time || '00:00'} - {activePerson.accessRules?.entry_end_time || '23:59'}
                  </span>
                </div>
                {activePerson.accessRules?.requires_id_verification && (
                  <div className="mt-2 p-2 rounded bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[11px] flex items-center gap-1.5">
                    <ShieldAlert className="w-3.5 h-3.5 shrink-0" />
                    <span>Security Notice: Guard must inspect physical photo ID prior to admission.</span>
                  </div>
                )}
              </div>
            </div>

            <div className="flex items-center justify-between gap-2 pt-1">
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  onCopyLink(
                    `Access Credential for ${activePerson.name} (${activePerson.credential?.passId}): ${window.location.origin}/dashboard/gate-pass`
                  );
                }}
                className="flex-1 text-xs gap-1.5"
              >
                <Copy className="w-3.5 h-3.5" />
                <span>Copy Link</span>
              </Button>

              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  const text = encodeURIComponent(
                    `Your gate access pass for ${activePerson.propertyLabel} is ready: ${window.location.origin}/dashboard/gate-pass`
                  );
                  window.open(`https://wa.me/?text=${text}`, '_blank');
                }}
                className="flex-1 text-xs gap-1.5 text-emerald-400 border-emerald-500/30"
              >
                <Share2 className="w-3.5 h-3.5" />
                <span>WhatsApp</span>
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
