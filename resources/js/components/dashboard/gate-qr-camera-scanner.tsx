import React, { useState, useEffect, useRef, useCallback } from 'react';
import jsQR from 'jsqr';
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
  Camera,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  Scan,
  RefreshCw,
  Zap,
  ZapOff,
  Layers,
  ShieldCheck,
  ShieldAlert,
  Clock,
  MapPin,
  Home,
  UserCheck,
  LogOut,
  ChevronDown,
  ChevronUp,
  ExternalLink,
  Info,
  Volume2,
  VolumeX,
} from 'lucide-react';
import { GateId, GatePassValidationReport, ScanDecision } from '@/lib/gate-pass-engine/types';
import { SilentAssistanceBanner } from '@/components/dashboard/silent-assistance-banner';
import { describeRequestError } from '@/lib/gate-pass-engine/api';
import { CategoryShapeIcon } from '@/lib/gate-pass-engine/shapes';
import { useToast } from '@/hooks/use-toast';
import { cn } from '@/lib/utils';
import axios from 'axios';

function describeDenyReason(primaryReason?: string): { label: string; explanation: string; action: string } {
  if (!primaryReason) {
    return {
      label: 'EXPIRED PASS',
      explanation: 'This pass has expired and is outside its authorized validity window.',
      action: 'Do not admit. Ask guest to contact their resident host.',
    };
  }
  const upper = primaryReason.toUpperCase();
  if (upper.includes('REPLAY') || upper.includes('DUPLICATE')) {
    return {
      label: 'REPLAY ATTACK (DUPLICATE CODE)',
      explanation: 'Static screenshot detected. This specific QR code token was already scanned and consumed.',
      action: 'Do not admit. Request live, animated pass from the resident app.',
    };
  }
  if (upper.includes('PASS_REVOKED') || upper.includes('REVOKED')) {
    return {
      label: 'REVOKED PASS',
      explanation: 'This credential was revoked by community administration.',
      action: 'Do not admit. Direct individual to estate management.',
    };
  }
  if (upper.includes('OUTSIDE_HOURS') || upper.includes('OUTSIDE_PASS_VALIDITY') || upper.includes('TOKEN_EXPIRED') || upper.includes('EXPIRED')) {
    return {
      label: 'EXPIRED PASS / OUTSIDE HOURS',
      explanation: 'Pass validity period has ended or visiting hours for this profile have closed.',
      action: 'Do not admit. Host must issue a refreshed clearance.',
    };
  }
  if (upper.includes('UNAUTHORIZED_GATE')) {
    return {
      label: 'WRONG GATE',
      explanation: 'Pass is designated for a different entrance portal.',
      action: 'Direct driver to their authorized gate.',
    };
  }
  if (upper.includes('UNAUTHORIZED_COMMUNITY') || upper.includes('COMMUNITY')) {
    return {
      label: 'WRONG ESTATE / COMMUNITY',
      explanation: 'This pass was minted for an external property, not Cypress Bay.',
      action: 'Do not admit. Check recipient credentials.',
    };
  }
  if (upper.includes('USER_NOT_ACTIVE')) {
    return {
      label: 'ACCOUNT DEACTIVATED',
      explanation: 'The sponsoring resident or homeowner account is suspended or expired.',
      action: 'Do not admit. Contact management office.',
    };
  }
  if (upper.includes('SIGNATURE_MISMATCH') || upper.includes('TAMPERED') || upper.includes('FORGED') || upper.includes('INVALID_QR')) {
    return {
      label: 'TAMPERED / FORGED CODE',
      explanation: 'Cryptographic HMAC signature check failed. Code was altered or forged.',
      action: 'Do not admit. Security supervisor notified.',
    };
  }
  return {
    label: 'ACCESS DENIED',
    explanation: primaryReason,
    action: 'Do not admit until verified with host.',
  };
}

interface GateQrCameraScannerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccessCheck?: () => void;
  initialGate?: GateId;
}

export function GateQrCameraScanner({
  open,
  onOpenChange,
  onSuccessCheck,
  initialGate = 'GATE-01',
}: GateQrCameraScannerProps) {
  const { toast } = useToast();
  const [selectedGate, setSelectedGate] = useState<GateId>(initialGate);
  const [inputToken, setInputToken] = useState('');
  const [isScanning, setIsScanning] = useState(false);
  const [isCameraActive, setIsCameraActive] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [facingMode, setFacingMode] = useState<'environment' | 'user'>('environment');
  const [torchEnabled, setTorchEnabled] = useState(false);
  const [torchSupported, setTorchSupported] = useState(false);
  const [soundEnabled, setSoundEnabled] = useState(true);

  // Scan state results
  const [report, setReport] = useState<GatePassValidationReport | null>(null);
  const [accessLogId, setAccessLogId] = useState<number | null>(null);
  const [decision, setDecision] = useState<ScanDecision | null>(null);
  const [scanId, setScanId] = useState<string | null>(null);
  const [completed, setCompleted] = useState<{ action: 'CHECK_IN' | 'CHECK_OUT' | 'REFUSED'; time: string } | null>(null);
  const [showTechnicalDetails, setShowTechnicalDetails] = useState(false);
  const [isConfirmingAction, setIsConfirmingAction] = useState(false);

  // Kiosk mode continuous scan with auto-rearm
  const [isKioskMode, setIsKioskMode] = useState<boolean>(false);
  const [autoRearmCountdown, setAutoRearmCountdown] = useState<number | null>(null);

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const scanLoopRef = useRef<number | null>(null);
  const lastScannedTokenRef = useRef<string | null>(null);
  const lastScanTimestampRef = useRef<number>(0);

  // Fast reset helper
  const handleResetForNextScan = useCallback(() => {
    setReport(null);
    setInputToken('');
    setCompleted(null);
    setScanId(null);
    setDecision(null);
    lastScannedTokenRef.current = null;
  }, []);

  // Continuous Kiosk Mode Auto-Rearm Countdown
  useEffect(() => {
    if (!isKioskMode || !report) {
      setAutoRearmCountdown(null);
      return;
    }

    setAutoRearmCountdown(3);
    const interval = setInterval(() => {
      setAutoRearmCountdown((prev) => {
        if (prev === null || prev <= 1) {
          clearInterval(interval);
          handleResetForNextScan();
          return null;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [isKioskMode, report, handleResetForNextScan]);

  // Start / Stop Camera Stream
  const startCamera = useCallback(async () => {
    setCameraError(null);
    try {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach((track) => track.stop());
      }

      const constraints: MediaStreamConstraints = {
        video: {
          facingMode: { ideal: facingMode },
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      };

      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      streamRef.current = stream;

      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play();
      }

      setIsCameraActive(true);

      const track = stream.getVideoTracks()[0];
      const capabilities = (track.getCapabilities ? track.getCapabilities() : {}) as any;
      if (capabilities && capabilities.torch) {
        setTorchSupported(true);
      } else {
        setTorchSupported(false);
      }
    } catch (err: any) {
      console.warn('Camera stream error:', err);
      setIsCameraActive(false);
      setCameraError(
        err.name === 'NotAllowedError'
          ? 'Camera permission denied. Allow camera access in browser or use manual code entry.'
          : 'Unable to initialize device camera video stream.'
      );
    }
  }, [facingMode]);

  const stopCamera = useCallback(() => {
    if (scanLoopRef.current) {
      cancelAnimationFrame(scanLoopRef.current);
      scanLoopRef.current = null;
    }
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((track) => track.stop());
      streamRef.current = null;
    }
    setIsCameraActive(false);
    setTorchEnabled(false);
  }, []);

  const toggleTorch = async () => {
    if (!streamRef.current) return;
    const track = streamRef.current.getVideoTracks()[0];
    if (track && (track.applyConstraints as any)) {
      try {
        const next = !torchEnabled;
        await (track.applyConstraints as any)({
          advanced: [{ torch: next }],
        });
        setTorchEnabled(next);
      } catch (err) {
        console.warn('Torch toggle failed', err);
      }
    }
  };

  const flipCamera = () => {
    setFacingMode((prev) => (prev === 'environment' ? 'user' : 'environment'));
  };

  // Run validation on a token through the backend Digital Gate Engine
  const executeScan = useCallback(
    async (token: string) => {
      setIsScanning(true);
      setCompleted(null);

      try {
        const response = await axios.post('/dashboard/gate-pass/scan', {
          token,
          gate: selectedGate,
        });

        const data = response.data;
        setReport(data.report);
        setAccessLogId(data.accessLogId);
        setDecision(data.decision);
        setScanId(data.scanId);

        // Sound / Beep Feedback (Web Audio API)
        if (soundEnabled) {
          try {
            const ctx = new (window.AudioContext || (window as any).webkitAudioContext)();
            const gain = ctx.createGain();
            gain.connect(ctx.destination);

            if (data.report.status === 'ALLOW') {
              // High pleasant dual-tone chime (880Hz then 1174Hz)
              const osc1 = ctx.createOscillator();
              const osc2 = ctx.createOscillator();
              osc1.type = 'sine';
              osc2.type = 'sine';
              osc1.frequency.setValueAtTime(880, ctx.currentTime);
              osc2.frequency.setValueAtTime(1174, ctx.currentTime + 0.08);
              gain.gain.setValueAtTime(0.25, ctx.currentTime);
              gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
              osc1.connect(gain);
              osc2.connect(gain);
              osc1.start(ctx.currentTime);
              osc2.start(ctx.currentTime + 0.08);
              osc1.stop(ctx.currentTime + 0.35);
              osc2.stop(ctx.currentTime + 0.35);
            } else {
              // Low warning alert buzz (180Hz then 120Hz sawtooth)
              const osc = ctx.createOscillator();
              osc.type = 'sawtooth';
              osc.frequency.setValueAtTime(180, ctx.currentTime);
              osc.frequency.setValueAtTime(120, ctx.currentTime + 0.12);
              gain.gain.setValueAtTime(0.3, ctx.currentTime);
              gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
              osc.connect(gain);
              osc.start(ctx.currentTime);
              osc.stop(ctx.currentTime + 0.35);
            }
          } catch (_) {}
        }

        // Mobile Device Haptic Vibration Feedback
        if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
          try {
            if (data.report.status === 'ALLOW') {
              navigator.vibrate([70, 40, 70]); // Crisp double pulse
            } else {
              navigator.vibrate([200, 100, 200, 100, 400]); // Strong warning buzz
            }
          } catch (_) {}
        }
      } catch (error: any) {
        toast({
          variant: 'destructive',
          title: 'Scan Request Failed',
          description: describeRequestError(error),
        });
      } finally {
        setIsScanning(false);
      }
    },
    [selectedGate, soundEnabled, toast],
  );

  // Frame processing loop for QR decoding
  useEffect(() => {
    if (!open || !isCameraActive) return;

    let isScanningFrame = false;

    const processFrame = () => {
      if (!isCameraActive || !videoRef.current || videoRef.current.readyState < 2) {
        scanLoopRef.current = requestAnimationFrame(processFrame);
        return;
      }

      const now = Date.now();
      if (now - lastScanTimestampRef.current > 100 && !isScanningFrame && !isScanning) {
        lastScanTimestampRef.current = now;
        isScanningFrame = true;

        try {
          const video = videoRef.current;
          let canvas = canvasRef.current;
          if (!canvas) {
            canvas = document.createElement('canvas');
            canvasRef.current = canvas;
          }

          if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
          }

          const ctx = canvas.getContext('2d', { willReadFrequently: true });
          if (ctx && canvas.width > 0 && canvas.height > 0) {
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const qrCode = jsQR(imageData.data, imageData.width, imageData.height, {
              inversionAttempts: 'dontInvert',
            });

            if (qrCode && qrCode.data) {
              const rawData = qrCode.data.trim();
              if (rawData && rawData !== lastScannedTokenRef.current) {
                lastScannedTokenRef.current = rawData;
                setInputToken(rawData);
                void executeScan(rawData);
              }
            }
          }
        } catch (_) {
          // ignore transient frame read errors
        } finally {
          isScanningFrame = false;
        }
      }

      scanLoopRef.current = requestAnimationFrame(processFrame);
    };

    scanLoopRef.current = requestAnimationFrame(processFrame);

    return () => {
      if (scanLoopRef.current) {
        cancelAnimationFrame(scanLoopRef.current);
        scanLoopRef.current = null;
      }
    };
  }, [open, isCameraActive, isScanning, executeScan]);

  // Manage camera on dialog open / close
  useEffect(() => {
    if (open) {
      void startCamera();
    } else {
      stopCamera();
      handleResetForNextScan();
    }
  }, [open, startCamera, stopCamera, handleResetForNextScan]);

  // Confirm or refuse entry
  const handleConfirm = useCallback(async (accept: boolean) => {
    if (!report || !scanId) return;
    setIsConfirmingAction(true);

    try {
      const res = await axios.post(`/dashboard/gate-pass/scans/${scanId}/confirm`, {
        accept,
        reason: accept ? null : 'Refused by officer after inspection',
      });

      setCompleted({ action: res.data.action, time: res.data.time });
      setScanId(null);

      toast({
        title: res.data.action === 'CHECK_IN' ? 'Checked in' : res.data.action === 'CHECK_OUT' ? 'Checked out' : 'Entry refused',
        description: `${report.userName}: ${res.data.message}`,
      });
      onSuccessCheck?.();
    } catch (err: any) {
      toast({
        variant: 'destructive',
        title: 'Not recorded',
        description: err.response?.data?.message || 'Could not record the decision. Scan the pass again.',
      });
    } finally {
      setIsConfirmingAction(false);
    }
  }, [report, scanId, toast, onSuccessCheck]);

  // Global Keyboard Shortcuts for Security Operator Speed
  useEffect(() => {
    if (!open) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      // Ignore if user is currently typing in the manual input
      if (['INPUT', 'TEXTAREA'].includes((e.target as HTMLElement)?.tagName)) {
        return;
      }

      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        if (report && !completed && (decision === 'CHECK_IN' || decision === 'CHECK_OUT')) {
          void handleConfirm(true);
        } else if (report && (decision === 'REJECT' || completed)) {
          handleResetForNextScan();
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [open, report, completed, decision, handleConfirm, handleResetForNextScan]);

  const denyInfo = report ? describeDenyReason(report.primaryReason) : null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl max-h-[96vh] overflow-y-auto p-0 border shadow-2xl bg-card">
        {/* Header with Portal Selector & Sound Controls */}
        <div className="p-5 border-b bg-muted/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shadow-md">
              <Camera className="w-6 h-6" />
            </div>
            <div>
              <DialogTitle className="text-xl font-black tracking-tight text-foreground flex items-center gap-2">
                <span>Gate Pass Scanner</span>
                <span className="text-[11px] font-mono font-bold px-2 py-0.5 rounded-full bg-emerald-600/10 text-emerald-600 border border-emerald-600/20">
                  REAL-TIME
                </span>
              </DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground">
                High-speed credential verification with anti-replay & perimeter validation.
              </DialogDescription>
            </div>
          </div>

          {/* Quick Toolbar */}
          <div className="flex items-center flex-wrap gap-2">
            {/* Audio Toggle */}
            <Button
              type="button"
              size="sm"
              variant={soundEnabled ? 'default' : 'outline'}
              onClick={() => setSoundEnabled((prev) => !prev)}
              className={cn(
                'text-xs font-bold gap-1.5 h-9 rounded-xl',
                soundEnabled && 'bg-slate-800 text-white hover:bg-slate-700'
              )}
              title={soundEnabled ? 'Audio Chimes Enabled' : 'Audio Muted'}
            >
              {soundEnabled ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
              <span>{soundEnabled ? 'Sound ON' : 'Muted'}</span>
            </Button>

            {/* Kiosk Mode Toggle */}
            <Button
              type="button"
              size="sm"
              variant={isKioskMode ? 'default' : 'outline'}
              onClick={() => setIsKioskMode((prev) => !prev)}
              className={cn(
                'text-xs font-bold gap-1.5 h-9 rounded-xl',
                isKioskMode && 'bg-amber-600 hover:bg-amber-700 text-white border-amber-600 shadow-sm'
              )}
              title="Continuous vehicle fast-lane mode with 3s auto-rearm"
            >
              <Zap className={cn('w-4 h-4', isKioskMode && 'fill-white animate-pulse')} />
              <span>{isKioskMode ? 'Fast-Lane: ON' : 'Fast-Lane Mode'}</span>
            </Button>

            {/* Portal Selection */}
            <div className="flex items-center gap-1 bg-background border rounded-xl p-1 text-xs shadow-xs">
              <span className="text-muted-foreground px-2 font-mono text-[11px] font-bold">GATE:</span>
              <button
                type="button"
                onClick={() => setSelectedGate('GATE-01')}
                className={cn(
                  'px-3 py-1.5 rounded-lg font-black transition-all text-xs cursor-pointer',
                  selectedGate === 'GATE-01'
                    ? 'bg-primary text-primary-foreground shadow-xs'
                    : 'text-muted-foreground hover:text-foreground'
                )}
              >
                Gate 01 (Main)
              </button>
              <button
                type="button"
                onClick={() => setSelectedGate('GATE-02')}
                className={cn(
                  'px-3 py-1.5 rounded-lg font-black transition-all text-xs cursor-pointer',
                  selectedGate === 'GATE-02'
                    ? 'bg-primary text-primary-foreground shadow-xs'
                    : 'text-muted-foreground hover:text-foreground'
                )}
              >
                Gate 02 (Service)
              </button>
            </div>
          </div>
        </div>

        <div className="p-6 space-y-6">
          {/* Continuous Fast-Lane Auto-Rearm Countdown */}
          {autoRearmCountdown !== null && (
            <div className="flex items-center justify-between bg-amber-500/10 border-2 border-amber-500/40 rounded-2xl px-5 py-3 text-sm text-amber-800 dark:text-amber-300 font-bold animate-in fade-in duration-200">
              <span className="flex items-center gap-2.5">
                <RefreshCw className="w-4 h-4 animate-spin text-amber-600" />
                <span>Fast-Lane Mode: Re-arming camera in <strong>{autoRearmCountdown}s</strong> for next vehicle…</span>
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setAutoRearmCountdown(null)}
                className="h-7 px-3 text-xs font-bold border-amber-500/50"
              >
                Pause Auto-Rearm
              </Button>
            </div>
          )}

          {/* LARGE CAMERA VIEWFINDER & HIGH-CONTRAST RETICLE */}
          <div className="relative rounded-3xl overflow-hidden bg-slate-950 border-2 border-slate-800 shadow-2xl flex flex-col items-center justify-center min-h-[380px] sm:min-h-[460px] max-h-[560px]">
            {/* Live Video Feed */}
            <video
              ref={videoRef}
              playsInline
              muted
              autoPlay
              className={cn(
                'w-full h-full min-h-[380px] sm:min-h-[460px] max-h-[560px] object-cover transition-opacity duration-300',
                isCameraActive ? 'opacity-90' : 'opacity-20 hidden'
              )}
            />

            {/* Fallback Viewport if camera is unavailable or loading */}
            {!isCameraActive && (
              <div className="p-8 text-center space-y-4">
                <div className="w-20 h-20 rounded-full bg-slate-900 border border-slate-700 flex items-center justify-center mx-auto text-slate-300 shadow-lg">
                  <Camera className="w-10 h-10" />
                </div>
                <div>
                  <p className="text-base font-bold text-slate-200">
                    {cameraError ? 'Camera Notice' : 'Connecting to High-Definition Camera Stream…'}
                  </p>
                  <p className="text-xs text-slate-400 max-w-sm mt-1 mx-auto">
                    {cameraError || 'Position the pass QR directly within device viewport.'}
                  </p>
                </div>
                <Button
                  size="default"
                  variant="outline"
                  onClick={() => void startCamera()}
                  className="text-xs font-bold gap-2 border-slate-600 text-slate-200 hover:bg-slate-800 h-11 px-5 rounded-xl cursor-pointer"
                >
                  <RefreshCw className="w-4 h-4" />
                  Retry Camera
                </Button>
              </div>
            )}

            {/* High-Contrast Target Reticle */}
            {isCameraActive && (
              <div className="absolute inset-0 pointer-events-none flex flex-col items-center justify-center p-4">
                <div className="relative w-64 h-64 sm:w-72 sm:h-72 border-2 border-emerald-400/50 rounded-3xl shadow-[0_0_30px_rgba(16,185,129,0.3)] flex items-center justify-center">
                  {/* Thick High-Contrast Corner Bars */}
                  <div className="absolute -top-1.5 -left-1.5 w-8 h-8 border-t-[6px] border-l-[6px] border-emerald-400 rounded-tl-xl" />
                  <div className="absolute -top-1.5 -right-1.5 w-8 h-8 border-t-[6px] border-r-[6px] border-emerald-400 rounded-tr-xl" />
                  <div className="absolute -bottom-1.5 -left-1.5 w-8 h-8 border-b-[6px] border-l-[6px] border-emerald-400 rounded-bl-xl" />
                  <div className="absolute -bottom-1.5 -right-1.5 w-8 h-8 border-b-[6px] border-r-[6px] border-emerald-400 rounded-br-xl" />

                  {/* Laser Scan Sweep Line */}
                  <div className="absolute left-2 right-2 h-1 bg-gradient-to-r from-transparent via-emerald-400 to-transparent shadow-[0_0_15px_#34d399] animate-bounce" />

                  {/* Center Glyph */}
                  <div className="text-emerald-400/40 font-mono text-3xl font-black">
                    ▣
                  </div>

                  <span className="absolute -bottom-9 text-xs font-mono font-black tracking-widest text-black bg-emerald-400 px-4 py-1 rounded-full shadow-lg border border-white">
                    ALIGN PASS IN CENTER
                  </span>
                </div>
              </div>
            )}

            {/* Floating Camera Utilities */}
            {isCameraActive && (
              <div className="absolute top-4 right-4 flex items-center gap-2">
                {torchSupported && (
                  <Button
                    size="icon"
                    variant="secondary"
                    className="h-10 w-10 rounded-full bg-slate-900/90 backdrop-blur-md border border-slate-700 text-slate-100 hover:bg-slate-800"
                    onClick={() => void toggleTorch()}
                    title="Toggle Flashlight"
                  >
                    {torchEnabled ? <Zap className="w-5 h-5 text-amber-400" /> : <ZapOff className="w-5 h-5" />}
                  </Button>
                )}
                <Button
                  size="icon"
                  variant="secondary"
                  className="h-10 w-10 rounded-full bg-slate-900/90 backdrop-blur-md border border-slate-700 text-slate-100 hover:bg-slate-800"
                  onClick={flipCamera}
                  title="Switch Camera (Front/Back)"
                >
                  <RefreshCw className="w-5 h-5" />
                </Button>
              </div>
            )}
          </div>

          {/* MANUAL TOKEN INPUT BACKUP */}
          <div className="bg-muted/40 border rounded-2xl p-4 space-y-3">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (inputToken.trim()) void executeScan(inputToken.trim());
              }}
              className="flex gap-2"
            >
              <Input
                placeholder="Scan QR or paste 'CH-GPE:v1....'"
                value={inputToken}
                onChange={(e) => setInputToken(e.target.value)}
                className="font-mono text-xs sm:text-sm h-11 bg-background rounded-xl"
              />
              <Button type="submit" size="default" className="h-11 px-6 text-xs sm:text-sm font-bold gap-2 rounded-xl" disabled={isScanning}>
                <Scan className="w-4 h-4" />
                Verify
              </Button>
            </form>
          </div>

          {/* ══════════════════════════════════════════════════════════════════
              HIGH-CONTRAST, ACCESSIBLE SCAN RESULTS
             ══════════════════════════════════════════════════════════════════ */}

          {report && (
            <div className="animate-in fade-in slide-in-from-bottom-4 duration-300 space-y-5">
              {/* A resident's silent request for help, for the guard's eyes only. */}
              <SilentAssistanceBanner signal={report.silentAssistance} />

              {/* STATE 1: CHECK IN (✓ VALID PASS) */}
              {decision === 'CHECK_IN' && !completed && (
                <div className="border-4 border-emerald-600 rounded-3xl overflow-hidden shadow-2xl bg-card">
                  {/* High-Contrast Valid Header */}
                  <div className="bg-emerald-600 dark:bg-emerald-700 p-6 text-white text-center space-y-3 border-b-4 border-emerald-800">
                    <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-black bg-white text-emerald-900 shadow-md uppercase tracking-wider">
                      <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                      <span>✓ ACCESS GRANTED — VALID PASS</span>
                    </div>

                    <div className="pt-2 flex flex-col items-center">
                      {report.photoUrl ? (
                        <div className="relative mb-3">
                          <img
                            src={report.photoUrl}
                            alt={report.person || report.userName}
                            className="w-24 h-24 rounded-full object-cover border-4 border-white shadow-xl"
                          />
                          <Badge className="absolute -bottom-2 left-1/2 -translate-x-1/2 bg-emerald-950 text-[11px] font-bold px-3 py-0.5 border-white text-white whitespace-nowrap">
                            Photo Verified
                          </Badge>
                        </div>
                      ) : (
                        <div className="w-20 h-20 rounded-full bg-white/20 border-4 border-white flex items-center justify-center text-3xl font-black mb-2 text-white shadow-md">
                          {(report.person || report.userName).charAt(0).toUpperCase()}
                        </div>
                      )}

                      <div className="flex items-center gap-2 mt-1">
                        <span className="text-xs font-mono uppercase tracking-widest text-emerald-100 font-bold">
                          {report.profile || report.category}
                        </span>
                      </div>

                      <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-white mt-1">
                        {report.person || report.userName}
                      </h2>
                      <p className="text-sm font-bold text-emerald-100 mt-0.5">
                        {report.property.startsWith('Property:') ? report.property : `Property: ${report.property}`}
                      </p>
                    </div>
                  </div>

                  {/* Structured Details */}
                  <div className="p-6 space-y-5">
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-left">
                      <div className="p-3 bg-muted/60 rounded-xl border-2">
                        <span className="text-[10px] font-mono text-muted-foreground uppercase block font-bold">Person</span>
                        <strong className="text-sm font-black text-foreground truncate block">
                          {report.person || report.userName}
                        </strong>
                      </div>
                      <div className="p-3 bg-muted/60 rounded-xl border-2">
                        <span className="text-[10px] font-mono text-muted-foreground uppercase block font-bold">Profile</span>
                        <strong className="text-sm font-black text-emerald-600 dark:text-emerald-400 truncate block">
                          {report.profile || report.category}
                        </strong>
                      </div>
                      <div className="p-3 bg-muted/60 rounded-xl border-2">
                        <span className="text-[10px] font-mono text-muted-foreground uppercase block font-bold">Property</span>
                        <strong className="text-sm font-black text-foreground truncate block">
                          {report.property}
                        </strong>
                      </div>
                      <div className="p-3 bg-muted/60 rounded-xl border-2">
                        <span className="text-[10px] font-mono text-muted-foreground uppercase block font-bold">Resident Host</span>
                        <strong className="text-sm font-black text-foreground truncate block">
                          {report.host || 'Self'}
                        </strong>
                      </div>
                      <div className="p-3 bg-muted/60 rounded-xl border-2">
                        <span className="text-[10px] font-mono text-muted-foreground uppercase block font-bold">Pass Type</span>
                        <strong className="text-sm font-black text-foreground truncate block">
                          {report.passType || 'Standard Access'}
                        </strong>
                      </div>
                      <div className="p-3 bg-muted/60 rounded-xl border-2">
                        <span className="text-[10px] font-mono text-muted-foreground uppercase block font-bold">Portal</span>
                        <strong className="text-sm font-black text-foreground truncate block">
                          {report.gateChecked}
                        </strong>
                      </div>
                    </div>

                    {/* Giant Check In Button */}
                    <div className="space-y-3 pt-2">
                      <Button
                        type="button"
                        size="lg"
                        className="w-full h-16 bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white text-lg font-black tracking-wide shadow-xl gap-3 rounded-2xl cursor-pointer focus-visible:ring-4 focus-visible:ring-emerald-400"
                        onClick={() => void handleConfirm(true)}
                        disabled={isConfirmingAction}
                      >
                        <UserCheck className="w-6 h-6" />
                        <span>CONFIRM CHECK IN</span>
                        <span className="ml-2 px-2.5 py-1 rounded-lg bg-black/25 text-xs font-mono border border-white/30">
                          [Enter / Space]
                        </span>
                      </Button>

                      <Button
                        type="button"
                        variant="outline"
                        className="w-full h-12 text-sm font-bold text-rose-600 dark:text-rose-400 border-2 border-rose-300 dark:border-rose-800 hover:bg-rose-50 dark:hover:bg-rose-950/30 rounded-xl cursor-pointer"
                        onClick={() => void handleConfirm(false)}
                        disabled={isConfirmingAction}
                      >
                        Refuse Entry (Photo / Identity Mismatch)
                      </Button>
                    </div>
                  </div>
                </div>
              )}

              {/* STATE 2: CHECK OUT (✓ VALID / INSIDE) */}
              {decision === 'CHECK_OUT' && !completed && (
                <div className="border-4 border-blue-600 rounded-3xl overflow-hidden shadow-2xl bg-card">
                  <div className="bg-blue-600 dark:bg-blue-700 p-6 text-white text-center space-y-3 border-b-4 border-blue-800">
                    <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-black bg-white text-blue-900 shadow-md uppercase tracking-wider">
                      <Clock className="w-5 h-5 text-blue-600" />
                      <span>✓ CURRENTLY INSIDE — RECORD DEPARTURE</span>
                    </div>

                    <div className="pt-2 flex flex-col items-center">
                      <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-white">
                        {report.person || report.userName}
                      </h2>
                      <p className="text-sm font-bold text-blue-100 mt-1">
                        {report.property.startsWith('Property:') ? report.property : `Property: ${report.property}`}
                      </p>
                    </div>
                  </div>

                  <div className="p-6 space-y-5">
                    {/* Giant Check Out Button */}
                    <div className="space-y-3 pt-2">
                      <Button
                        type="button"
                        size="lg"
                        className="w-full h-16 bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white text-lg font-black tracking-wide shadow-xl gap-3 rounded-2xl cursor-pointer focus-visible:ring-4 focus-visible:ring-blue-400"
                        onClick={() => void handleConfirm(true)}
                        disabled={isConfirmingAction}
                      >
                        <LogOut className="w-6 h-6" />
                        <span>CONFIRM CHECK OUT</span>
                        <span className="ml-2 px-2.5 py-1 rounded-lg bg-black/25 text-xs font-mono border border-white/30">
                          [Enter / Space]
                        </span>
                      </Button>
                    </div>
                  </div>
                </div>
              )}

              {/* CONFIRMATION RECORDED */}
              {completed && (
                <div className="p-8 rounded-3xl bg-card border-4 border-emerald-600 shadow-2xl text-center space-y-4">
                  <div className="w-16 h-16 rounded-full bg-emerald-600 text-white flex items-center justify-center mx-auto shadow-lg">
                    <CheckCircle2 className="w-10 h-10" />
                  </div>
                  <div>
                    <h3 className="text-2xl font-black text-foreground">
                      {completed.action === 'CHECK_IN' ? '✓ CHECKED IN' : completed.action === 'CHECK_OUT' ? '✓ CHECKED OUT' : '✕ ENTRY REFUSED'}
                    </h3>
                    <p className="text-sm font-bold text-muted-foreground mt-1">
                      Recorded at {completed.time}. Access log entry created.
                    </p>
                  </div>

                  <Button
                    type="button"
                    size="lg"
                    className="w-full h-16 text-base font-black bg-primary text-primary-foreground gap-3 rounded-2xl shadow-xl cursor-pointer"
                    onClick={handleResetForNextScan}
                  >
                    <RefreshCw className="w-5 h-5" />
                    <span>SCAN NEXT PASS</span>
                    <span className="px-2 py-0.5 rounded-md bg-black/25 text-xs font-mono border border-white/20">
                      [Enter / Space]
                    </span>
                  </Button>
                </div>
              )}

              {/* STATE 3: REJECT (✕ ACCESS DENIED) */}
              {decision === 'REJECT' && (
                <div className="border-4 border-rose-600 rounded-3xl overflow-hidden shadow-2xl bg-card">
                  {/* High-Contrast Red Header */}
                  <div className="bg-rose-700 dark:bg-rose-900 p-6 text-white text-center space-y-3 border-b-4 border-rose-950">
                    <div className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-xs font-black bg-white text-rose-900 shadow-md uppercase tracking-wider">
                      <XCircle className="w-5 h-5 text-rose-700" />
                      <span>✕ ACCESS DENIED — DO NOT ADMIT</span>
                    </div>

                    <div className="pt-2">
                      <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-white">
                        REJECTED
                      </h2>
                      <div className="mt-3 inline-block px-4 py-1.5 rounded-xl bg-black/40 font-mono text-sm font-black text-rose-100 border border-white/30">
                        {denyInfo?.label || 'UNAUTHORIZED'}
                      </div>
                    </div>
                  </div>

                  {/* Clear Readable Error Message & Security Actions */}
                  <div className="p-6 space-y-5">
                    <div className="p-5 bg-rose-500/10 rounded-2xl border-2 border-rose-500/30 space-y-3">
                      <div>
                        <span className="text-[11px] font-mono uppercase text-muted-foreground font-bold block">Security Notice:</span>
                        <p className="text-sm font-bold text-foreground mt-0.5">
                          {denyInfo?.explanation}
                        </p>
                      </div>

                      <div className="p-3 bg-card rounded-xl border border-rose-500/20 text-xs font-bold text-rose-700 dark:text-rose-400">
                        <span>Officer Directive: </span>
                        <span>{denyInfo?.action}</span>
                      </div>

                      <div className="grid grid-cols-2 gap-2 text-xs font-mono text-muted-foreground pt-2 border-t border-rose-500/20">
                        <div>
                          <span>Attempted Gate: </span>
                          <strong className="text-foreground">{report.gateChecked}</strong>
                        </div>
                        <div>
                          <span>Pass ID: </span>
                          <strong className="text-foreground">{report.passId}</strong>
                        </div>
                      </div>
                    </div>

                    {/* Automatic Audit Log Notice */}
                    <div className="p-4 bg-muted/60 rounded-2xl border flex items-center gap-3">
                      <ShieldAlert className="w-6 h-6 text-rose-600 dark:text-rose-400 shrink-0" />
                      <div className="text-xs text-foreground">
                        <span className="font-bold block">Incident Recorded in Security Log</span>
                        <span className="text-muted-foreground">
                          Rejection logged to central security directory {accessLogId ? `(Access Log #${accessLogId})` : ''} and dispatched to supervisor.
                        </span>
                      </div>
                    </div>

                    {/* Large Fast-Recovery Button */}
                    <div className="space-y-3 pt-2">
                      <Button
                        type="button"
                        size="lg"
                        className="w-full h-16 text-base font-black bg-primary text-primary-foreground gap-3 rounded-2xl shadow-xl cursor-pointer"
                        onClick={handleResetForNextScan}
                      >
                        <RefreshCw className="w-5 h-5" />
                        <span>↺ READY FOR NEXT SCAN (RESET)</span>
                        <span className="px-2 py-0.5 rounded-md bg-black/25 text-xs font-mono border border-white/20">
                          [Enter / Space]
                        </span>
                      </Button>

                      <Button
                        type="button"
                        variant="outline"
                        className="w-full h-11 text-xs font-bold gap-2 rounded-xl"
                        onClick={() => setShowTechnicalDetails(!showTechnicalDetails)}
                      >
                        <Layers className="w-4 h-4" />
                        {showTechnicalDetails ? 'Hide Security Audit Matrix' : 'View Security Audit Matrix'}
                      </Button>
                    </div>
                  </div>
                </div>
              )}

              {/* 16-POINT TECHNICAL MATRIX */}
              {showTechnicalDetails && (
                <div className="border rounded-2xl p-5 bg-card space-y-4 animate-in fade-in duration-200">
                  <div className="flex items-center justify-between border-b pb-3">
                    <span className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                      <Layers className="w-4 h-4 text-primary" />
                      16-Factor Security Ingress Audit Matrix
                    </span>
                    <Badge variant="outline" className="text-[10px] font-mono">
                      Pass ID: {report.passId}
                    </Badge>
                  </div>

                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
                    <div className="p-2.5 rounded-lg border bg-muted/20">
                      <span className="text-[10px] text-muted-foreground block font-mono">1. QR Structure</span>
                      <strong className={report.stages.structure.passed ? "text-emerald-600" : "text-rose-600"}>
                        {report.stages.structure.passed ? '✓ Valid Envelope' : '✕ Malformed'}
                      </strong>
                    </div>
                    <div className="p-2.5 rounded-lg border bg-muted/20">
                      <span className="text-[10px] text-muted-foreground block font-mono">2. HMAC-SHA256</span>
                      <strong className={report.stages.cryptography.passed ? "text-emerald-600" : "text-rose-600"}>
                        {report.stages.cryptography.passed ? '✓ Verified' : '✕ Forged'}
                      </strong>
                    </div>
                    <div className="p-2.5 rounded-lg border bg-muted/20">
                      <span className="text-[10px] text-muted-foreground block font-mono">3. Pass ID</span>
                      <strong className="text-foreground truncate block">{report.passId}</strong>
                    </div>
                    <div className="p-2.5 rounded-lg border bg-muted/20">
                      <span className="text-[10px] text-muted-foreground block font-mono">4. Person/Account</span>
                      <strong className="text-foreground truncate block">{report.userName}</strong>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
