import React from 'react';
import { 
  Camera, 
  Activity, 
  AlertOctagon, 
  History, 
  RefreshCw 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { SensorEvent, RecentScan } from './types';

interface GateAnprCardProps {
  plate: string;
  onPlateChange: (val: string) => void;
  isLoading: boolean;
  lookupResult: any | null;
  onSubmit: (e?: React.FormEvent) => void;
}

export function GateAnprCard({
  plate,
  onPlateChange,
  isLoading,
  lookupResult,
  onSubmit,
}: GateAnprCardProps) {
  return (
    <Card className="border border-border/70 bg-card/80 shadow-xs">
      <CardHeader className="py-2.5 px-3.5 bg-muted/20 border-b border-border/40">
        <div className="flex items-center justify-between">
          <CardTitle className="text-xs font-bold flex items-center gap-1.5">
            <Camera className="h-4 w-4 text-blue-400" />
            ANPR Optical Camera
          </CardTitle>
          <Badge variant="outline" className="text-[10px] font-mono text-blue-400 border-blue-500/30">
            Fast Lane Ready
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="p-3 space-y-2.5">
        <form onSubmit={onSubmit} className="flex gap-2">
          <Input
            placeholder="e.g. XXX-1234 or 9821-JA"
            value={plate}
            onChange={(e) => onPlateChange(e.target.value.toUpperCase())}
            className="font-mono text-xs uppercase h-8"
          />
          <Button
            type="submit"
            size="sm"
            disabled={isLoading || !plate.trim()}
            className="h-8 text-xs bg-blue-600 hover:bg-blue-500 text-white px-3"
          >
            {isLoading ? <RefreshCw className="h-3 w-3 animate-spin" /> : 'Scan Plate'}
          </Button>
        </form>

        {lookupResult && (
          <div
            className={cn(
              'p-2 rounded-lg border text-xs space-y-1',
              lookupResult.authorized
                ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300'
                : 'bg-destructive/10 border-destructive/30 text-destructive'
            )}
          >
            <div className="flex items-center justify-between font-bold text-[11px]">
              <span>{lookupResult.authorized ? 'BOOM GATE OPEN — ANPR PASS' : 'ANPR HOLD — UNVERIFIED'}</span>
              <span className="font-mono text-[10px]">{lookupResult.normalized_plate}</span>
            </div>
            {lookupResult.owner && (
              <div className="text-[11px] text-foreground font-sans">
                {lookupResult.owner.name} &bull; {lookupResult.vehicle?.color} {lookupResult.vehicle?.make} {lookupResult.vehicle?.model} &bull; {lookupResult.vehicle?.parking_location || 'Resident Bay'}
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

interface GateSensorCardProps {
  openAlerts: SensorEvent[];
  latestReading?: SensorEvent;
  feedFailed: boolean;
  onResolveAlert: (eventId: number, status: string) => void;
}

export function GateSensorCard({
  openAlerts,
  latestReading,
  feedFailed,
  onResolveAlert,
}: GateSensorCardProps) {
  return (
    <Card className="border border-border/70 bg-card/80 shadow-xs">
      <CardHeader className="py-2.5 px-3.5 bg-muted/20 border-b border-border/40">
        <div className="flex items-center justify-between">
          <CardTitle className="text-xs font-bold flex items-center gap-1.5">
            <Activity className="h-4 w-4 text-amber-400" />
            Optical Sensor & Tailgating
          </CardTitle>
          <Badge variant={openAlerts.length ? 'destructive' : 'outline'} className="text-[10px] font-mono">
            {openAlerts.length ? `${openAlerts.length} open` : 'Clear'}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="p-3 space-y-2.5">
        {openAlerts.map((alert) => (
          <div
            key={alert.id}
            className="p-2 rounded-lg bg-destructive/15 border-2 border-destructive/60 text-destructive text-xs space-y-1.5"
          >
            <div className="flex items-center justify-between font-bold text-[11px]">
              <span className="flex items-center gap-1">
                <AlertOctagon className="h-3.5 w-3.5" /> Possible tailgating at {alert.gate}
              </span>
              <span className="text-[10px] font-mono">
                {new Date(alert.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              </span>
            </div>
            <p className="text-[11px] text-destructive-foreground dark:text-red-200">
              Allowed {alert.authorized_occupants} &bull; counted {alert.detected_occupants}
              {alert.sensor_metadata?.device_name ? ` (${alert.sensor_metadata.device_name})` : ''}
            </p>
            <div className="flex gap-1.5 pt-0.5">
              <Button
                size="sm"
                variant="destructive"
                onClick={() => onResolveAlert(alert.id, 'CONFIRMED_VIOLATION')}
                className="h-6 text-[10px] px-2"
              >
                Dispatch Patrol
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={() => onResolveAlert(alert.id, 'DISMISSED')}
                className="h-6 text-[10px] px-2 border-destructive/40 text-destructive"
              >
                Dismiss
              </Button>
            </div>
          </div>
        ))}

        {openAlerts.length === 0 && (
          <p className="text-[11px] text-muted-foreground">
            {feedFailed
              ? 'Could not load sensor readings. Retrying…'
              : latestReading
                ? `No open tailgating alerts. Last reading ${new Date(latestReading.occurred_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} at ${latestReading.gate}.`
                : 'No sensor readings yet. Gate sensors report here once registered (php artisan gate-devices:register).'}
          </p>
        )}
      </CardContent>
    </Card>
  );
}

interface GateRecentScansCardProps {
  scans: RecentScan[];
}

export function GateRecentScansCard({ scans }: GateRecentScansCardProps) {
  return (
    <Card className="border-border shadow-xs">
      <CardHeader className="pb-3 border-b border-border/60">
        <CardTitle className="text-sm font-semibold flex items-center gap-2">
          <History className="h-4 w-4 text-muted-foreground" />
          Recent Gate Clearances
        </CardTitle>
        <CardDescription className="text-xs">
          Live verification audit feed
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0 divide-y divide-border/60 max-h-[500px] overflow-y-auto">
        {scans.length === 0 ? (
          <p className="text-xs text-muted-foreground p-4 text-center">No scans recorded this session.</p>
        ) : (
          scans.map((scan) => (
            <div key={scan.id} className="p-3.5 hover:bg-muted/40 transition text-xs space-y-1">
              <div className="flex items-center justify-between">
                <span className="font-semibold text-foreground truncate max-w-[150px]">{scan.userName}</span>
                <Badge
                  variant={scan.result === 'GRANT' ? 'default' : 'destructive'}
                  className="text-[10px] uppercase font-bold"
                >
                  {scan.result}
                </Badge>
              </div>
              <div className="flex items-center justify-between text-muted-foreground text-[11px]">
                <span>{scan.userRole} &middot; {scan.gate}</span>
                <span>{scan.occurredAt}</span>
              </div>
              {scan.denyReason && (
                <p className="text-[10px] text-destructive italic truncate">
                  Reason: {scan.denyReason}
                </p>
              )}
            </div>
          ))
        )}
      </CardContent>
    </Card>
  );
}

interface GateOfficerDutyCardProps {
  guardName?: string;
  selectedGate: string;
}

export function GateOfficerDutyCard({
  guardName,
  selectedGate,
}: GateOfficerDutyCardProps) {
  return (
    <div className="rounded-xl border border-border/60 bg-muted/30 p-4 text-xs space-y-2">
      <span className="font-bold text-foreground uppercase tracking-wider text-[10px] block">
        Officer on Duty
      </span>
      <div className="flex items-center justify-between">
        <span className="text-muted-foreground">Guard:</span>
        <span className="font-semibold text-foreground">{guardName || 'Security Personnel'}</span>
      </div>
      <div className="flex items-center justify-between">
        <span className="text-muted-foreground">Duty Station:</span>
        <span className="font-semibold text-foreground">{selectedGate}</span>
      </div>
      <div className="flex items-center justify-between">
        <span className="text-muted-foreground">Crypto Protocol:</span>
        <span className="font-mono text-foreground font-semibold">HMAC-SHA256 (Slot: 30s)</span>
      </div>
    </div>
  );
}
