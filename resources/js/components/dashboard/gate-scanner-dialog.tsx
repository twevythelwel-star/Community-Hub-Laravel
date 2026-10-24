
import React, { useState, useEffect, useCallback } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { 
  Shield, 
  ShieldCheck, 
  ShieldAlert, 
  CheckCircle2, 
  XCircle, 
  Scan, 
  Clock, 
  MapPin, 
  Lock, 
  Unlock, 
  AlertTriangle,
  RotateCcw,
  Sparkles,
  Layers,
  ArrowRight,
  ExternalLink
} from 'lucide-react';
import { 
  GateId, 
  PassCategory, 
  GatePassValidationReport 
} from '@/lib/gate-pass-engine/types';
import { getCategoryConfig } from '@/lib/gate-pass-engine/config';
import {
  scanGatePassToken,
  fetchGatePassToken,
  describeRequestError,
} from '@/lib/gate-pass-engine/api';
import { CategoryShapeIcon } from '@/lib/gate-pass-engine/shapes';
import { useToast } from '@/hooks/use-toast';
import { cn } from '@/lib/utils';
import { SilentAssistanceBanner } from '@/components/dashboard/silent-assistance-banner';

interface GateScannerDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  initialToken?: string;
}

/**
 * Gate verification console.
 *
 * Validation now happens on the server. The previous version called
 * validateGatePassToken() in the browser with `bypassReplayCheck: true`, which
 * meant the client both decided the outcome and could switch off replay
 * detection — neither is possible any more.
 *
 * Two consequences worth knowing:
 *
 *  1. The demo presets that minted tokens for arbitrary identities are gone.
 *     Forging a pass for another resident required the signing key, which is now
 *     server-only. The remaining diagnostics corrupt the operator's *own* real
 *     token, so they exercise the genuine server pipeline.
 *
 *  2. Every scan is recorded. The server writes the access-log entry in the same
 *     transaction as the decision, so a denial cannot be discarded by closing
 *     this dialog.
 */
export function GateScannerDialog({
  open,
  onOpenChange,
  initialToken,
}: GateScannerDialogProps) {
  const { toast } = useToast();
  const [selectedGate, setSelectedGate] = useState<GateId>('GATE-01');
  const [inputToken, setInputToken] = useState<string>(initialToken || '');
  const [report, setReport] = useState<GatePassValidationReport | null>(null);
  const [isScanning, setIsScanning] = useState(false);
  const [scanError, setScanError] = useState<string | null>(null);
  const [accessLogId, setAccessLogId] = useState<number | null>(null);

  const runValidation = useCallback(
    async (tokenToValidate: string, gate: GateId) => {
      setIsScanning(true);
      setScanError(null);

      try {
        const result = await scanGatePassToken(tokenToValidate, gate);

        setReport(result.report);
        setAccessLogId(result.accessLogId);
      } catch (error) {
        setReport(null);
        setAccessLogId(null);
        setScanError(describeRequestError(error));
      } finally {
        setIsScanning(false);
      }
    },
    [],
  );

  // Auto-scan a token handed in by the pass card.
  useEffect(() => {
    if (open && initialToken) {
      setInputToken(initialToken);
      void runValidation(initialToken, selectedGate);
    }
    // selectedGate is deliberately excluded: changing the gate should not
    // silently re-scan and write another access-log entry.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialToken, runValidation]);

  const handleScanSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!inputToken.trim()) return;
    void runValidation(inputToken.trim(), selectedGate);
  };

  /**
   * Diagnostic: corrupt the operator's own live token.
   * Exercises the real signature check and must always come back DENY with
   * CRYPTOGRAPHIC_SIGNATURE_MISMATCH.
   */
  const handleTestTampered = async () => {
    try {
      const issued = await fetchGatePassToken();
      const tampered = issued.token.slice(0, -4) + 'XXXX';

      setInputToken(tampered);
      await runValidation(tampered, selectedGate);
    } catch (error) {
      setScanError(describeRequestError(error));
    }
  };

  /** Diagnostic: a QR that was never issued by this engine. */
  const handleTestNonGPE = () => {
    const genericQR = 'https://example.com/unauthorized-visitor-checkin';

    setInputToken(genericQR);
    void runValidation(genericQR, selectedGate);
  };

  /** Diagnostic: structurally broken envelope. */
  const handleTestMalformed = () => {
    const malformed = 'CH-GPE:v1.not-a-valid-payload';

    setInputToken(malformed);
    void runValidation(malformed, selectedGate);
  };

  /**
   * Diagnostic: re-scan the same token twice.
   * The second scan must be refused as a replay, which is the behaviour that
   * previously did not survive a page reload.
   */
  const handleTestReplay = async () => {
    try {
      const issued = await fetchGatePassToken();

      setInputToken(issued.token);
      await runValidation(issued.token, selectedGate);
      await runValidation(issued.token, selectedGate);
    } catch (error) {
      setScanError(describeRequestError(error));
    }
  };

  const handleGrantIngress = () => {
    toast({
      title: 'Ingress Authorized & Gate Opened',
      description: `Barrier raised at ${selectedGate} for ${report?.userName} (${report?.passId}).`,
    });
  };

  const handleLogIncident = () => {
    // The denial is already persisted; this only confirms it to the operator.
    toast({
      variant: 'destructive',
      title: 'Security Incident Recorded',
      description: accessLogId
        ? `Logged as access record #${accessLogId} at ${selectedGate}: ${report?.primaryReason}`
        : `Disallowed ingress flagged at ${selectedGate}: ${report?.primaryReason}`,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[92vh] overflow-y-auto p-0 border shadow-2xl bg-card">
        {/* Modal Header */}
        <div className="p-6 pb-4 border-b bg-muted/40 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <div className="w-10 h-10 rounded-xl bg-primary/10 border border-primary/20 flex items-center justify-center text-primary shadow-sm">
              <Scan className="w-5 h-5" />
            </div>
            <div>
              <DialogTitle className="text-xl font-bold text-foreground">
                Gate Pass Security Scanner & Verifier
              </DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground">
                Digital Gate Pass Engine (GPE) • Multi-Tier Recognition & Cryptographic Validator
              </DialogDescription>
            </div>
          </div>

          {/* Gate Selector */}
          <div className="flex items-center gap-1.5 bg-background border rounded-lg p-1 text-xs">
            <span className="text-muted-foreground px-2 font-mono text-[11px]">PORTAL:</span>
            <button
              type="button"
              onClick={() => setSelectedGate('GATE-01')}
              className={cn(
                "px-2.5 py-1 rounded font-bold transition-colors",
                selectedGate === 'GATE-01' 
                  ? "bg-primary text-primary-foreground shadow-xs" 
                  : "text-muted-foreground hover:text-foreground"
              )}
            >
              Main Gate (01)
            </button>
            <button
              type="button"
              onClick={() => setSelectedGate('GATE-02')}
              className={cn(
                "px-2.5 py-1 rounded font-bold transition-colors",
                selectedGate === 'GATE-02' 
                  ? "bg-primary text-primary-foreground shadow-xs" 
                  : "text-muted-foreground hover:text-foreground"
              )}
            >
              North Gate (02)
            </button>
          </div>
        </div>

        <div className="p-6 space-y-6">
          {/* Token Input Form */}
          <form onSubmit={handleScanSubmit} className="space-y-2">
            <label className="text-xs font-bold text-foreground uppercase tracking-wider block">
              Scan / Paste Token Payload:
            </label>
            <div className="flex gap-2">
              <Input
                value={inputToken}
                onChange={(e) => setInputToken(e.target.value)}
                placeholder="Scan QR or paste 'CH-GPE:v1....'"
                className="font-mono text-xs h-9 bg-muted/20"
              />
              <Button type="submit" size="sm" className="h-9 px-4 text-xs font-bold gap-1.5" disabled={isScanning}>
                <Scan className="w-3.5 h-3.5" />
                <span>Verify</span>
              </Button>
            </div>
          </form>

          {/* Request-level failure (403, 419, network) — distinct from a DENY decision. */}
          {scanError && (
            <div
              role="alert"
              className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
            >
              <p className="font-semibold">Scan could not be completed</p>
              <p className="mt-0.5 text-xs text-destructive/90">{scanError}</p>
            </div>
          )}

          {/*
            Pipeline diagnostics.

            The previous build offered one-click passes for eight fabricated
            identities, which only worked because the browser could sign tokens.
            It cannot any more, so these instead exercise the real server
            pipeline — three of them by corrupting the operator's own live token.
          */}
          <div className="space-y-2">
            <span className="text-[11px] font-bold text-muted-foreground uppercase tracking-wider block">
              Pipeline Diagnostics (server-evaluated):
            </span>

            <div className="flex flex-wrap gap-2 items-center">
              <Button
                variant="outline"
                size="sm"
                onClick={handleTestNonGPE}
                disabled={isScanning}
                className="h-6 text-[10px] text-purple-600 border-purple-500/30 hover:bg-purple-500/10"
              >
                Non-GPE QR
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={handleTestMalformed}
                disabled={isScanning}
                className="h-6 text-[10px] text-amber-600 border-amber-500/30 hover:bg-amber-500/10"
              >
                Malformed Envelope
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => void handleTestTampered()}
                disabled={isScanning}
                className="h-6 text-[10px] text-red-600 border-red-500/30 hover:bg-red-500/10"
              >
                Forged Signature
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => void handleTestReplay()}
                disabled={isScanning}
                className="h-6 text-[10px] text-orange-600 border-orange-500/30 hover:bg-orange-500/10"
              >
                Replay (scan twice)
              </Button>
            </div>

            <p className="text-[10px] leading-snug text-muted-foreground">
              Each diagnostic performs a real scan and is written to the access log.
              Expiry and out-of-shift denials can no longer be simulated from here —
              they depend on server time and the category schedule in{' '}
              <code className="font-mono">config/gatepass.php</code>.
            </p>
          </div>

          {/* A resident's silent request for help, for the guard's eyes only. */}
          <SilentAssistanceBanner signal={report?.silentAssistance} />

          {/* ── VALIDATION DECISION DISPLAY ── */}
          {report && (
            <div className="border rounded-2xl overflow-hidden shadow-lg transition-all animate-in fade-in duration-300">
              {/* PRIMARY DECISION BANNER */}
              <div 
                className={cn(
                  "p-5 text-white flex flex-col sm:flex-row sm:items-center justify-between gap-4",
                  report.status === 'ALLOW' 
                    ? "bg-gradient-to-r from-emerald-600 to-teal-700" 
                    : "bg-gradient-to-r from-red-600 to-rose-700"
                )}
              >
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 rounded-2xl bg-white/20 border-2 border-white/40 flex items-center justify-center shrink-0 shadow-md">
                    {report.status === 'ALLOW' ? (
                      <CheckCircle2 className="w-7 h-7 text-white" />
                    ) : (
                      <XCircle className="w-7 h-7 text-white" />
                    )}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-xs font-bold uppercase tracking-wider bg-black/30 px-2 py-0.5 rounded">
                        {report.status === 'ALLOW' ? '🟢 VALID' : '🔴 INVALID'}
                      </span>
                      <span className="font-mono text-xs text-white/80">
                        {report.passId}
                      </span>
                    </div>
                    <h2 className="text-2xl font-black tracking-tight mt-1">
                      {report.status === 'ALLOW' ? 'ACCESS: APPROVED' : 'ACCESS: DENIED'}
                    </h2>
                  </div>
                </div>

                {/* Category & Shape Chip */}
                <div className="bg-black/30 backdrop-blur-sm border border-white/20 rounded-xl p-2.5 px-4 flex items-center gap-3">
                  <CategoryShapeIcon shape={report.shape} className="w-6 h-6 text-white" />
                  <div className="leading-tight">
                    <span className="text-[10px] text-white/70 font-mono uppercase block">{report.shape} SHAPE</span>
                    <strong className="text-sm text-white font-bold">{report.category}</strong>
                  </div>
                </div>
              </div>

              {/* DETAILS & REASON */}
              <div className="p-5 bg-card space-y-4">
                {report.status === 'DENY' && (
                  <div className="p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-xs flex items-start gap-2.5">
                    <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                    <div>
                      <strong className="block font-bold">REASON FOR DENIAL:</strong>
                      <span className="font-mono">{report.primaryReason}</span>
                    </div>
                  </div>
                )}

                {/* Holder & Property Info */}
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                  <div className="p-3 bg-muted/40 rounded-xl border">
                    <span className="text-muted-foreground text-[10px] uppercase font-mono block">Pass Holder</span>
                    <strong className="text-foreground text-sm">{report.userName}</strong>
                  </div>
                  <div className="p-3 bg-muted/40 rounded-xl border">
                    <span className="text-muted-foreground text-[10px] uppercase font-mono block">Assigned Property</span>
                    <strong className="text-foreground text-sm">{report.property}</strong>
                  </div>
                  <div className="p-3 bg-muted/40 rounded-xl border col-span-2 sm:col-span-1">
                    <span className="text-muted-foreground text-[10px] uppercase font-mono block">Scanned Ingress Portal</span>
                    <strong className="text-foreground text-sm font-mono">{report.gateChecked}</strong>
                  </div>
                </div>

                {/* ── 6-FACTOR IMMEDIATE SECURITY VERIFICATION MATRIX ── */}
                <div className="space-y-2 pt-2 border-t">
                  <div className="flex items-center justify-between">
                    <span className="text-[11px] font-bold text-foreground uppercase tracking-wider block">
                      6-Factor Officer Ingress Verification Matrix:
                    </span>
                    <span className="text-[10px] font-mono text-muted-foreground">
                      GPE ID: {report.communityId} • {report.accessZone}
                    </span>
                  </div>

                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    {/* Factor 1: Shape */}
                    <div className={cn(
                      "p-2.5 rounded-xl border flex flex-col justify-between gap-1 transition-colors",
                      report.checks?.shapeCategoryMatch
                        ? "bg-emerald-500/5 border-emerald-500/30"
                        : "bg-red-500/5 border-red-500/30"
                    )}>
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-mono font-bold uppercase text-muted-foreground">1. SHAPE</span>
                        {report.checks?.shapeCategoryMatch ? (
                          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                        ) : (
                          <XCircle className="w-3.5 h-3.5 text-red-600" />
                        )}
                      </div>
                      <div className="flex items-center gap-1.5 mt-0.5">
                        <CategoryShapeIcon shape={report.shape} className="w-3.5 h-3.5 text-foreground shrink-0" />
                        <span className="text-xs font-bold text-foreground truncate">{report.shape}</span>
                      </div>
                      <span className="text-[10px] text-muted-foreground font-mono truncate">
                        = {report.category}
                      </span>
                    </div>

                    {/* Factor 2: Color */}
                    <div className={cn(
                      "p-2.5 rounded-xl border flex flex-col justify-between gap-1 transition-colors",
                      report.checks?.colorClassMatch
                        ? "bg-emerald-500/5 border-emerald-500/30"
                        : "bg-red-500/5 border-red-500/30"
                    )}>
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-mono font-bold uppercase text-muted-foreground">2. COLOR</span>
                        <Badge variant="outline" className="text-[9px] px-1 py-0 h-4 border-emerald-500/30 text-emerald-600">
                          APPROVED
                        </Badge>
                      </div>
                      <div className="flex items-center gap-1.5 mt-0.5">
                        <div 
                          className="w-3 h-3 rounded-full border border-white/30 shrink-0" 
                          style={{ backgroundColor: report.visualIdentity?.colorHex || getCategoryConfig(report.category).themeColor || '#64748b' }}
                        />
                        <span className="text-xs font-bold text-foreground truncate">
                          {report.visualIdentity?.colorVariantName || 'Assigned Palette'}
                        </span>
                      </div>
                      <span className="text-[10px] text-muted-foreground font-mono truncate">
                        = Visual Identifier (Non-Credential)
                      </span>
                    </div>

                    {/* Factor 3: QR Payload */}
                    <div className={cn(
                      "p-2.5 rounded-xl border flex flex-col justify-between gap-1 transition-colors",
                      report.checks?.payloadAuthenticity
                        ? "bg-emerald-500/5 border-emerald-500/30"
                        : "bg-red-500/5 border-red-500/30"
                    )}>
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-mono font-bold uppercase text-muted-foreground">3. PAYLOAD</span>
                        {report.checks?.payloadAuthenticity ? (
                          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                        ) : (
                          <XCircle className="w-3.5 h-3.5 text-red-600" />
                        )}
                      </div>
                      <span className="text-xs font-bold text-foreground truncate mt-0.5">
                        {report.userName || 'Unknown'}
                      </span>
                      <span className="text-[10px] text-muted-foreground font-mono truncate">
                        = {report.passId}
                      </span>
                    </div>

                    {/* Factor 4: Signature */}
                    <div className={cn(
                      "p-2.5 rounded-xl border flex flex-col justify-between gap-1 transition-colors",
                      report.checks?.cryptographicSignature
                        ? "bg-emerald-500/5 border-emerald-500/30"
                        : "bg-red-500/5 border-red-500/30"
                    )}>
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-mono font-bold uppercase text-muted-foreground">4. SIGNATURE</span>
                        {report.checks?.cryptographicSignature ? (
                          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                        ) : (
                          <XCircle className="w-3.5 h-3.5 text-red-600" />
                        )}
                      </div>
                      <span className="text-xs font-bold text-foreground truncate mt-0.5">
                        HMAC-SHA256
                      </span>
                      <span className="text-[10px] text-muted-foreground font-mono truncate">
                        = GPE Engine Auth
                      </span>
                    </div>

                    {/* Factor 5: GPS / Gate */}
                    <div className={cn(
                      "p-2.5 rounded-xl border flex flex-col justify-between gap-1 transition-colors",
                      report.checks?.physicalGateAuth
                        ? "bg-emerald-500/5 border-emerald-500/30"
                        : "bg-red-500/5 border-red-500/30"
                    )}>
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-mono font-bold uppercase text-muted-foreground">5. GPS / GATE</span>
                        {report.checks?.physicalGateAuth ? (
                          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                        ) : (
                          <XCircle className="w-3.5 h-3.5 text-red-600" />
                        )}
                      </div>
                      <span className="text-xs font-bold text-foreground truncate mt-0.5 font-mono">
                        {report.gateChecked}
                      </span>
                      <span className="text-[10px] text-muted-foreground font-mono truncate">
                        = Physical Clearance
                      </span>
                    </div>

                    {/* Factor 6: Time */}
                    <div className={cn(
                      "p-2.5 rounded-xl border flex flex-col justify-between gap-1 transition-colors",
                      report.checks?.temporalTimeAuth
                        ? "bg-emerald-500/5 border-emerald-500/30"
                        : "bg-red-500/5 border-red-500/30"
                    )}>
                      <div className="flex items-center justify-between">
                        <span className="text-[10px] font-mono font-bold uppercase text-muted-foreground">6. TIME</span>
                        {report.checks?.temporalTimeAuth ? (
                          <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                        ) : (
                          <XCircle className="w-3.5 h-3.5 text-red-600" />
                        )}
                      </div>
                      <span className="text-xs font-bold text-foreground truncate mt-0.5">
                        {report.secondsRemaining > 0 ? `${report.secondsRemaining}s left` : 'Expired'}
                      </span>
                      <span className="text-[10px] text-muted-foreground font-mono truncate">
                        = Temporal Auth
                      </span>
                    </div>
                  </div>

                  {/* Security Principle Callout */}
                  <div className="p-2.5 rounded-lg bg-blue-500/5 border border-blue-500/20 text-[11px] text-muted-foreground flex items-start gap-2">
                    <ShieldCheck className="w-4 h-4 text-blue-600 shrink-0 mt-0.5" />
                    <div>
                      <strong className="text-foreground font-semibold block">Security Decoupling Principle:</strong>
                      <span>Color is a human visual differentiator from the approved WCAG palette to prevent optical collisions. Access clearance is granted strictly via cryptographic HMAC signature and active token verification.</span>
                    </div>
                  </div>
                </div>

                {/* ── 4-LAYER VALIDATION BREAKDOWN ── */}
                <div className="space-y-2 pt-2 border-t">
                  <span className="text-[11px] font-bold text-foreground uppercase tracking-wider block">
                    Three-Layer Verification Breakdown:
                  </span>
                  
                  <div className="grid gap-2">
                    {/* Stage 1: Structure */}
                    <div className="flex items-center justify-between p-2.5 rounded-lg border bg-muted/20 text-xs">
                      <div className="flex items-center gap-2">
                        {report.stages.structure.passed ? (
                          <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                        ) : (
                          <XCircle className="w-4 h-4 text-red-600 shrink-0" />
                        )}
                        <div>
                          <strong className="text-foreground">1. QR Structure & Format:</strong>
                          <span className="text-muted-foreground ml-1">{report.stages.structure.message}</span>
                        </div>
                      </div>
                      <Badge variant="outline" className={report.stages.structure.passed ? "text-emerald-600 border-emerald-500/30" : "text-red-600 border-red-500/30"}>
                        {report.stages.structure.passed ? "PASSED" : "FAILED"}
                      </Badge>
                    </div>

                    {/* Stage 2: Cryptography */}
                    <div className="flex items-center justify-between p-2.5 rounded-lg border bg-muted/20 text-xs">
                      <div className="flex items-center gap-2">
                        {report.stages.cryptography.passed ? (
                          <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                        ) : (
                          <XCircle className="w-4 h-4 text-red-600 shrink-0" />
                        )}
                        <div>
                          <strong className="text-foreground">2. Cryptographic Signature:</strong>
                          <span className="text-muted-foreground ml-1">{report.stages.cryptography.message}</span>
                        </div>
                      </div>
                      <Badge variant="outline" className={report.stages.cryptography.passed ? "text-emerald-600 border-emerald-500/30" : "text-red-600 border-red-500/30"}>
                        {report.stages.cryptography.passed ? "VERIFIED" : "TAMPERED"}
                      </Badge>
                    </div>

                    {/* Stage 3: Server/Cache */}
                    <div className="flex items-center justify-between p-2.5 rounded-lg border bg-muted/20 text-xs">
                      <div className="flex items-center gap-2">
                        {report.stages.serverCache.passed ? (
                          <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                        ) : (
                          <XCircle className="w-4 h-4 text-red-600 shrink-0" />
                        )}
                        <div>
                          <strong className="text-foreground">3. Server/Cache & Anti-Replay:</strong>
                          <span className="text-muted-foreground ml-1">{report.stages.serverCache.message}</span>
                        </div>
                      </div>
                      <Badge variant="outline" className={report.stages.serverCache.passed ? "text-emerald-600 border-emerald-500/30" : "text-red-600 border-red-500/30"}>
                        {report.stages.serverCache.passed ? "ACTIVE" : "FLAGGED"}
                      </Badge>
                    </div>

                    {/* Stage 4: Access Policy */}
                    <div className="flex items-center justify-between p-2.5 rounded-lg border bg-muted/20 text-xs">
                      <div className="flex items-center gap-2">
                        {report.stages.accessPolicy.passed ? (
                          <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                        ) : (
                          <XCircle className="w-4 h-4 text-red-600 shrink-0" />
                        )}
                        <div>
                          <strong className="text-foreground">4. Category Access Policy:</strong>
                          <span className="text-muted-foreground ml-1">{report.stages.accessPolicy.message}</span>
                        </div>
                      </div>
                      <Badge variant="outline" className={report.stages.accessPolicy.passed ? "text-emerald-600 border-emerald-500/30" : "text-red-600 border-red-500/30"}>
                        {report.stages.accessPolicy.passed ? "CLEARANCE OK" : "POLICY DENY"}
                      </Badge>
                    </div>
                  </div>
                </div>

                {/* Authorized Zones Preview */}
                {report.status === 'ALLOW' && (
                  <div className="p-3 bg-emerald-500/5 border border-emerald-500/20 rounded-xl space-y-1">
                    <span className="text-[10px] font-mono uppercase text-emerald-600 font-bold block">
                      Granted Clearance Zones ({report.category}):
                    </span>
                    <ul className="text-xs text-muted-foreground space-y-0.5 list-disc list-inside">
                      {report.policy.authorizedZones.map((zone, idx) => (
                        <li key={idx}><span className="text-foreground font-medium">{zone}</span></li>
                      ))}
                    </ul>
                  </div>
                )}

                {/* Security Officer Action Footer */}
                <div className="pt-3 border-t flex flex-wrap gap-2 justify-end">
                  <Button 
                    variant="outline" 
                    size="sm" 
                    onClick={handleLogIncident}
                    className="text-xs text-red-600 border-red-500/30 hover:bg-red-500/10"
                  >
                    Flag Incident Report
                  </Button>
                  {report.status === 'ALLOW' && (
                    <Button 
                      size="sm" 
                      onClick={handleGrantIngress}
                      className="text-xs font-bold gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white shadow-md"
                    >
                      <Unlock className="w-3.5 h-3.5" />
                      <span>Authorize Ingress & Open Gate</span>
                    </Button>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
