import React, { useState, useRef, useEffect, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Scan } from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import axios from 'axios';

import type { 
  GateOption, 
  RecentScan, 
  ScanOutcome, 
  SensorEvent, 
  GateScannerProps 
} from '@/components/dashboard/gate-scanner/types';
import { 
  GateScannerHeaderControls, 
  GateScannerInputCard 
} from '@/components/dashboard/gate-scanner/gate-scanner-input-card';
import { 
  GateDecisionCard, 
  AntiPassbackOverrideModal 
} from '@/components/dashboard/gate-scanner/gate-decision-card';
import { 
  GateAnprCard, 
  GateSensorCard, 
  GateRecentScansCard, 
  GateOfficerDutyCard 
} from '@/components/dashboard/gate-scanner/gate-hardware-monitor';

export type { GateOption, RecentScan, ScanOutcome, SensorEvent, GateScannerProps };

export default function GateScannerPage({ gates = [], recentScans = [], guard }: GateScannerProps) {
  const { toast } = useToast();
  const [selectedGate, setSelectedGate] = useState<string>(gates[0]?.id || 'GATE-01');
  const [direction, setDirection] = useState<'in' | 'out'>('in');
  const [tokenInput, setTokenInput] = useState<string>('');
  const [lastScannedToken, setLastScannedToken] = useState<string>('');
  const [isScanning, setIsScanning] = useState<boolean>(false);
  const [isConfirming, setIsConfirming] = useState<boolean>(false);
  const [outcome, setOutcome] = useState<ScanOutcome | null>(null);
  const [soundEnabled, setSoundEnabled] = useState<boolean>(true);
  const [scansList, setScansList] = useState<RecentScan[]>(recentScans);

  // Anti-Passback Security Override Modal State
  const [showOverrideModal, setShowOverrideModal] = useState<boolean>(false);
  const [overrideReason, setOverrideReason] = useState<string>('');
  const [isOverriding, setIsOverriding] = useState<boolean>(false);

  // ANPR Plate Fast Scan State
  const [anprPlate, setAnprPlate] = useState<string>('XXX-1234');
  const [isAnprLoading, setIsAnprLoading] = useState<boolean>(false);
  const [anprLookupResult, setAnprLookupResult] = useState<any | null>(null);

  // Occupancy sensor readings reported by gate hardware
  const [sensorEvents, setSensorEvents] = useState<SensorEvent[]>([]);
  const [sensorFeedFailed, setSensorFeedFailed] = useState<boolean>(false);
  const openSensorAlertIds = useRef<Set<number>>(new Set());

  const inputRef = useRef<HTMLInputElement>(null);

  // Focus input automatically on mount and after confirmation
  useEffect(() => {
    inputRef.current?.focus();
  }, [outcome]);

  // Synthetic Audio Chime using Web Audio API
  const playAudioCue = useCallback((success: boolean) => {
    if (!soundEnabled) return;
    try {
      const AudioContextClass = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
      const ctx = new AudioContextClass();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.connect(gain);
      gain.connect(ctx.destination);

      if (success) {
        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime);
        osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.4);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.4);
      } else {
        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(220, ctx.currentTime);
        osc.frequency.setValueAtTime(164.81, ctx.currentTime + 0.15);
        gain.gain.setValueAtTime(0.35, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.45);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + 0.45);
      }
    } catch {
      // AudioContext unavailable
    }
  }, [soundEnabled]);

  const handleScanSubmit = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    const raw = tokenInput.trim();
    if (!raw) return;

    setLastScannedToken(raw);
    setIsScanning(true);
    try {
      const res = await axios.post('/dashboard/gate-pass/scan', {
        token: raw,
        gate: selectedGate,
        direction: direction,
      });

      const data = res.data;
      const isGranted = data.decision === 'CHECK_IN' || data.decision === 'CHECK_OUT';
      playAudioCue(isGranted);

      const isPassback = Boolean(
        data.isPassbackViolation ||
        data.report?.denyReason === 'ALREADY_CHECKED_IN' ||
        data.report?.primaryReason?.includes('Already Checked In')
      );

      const newOutcome: ScanOutcome = {
        decision: data.decision,
        status: data.report?.status || (isGranted ? 'VALID' : 'INVALID'),
        scanId: data.scanId || null,
        isPassbackViolation: isPassback,
        canOverride: data.canOverride ?? isPassback,
        checkedInAt: data.checkedInAt || data.report?.checkedInAt,
        report: data.report || {
          passId: 'UNKNOWN',
          category: 'VISITOR',
          userName: 'Unknown Visitor',
        },
        occurredAt: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
      };

      setOutcome(newOutcome);

      // Add to local recent scan log
      setScansList((prev) => [
        {
          id: Date.now(),
          userName: newOutcome.report.userName,
          userRole: newOutcome.report.category,
          result: isGranted ? 'GRANT' : 'DENY',
          gate: gates.find((g) => g.id === selectedGate)?.name || selectedGate,
          occurredAt: 'Just now',
          denyReason: isGranted ? null : (newOutcome.report.primaryReason || 'Verification failed'),
        },
        ...prev.slice(0, 11),
      ]);

      setTokenInput('');
    } catch (err: unknown) {
      playAudioCue(false);
      const errMsg = axios.isAxiosError(err) && err.response?.data?.message
        ? err.response.data.message
        : 'Failed to process gate token. Please verify token syntax.';

      toast({
        variant: 'destructive',
        title: 'Scan Error',
        description: errMsg,
      });
    } finally {
      setIsScanning(false);
    }
  };

  const handleOverrideSubmit = async () => {
    if (!outcome) return;
    setIsOverriding(true);
    try {
      const res = await axios.post('/dashboard/gate-pass/scan', {
        token: lastScannedToken || outcome.report.passId,
        gate: selectedGate,
        direction: 'in',
        override_passback: true,
        override_reason: overrideReason.trim(),
      });

      const data = res.data;
      const isGranted = data.decision === 'CHECK_IN';
      playAudioCue(isGranted);

      const updatedOutcome: ScanOutcome = {
        decision: data.decision,
        status: data.report?.status || 'VALID',
        scanId: data.scanId || null,
        isPassbackViolation: false,
        canOverride: false,
        report: data.report || {
          passId: outcome.report.passId,
          category: outcome.report.category,
          userName: outcome.report.userName,
          primaryReason: data.report?.primaryReason,
        },
        occurredAt: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
      };

      setOutcome(updatedOutcome);
      setShowOverrideModal(false);

      toast({
        title: 'Security Override Approved',
        description: `Anti-passback cleared for ${outcome.report.userName}. Open barrier confirmation enabled.`,
      });
    } catch (err: unknown) {
      playAudioCue(false);
      const errMsg = axios.isAxiosError(err) && err.response?.data?.message
        ? err.response.data.message
        : 'Security override failed. Ensure pass is active.';
      toast({
        variant: 'destructive',
        title: 'Override Error',
        description: errMsg,
      });
    } finally {
      setIsOverriding(false);
    }
  };

  const handleConfirmAdmission = async () => {
    if (!outcome?.scanId) return;

    setIsConfirming(true);
    try {
      await axios.post(`/dashboard/gate-pass/scans/${outcome.scanId}/confirm`, { accept: true });
      playAudioCue(true);

      toast({
        title: outcome.decision === 'CHECK_IN' ? 'Guest Admitted' : 'Exit Logged',
        description: `${outcome.report.userName} entry verified. Resident host notified.`,
      });

      setOutcome(null);
      inputRef.current?.focus();
    } catch (err: unknown) {
      const errMsg = axios.isAxiosError(err) && err.response?.data?.message
        ? err.response.data.message
        : 'Scan expired or already confirmed.';

      toast({
        variant: 'destructive',
        title: 'Confirmation Failed',
        description: errMsg,
      });
    } finally {
      setIsConfirming(false);
    }
  };

  const handleAnprFastLookup = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!anprPlate.trim()) return;

    setIsAnprLoading(true);
    setAnprLookupResult(null);

    try {
      const res = await axios.get('/dashboard/vehicles/anpr/lookup', {
        params: { plate: anprPlate.trim() },
      });
      setAnprLookupResult(res.data);
      if (res.data.found && res.data.authorized) {
        playAudioCue(true);
        toast({
          title: 'ANPR Fast Lane: AUTHORIZED',
          description: `${res.data.owner?.name} (${res.data.vehicle?.make} ${res.data.vehicle?.model}) - Gate Barrier Opened.`,
        });
      } else {
        playAudioCue(false);
        toast({
          variant: 'destructive',
          title: 'ANPR Fast Lane: HOLD VEHICLE',
          description: res.data.message || 'Plate not authorized.',
        });
      }
    } catch (err: any) {
      if (err.response?.status === 404 && err.response.data) {
        setAnprLookupResult(err.response.data);
        playAudioCue(false);
        toast({
          variant: 'destructive',
          title: 'ANPR Fast Lane: HOLD VEHICLE',
          description: err.response.data.message || 'Plate not authorized.',
        });
        return;
      }
      setAnprLookupResult({
        found: false,
        authorized: false,
        message: 'Plate lookup error.',
      });
    } finally {
      setIsAnprLoading(false);
    }
  };

  const loadSensorEvents = useCallback(async () => {
    try {
      const res = await axios.get<{ events: SensorEvent[] }>('/dashboard/gate-sensor/recent');
      const events = res.data.events ?? [];
      const open = events.filter((e) => e.is_tailgating && e.resolution_status === 'UNRESOLVED');
      if (open.some((e) => !openSensorAlertIds.current.has(e.id))) {
        playAudioCue(false);
      }
      openSensorAlertIds.current = new Set(open.map((e) => e.id));
      setSensorEvents(events);
      setSensorFeedFailed(false);
    } catch {
      setSensorFeedFailed(true);
    }
  }, [playAudioCue]);

  useEffect(() => {
    loadSensorEvents();
    const timer = setInterval(loadSensorEvents, 15000);
    return () => clearInterval(timer);
  }, [loadSensorEvents]);

  const handleResolveSensorAlert = async (eventId: number, status: string) => {
    try {
      await axios.post('/dashboard/gate-sensor/resolve', {
        event_id: eventId,
        status,
        notes: `Handled by gate kiosk officer: ${guard?.name || 'Security'}`,
      });
      toast({
        title: status === 'CONFIRMED_VIOLATION' ? 'Patrol Dispatched' : 'Alert Dismissed',
        description: `Tailgating event marked ${status === 'CONFIRMED_VIOLATION' ? 'confirmed' : 'dismissed'}.`,
      });
      loadSensorEvents();
    } catch {
      toast({
        variant: 'destructive',
        title: 'Error resolving event',
      });
    }
  };

  const openSensorAlerts = sensorEvents.filter((e) => e.is_tailgating && e.resolution_status === 'UNRESOLVED');
  const latestSensorReading = sensorEvents[0];

  return (
    <DashboardLayout>
      <Head title="Gate Scanner Kiosk" />

      <div className="space-y-6 max-w-7xl mx-auto px-2 sm:px-4 py-4">
        {/* Kiosk Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border/60 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="h-3 w-3 rounded-full bg-emerald-500 animate-pulse" />
              <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <Scan className="h-6 w-6 text-primary" />
                Gate Verification Kiosk
              </h1>
            </div>
            <p className="text-sm text-muted-foreground mt-0.5">
              High-speed cryptographic clearance station for security officers.
            </p>
          </div>

          <GateScannerHeaderControls
            direction={direction}
            onDirectionChange={setDirection}
            selectedGate={selectedGate}
            onGateChange={setSelectedGate}
            gates={gates}
            soundEnabled={soundEnabled}
            onToggleSound={() => setSoundEnabled(!soundEnabled)}
          />
        </div>

        {/* Main Scanner Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Active Scan Station (8 cols) */}
          <div className="lg:col-span-8 space-y-6">
            <GateScannerInputCard
              direction={direction}
              tokenInput={tokenInput}
              onTokenInputChange={setTokenInput}
              onScanSubmit={handleScanSubmit}
              isScanning={isScanning}
              inputRef={inputRef}
            />

            {/* ANPR & Optical Sensor Systems Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <GateAnprCard
                plate={anprPlate}
                onPlateChange={setAnprPlate}
                isLoading={isAnprLoading}
                lookupResult={anprLookupResult}
                onSubmit={handleAnprFastLookup}
              />

              <GateSensorCard
                openAlerts={openSensorAlerts}
                latestReading={latestSensorReading}
                feedFailed={sensorFeedFailed}
                onResolveAlert={handleResolveSensorAlert}
              />
            </div>

            {/* Verification Result Display */}
            <GateDecisionCard
              outcome={outcome}
              isConfirming={isConfirming}
              onConfirmAdmission={handleConfirmAdmission}
              onOpenOverrideModal={() => setShowOverrideModal(true)}
              onReset={() => {
                setOutcome(null);
                inputRef.current?.focus();
              }}
            />
          </div>

          {/* Recent Gate Activity (4 cols) */}
          <div className="lg:col-span-4 space-y-6">
            <GateRecentScansCard scans={scansList} />

            <GateOfficerDutyCard
              guardName={guard?.name}
              selectedGate={selectedGate}
            />
          </div>
        </div>
      </div>

      {/* Anti-Passback Security Override Confirmation Dialog */}
      <AntiPassbackOverrideModal
        open={showOverrideModal}
        onOpenChange={setShowOverrideModal}
        outcome={outcome}
        overrideReason={overrideReason}
        onOverrideReasonChange={setOverrideReason}
        isOverriding={isOverriding}
        onOverrideSubmit={handleOverrideSubmit}
        guardName={guard?.name}
      />
    </DashboardLayout>
  );
}
