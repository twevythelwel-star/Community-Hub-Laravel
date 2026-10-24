import React from 'react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ShieldCheck, Settings2, Zap, Copy, Info } from 'lucide-react';

interface MapGeofenceTelemetryProps {
  isSystemAdmin: boolean;
  coordinatesCount: number;
  areaAcres: number | string;
  areaHectares: number | string;
  perimeterMeters: number | string;
  center: [number, number];
  onOpenGeofenceDialog: () => void;
  onSimulateGeofence: () => void;
  onCopyCentroid: (coords: [number, number]) => void;
}

export function MapGeofenceTelemetry({
  isSystemAdmin,
  coordinatesCount,
  areaAcres,
  areaHectares,
  perimeterMeters,
  center,
  onOpenGeofenceDialog,
  onSimulateGeofence,
  onCopyCentroid,
}: MapGeofenceTelemetryProps) {
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
          <ShieldCheck className="h-4 w-4 text-emerald-500" />
          Geofence Parameters
        </h2>
        <div className="flex items-center gap-1.5">
          {isSystemAdmin && (
            <Button
              size="sm"
              variant="outline"
              onClick={onOpenGeofenceDialog}
              className="text-xs h-7 gap-1 border-emerald-500/40 text-emerald-600 bg-emerald-500/10 font-medium"
            >
              <Settings2 className="h-3 w-3" />
              Edit Geofence
            </Button>
          )}
          <Button
            size="sm"
            variant="outline"
            onClick={onSimulateGeofence}
            className="text-xs h-7 gap-1"
          >
            <Zap className="h-3.5 w-3.5 text-emerald-500" />
            Verify
          </Button>
        </div>
      </div>

      <Card className="border shadow-sm">
        <CardHeader className="p-4 pb-2">
          <CardTitle className="text-sm font-semibold flex items-center justify-between">
            <span>Perimeter Surveillance</span>
            <span className="text-xs font-mono text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded-full">
              ARMED
            </span>
          </CardTitle>
          <CardDescription className="text-xs">
            Active geofencing with {coordinatesCount} radar corner nodes and 2 ingress control gates.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-4 pt-2 space-y-3">
          <div className="flex justify-between items-center py-1.5 border-b text-xs">
            <span className="text-muted-foreground">Geofenced Area</span>
            <span className="font-semibold text-foreground font-mono">
              {areaAcres} Acres ({areaHectares} Ha)
            </span>
          </div>
          <div className="flex justify-between items-center py-1.5 border-b text-xs">
            <span className="text-muted-foreground">Perimeter Fence Length</span>
            <span className="font-semibold text-foreground font-mono">{perimeterMeters} meters</span>
          </div>
          <div className="flex justify-between items-center py-1.5 border-b text-xs">
            <span className="text-muted-foreground">Boundary Radar Beacons</span>
            <span className="font-semibold text-emerald-600 font-mono">{coordinatesCount} Nodes Online</span>
          </div>
          <div className="flex justify-between items-center py-1.5 border-b text-xs">
            <span className="text-muted-foreground">Controlled Ingress Gates</span>
            <span className="font-semibold text-foreground font-mono">2 Gates (Main & North)</span>
          </div>
          <div className="flex justify-between items-center py-1.5 text-xs">
            <span className="text-muted-foreground">Centroid Benchmark</span>
            <button
              type="button"
              onClick={() => onCopyCentroid(center)}
              className="font-mono text-primary hover:underline flex items-center gap-1"
              title="Copy Centroid Coordinates"
            >
              {center[0].toFixed(4)}°, {center[1].toFixed(4)}°
              <Copy className="h-3 w-3" />
            </button>
          </div>
        </CardContent>
      </Card>

      {/* Geofence Rules Box */}
      <div className="p-3.5 rounded-xl bg-muted/40 border text-xs space-y-1.5 text-muted-foreground">
        <div className="flex items-center gap-1.5 font-semibold text-foreground text-xs">
          <Info className="h-4 w-4 text-emerald-500 shrink-0" />
          <span>Geofencing Active Rules</span>
        </div>
        <p>
          • <strong>Click anywhere</strong> on the map to test whether a coordinate is <em>Inside</em> or <em>Outside</em> the geofence perimeter.
        </p>
        <p>
          • <strong>Perimeter Mask</strong> automatically dims exterior terrain to clearly demarcate the residential community boundaries.
        </p>
        <p>
          • <strong>System Admin</strong> can click <em>Set Geofence Coordinates</em> to modify or load boundary presets in real time.
        </p>
      </div>
    </div>
  );
}
