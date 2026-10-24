
import { useState, useEffect } from 'react';
import { useMap } from '@/context/map-context';
import { useAuth } from '@/context/auth-context';
import { useToast } from '@/hooks/use-toast';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { 
  ShieldCheck, 
  Plus, 
  Trash2, 
  RotateCcw, 
  Save, 
  MapPin, 
  Info,
  CheckCircle2,
  AlertTriangle
} from 'lucide-react';
import { 
  DEFAULT_GEOFENCE_COORDS, 
  GEOFENCE_PRESETS, 
  calculatePolygonMetrics 
} from '@/lib/geofence-utils';

interface SetGeofenceDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function SetGeofenceDialog({ open, onOpenChange }: SetGeofenceDialogProps) {
  const { coordinates, setCoordinates } = useMap();
  const { can } = useAuth();
  const { toast } = useToast();

  const [localCoords, setLocalCoords] = useState<[number, number][]>([]);
  const [selectedPreset, setSelectedPreset] = useState<string>('');

  // Sync with current MapContext coordinates when dialog opens
  useEffect(() => {
    if (open) {
      setLocalCoords(coordinates);
      setSelectedPreset('');
    }
  }, [open, coordinates]);

  const [isPublishing, setIsPublishing] = useState(false);

  /*
   * Mirrors the server's `manageBoundary` gate, which covers System Admin *and*
   * Admin. The dialog previously checked `role === 'System Admin'` inline, so an
   * Admin was shown a read-only form even though the server would have accepted
   * their change.
   */
  const isSystemAdmin = can.manageBoundary;

  // Live metrics calculation
  const metrics = calculatePolygonMetrics(localCoords);

  const handleCoordChange = (index: number, field: 'lat' | 'lng', value: string) => {
    const num = parseFloat(value);
    if (!isNaN(num)) {
      const updated = [...localCoords];
      updated[index] = field === 'lat'
        ? [num, updated[index][1]]
        : [updated[index][0], num];
      setLocalCoords(updated);
    }
  };

  const handleAddVertex = () => {
    // The schema stores at most 8 boundary points, so stop before the server does.
    if (localCoords.length >= 8) {
      toast({
        variant: 'destructive',
        title: 'Maximum 8 Points',
        description: 'A boundary supports up to 8 survey points.',
      });
      return;
    }

    const last = localCoords[localCoords.length - 1] || [18.4766, -77.9257];
    // Slightly offset from the last point
    setLocalCoords([...localCoords, [+(last[0] + 0.0005).toFixed(6), +(last[1] + 0.0005).toFixed(6)]]);
  };

  const handleRemoveVertex = (index: number) => {
    // BoundaryController requires 4 to 8 points; points 1-4 are mandatory.
    if (localCoords.length <= 4) {
      toast({
        variant: 'destructive',
        title: 'Minimum 4 Points Required',
        description: 'A published boundary requires at least 4 vertices. Points 5 to 8 are optional.',
      });
      return;
    }
    const updated = localCoords.filter((_, i) => i !== index);
    setLocalCoords(updated);
  };

  const handleApplyPreset = (presetName: string) => {
    setSelectedPreset(presetName);
    const found = GEOFENCE_PRESETS.find(p => p.name === presetName);
    if (found) {
      setLocalCoords(found.coords);
      toast({
        title: 'Preset Loaded',
        description: `Applied ${found.name} (${found.coords.length} vertices).`,
      });
    }
  };

  const handleResetDefault = () => {
    setLocalCoords(DEFAULT_GEOFENCE_COORDS);
    setSelectedPreset(GEOFENCE_PRESETS[0].name);
    toast({
      title: 'Reset to Factory Geofence',
      description: 'Default 4-node Montego Bay coordinates restored.',
    });
  };

  const handleSave = () => {
    if (!isSystemAdmin) {
      toast({
        variant: 'destructive',
        title: 'Permission Denied',
        description: 'Your role cannot configure the official community geofence.',
      });
      return;
    }

    if (localCoords.length < 4 || localCoords.length > 8) {
      toast({
        variant: 'destructive',
        title: 'Invalid Boundary',
        description: 'A boundary needs between 4 and 8 coordinate points.',
      });
      return;
    }

    // Validate coordinate ranges
    const isValid = localCoords.every(
      c => c[0] >= -90 && c[0] <= 90 && c[1] >= -180 && c[1] <= 180
    );

    if (!isValid) {
      toast({
        variant: 'destructive',
        title: 'Coordinate Range Error',
        description: 'Latitudes must be [-90, 90] and Longitudes [-180, 180].',
      });
      return;
    }

    /*
     * Publishing is a server round-trip now, not a localStorage write, so the
     * confirmation has to wait for the response. The previous version toasted
     * "Successfully Updated" synchronously — it could not fail, because it never
     * asked anyone. The server re-validates the polygon and can still refuse it.
     */
    setIsPublishing(true);

    setCoordinates(localCoords, {
      notes: `Published from the dashboard geofence editor (${localCoords.length} vertices).`,
      onSuccess: () => {
        setIsPublishing(false);

        toast({
          title: 'Geofence Published',
          description: `New perimeter of ${metrics.perimeterMeters}m (${metrics.areaAcres} acres) across ${localCoords.length} beacons is now live for every resident.`,
        });

        onOpenChange(false);
      },
      onError: () => {
        setIsPublishing(false);

        toast({
          variant: 'destructive',
          title: 'Geofence Not Published',
          description: 'The server rejected this boundary. Check that it has 4 to 8 points and valid coordinates.',
        });
      },
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <div className="flex items-center gap-2 mb-1">
            <span className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600">
              <ShieldCheck className="h-5 w-5" />
            </span>
            <DialogTitle className="text-xl font-bold">Set Community Geofence Coordinates</DialogTitle>
          </div>
          <DialogDescription className="text-xs">
            System Admin Authority: Define the official georeferenced polygon boundary. All resident dashboards, security gates, and perimeter alerts synchronize to these coordinates.
          </DialogDescription>
        </DialogHeader>

        {/* Role Warning if not System Admin */}
        {!isSystemAdmin && (
          <div className="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-700 dark:text-amber-400 text-xs flex items-center gap-2">
            <AlertTriangle className="h-4 w-4 shrink-0" />
            <span>Read-Only Mode: your role cannot commit geofence modifications.</span>
          </div>
        )}

        {/* Preset Selector */}
        <div className="space-y-1.5 pt-2">
          <Label className="text-xs font-semibold">Load Boundary Preset</Label>
          <Select value={selectedPreset} onValueChange={handleApplyPreset} disabled={!isSystemAdmin}>
            <SelectTrigger className="text-xs h-9">
              <SelectValue placeholder="Select a pre-calibrated boundary preset..." />
            </SelectTrigger>
            <SelectContent>
              {GEOFENCE_PRESETS.map((p) => (
                <SelectItem key={p.name} value={p.name} className="text-xs">
                  {p.name} ({p.coords.length} points)
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Live Metrics Card */}
        <div className="grid grid-cols-3 gap-2 p-3 bg-muted/40 rounded-xl border text-center text-xs">
          <div>
            <span className="text-muted-foreground block text-[10px] uppercase">Enclosed Area</span>
            <strong className="text-foreground text-sm">{metrics.areaAcres} Acres</strong>
          </div>
          <div>
            <span className="text-muted-foreground block text-[10px] uppercase">Perimeter</span>
            <strong className="text-foreground text-sm">{metrics.perimeterMeters} m</strong>
          </div>
          <div>
            <span className="text-muted-foreground block text-[10px] uppercase">Radar Beacons</span>
            <strong className="text-emerald-600 text-sm">{localCoords.length} Nodes</strong>
          </div>
        </div>

        {/* Coordinate Points List */}
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-xs font-semibold flex items-center gap-1.5">
              <MapPin className="h-3.5 w-3.5 text-primary" />
              <span>Boundary Vertices (Latitude, Longitude in Decimal Degrees)</span>
            </Label>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleAddVertex}
              disabled={!isSystemAdmin}
              className="text-xs h-7 gap-1"
            >
              <Plus className="h-3.5 w-3.5" />
              Add Node
            </Button>
          </div>

          <div className="space-y-2 max-h-56 overflow-y-auto pr-1">
            {localCoords.map((coord, idx) => (
              <div 
                key={idx} 
                className="flex items-center gap-2 p-2 rounded-lg bg-card border text-xs shadow-sm hover:border-primary/50 transition-colors"
              >
                <span className="font-mono text-[11px] font-bold text-muted-foreground w-8 text-center bg-muted/60 py-1 rounded">
                  #{idx + 1}
                </span>

                <div className="flex-1 grid grid-cols-2 gap-2">
                  <div className="flex items-center gap-1">
                    <span className="text-[10px] text-muted-foreground font-mono">Lat:</span>
                    <Input
                      type="number"
                      step="0.000001"
                      value={coord[0]}
                      disabled={!isSystemAdmin}
                      onChange={(e) => handleCoordChange(idx, 'lat', e.target.value)}
                      className="h-8 text-xs font-mono"
                    />
                  </div>

                  <div className="flex items-center gap-1">
                    <span className="text-[10px] text-muted-foreground font-mono">Lng:</span>
                    <Input
                      type="number"
                      step="0.000001"
                      value={coord[1]}
                      disabled={!isSystemAdmin}
                      onChange={(e) => handleCoordChange(idx, 'lng', e.target.value)}
                      className="h-8 text-xs font-mono"
                    />
                  </div>
                </div>

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  disabled={!isSystemAdmin || localCoords.length <= 4}
                  onClick={() => handleRemoveVertex(idx)}
                  className="h-8 w-8 text-muted-foreground hover:text-destructive shrink-0"
                  title="Remove this boundary point"
                >
                  <Trash2 className="h-3.5 w-3.5" />
                </Button>
              </div>
            ))}
          </div>
        </div>

        <DialogFooter className="flex-col sm:flex-row gap-2 pt-3 border-t">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={handleResetDefault}
            disabled={!isSystemAdmin}
            className="text-xs h-9 gap-1.5 sm:mr-auto"
          >
            <RotateCcw className="h-3.5 w-3.5" />
            Reset Default
          </Button>

          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onOpenChange(false)}
            className="text-xs h-9"
          >
            Cancel
          </Button>

          <Button
            type="button"
            size="sm"
            onClick={handleSave}
            disabled={!isSystemAdmin || isPublishing}
            className="text-xs h-9 gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium"
          >
            <Save className="h-3.5 w-3.5" />
            {isPublishing ? 'Publishing…' : 'Publish Geofence'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
