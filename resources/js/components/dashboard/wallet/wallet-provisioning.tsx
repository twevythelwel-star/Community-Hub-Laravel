import React from 'react';
import { 
  Download, 
  Sparkles, 
  Share2, 
  ShieldAlert, 
  AlertCircle, 
  ExternalLink, 
  Copy, 
  Check, 
  RefreshCw, 
  Smartphone 
} from 'lucide-react';
import { CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import type { WalletCredential } from './types';

interface WalletMobileWalletsTabProps {
  currentPass: WalletCredential;
  onOpenGoogleWallet: () => void;
  onOpenShareModal: () => void;
}

export function WalletMobileWalletsTab({
  currentPass,
  onOpenGoogleWallet,
  onOpenShareModal,
}: WalletMobileWalletsTabProps) {
  return (
    <CardContent className="p-6 sm:p-8 space-y-6">
      <div>
        <h3 className="text-base font-bold text-foreground">
          Native Mobile Wallets (No App Required)
        </h3>
        <p className="text-xs text-muted-foreground mt-0.5">
          Add your pass to Apple Wallet or Google Wallet for one-tap access from the lock screen.
        </p>
      </div>

      {!currentPass.wallet_integrations.apple_wallet.available &&
        !currentPass.wallet_integrations.google_wallet.available && (
          <div className="p-4 rounded-xl border border-border bg-muted/40 text-xs text-muted-foreground">
            Wallet passes aren't set up for this estate yet. Use the QR code in the first tab at the gate.
          </div>
        )}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {/* 1. Apple Wallet (.pkpass) */}
        {currentPass.wallet_integrations.apple_wallet.available && (
          <div className="p-5 rounded-2xl bg-black border border-slate-800 text-white flex flex-col justify-between space-y-4 shadow-lg hover:border-slate-700 transition-all">
            <div>
              <div className="flex items-center justify-between">
                <span className="text-xs font-mono font-bold text-slate-400 uppercase tracking-wider">
                  iOS / macOS
                </span>
                <Badge variant="outline" className="border-white/20 text-white text-[10px]">
                  .pkpass
                </Badge>
              </div>
              <h4 className="text-lg font-black tracking-tight mt-2 flex items-center gap-1.5">
                Apple Wallet
              </h4>
              <p className="text-xs text-slate-400 mt-1">
                A signed pass with your gate code, shown on the lock screen.
              </p>
            </div>

            <a
              href={currentPass.wallet_integrations.apple_wallet.download_url}
              download={currentPass.wallet_integrations.apple_wallet.file_name}
              className="w-full"
            >
              <Button className="w-full bg-white hover:bg-slate-200 text-black font-bold text-xs gap-1.5">
                <Download className="w-3.5 h-3.5" />
                Add to Apple Wallet
              </Button>
            </a>
          </div>
        )}

        {/* 2. Google Wallet */}
        {currentPass.wallet_integrations.google_wallet.available && (
          <div className="p-5 rounded-2xl bg-slate-900 border border-slate-800 text-white flex flex-col justify-between space-y-4 shadow-lg hover:border-slate-700 transition-all">
            <div>
              <div className="flex items-center justify-between">
                <span className="text-xs font-mono font-bold text-emerald-400 uppercase tracking-wider">
                  Android / Wear OS
                </span>
              </div>
              <h4 className="text-lg font-black tracking-tight mt-2 flex items-center gap-1.5">
                Google Wallet
              </h4>
              <p className="text-xs text-slate-400 mt-1">
                Saves the pass with your gate code to Google Wallet.
              </p>
            </div>

            <Button
              onClick={onOpenGoogleWallet}
              className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs gap-1.5"
            >
              <Sparkles className="w-3.5 h-3.5" />
              Save to Google Wallet
            </Button>
          </div>
        )}
      </div>

      {/* Household Pass Dispatching */}
      <div className="p-4 rounded-xl border border-primary/20 bg-primary/5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <p className="text-xs font-bold text-foreground">
            Send Wallet Pass to {currentPass.holder_name}
          </p>
          <p className="text-[11px] text-muted-foreground mt-0.5">
            Transmit the Apple/Google Wallet pass link directly to this household member's phone via SMS or WhatsApp.
          </p>
        </div>

        <Button
          size="sm"
          onClick={onOpenShareModal}
          className="bg-primary hover:bg-primary/90 text-primary-foreground font-bold text-xs gap-1.5 shrink-0"
        >
          <Share2 className="w-3.5 h-3.5" />
          Dispatch Member Pass
        </Button>
      </div>
    </CardContent>
  );
}

interface WalletIncidentBannerProps {
  lastIncident: {
    revoked_pass_id: string;
    replacement_pass_id: string;
    revoked_at: string;
    holder_name: string;
  };
  copiedKey: string | null;
  onCopy: (text: string, key: string) => void;
}

export function WalletIncidentBanner({
  lastIncident,
  copiedKey,
  onCopy,
}: WalletIncidentBannerProps) {
  return (
    <div className="p-4 rounded-2xl bg-gradient-to-r from-red-950/90 via-slate-900 to-amber-950/90 border border-red-500/50 text-white shadow-xl space-y-2 animate-in fade-in duration-300">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-red-500/20 border border-red-500/40 flex items-center justify-center text-red-400 shrink-0">
            <ShieldAlert className="w-5 h-5 animate-pulse" />
          </div>
          <div>
            <h4 className="font-bold text-sm text-red-200 flex items-center gap-2">
              Credential #{lastIncident.revoked_pass_id} REVOKED Immediately
              <Badge variant="destructive" className="font-mono text-[9px] uppercase px-1.5 py-0 bg-red-600 text-white">
                Screenshots Dead
              </Badge>
            </h4>
            <p className="text-xs text-slate-300 mt-0.5">
              All prior screenshots, exported passes, and physical NFC clones have been permanently invalidated. New credential <strong className="text-emerald-400 font-mono">#{lastIncident.replacement_pass_id}</strong> is active.
            </p>
          </div>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2 pt-1 border-t border-red-500/20">
        <Button
          size="sm"
          variant="outline"
          onClick={() => window.open('/dashboard/gate-scanner', '_blank')}
          className="h-7 text-xs border-red-400/40 text-red-300 hover:bg-red-500/10 gap-1.5"
        >
          <ExternalLink className="w-3 h-3" />
          Verify Rejection in Gate Scanner Kiosk
        </Button>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => onCopy(lastIncident.revoked_pass_id, 'Revoked Pass ID')}
          className="h-7 text-xs text-slate-300 hover:text-white gap-1"
        >
          {copiedKey === 'Revoked Pass ID' ? <Check className="w-3 h-3 text-emerald-400" /> : <Copy className="w-3 h-3" />}
          Copy Revoked Pass ID ({lastIncident.revoked_pass_id})
        </Button>
      </div>
    </div>
  );
}

interface ShareMemberPassDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  currentPass: WalletCredential;
  householdName?: string;
}

export function ShareMemberPassDialog({
  open,
  onOpenChange,
  currentPass,
  householdName,
}: ShareMemberPassDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Share2 className="w-5 h-5 text-primary" />
            Dispatch Credential to {currentPass.holder_name}
          </DialogTitle>
          <DialogDescription>
            Share the mobile digital wallet pass directly with this household member.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 my-2">
          <div className="p-3 rounded-xl bg-muted/40 border text-xs space-y-2">
            <p className="font-semibold text-foreground">Message Preview:</p>
            <p className="text-muted-foreground italic">
              "Hello {currentPass.holder_name}, your official {householdName || 'Community Hub'} gate access pass ({currentPass.pass_id}) is ready. Add to Apple Wallet or tap at the gate with NFC: {typeof window !== 'undefined' ? window.location.origin : ''}/dashboard/wallet"
            </p>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Button
              variant="outline"
              onClick={() => {
                const msg = encodeURIComponent(`Hello ${currentPass.holder_name}, your official Community Hub gate pass (${currentPass.pass_id}) is ready: ${window.location.origin}/dashboard/wallet`);
                window.open(`https://wa.me/?text=${msg}`, '_blank');
              }}
              className="gap-2 text-xs font-semibold"
            >
              <Share2 className="w-4 h-4 text-emerald-500" />
              WhatsApp
            </Button>

            <Button
              variant="outline"
              onClick={() => {
                const msg = encodeURIComponent(`Your gate pass (${currentPass.pass_id}) is ready: ${window.location.origin}/dashboard/wallet`);
                window.open(`sms:?body=${msg}`, '_blank');
              }}
              className="gap-2 text-xs font-semibold"
            >
              <Smartphone className="w-4 h-4 text-primary" />
              SMS Text
            </Button>
          </div>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

interface ReportLostDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  currentPass: WalletCredential;
  reportReason: string;
  onReportReasonChange: (reason: string) => void;
  isReportingLost: boolean;
  onConfirmReportLost: () => void;
}

export function ReportLostDialog({
  open,
  onOpenChange,
  currentPass,
  reportReason,
  onReportReasonChange,
  isReportingLost,
  onConfirmReportLost,
}: ReportLostDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg border-red-500/30">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-red-500">
            <ShieldAlert className="w-5 h-5 text-red-500" />
            Report Credential Lost / Stolen
          </DialogTitle>
          <DialogDescription>
            Immediate one-button credential revocation and tamper-proof replacement protocol.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 my-2">
          {/* Warning banner explaining screenshot invalidation */}
          <div className="p-4 rounded-xl bg-red-500/10 border border-red-500/30 text-xs space-y-2">
            <div className="flex items-center gap-2 font-bold text-red-400">
              <AlertCircle className="w-4 h-4 shrink-0" />
              Permanent Cryptographic Revocation
            </div>
            <p className="text-slate-300 leading-relaxed">
              Once confirmed, <strong>{currentPass.holder_name}</strong>'s current QR pass (<code className="font-mono text-amber-300">{currentPass.pass_id}</code>) will be permanently <strong className="text-red-400">REVOKED</strong> in the estate security directory.
            </p>
            <div className="p-2.5 rounded-lg bg-black/40 border border-white/10 font-mono text-[11px] text-amber-200">
              ⚠️ Connected gate scanners reject previous screenshots, forwarded QR codes, and cloned NFC cards immediately. Scans queued during a network outage are revalidated when they sync.
            </div>
          </div>

          <div className="p-3 rounded-xl bg-slate-900 border border-slate-800 text-xs space-y-1.5">
            <span className="font-semibold text-slate-300">Automatic Replacement:</span>
            <p className="text-slate-400">
              A brand-new replacement credential will be issued immediately with an incremented rotation sequence, fresh HMAC signature, and new offline keypad PIN.
            </p>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-foreground">Incident / Revocation Reason</label>
            <input
              type="text"
              value={reportReason}
              onChange={(e) => onReportReasonChange(e.target.value)}
              placeholder="e.g. Phone stolen on commute, leaked screenshot, misplaced key fob"
              className="w-full text-xs p-2.5 rounded-lg bg-background border border-border focus:border-red-500 focus:outline-none"
            />
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={isReportingLost}>
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={onConfirmReportLost}
            disabled={isReportingLost}
            className="bg-red-600 hover:bg-red-700 text-white font-bold gap-2"
          >
            {isReportingLost ? (
              <>
                <RefreshCw className="w-4 h-4 animate-spin" />
                Revoking & Reissuing...
              </>
            ) : (
              <>
                <ShieldAlert className="w-4 h-4" />
                Confirm Revocation & Generate Replacement
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
