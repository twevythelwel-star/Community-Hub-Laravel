import React from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CheckCircle2, AlertTriangle, Copy, Plus } from 'lucide-react';
import { toDMS, estimateElevation } from '@/lib/geofence-utils';

interface GeofencePerimeterInfo {
  isInside: boolean;
  distanceMeters: number;
  nearestGate: {
    name: string;
    distance: number;
  };
}

interface MapInspectorCardProps {
  inspectedCoords: [number, number];
  geofenceInfo: GeofencePerimeterInfo;
  onClose: () => void;
  onCopyCoordinates: (coords: [number, number]) => void;
  onDropPinHere: (coords: [number, number]) => void;
}

export function MapInspectorCard({
  inspectedCoords,
  geofenceInfo,
  onClose,
  onCopyCoordinates,
  onDropPinHere,
}: MapInspectorCardProps) {
  const elevation = estimateElevation(inspectedCoords[0], inspectedCoords[1]);
  const dmsLat = toDMS(inspectedCoords[0], true);
  const dmsLng = toDMS(inspectedCoords[1], false);

  return (
    <div className="absolute bottom-6 left-4 z-[400] max-w-sm bg-card/95 backdrop-blur-md border border-border shadow-xl rounded-2xl p-4 transition-all animate-in fade-in slide-in-from-bottom-2">
      <div className="flex items-center justify-between gap-2 mb-2">
        <div className="flex items-center gap-1.5">
          {geofenceInfo.isInside ? (
            <Badge variant="outline" className="border-emerald-500/40 text-emerald-600 bg-emerald-500/10 text-[10px] font-bold flex items-center gap-1">
              <CheckCircle2 className="h-3 w-3" />
              INSIDE GEOFENCE
            </Badge>
          ) : (
            <Badge variant="outline" className="border-amber-500/40 text-amber-600 bg-amber-500/10 text-[10px] font-bold flex items-center gap-1">
              <AlertTriangle className="h-3 w-3" />
              OUTSIDE GEOFENCE
            </Badge>
          )}
        </div>
        <button
          type="button"
          onClick={onClose}
          className="text-xs text-muted-foreground hover:text-foreground h-5 w-5 rounded-full flex items-center justify-center hover:bg-muted"
          aria-label="Close coordinate inspector"
        >
          ✕
        </button>
      </div>

      <div className="space-y-1 mb-3">
        <p className="font-mono text-sm font-bold text-foreground">
          {inspectedCoords[0].toFixed(6)}°, {inspectedCoords[1].toFixed(6)}°
        </p>
        <p className="font-mono text-xs text-muted-foreground">
          {dmsLat} {dmsLng}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-2 text-xs bg-muted/40 p-2.5 rounded-xl border mb-3">
        <div>
          <span className="text-muted-foreground block text-[10px] uppercase">Terrain Elevation</span>
          <span className="font-semibold text-foreground">~{elevation} m MSL</span>
        </div>
        <div>
          <span className="text-muted-foreground block text-[10px] uppercase">Fence Distance</span>
          <span className={`font-semibold ${geofenceInfo.isInside ? 'text-emerald-600' : 'text-amber-600'}`}>
            {geofenceInfo.distanceMeters}m {geofenceInfo.isInside ? 'inside' : 'beyond'}
          </span>
        </div>
        <div className="col-span-2 pt-1 border-t mt-1">
          <span className="text-muted-foreground block text-[10px] uppercase">Nearest Ingress Portal</span>
          <span className="font-medium text-foreground text-xs">
            {geofenceInfo.nearestGate.name} ({geofenceInfo.nearestGate.distance}m away)
          </span>
        </div>
      </div>

      <div className="flex items-center gap-2">
        <Button
          size="sm"
          variant="outline"
          className="flex-1 h-8 text-xs font-medium gap-1.5"
          onClick={() => onCopyCoordinates(inspectedCoords)}
        >
          <Copy className="h-3.5 w-3.5" />
          Copy
        </Button>
        <Button
          size="sm"
          className="flex-1 h-8 text-xs font-medium gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white"
          onClick={() => onDropPinHere(inspectedCoords)}
        >
          <Plus className="h-3.5 w-3.5" />
          Drop Pin Here
        </Button>
      </div>
    </div>
  );
}
