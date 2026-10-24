import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/hooks/use-toast';
import { isWebNfcSupported, nfcErrorMessage, writeNfcText } from '@/lib/web-nfc';
import {
  Wallet,
  QrCode,
  Nfc,
  Smartphone,
  ShieldCheck,
  ShieldAlert,
  Share2,
  CheckCircle2,
  Radio,
} from 'lucide-react';
import axios from 'axios';

import type { WalletCredential, RevokedCredential } from '@/components/dashboard/wallet/types';
import { 
  WalletPassRoster, 
  WalletQrPresentation, 
  WalletCardFooter 
} from '@/components/dashboard/wallet/wallet-pass-display';
import { 
  WalletMobileWalletsTab, 
  WalletIncidentBanner, 
  ShareMemberPassDialog, 
  ReportLostDialog 
} from '@/components/dashboard/wallet/wallet-provisioning';
import { 
  WalletNfcTab, 
  WalletRevocationHistory 
} from '@/components/dashboard/wallet/wallet-device-status';

export type { WalletCredential, RevokedCredential };

type Props = {
  credentials: WalletCredential[];
  revokedHistory?: RevokedCredential[];
  household?: {
    id: number;
    name: string;
    property_number: string;
    members_count: number;
  } | null;
  gates?: Array<{ id: string; name: string }>;
  user?: {
    id: number;
    name: string;
    role: string;
    canScan: boolean;
  };
};

export default function DigitalAccessWalletPage({
  credentials = [],
  revokedHistory = [],
  household,
}: Props) {
  const { toast } = useToast();
  const [credentialsList, setCredentialsList] = useState<WalletCredential[]>(credentials);
  const [revokedList, setRevokedList] = useState<RevokedCredential[]>(revokedHistory);
  const [selectedPassId, setSelectedPassId] = useState<string>(
    credentials[0]?.pass_id || ''
  );
  const [activeTab, setActiveTab] = useState<'qr' | 'nfc' | 'mobile_wallet'>('qr');
  const [secondsRemaining, setSecondsRemaining] = useState<number>(30);
  const [isWritingNfc, setIsWritingNfc] = useState<boolean>(false);
  const [nfcWrittenFor, setNfcWrittenFor] = useState<string | null>(null);
  const nfcWriteAbortRef = useRef<AbortController | null>(null);
  const [shareModalOpen, setShareModalOpen] = useState<boolean>(false);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [webNfcSupported, setWebNfcSupported] = useState<boolean>(false);

  // Lost / Stolen Credential Protocol State
  const [lostModalOpen, setLostModalOpen] = useState<boolean>(false);
  const [isReportingLost, setIsReportingLost] = useState<boolean>(false);
  const [reportReason, setReportReason] = useState<string>('Reported Lost/Stolen by Cardholder');
  const [lastIncident, setLastIncident] = useState<{
    revoked_pass_id: string;
    replacement_pass_id: string;
    revoked_at: string;
    holder_name: string;
  } | null>(null);

  // Selected pass record
  const currentPass = useMemo(() => {
    return credentialsList.find((c) => c.pass_id === selectedPassId) || credentialsList[0];
  }, [credentialsList, selectedPassId]);

  // Web NFC support
  useEffect(() => {
    setWebNfcSupported(isWebNfcSupported());
    return () => nfcWriteAbortRef.current?.abort();
  }, []);

  // Invalidate pending write on pass change
  useEffect(() => {
    nfcWriteAbortRef.current?.abort();
    setNfcWrittenFor(null);
  }, [currentPass?.pass_id]);

  // Rolling countdown timer
  useEffect(() => {
    if (!currentPass?.qr?.seconds_remaining) return;
    setSecondsRemaining(currentPass.qr.seconds_remaining);

    const interval = setInterval(() => {
      setSecondsRemaining((prev) => {
        if (prev <= 1) {
          return 30;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [currentPass?.pass_id]);

  // Copy helper
  const handleCopy = (text: string, key: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    toast({
      title: 'Copied to Clipboard',
      description: `Copied ${key} successfully.`,
    });
    setTimeout(() => setCopiedKey(null), 2500);
  };

  // Play synthetic tone on successful gate clearance
  const playClearanceChime = useCallback(() => {
    try {
      const AudioCtx = window.AudioContext || (window as any).webkitAudioContext;
      if (!AudioCtx) return;
      const ctx = new AudioCtx();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime);
      osc.frequency.setValueAtTime(880.0, ctx.currentTime + 0.1);
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
      osc.start();
      osc.stop(ctx.currentTime + 0.35);
    } catch {
      // Audio not permitted
    }
  }, []);

  // Write this pass's signed NFC code onto a keycard or fob
  const handleWriteNfcKeycard = async () => {
    if (!currentPass?.is_active) return;

    nfcWriteAbortRef.current?.abort();
    const controller = new AbortController();
    nfcWriteAbortRef.current = controller;
    setIsWritingNfc(true);
    setNfcWrittenFor(null);

    try {
      await writeNfcText(currentPass.nfc.payload, controller.signal);
      playClearanceChime();
      setNfcWrittenFor(currentPass.pass_id);
      toast({
        title: 'Keycard written',
        description: `${currentPass.holder_name}'s keycard now opens the gate. Reporting the pass lost stops it too.`,
      });
    } catch (err) {
      const message = nfcErrorMessage(err);
      if (message) {
        toast({ title: 'Keycard not written', description: message, variant: 'destructive' });
      }
    } finally {
      setIsWritingNfc(false);
    }
  };

  // Google Wallet handover
  const handleOpenGoogleWallet = async () => {
    if (!currentPass) return;
    try {
      const res = await axios.get<{ save_url: string }>(currentPass.wallet_integrations.google_wallet.save_url);
      window.location.assign(res.data.save_url);
    } catch (err: any) {
      toast({
        title: 'Google Wallet Error',
        description: err?.response?.data?.message ?? 'Unable to prepare the Google Wallet pass.',
        variant: 'destructive',
      });
    }
  };

  // One-Button Lost / Stolen Credential Protocol
  const handleReportLost = async (passIdToRevoke?: string) => {
    const targetPassId = passIdToRevoke || currentPass?.pass_id;
    if (!targetPassId) return;

    setIsReportingLost(true);
    try {
      const res = await axios.post('/dashboard/wallet/report-lost', {
        pass_id: targetPassId,
        reason: reportReason || 'Reported Lost/Stolen by Cardholder',
      });

      const { revoked_pass, replacement_pass, message } = res.data;

      setCredentialsList((prev) => {
        const filtered = prev.filter((p) => p.pass_id !== revoked_pass.pass_id);
        return [replacement_pass, ...filtered];
      });

      setRevokedList((prev) => [
        {
          id: revoked_pass.id || Date.now(),
          pass_id: revoked_pass.pass_id,
          holder_name: revoked_pass.holder_name,
          category: replacement_pass.category,
          category_label: replacement_pass.category_label,
          property: replacement_pass.property,
          status: 'REVOKED',
          revoked_at: revoked_pass.revoked_at,
          revoked_at_formatted: new Date(revoked_pass.revoked_at).toLocaleString(),
          revocation_reason: revoked_pass.revocation_reason,
          offline_pin: currentPass.offline_pin,
        },
        ...prev,
      ]);

      setSelectedPassId(replacement_pass.pass_id);

      setLastIncident({
        revoked_pass_id: revoked_pass.pass_id,
        replacement_pass_id: replacement_pass.pass_id,
        revoked_at: revoked_pass.revoked_at,
        holder_name: revoked_pass.holder_name,
      });

      setLostModalOpen(false);

      toast({
        title: '🚨 Credential REVOKED & Replaced',
        description: message,
      });
    } catch (err: any) {
      toast({
        title: 'Revocation Failed',
        description: err.response?.data?.message || 'Unable to revoke credential.',
        variant: 'destructive',
      });
    } finally {
      setIsReportingLost(false);
    }
  };

  if (!currentPass) {
    return (
      <DashboardLayout>
        <Head title="Access Wallet" />
        <div className="p-8 text-center text-muted-foreground">
          <Wallet className="w-12 h-12 mx-auto mb-3 opacity-40" />
          <h2 className="text-xl font-bold">No Digital Credentials Found</h2>
          <p className="mt-1">Please ensure your account has an active gate pass assigned.</p>
        </div>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout>
      <Head title="Digital Access Wallet — QR + NFC + Mobile Credential" />

      <div className="max-w-7xl mx-auto space-y-8 pb-16">
        {/* Top Header & Telemetry */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-border/60 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500/20 to-emerald-500/20 border border-amber-500/30 flex items-center justify-center text-amber-400 shadow-sm">
                <Wallet className="w-5 h-5" />
              </div>
              <div>
                <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-foreground flex items-center gap-2">
                  Digital Access Wallet
                  <Badge variant="outline" className="border-emerald-500/40 text-emerald-400 bg-emerald-500/10 text-xs py-0.5">
                    Live Credentials
                  </Badge>
                </h1>
                <p className="text-sm text-muted-foreground mt-0.5">
                  Multi-modal gate clearance: Dynamic ISO 18004 QR, Contactless NFC Tap & Native Mobile Wallets.
                </p>
              </div>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="secondary" className="px-3 py-1.5 flex items-center gap-1.5 bg-slate-900/80 border border-slate-700/80 text-slate-300 font-mono text-xs">
              <Radio className="w-3.5 h-3.5 text-emerald-400 animate-pulse" />
              NFC: 13.56 MHz High Frequency
            </Badge>

            <Badge variant="secondary" className="px-3 py-1.5 flex items-center gap-1.5 bg-slate-900/80 border border-slate-700/80 text-slate-300 font-mono text-xs">
              <ShieldCheck className="w-3.5 h-3.5 text-amber-400" />
              Guilloche Heraldic Shield
            </Badge>

            <Button
              variant="outline"
              size="sm"
              onClick={() => setShareModalOpen(true)}
              className="border-slate-700 hover:bg-slate-800 text-xs font-semibold gap-1.5"
            >
              <Share2 className="w-3.5 h-3.5 text-primary" />
              Share Member Pass
            </Button>
          </div>
        </div>

        {/* Main Grid: Stacked Wallet Cards (Left) & Credential Presentation (Right) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
          {/* Left Column: Interactive Wallet Card Stack */}
          <WalletPassRoster
            credentials={credentialsList}
            currentPass={currentPass}
            household={household}
            onSelectPassId={setSelectedPassId}
          />

          {/* Right Column: Multi-Modal Credential Card & Gate Handshake */}
          <div className="lg:col-span-8 space-y-6">
            {/* Real-Time Incident Banner after reporting lost */}
            {lastIncident && (
              <WalletIncidentBanner
                lastIncident={lastIncident}
                copiedKey={copiedKey}
                onCopy={handleCopy}
              />
            )}

            <Card className="border border-border/80 shadow-2xl bg-card/90 backdrop-blur overflow-hidden">
              {/* Card Banner */}
              <div className="relative p-6 sm:p-8 border-b border-border/60 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-950 text-white">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-xs uppercase font-mono tracking-widest text-amber-400 font-bold">
                        {currentPass.category_label} CREDENTIAL
                      </span>
                      <span className="text-slate-600">•</span>
                      <span className="text-xs font-mono text-emerald-400 flex items-center gap-1 font-semibold">
                        <CheckCircle2 className="w-3.5 h-3.5" />
                        {currentPass.status_label}
                      </span>
                    </div>

                    <h2 className="text-3xl font-black tracking-tight text-white mt-1">
                      {currentPass.holder_name}
                    </h2>

                    <div className="flex flex-wrap items-center gap-3 mt-2 text-xs text-slate-300">
                      <span className="font-medium bg-white/10 px-2.5 py-0.5 rounded-full border border-white/15">
                        {currentPass.relationship_label}
                      </span>
                      <span>Property: <strong className="text-white">{currentPass.property}</strong></span>
                      <span>Designated: <strong className="text-amber-300">{currentPass.designated_gate}</strong></span>
                    </div>
                  </div>

                  {/* Visual Category Enclosure Emblem & One-Button Lost Reporting */}
                  <div className="flex flex-col items-center sm:items-end justify-center gap-2">
                    <div className="flex items-center gap-2">
                      <div className="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 font-mono text-xs text-slate-300">
                        ID: <span className="text-amber-400 font-bold">{currentPass.pass_id}</span>
                      </div>
                      <span className="text-[11px] text-slate-400 font-mono">
                        PIN: {currentPass.offline_pin}
                      </span>
                    </div>

                    {/* ONE BUTTON: Report Credential Lost */}
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={() => setLostModalOpen(true)}
                      className="bg-red-600/90 hover:bg-red-600 text-white font-bold text-xs h-8 px-3 shadow-md gap-1.5 border border-red-500/40"
                      title="Immediately revoke this QR/NFC credential and generate a replacement"
                    >
                      <ShieldAlert className="w-3.5 h-3.5 text-white" />
                      <span>Report Credential Lost</span>
                    </Button>
                  </div>
                </div>

                {/* Multi-Modal Tab Navigation */}
                <div className="mt-8">
                  <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as any)}>
                    <TabsList className="grid grid-cols-3 bg-slate-950/80 border border-slate-800 p-1 rounded-xl">
                      <TabsTrigger
                        value="qr"
                        className="data-[state=active]:bg-gradient-to-r data-[state=active]:from-amber-500/20 data-[state=active]:to-emerald-500/20 data-[state=active]:text-white font-semibold text-xs sm:text-sm py-2 flex items-center gap-2"
                      >
                        <QrCode className="w-4 h-4 text-amber-400" />
                        <span>Dynamic QR</span>
                      </TabsTrigger>

                      <TabsTrigger
                        value="nfc"
                        className="data-[state=active]:bg-gradient-to-r data-[state=active]:from-emerald-500/20 data-[state=active]:to-sky-500/20 data-[state=active]:text-white font-semibold text-xs sm:text-sm py-2 flex items-center gap-2"
                      >
                        <Nfc className="w-4 h-4 text-emerald-400" />
                        <span>Contactless NFC</span>
                      </TabsTrigger>

                      <TabsTrigger
                        value="mobile_wallet"
                        className="data-[state=active]:bg-gradient-to-r data-[state=active]:from-indigo-500/20 data-[state=active]:to-purple-500/20 data-[state=active]:text-white font-semibold text-xs sm:text-sm py-2 flex items-center gap-2"
                      >
                        <Smartphone className="w-4 h-4 text-indigo-400" />
                        <span>Mobile Wallet</span>
                      </TabsTrigger>
                    </TabsList>
                  </Tabs>
                </div>
              </div>

              {/* Tab 1: Dynamic QR Code Presentation */}
              {activeTab === 'qr' && (
                <WalletQrPresentation
                  currentPass={currentPass}
                  secondsRemaining={secondsRemaining}
                  copiedKey={copiedKey}
                  onCopy={handleCopy}
                />
              )}

              {/* Tab 2: Contactless NFC Tap Presentation */}
              {activeTab === 'nfc' && (
                <WalletNfcTab
                  currentPass={currentPass}
                  copiedKey={copiedKey}
                  webNfcSupported={webNfcSupported}
                  isWritingNfc={isWritingNfc}
                  nfcWrittenFor={nfcWrittenFor}
                  onCopy={handleCopy}
                  onWriteNfcKeycard={handleWriteNfcKeycard}
                  onAbortWriteNfc={() => nfcWriteAbortRef.current?.abort()}
                />
              )}

              {/* Tab 3: Mobile Wallet Integrations */}
              {activeTab === 'mobile_wallet' && (
                <WalletMobileWalletsTab
                  currentPass={currentPass}
                  onOpenGoogleWallet={handleOpenGoogleWallet}
                  onOpenShareModal={() => setShareModalOpen(true)}
                />
              )}

              {/* Bottom Card Footer: Governance, Schedule & Security Policies */}
              <WalletCardFooter currentPass={currentPass} />
            </Card>

            {/* Revocation & Security Audit Log */}
            <WalletRevocationHistory
              revokedList={revokedList}
              onCopy={handleCopy}
            />
          </div>
        </div>
      </div>

      {/* Share Member Pass Modal */}
      <ShareMemberPassDialog
        open={shareModalOpen}
        onOpenChange={setShareModalOpen}
        currentPass={currentPass}
        householdName={household?.name}
      />

      {/* Report Credential Lost / Stolen Protocol Dialog */}
      <ReportLostDialog
        open={lostModalOpen}
        onOpenChange={setLostModalOpen}
        currentPass={currentPass}
        reportReason={reportReason}
        onReportReasonChange={setReportReason}
        isReportingLost={isReportingLost}
        onConfirmReportLost={() => handleReportLost()}
      />
    </DashboardLayout>
  );
}
