import React from 'react';
import { 
  Smartphone, 
  ShieldCheck, 
  Check, 
  AlertCircle 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { 
  SimulatedDeviceType, 
  WalletCapability 
} from '@/lib/payment-capabilities';

interface WalletManagementCardProps {
  deviceSim: SimulatedDeviceType;
  profileLabel: string;
  onDeviceSimChange: (mode: SimulatedDeviceType) => void;
  infoModalOpen: boolean;
  onInfoModalOpenChange: (open: boolean) => void;
  selectedWalletInfo: WalletCapability | null;
}

export function WalletManagementCard({
  deviceSim,
  profileLabel,
  onDeviceSimChange,
  infoModalOpen,
  onInfoModalOpenChange,
  selectedWalletInfo,
}: WalletManagementCardProps) {
  return (
    <>
      {/* ── DEVICE SIMULATOR / CAPABILITY PREVIEW BAR ───────────────────── */}
      <div className="p-3 bg-muted/40 rounded-xl border border-border/60 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
        <div className="flex items-center gap-2">
          <Smartphone className="w-4 h-4 text-primary" />
          <span className="font-semibold text-foreground">Device Capability Detection:</span>
          <span className="text-muted-foreground">({profileLabel})</span>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="text-muted-foreground mr-1 text-[11px]">Preview as:</span>
          {(
            [
              { key: 'real', label: 'Active Device' },
              { key: 'iphone', label: 'iPhone (iOS)' },
              { key: 'samsung', label: 'Samsung Galaxy' },
              { key: 'desktop', label: 'Desktop Browser' },
              { key: 'terminal', label: 'Office Terminal (POS)' },
            ] as const
          ).map((item) => (
            <button
              key={item.key}
              type="button"
              onClick={() => onDeviceSimChange(item.key)}
              className={`px-2.5 py-1 rounded-md text-xs font-medium transition-all ${
                deviceSim === item.key
                  ? 'bg-primary text-primary-foreground shadow-sm'
                  : 'bg-background hover:bg-muted text-muted-foreground border border-border/50'
              }`}
            >
              {item.label}
            </button>
          ))}
        </div>
      </div>

      {/* ── ZERO-TRUST NON-CUSTODIAL ARCHITECTURE CARD ──────────────────── */}
      <Card className="border-border/70 bg-gradient-to-br from-card via-muted/15 to-muted/30 shadow-sm overflow-hidden">
        <CardHeader className="pb-3 border-b border-border/40 bg-muted/10">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                <ShieldCheck className="w-4 h-4" />
              </div>
              <div>
                <CardTitle className="text-sm font-bold text-foreground">
                  Security Architecture: Non-Custodial Tokenization
                </CardTitle>
                <CardDescription className="text-[11px]">
                  CommunityHub never stores Apple Pay, Google Pay, Samsung Pay credentials or raw card details.
                </CardDescription>
              </div>
            </div>
            <Badge variant="outline" className="text-[10px] uppercase font-mono text-emerald-600 dark:text-emerald-400 border-emerald-500/30">
              PCI-DSS Level 1 Safe
            </Badge>
          </div>
        </CardHeader>

        <CardContent className="pt-4 space-y-4 text-xs">
          {/* Visual Architecture Flow */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            {/* Step 1: CommunityHub */}
            <div className="p-3.5 rounded-xl border border-border/70 bg-card space-y-2 shadow-xs">
              <div className="flex items-center justify-between">
                <span className="font-bold text-foreground text-xs flex items-center gap-1.5">
                  <span className="w-2 h-2 rounded-full bg-primary" /> CommunityHub
                </span>
                <Badge variant="outline" className="text-[9px] uppercase font-mono">App Layer</Badge>
              </div>
              <p className="text-[11px] text-muted-foreground leading-relaxed">
                Holds only customer/method tokens (<code className="font-mono text-primary text-[10px]">cus_...</code> / <code className="font-mono text-primary text-[10px]">pm_...</code>) and property ledger links.
              </p>
              <div className="pt-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
                <Check className="w-3 h-3" /> Zero raw credentials stored
              </div>
            </div>

            {/* Step 2: Payment Provider */}
            <div className="p-3.5 rounded-xl border border-primary/30 bg-primary/5 space-y-2 shadow-xs">
              <div className="flex items-center justify-between">
                <span className="font-bold text-foreground text-xs flex items-center gap-1.5">
                  <span className="w-2 h-2 rounded-full bg-sky-500" /> Payment Provider
                </span>
                <Badge variant="outline" className="text-[9px] uppercase font-mono">Stripe / WiPay</Badge>
              </div>
              <p className="text-[11px] text-muted-foreground leading-relaxed">
                PCI-DSS Level 1 certified gateway. Validates merchant sessions, processes tokens, and coordinates settlement.
              </p>
              <div className="pt-1 text-[10px] text-sky-600 dark:text-sky-400 font-semibold flex items-center gap-1">
                <Check className="w-3 h-3" /> Provider / Customer Reference
              </div>
            </div>

            {/* Step 3: Biometric Device Vault */}
            <div className="p-3.5 rounded-xl border border-border/70 bg-card space-y-2 shadow-xs">
              <div className="flex items-center justify-between">
                <span className="font-bold text-foreground text-xs flex items-center gap-1.5">
                  <span className="w-2 h-2 rounded-full bg-emerald-500" /> Biometric Wallet Vault
                </span>
                <Badge variant="outline" className="text-[9px] uppercase font-mono">Device Enclave</Badge>
              </div>
              <p className="text-[11px] text-muted-foreground leading-relaxed">
                Apple Secure Element, Google DPAN, Samsung Knox keystore, or EMV chip. Unlocked strictly via Face ID / Fingerprint.
              </p>
              <div className="pt-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1">
                <Check className="w-3 h-3" /> Hardware Secure Element
              </div>
            </div>
          </div>

          {/* Clean ASCII Connection Chain matching specification */}
          <div className="p-3 rounded-lg bg-muted/40 border border-border/50 font-mono text-[11px] text-muted-foreground flex flex-col sm:flex-row items-center justify-around gap-2 text-center">
            <div>
              <strong className="text-foreground text-xs">CommunityHub</strong>
              <p className="text-[10px] text-muted-foreground">provider/customer reference</p>
            </div>
            <span className="text-primary font-bold hidden sm:inline">│ ──▶</span>
            <span className="text-primary font-bold sm:hidden">▼</span>
            <div>
              <strong className="text-foreground text-xs">Payment Provider</strong>
              <p className="text-[10px] text-muted-foreground">Stripe / WiPay Tokenization</p>
            </div>
            <span className="text-primary font-bold hidden sm:inline">│ ──▶</span>
            <span className="text-primary font-bold sm:hidden">▼</span>
            <div>
              <strong className="text-foreground text-xs">Wallet / Card Credentials</strong>
              <p className="text-[10px] text-muted-foreground">Device Hardware Secure Enclave</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* ── INCOMPATIBLE HARDWARE HELPER MODAL ───────────────────────── */}
      <Dialog open={infoModalOpen} onOpenChange={onInfoModalOpenChange}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-lg">
              <AlertCircle className="w-5 h-5 text-amber-500" />
              {selectedWalletInfo?.name}: Device Incompatible
            </DialogTitle>
            <DialogDescription className="text-xs">
              Hardware capability requirement details for this payment method.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2 text-xs">
            <div className="p-3 bg-muted/40 rounded-lg border border-border/60 space-y-1.5">
              <p className="font-semibold text-foreground">Why is this option not available?</p>
              <p className="text-muted-foreground leading-relaxed">
                {selectedWalletInfo?.reason}
              </p>
            </div>

            <div className="space-y-2 text-muted-foreground text-[11px]">
              <p className="font-semibold text-foreground text-xs">Required Hardware & Platforms:</p>
              {selectedWalletInfo?.id === 'apple_pay' && (
                <ul className="list-disc pl-4 space-y-1">
                  <li>Apple iPhone, iPad, or Apple Watch with Touch ID / Face ID.</li>
                  <li>Safari browser on iOS or macOS with configured Apple Wallet.</li>
                </ul>
              )}
              {selectedWalletInfo?.id === 'samsung_pay' && (
                <ul className="list-disc pl-4 space-y-1">
                  <li>Samsung Galaxy smartphone with Samsung Wallet & Knox Security.</li>
                  <li>Not supported on iPhone, desktop PC, or non-Samsung Android devices.</li>
                </ul>
              )}
              {selectedWalletInfo?.id === 'tap_nfc' && (
                <ul className="list-disc pl-4 space-y-1">
                  <li>Tapping a physical card requires an active card-present terminal (such as Stripe Terminal or an authorized Tap-to-Pay reader).</li>
                  <li>Desktop browsers and standard mobile web browsers do not have physical contactless card readers.</li>
                  <li>To tap a physical card, visit the community management office where registered readers are operational.</li>
                </ul>
              )}
              {selectedWalletInfo?.id === 'google_pay' && (
                <ul className="list-disc pl-4 space-y-1">
                  <li>Android device with Google Wallet or Google Play Services.</li>
                  <li>Chrome / Edge browser with cards saved in your Google Account.</li>
                </ul>
              )}
            </div>

            <div className="pt-2 flex justify-end">
              <Button onClick={() => onInfoModalOpenChange(false)} size="sm">
                Understood
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
