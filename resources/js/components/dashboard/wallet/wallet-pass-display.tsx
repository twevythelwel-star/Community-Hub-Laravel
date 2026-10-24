import React from 'react';
import { 
  QrCode, 
  KeyRound, 
  RefreshCw, 
  Copy, 
  Check, 
  Calendar, 
  ShieldCheck, 
  PhoneCall, 
  Layers, 
  Clock, 
  Users 
} from 'lucide-react';
import { CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ProfileCustomQRCode } from '@/components/dashboard/ProfileCustomQRCode';
import type { PassCategory } from '@/lib/gate-pass-engine/types';
import { cn } from '@/lib/utils';
import type { WalletCredential } from './types';

interface WalletPassRosterProps {
  credentials: WalletCredential[];
  currentPass: WalletCredential;
  household?: {
    id: number;
    name: string;
    property_number: string;
    members_count: number;
  } | null;
  onSelectPassId: (passId: string) => void;
}

export function WalletPassRoster({
  credentials,
  currentPass,
  household,
  onSelectPassId,
}: WalletPassRosterProps) {
  return (
    <div className="lg:col-span-4 space-y-4">
      <div className="flex items-center justify-between px-1">
        <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
          <Layers className="w-4 h-4 text-primary" />
          Active Pass Roster ({credentials.length})
        </span>
        {household && (
          <span className="text-xs text-muted-foreground font-mono">
            {household.name} • {household.property_number}
          </span>
        )}
      </div>

      <div className="space-y-3">
        {credentials.map((cred) => {
          const isSelected = cred.pass_id === currentPass.pass_id;
          const isCurfew = cred.schedule?.curfew_enabled;
          const isCaregiver = cred.role_in_household === 'caregiver';

          return (
            <div
              key={cred.pass_id}
              onClick={() => onSelectPassId(cred.pass_id)}
              className={cn(
                "relative p-4 rounded-2xl border transition-all duration-300 cursor-pointer overflow-hidden",
                isSelected
                  ? "bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 border-amber-500/60 shadow-xl shadow-amber-500/5 ring-1 ring-amber-500/40 translate-x-1"
                  : "bg-card/60 hover:bg-card border-border/70 hover:border-border opacity-85 hover:opacity-100 hover:translate-x-0.5"
              )}
            >
              {/* Metallic highlight ribbon */}
              <div
                className="absolute top-0 left-0 right-0 h-1"
                style={{
                  background: isSelected
                    ? 'linear-gradient(90deg, #F59E0B, #10B981, #6366F1)'
                    : 'transparent',
                }}
              />

              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-foreground text-base tracking-tight">
                      {cred.holder_name}
                    </span>
                    {isSelected && (
                      <span className="w-2 h-2 rounded-full bg-emerald-400 animate-ping" />
                    )}
                  </div>
                  <p className="text-xs text-muted-foreground mt-0.5 flex items-center gap-1.5">
                    <span className="font-semibold text-primary/90">{cred.relationship_label}</span>
                    <span>•</span>
                    <span className="font-mono text-[11px]">{cred.property}</span>
                  </p>
                </div>

                <Badge
                  variant="outline"
                  className={cn(
                    "text-[10px] uppercase font-mono px-2 py-0.5 border font-semibold",
                    isSelected
                      ? "border-amber-400/50 text-amber-300 bg-amber-400/10"
                      : "border-border text-muted-foreground"
                  )}
                >
                  {cred.category_label}
                </Badge>
              </div>

              {/* Operational constraints tags */}
              <div className="mt-3 flex flex-wrap items-center gap-1.5">
                <span className="text-[11px] font-mono text-muted-foreground bg-slate-800/80 px-2 py-0.5 rounded border border-slate-700/60 flex items-center gap-1">
                  <KeyRound className="w-3 h-3 text-amber-400" />
                  {cred.pass_id}
                </span>

                {isCurfew && (
                  <span className="text-[11px] font-semibold text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/30 flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    Curfew: 6am–9pm
                  </span>
                )}

                {isCaregiver && cred.schedule?.hours && (
                  <span className="text-[11px] font-semibold text-sky-400 bg-sky-500/10 px-2 py-0.5 rounded border border-sky-500/30 flex items-center gap-1">
                    <Calendar className="w-3 h-3" />
                    Mon–Fri Shift
                  </span>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Quick Household Switcher Notice */}
      <div className="p-4 rounded-xl border border-dashed border-border/80 bg-muted/20 text-xs text-muted-foreground flex items-start gap-2.5">
        <Users className="w-4 h-4 text-primary shrink-0 mt-0.5" />
        <div>
          <p className="font-medium text-foreground">Multi-Person Household Wallet</p>
          <p className="mt-0.5">
            Select any family member or caregiver above to view their active QR pass, write to an NFC keycard, or export to Apple/Google Wallet.
          </p>
        </div>
      </div>
    </div>
  );
}

interface WalletQrPresentationProps {
  currentPass: WalletCredential;
  secondsRemaining: number;
  copiedKey: string | null;
  onCopy: (text: string, key: string) => void;
}

export function WalletQrPresentation({
  currentPass,
  secondsRemaining,
  copiedKey,
  onCopy,
}: WalletQrPresentationProps) {
  return (
    <CardContent className="p-6 sm:p-8 space-y-6">
      <div className="flex flex-col items-center justify-center text-center">
        {/* QR Code in its profile bezel */}
        <div className="relative p-6 rounded-3xl bg-slate-950/90 border border-border shadow-2xl">
          <ProfileCustomQRCode
            value={currentPass.qr.token || currentPass.pass_id}
            category={currentPass.category as PassCategory}
            size={220}
          />

          {/* Rolling Countdown Progress Indicator */}
          <div className="mt-4 flex items-center justify-center gap-2 font-mono text-xs text-muted-foreground">
            <RefreshCw className={cn("w-3.5 h-3.5 text-emerald-400", secondsRemaining < 6 && "animate-spin text-amber-400")} />
            <span>Token rotates in <strong className="text-foreground">{secondsRemaining}s</strong></span>
          </div>
        </div>

        <div className="mt-4 max-w-md">
          <p className="text-xs text-muted-foreground">
            Certified ISO/IEC 18004 Tamper-Evident Anti-Replay Format. Automatically rolls every 30 seconds to prevent unauthorized screenshots or forwarding.
          </p>
        </div>
      </div>

      {/* Fallback Offline Gate PIN */}
      <div className="p-4 rounded-xl bg-muted/40 border border-border/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-500 shrink-0">
            <KeyRound className="w-4 h-4" />
          </div>
          <div>
            <p className="text-xs font-semibold text-foreground">Offline Gate Keypad PIN</p>
            <p className="text-[11px] text-muted-foreground">Use if gate optical scanner is impaired or during cellular outage.</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <code className="text-base font-black tracking-widest px-3 py-1 rounded bg-slate-900 text-amber-400 border border-slate-700 font-mono">
            {currentPass.offline_pin}
          </code>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onCopy(currentPass.offline_pin, 'PIN')}
            className="h-8 w-8 p-0"
          >
            {copiedKey === 'PIN' ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
          </Button>
        </div>
      </div>
    </CardContent>
  );
}

interface WalletCardFooterProps {
  currentPass: WalletCredential;
}

export function WalletCardFooter({ currentPass }: WalletCardFooterProps) {
  return (
    <div className="p-6 bg-muted/20 border-t border-border/60 grid grid-cols-1 md:grid-cols-3 gap-6">
      <div>
        <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
          <Calendar className="w-3.5 h-3.5 text-primary" />
          Access Schedule & Curfew
        </span>
        <div className="mt-2 text-xs space-y-1">
          {currentPass.schedule?.curfew_enabled ? (
            <div className="p-2 rounded bg-amber-500/10 border border-amber-500/30 text-amber-300">
              <strong>Curfew Active:</strong> {currentPass.schedule.curfew_hours}
            </div>
          ) : currentPass.schedule?.days ? (
            <div className="p-2 rounded bg-sky-500/10 border border-sky-500/30 text-sky-300">
              <strong>Shift Hours:</strong> {currentPass.schedule.hours}
              <div className="text-[11px] opacity-80 mt-0.5">
                Days: {currentPass.schedule.days.join(', ')}
              </div>
            </div>
          ) : (
            <div className="p-2 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300">
              <strong>24/7 Clearance:</strong> Round-the-clock estate gate access.
            </div>
          )}
        </div>
      </div>

      <div>
        <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
          <ShieldCheck className="w-3.5 h-3.5 text-primary" />
          Granted Privileges
        </span>
        <div className="mt-2 flex flex-wrap gap-1.5">
          {(currentPass.permissions || ['gate_access_24_7', 'receive_emergency_alerts']).map((perm) => (
            <Badge key={perm} variant="secondary" className="text-[10px] font-mono py-0.5">
              {perm.replace(/_/g, ' ')}
            </Badge>
          ))}
        </div>
      </div>

      <div>
        <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
          <PhoneCall className="w-3.5 h-3.5 text-primary" />
          Estate Dispatch
        </span>
        <p className="text-xs text-muted-foreground mt-2">
          Security Gatehouse: <strong className="text-foreground">+1 (876) 555-GATE</strong>
        </p>
        <p className="text-xs text-muted-foreground mt-0.5">
          Emergency Police / Fire: <strong className="text-red-400">119</strong>
        </p>
      </div>
    </div>
  );
}
