
import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { 
  PassCategory, 
  GateId,
  ApprovedColorVariant,
} from '@/lib/gate-pass-engine/types';
import {
  getCategoryConfig,
  formatPassIdForDisplay,
  type AccessPolicy,
  type ColorVariant,
} from '@/lib/gate-pass-engine/config';
import {
  fetchGatePassToken,
  rotateGatePassVisual,
  isAbortError,
  describeRequestError,
} from '@/lib/gate-pass-engine/api';
import { CategoryShapeIcon } from '@/lib/gate-pass-engine/shapes';
import { useDiscreetHold } from '@/lib/silent-assistance';
import { ProfileCustomQRCode } from './ProfileCustomQRCode';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { 
  Copy, 
  Check, 
  RotateCw, 
  ShieldCheck, 
  Clock, 
  MapPin, 
  Smartphone, 
  Apple,
  ExternalLink,
  Sparkles,
  Layers,
  AlertTriangle,
  Eye,
  Palette
} from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import { cn } from '@/lib/utils';

export interface GatePassCardProps {
  category: PassCategory;
  userName: string;
  property: string;
  passId?: string;
  userId?: string;
  gate?: GateId;
  photoUrl?: string;
  status?: 'ACTIVE' | 'EXPIRED' | 'REVOKED';
  initialColorVariant?: ApprovedColorVariant | ColorVariant;
  onScanInVerifier?: (token: string) => void;
  className?: string;
  compact?: boolean;

  /**
   * Access policy for this category, from the server. The card used to read
   * CATEGORY_POLICIES from the bundled engine; policy is now evaluated and
   * supplied by App\Services\GatePassEngine.
   */
  policy?: AccessPolicy;

  /** Rolling window length in seconds, from config/gatepass.php. */
  windowSeconds?: number;

  /**
   * Whether this card represents the viewer's own pass. Only then can it poll
   * for a live token or rotate its colour — both endpoints act on the signed-in
   * user's pass, so rendering someone else's pass shows a static preview.
   */
  isOwnPass?: boolean;

  /** Initial sequence number, so the badge matches the server before first rotate. */
  initialRotationSeq?: number;
}

/** Shown when the server has not supplied a policy (e.g. a preview card). */
const UNKNOWN_POLICY: AccessPolicy = {
  title: 'Clearance details unavailable',
  description: 'Policy is evaluated server-side and was not supplied for this view.',
  authorizedZones: [],
  allowedGates: [],
  operationalHours: { is24Hours: true },
  privileges: {
    canManageGuests: false,
    canAssociateVehicles: false,
    hasEmergencyOverride: false,
    hasGateOperationOverride: false,
    restrictedFromHomeownerFunctions: true,
  },
};

export function GatePassCard({
  category,
  userName,
  property,
  passId,
  userId = 'usr_1042',
  gate = 'GATE-ANY',
  photoUrl,
  status = 'ACTIVE',
  initialColorVariant,
  onScanInVerifier,
  className,
  compact = false,
  policy: policyProp,
  windowSeconds = 30,
  isOwnPass = false,
  initialRotationSeq = 1,
}: GatePassCardProps) {
  const { toast } = useToast();
  const [copied, setCopied] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(windowSeconds);
  const [isRotating, setIsRotating] = useState(false);
  const [visualSeq, setVisualSeq] = useState(initialRotationSeq);
  const [tokenError, setTokenError] = useState<string | null>(null);
  const discreetHold = useDiscreetHold(isOwnPass && status === 'ACTIVE');

  const resolvedPassId = passId || formatPassIdForDisplay(category, userId);
  const config = getCategoryConfig(category);
  const policy = policyProp ?? UNKNOWN_POLICY;

  /*
   * The server assigns the colour variant (deterministically, from the approved
   * WCAG-tested palette) and sends it as a prop. The fallback below uses the
   * category's own theme colours so the card still renders correctly if a caller
   * omits it — the variant is presentational and carries no authority.
   */
  const [activeColorVariant, setActiveColorVariant] = useState<ColorVariant>(() => ({
    id: 'category-default',
    name: config.displayName,
    hex: config.themeColor,
    accentHex: config.accentColor,
    contrastRatio: 4.5,
    wcagPass: true,
    ...(initialColorVariant as Partial<ColorVariant> | undefined),
  }));

  /*
   * The token is minted by the server and fetched here.
   *
   * It used to be generated in this component with a signing key that shipped in
   * the bundle, which meant anyone could mint a pass for any category. The
   * browser now only displays what the server signs; without the `scanPasses`
   * permission it cannot even check whether a token is valid.
   *
   * Empty string until the first fetch resolves, so no stale or forged value is
   * ever rendered as a QR code.
   */
  const [currentToken, setCurrentToken] = useState('');

  const loadToken = useCallback(
    async (signal?: AbortSignal) => {
      if (!isOwnPass || status !== 'ACTIVE') return;

      try {
        setIsRotating(true);
        const issued = await fetchGatePassToken(signal);

        setCurrentToken(issued.token);
        // Trust the server's clock, not a local countdown that can drift.
        setSecondsLeft(Math.max(1, issued.secondsRemaining));
        setTokenError(null);
      } catch (error) {
        if (isAbortError(error)) return;

        setTokenError(describeRequestError(error));
        setCurrentToken('');
      } finally {
        setTimeout(() => setIsRotating(false), 600);
      }
    },
    [isOwnPass, status],
  );

  // Initial fetch, cancelled if the card unmounts mid-flight.
  useEffect(() => {
    const controller = new AbortController();

    void loadToken(controller.signal);

    return () => controller.abort();
  }, [loadToken]);

  /** Rolls to the next approved colour variant. The server decides which. */
  const handleRollVisualIdentity = async () => {
    if (!isOwnPass) return;

    try {
      setIsRotating(true);
      const result = await rotateGatePassVisual();

      setVisualSeq(result.rotationSeq);
      setActiveColorVariant(result.variant);

      // Re-fetch so the QR carries the new sequence number.
      await loadToken();

      toast({
        title: 'Visual Identity Rotated',
        description: `Assigned color: ${result.variant.name} (${result.variant.hex}). Pass remains cryptographically valid.`,
      });
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Rotation Failed',
        description: describeRequestError(error),
      });
    } finally {
      setTimeout(() => setIsRotating(false), 600);
    }
  };

  // Countdown display only; reaching zero triggers a re-fetch rather than a
  // locally-generated replacement.
  useEffect(() => {
    if (status !== 'ACTIVE' || !isOwnPass) return;

    const interval = setInterval(() => {
      setSecondsLeft((prev) => {
        if (prev <= 1) {
          void loadToken();
          return windowSeconds;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [loadToken, status, isOwnPass, windowSeconds]);

  // Pause polling while the tab is hidden; resume with a fresh token, since any
  // token minted while hidden has already expired.
  useEffect(() => {
    if (!isOwnPass) return;

    const onVisibility = () => {
      if (document.visibilityState === 'visible') {
        void loadToken();
      }
    };

    document.addEventListener('visibilitychange', onVisibility);

    return () => document.removeEventListener('visibilitychange', onVisibility);
  }, [loadToken, isOwnPass]);

  const handleCopyPassId = () => {
    navigator.clipboard.writeText(resolvedPassId);
    setCopied(true);
    toast({
      title: "Pass ID Copied",
      description: `${resolvedPassId} copied to clipboard.`,
    });
    setTimeout(() => setCopied(false), 2000);
  };

  const operationalHoursLabel = policy.operationalHours.is24Hours 
    ? '24/7 Access' 
    : `${policy.operationalHours.startHour?.toString().padStart(2, '0')}:00–${policy.operationalHours.endHour?.toString().padStart(2, '0')}:00`;

  return (
    <div 
      className={cn(
        "relative rounded-2xl overflow-hidden shadow-2xl border text-white transition-all duration-300",
        `bg-gradient-to-br ${config.gradient}`,
        config.badgeBorder,
        className
      )}
    >
      {/* ── Background Subtle Geometric Watermark ── */}
      <div 
        className="absolute -right-12 -top-12 opacity-10 pointer-events-none transform rotate-12"
      >
        <CategoryShapeIcon shape={config.shape} className="w-64 h-64 text-white" />
      </div>

      {/* ── Card Header: Digital Pass Banner ── */}
      <div className="p-5 pb-3 border-b border-white/10 flex items-center justify-between relative z-10">
        <div className="flex items-center gap-2">
          <div 
            className="w-7 h-7 rounded-lg flex items-center justify-center shadow-md border border-white/20"
            style={{ backgroundColor: config.themeColor }}
          >
            <CategoryShapeIcon shape={config.shape} className="w-4 h-4 text-white" />
          </div>
          <div>
            <h3 className="font-mono text-xs font-bold uppercase tracking-widest text-white/90">
              COMMUNITY DIGITAL PASS
            </h3>
            <p className="text-[10px] text-white/60 font-mono">
              {config.shapeLabel} • {config.displayName}
            </p>
          </div>
        </div>

        {/* Status Badge */}
        <Badge 
          className={cn(
            "text-[10px] font-bold px-2.5 py-0.5 border shadow-sm flex items-center gap-1",
            status === 'ACTIVE' 
              ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40' 
              : 'bg-red-500/20 text-red-300 border-red-500/40'
          )}
        >
          <span 
            className={cn(
              "w-1.5 h-1.5 rounded-full inline-block",
              status === 'ACTIVE' ? "bg-emerald-400 animate-ping" : "bg-red-400"
            )}
          />
          <span>{status === 'ACTIVE' ? 'ACTIVE' : status}</span>
        </Badge>
      </div>

      {/* ── Pass Holder Identity Bar ── */}
      <div className="px-5 pt-3 flex items-center justify-between relative z-10">
        <div>
          <h2 className="text-lg font-extrabold text-white tracking-tight leading-tight">
            {userName}
          </h2>
          <div className="flex items-center gap-2 text-xs text-white/70 mt-0.5">
            <MapPin className="w-3 h-3 text-white/50" />
            <span className="font-medium text-white/90">{property}</span>
            <span className="text-white/30">•</span>
            <span className="font-mono text-[11px] text-white/80">{gate}</span>
          </div>
        </div>

        {/* One-click Copy Pass ID */}
        <button
          onClick={handleCopyPassId}
          type="button"
          className="flex items-center gap-1.5 bg-black/30 hover:bg-black/50 border border-white/15 px-2.5 py-1 rounded-lg font-mono text-xs font-bold text-white transition-colors cursor-pointer"
          title="Click to copy Pass ID"
        >
          <span>{resolvedPassId}</span>
          {copied ? <Check className="w-3 h-3 text-emerald-400" /> : <Copy className="w-3 h-3 text-white/60" />}
        </button>
      </div>

      {/* ── Central QR Code Framed in Profile-Specific Custom Design ── */}
      <div className="py-4 flex flex-col items-center justify-center relative z-10">
        {/* Holding your own QR code raises silent assistance. Nothing on
            screen changes, so someone beside you cannot tell. */}
        <div className="relative select-none touch-manipulation" {...discreetHold}>
          <ProfileCustomQRCode
            value={currentToken}
            category={category}
            size={compact ? 150 : 175}
            themeColor={activeColorVariant.hex}
            accentColor={activeColorVariant.accentHex}
            colorVariantName={activeColorVariant.name}
            showScanGlow={status === 'ACTIVE' && Boolean(currentToken)}
          />

          {/* No token yet: waiting on the server, refused, or someone else's pass. */}
          {!currentToken && (
            <div
              className="absolute inset-0 flex items-center justify-center rounded-xl bg-slate-950/80 p-3 text-center backdrop-blur-sm"
              role="status"
              aria-live="polite"
            >
              {tokenError ? (
                <div className="space-y-1.5">
                  <p className="text-[11px] font-semibold text-red-300">Token unavailable</p>
                  <p className="text-[10px] leading-snug text-white/70">{tokenError}</p>
                  {isOwnPass && (
                    <Button
                      size="sm"
                      variant="outline"
                      className="mt-1 h-6 border-white/30 bg-white/10 text-[10px] text-white hover:bg-white/20"
                      onClick={() => void loadToken()}
                    >
                      Retry
                    </Button>
                  )}
                </div>
              ) : isOwnPass ? (
                <p className="text-[11px] font-medium text-white/80">Requesting signed token…</p>
              ) : (
                <div className="space-y-1">
                  <p className="text-[11px] font-semibold text-white/90">Preview only</p>
                  <p className="text-[10px] leading-snug text-white/60">
                    Live tokens are issued to the pass holder.
                  </p>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Dual Visual Identity & Dynamic Token Controls */}
        <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
          {/* Dynamic Rolling Token Countdown Indicator */}
          <div className="flex items-center gap-2 bg-black/40 border border-white/15 px-3 py-1 rounded-full text-xs font-mono">
            <button 
              type="button" 
              onClick={() => void loadToken()}
              disabled={!isOwnPass}
              className="text-white/60 hover:text-white transition-colors disabled:opacity-40 disabled:hover:text-white/60"
              title={isOwnPass ? 'Fetch a fresh signed token' : 'Only the pass holder can request a token'}
            >
              <RotateCw className={cn("w-3 h-3", isRotating && "animate-spin text-emerald-400")} />
            </button>
            <span className="text-white/70 text-[11px]">
              Rolling Window:
            </span>
            <span className="font-bold text-emerald-400 min-w-[28px] text-right">
              {secondsLeft}s
            </span>
          </div>

          {/* Controlled Random Color / Visual Regeneration Button */}
          <button
            type="button"
            onClick={() => void handleRollVisualIdentity()}
            disabled={!isOwnPass || isRotating}
            className="flex items-center gap-1.5 bg-black/40 hover:bg-black/60 border border-white/15 hover:border-white/30 px-3 py-1 rounded-full text-xs font-mono text-white transition-all cursor-pointer group shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
            title={
              isOwnPass
                ? 'Rotate visual color variant to prevent static screenshot reuse while maintaining cryptographic validity'
                : 'Only the pass holder can rotate their visual identity'
            }
          >
            <Palette className="w-3 h-3 text-amber-400 group-hover:rotate-45 transition-transform" />
            <span className="text-[11px] text-white/80">Roll Color:</span>
            <span 
              className="w-2.5 h-2.5 rounded-full inline-block border border-white/40 shadow-xs" 
              style={{ backgroundColor: activeColorVariant.hex }} 
            />
            <span className="font-bold text-white text-[11px] truncate max-w-[90px]">
              {activeColorVariant.name.split(' ')[0]}
            </span>
          </button>
        </div>
      </div>

      {/* ── Category Policy & Operational Clearance Footer ── */}
      <div className="p-5 pt-3 border-t border-white/10 bg-black/25 flex flex-col gap-3 relative z-10">
        <div className="grid grid-cols-2 gap-2 text-xs">
          <div className="bg-white/5 border border-white/10 rounded-lg p-2">
            <span className="text-white/50 text-[10px] uppercase font-mono block">Category Clearance</span>
            <span className="font-bold text-white text-xs">{config.displayName}</span>
          </div>
          <div className="bg-white/5 border border-white/10 rounded-lg p-2">
            <span className="text-white/50 text-[10px] uppercase font-mono block">Operating Window</span>
            <span className="font-bold text-emerald-300 text-xs flex items-center gap-1">
              <Clock className="w-3 h-3" />
              {operationalHoursLabel}
            </span>
          </div>
        </div>

        {/* ── 6-Factor Multi-Tier Security Verification Strip ── */}
        <div className="bg-black/35 border border-white/10 rounded-lg p-2.5 flex flex-col gap-1.5">
          <div className="flex items-center justify-between text-[10px] font-mono uppercase tracking-wider text-white/60">
            <span>Visual Identity Engine (Decoupled Security)</span>
            <span className="text-emerald-400 font-bold">WCAG {activeColorVariant.contrastRatio}:1 PASS</span>
          </div>
          <div className="grid grid-cols-3 gap-1.5 text-[10px] font-mono">
            <div className="bg-white/5 rounded px-2 py-1 flex items-center gap-1">
              <span className="text-white/40">Shape:</span>
              <span className="text-white font-bold truncate">{config.shapeLabel.split(' ')[0]}</span>
            </div>
            <div className="bg-white/5 rounded px-2 py-1 flex items-center gap-1 truncate">
              <span className="text-white/40">Color:</span>
              <span 
                className="w-2 h-2 rounded-full inline-block shrink-0" 
                style={{ backgroundColor: activeColorVariant.hex }} 
              />
              <span className="text-white font-bold truncate">{activeColorVariant.name.split(' ')[0]}</span>
            </div>
            <div className="bg-white/5 rounded px-2 py-1 flex items-center gap-1">
              <span className="text-white/40">Auth:</span>
              <span className="text-emerald-300 font-bold">HMAC Signature</span>
            </div>
          </div>
          <p className="text-[9px] text-white/50 font-mono italic leading-tight pt-0.5">
            *Shape designates profile tier. Color differentiates individual pass. Digital signature authenticates ingress.
          </p>
        </div>

        {/* Quick Action Buttons */}
        <div className="flex items-center gap-2 pt-1">
          {onScanInVerifier && (
            <Button
              size="sm"
              onClick={() => onScanInVerifier(currentToken)}
              disabled={!currentToken}
              className="flex-1 h-8 text-xs font-semibold gap-1.5 bg-white text-slate-900 hover:bg-white/90 shadow-md disabled:opacity-50"
              title={currentToken ? undefined : 'Waiting for a signed token from the server'}
            >
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
              <span>Test in Gate Scanner</span>
            </Button>
          )}

          <div className="flex items-center gap-1.5 ml-auto">
            <span className="text-[10px] text-white/50 font-mono hidden sm:inline">Cryptographic v1</span>
            <div className="flex items-center gap-1 bg-white/10 px-2 py-1 rounded text-[10px] text-white/80">
              <Apple className="w-3 h-3" />
              <Smartphone className="w-3 h-3" />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
