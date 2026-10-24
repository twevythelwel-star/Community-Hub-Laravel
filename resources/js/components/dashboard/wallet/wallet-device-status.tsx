import React from 'react';
import { 
  Nfc, 
  Radio, 
  RefreshCw, 
  ShieldCheck, 
  ShieldAlert, 
  ExternalLink, 
  Copy, 
  Check 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import type { WalletCredential, RevokedCredential } from './types';

interface WalletNfcTabProps {
  currentPass: WalletCredential;
  copiedKey: string | null;
  webNfcSupported: boolean;
  isWritingNfc: boolean;
  nfcWrittenFor: string | null;
  onCopy: (text: string, key: string) => void;
  onWriteNfcKeycard: () => void;
  onAbortWriteNfc: () => void;
}

export function WalletNfcTab({
  currentPass,
  copiedKey,
  webNfcSupported,
  isWritingNfc,
  nfcWrittenFor,
  onCopy,
  onWriteNfcKeycard,
  onAbortWriteNfc,
}: WalletNfcTabProps) {
  return (
    <CardContent className="p-6 sm:p-8 space-y-6">
      {/* Radar Wave Antenna Animation */}
      <div className="relative py-8 flex flex-col items-center justify-center text-center overflow-hidden rounded-2xl bg-gradient-to-b from-slate-950 via-slate-900 to-slate-950 border border-slate-800">
        {/* Glowing circular waves */}
        <div className="absolute w-48 h-48 rounded-full border border-emerald-500/20 animate-ping opacity-40" />
        <div className="absolute w-32 h-32 rounded-full border border-emerald-400/30 animate-pulse" />

        <div className="relative w-20 h-20 rounded-full bg-gradient-to-tr from-emerald-500/20 to-teal-500/30 border-2 border-emerald-400 flex items-center justify-center text-emerald-400 shadow-lg shadow-emerald-500/20">
          <Nfc className="w-10 h-10 animate-bounce" />
        </div>

        <div className="relative mt-4">
          <h3 className="text-lg font-bold text-white tracking-tight">
            Tap a Keycard at the Gate
          </h3>
          <p className="text-xs text-slate-400 max-w-sm mt-1 mx-auto">
            Write this pass to an NFC keycard or fob below, then tap it on the gate's reader. Your phone itself can't be tapped from a web page; use the QR code or a wallet pass for that.
          </p>
        </div>

        {/* Virtual NFC Card UID */}
        <div className="relative mt-5 flex items-center gap-2 bg-slate-900/90 border border-slate-700 px-4 py-2 rounded-xl">
          <span className="text-[11px] font-mono text-slate-400 uppercase">Card UID:</span>
          <span className="text-sm font-mono font-bold text-emerald-400 tracking-wider">
            {currentPass.nfc.card_uid}
          </span>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onCopy(currentPass.nfc.card_uid, 'NFC UID')}
            className="h-7 w-7 p-0 ml-1 text-slate-400 hover:text-white"
          >
            {copiedKey === 'NFC UID' ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
          </Button>
        </div>
      </div>

      {/* Write this pass to an NFC keycard (Web NFC, Chrome on Android) */}
      <div className="p-4 rounded-xl bg-slate-900/50 border border-border/80 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <p className="text-xs font-bold text-foreground flex items-center gap-1.5">
            <Radio className="w-3.5 h-3.5 text-emerald-400" />
            Write to an NFC keycard
          </p>
          <p className="text-[11px] text-muted-foreground mt-0.5">
            {!webNfcSupported
              ? 'Writing keycards needs Chrome on an Android phone with NFC switched on.'
              : !currentPass.is_active
                ? 'Only an active pass can be written to a keycard.'
                : isWritingNfc
                  ? 'Hold a blank NFC keycard or fob to the back of this phone.'
                  : `Puts ${currentPass.holder_name}'s signed gate code on a keycard or fob. Reporting the pass lost stops the keycard too.`}
          </p>
        </div>

        {webNfcSupported && (
          isWritingNfc ? (
            <Button
              variant="outline"
              onClick={onAbortWriteNfc}
              className="font-bold text-xs gap-2 shrink-0"
            >
              <RefreshCw className="w-3.5 h-3.5 animate-spin" />
              Waiting for keycard… Cancel
            </Button>
          ) : (
            <Button
              onClick={onWriteNfcKeycard}
              disabled={!currentPass.is_active}
              className="bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs gap-2 shrink-0"
            >
              <Nfc className="w-3.5 h-3.5" />
              Write keycard
            </Button>
          )
        )}
      </div>

      {nfcWrittenFor === currentPass.pass_id && (
        <div className="p-4 rounded-xl border bg-emerald-500/10 border-emerald-500/40 text-emerald-300 animate-in fade-in duration-300">
          <div className="flex items-start gap-3">
            <ShieldCheck className="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" />
            <div>
              <span className="font-bold text-sm">Keycard written</span>
              <p className="text-xs mt-1 text-slate-300">
                Tap it on the gate reader, or hand it to the guard to read with their phone.
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Physical Fob / ISO Specs */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
        <div className="p-3 rounded-lg border border-border/70 bg-card/40">
          <span className="font-bold text-foreground">Standard & Protocol:</span>
          <p className="text-muted-foreground mt-0.5 font-mono text-[11px]">
            {currentPass.nfc.protocol}
          </p>
        </div>
        <div className="p-3 rounded-lg border border-border/70 bg-card/40">
          <span className="font-bold text-foreground">Hardware Keycards & Fobs:</span>
          <p className="text-muted-foreground mt-0.5 text-[11px]">
            Physical RFID fobs registered to this UID will authenticate seamlessly at estate barriers.
          </p>
        </div>
      </div>
    </CardContent>
  );
}

interface WalletRevocationHistoryProps {
  revokedList: RevokedCredential[];
  onCopy: (text: string, key: string) => void;
}

export function WalletRevocationHistory({
  revokedList,
  onCopy,
}: WalletRevocationHistoryProps) {
  return (
    <Card className="border border-border/80 bg-card/90">
      <CardHeader className="pb-3 border-b border-border/60">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <ShieldAlert className="w-4 h-4 text-red-400" />
            <CardTitle className="text-base font-bold">
              Revocation & Lost Credential History ({revokedList.length})
            </CardTitle>
          </div>
          <Badge variant="outline" className="border-red-500/30 text-red-400 font-mono text-[10px]">
            Zero-Trust Directory
          </Badge>
        </div>
        <CardDescription className="text-xs">
          Permanently invalidated credentials. Attempting to scan any of these tokens at an estate gate scanner triggers an immediate <code className="text-red-400 font-mono">PASS_REVOKED_BY_ADMIN</code> refusal.
        </CardDescription>
      </CardHeader>
      <CardContent className="p-4 space-y-3">
        {revokedList.length === 0 ? (
          <p className="text-xs text-muted-foreground py-2 text-center">
            No credentials have been reported lost or revoked in this household.
          </p>
        ) : (
          <div className="divide-y divide-border/60">
            {revokedList.map((rev) => (
              <div key={rev.pass_id} className="py-3 first:pt-0 last:pb-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-foreground text-sm">{rev.holder_name}</span>
                    <Badge variant="destructive" className="text-[10px] font-mono px-1.5 py-0 bg-red-600/80">
                      REVOKED
                    </Badge>
                    <span className="text-xs font-mono text-muted-foreground">ID: {rev.pass_id}</span>
                  </div>
                  <p className="text-[11px] text-muted-foreground mt-0.5">
                    Revoked: <span className="font-mono text-slate-300">{rev.revoked_at_formatted || rev.revoked_at}</span> • Reason: <span className="italic">{rev.revocation_reason || 'Reported Lost/Stolen'}</span>
                  </p>
                </div>

                <div className="flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => window.open('/dashboard/gate-scanner', '_blank')}
                    className="h-7 text-xs border-red-500/30 text-red-400 hover:bg-red-500/10 gap-1"
                  >
                    <ExternalLink className="w-3 h-3" />
                    Test at Gate
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => onCopy(rev.pass_id, `Revoked ${rev.pass_id}`)}
                    className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                    title="Copy pass ID"
                  >
                    <Copy className="w-3.5 h-3.5" />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
