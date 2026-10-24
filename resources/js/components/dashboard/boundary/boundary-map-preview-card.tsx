import React from 'react';
import dynamic from '@/lib/dynamic';
import { Card, CardContent, CardDescription, CardHeader, CardTitle, CardFooter } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Compass, CheckCircle2, AlertTriangle, Save } from 'lucide-react';
import type { BoundaryPoint, BoundaryConfig, BoundaryValidationResult } from '@/lib/boundary-manager/types';

const BoundaryPointMap = dynamic(() => import('../boundary-point-map'), {
  ssr: false,
  loading: () => (
    <div className="h-[540px] w-full flex flex-col items-center justify-center bg-muted/20 gap-3 rounded-2xl border">
      <Compass className="h-8 w-8 text-primary animate-spin" />
      <p className="text-sm text-muted-foreground font-medium">Loading Boundary Mapping Engine...</p>
    </div>
  ),
});

export interface BoundaryMapPreviewCardProps {
  validation: BoundaryValidationResult;
  isClickToPlaceActive: boolean;
  canEditBoundary: boolean;
  points: BoundaryPoint[];
  selectedPointIndex: number | null;
  config: BoundaryConfig;
  focusTarget: { lat: number; lng: number; zoom?: number; timestamp: number } | null;
  onPointDrag: (id: number, lat: number, lng: number) => void;
  onSelectPoint: (index: number) => void;
  onMapClick: (lat: number, lng: number) => void;
  onSaveDraft: () => void;
  onPublishBoundary: () => void;
}

export function BoundaryMapPreviewCard({
  validation,
  isClickToPlaceActive,
  canEditBoundary,
  points,
  selectedPointIndex,
  config,
  focusTarget,
  onPointDrag,
  onSelectPoint,
  onMapClick,
  onSaveDraft,
  onPublishBoundary,
}: BoundaryMapPreviewCardProps) {
  return (
    <div className="space-y-6">
      {/* 3. Preview Boundary Card */}
      <Card className="border shadow-md">
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Compass className="h-5 w-5 text-primary" />
              <CardTitle className="text-lg font-bold">Preview Boundary</CardTitle>
            </div>

            {/* Usable Boundary Indicator */}
            <Badge
              variant={validation.canPublish ? 'default' : 'destructive'}
              className="font-mono text-xs flex items-center gap-1"
            >
              {validation.canPublish ? (
                <>
                  <CheckCircle2 className="h-3 w-3" />
                  <span>USABLE BOUNDARY</span>
                </>
              ) : (
                <>
                  <AlertTriangle className="h-3 w-3" />
                  <span>INVALID PERIMETER</span>
                </>
              )}
            </Badge>
          </div>
          <CardDescription className="text-xs">
            {isClickToPlaceActive
              ? `🎯 Click anywhere on the map to set coordinates for ${points[selectedPointIndex || 0]?.label || 'Point'}.`
              : canEditBoundary
              ? 'Connected sequentially. You can drag markers, manually type Lat/Lng, or paste from Google Maps.'
              : 'Interactive preview in read-only mode.'}
          </CardDescription>
        </CardHeader>

        <CardContent className="p-3">
          {/* Boundary Leaflet Map */}
          <BoundaryPointMap
            points={points}
            onPointDrag={onPointDrag}
            selectedPointIndex={selectedPointIndex}
            onSelectPoint={onSelectPoint}
            onMapClick={onMapClick}
            isEditable={canEditBoundary}
            status={config.status}
            validation={validation}
            focusTarget={focusTarget}
          />

          {/* Validation Warnings / Errors Banner */}
          {validation.errors.length > 0 && (
            <div className="mt-3 p-3 rounded-xl bg-destructive/10 border border-destructive/30 text-destructive text-xs space-y-1">
              <div className="font-bold flex items-center gap-1.5">
                <AlertTriangle className="h-4 w-4" />
                <span>Boundary Usability Issues Detected:</span>
              </div>
              {validation.errors.map((err, i) => (
                <div key={i} className="pl-5">• {err}</div>
              ))}
            </div>
          )}

          {/* Enclosed Area Metrics Grid */}
          <div className="grid grid-cols-3 gap-3 mt-3 p-3 bg-muted/40 rounded-xl border text-center text-xs">
            <div>
              <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Enclosed Area</span>
              <strong className="text-foreground text-sm font-mono">{validation.areaAcres} Acres</strong>
              <span className="text-[10px] text-muted-foreground block">({validation.areaHectares} Hectares)</span>
            </div>
            <div>
              <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Perimeter Length</span>
              <strong className="text-foreground text-sm font-mono">{validation.perimeterMeters} m</strong>
              <span className="text-[10px] text-muted-foreground block">Total Fence Line</span>
            </div>
            <div>
              <span className="text-muted-foreground block text-[10px] uppercase font-semibold">Boundary Points</span>
              <strong className="text-emerald-600 text-sm font-mono">{points.length} Vertices</strong>
              <span className="text-[10px] text-muted-foreground block">4–8 Rule Enforced</span>
            </div>
          </div>
        </CardContent>

        {/* 4. Save / Publish Footer */}
        <CardFooter className="pt-2 border-t flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <span className="w-2 h-2 rounded-full bg-emerald-500" />
            <span>
              Last Published: <strong>{config.lastPublishedAt || 'Never'}</strong> by {config.lastPublishedBy || 'None'}
            </span>
          </div>

          <div className="flex items-center gap-2">
            {canEditBoundary && (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={onSaveDraft}
                className="text-xs h-9 gap-1.5"
              >
                <Save className="h-3.5 w-3.5" />
                <span>Save Draft</span>
              </Button>
            )}

            <Button
              type="button"
              variant="default"
              size="sm"
              onClick={onPublishBoundary}
              disabled={!canEditBoundary || !validation.canPublish}
              className="text-xs h-9 gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold shadow-md"
            >
              <CheckCircle2 className="h-3.5 w-3.5" />
              <span>Save & Publish Boundary</span>
            </Button>
          </div>
        </CardFooter>
      </Card>

      {/* 5. Permission Enforcement Matrix Card */}
      <Card className="border shadow-sm">
        <CardHeader className="pb-2">
          <CardTitle className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
            Security & Permission Enforcement Matrix
          </CardTitle>
        </CardHeader>
        <CardContent className="text-xs">
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-center">
            <div className="p-2 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
              <strong className="block text-[11px] text-emerald-700 dark:text-emerald-300">System Admin</strong>
              <span className="text-[10px] text-emerald-600 font-mono">Manual • Drag • Publish (✓)</span>
            </div>
            <div className="p-2 rounded-lg bg-muted/40 border">
              <strong className="block text-[11px] text-foreground">Community Admin</strong>
              <span className="text-[10px] text-muted-foreground font-mono">View Only (✗)</span>
            </div>
            <div className="p-2 rounded-lg bg-muted/40 border">
              <strong className="block text-[11px] text-foreground">Security</strong>
              <span className="text-[10px] text-muted-foreground font-mono">Operational Map (✗)</span>
            </div>
            <div className="p-2 rounded-lg bg-muted/40 border">
              <strong className="block text-[11px] text-foreground">Homeowner</strong>
              <span className="text-[10px] text-muted-foreground font-mono">No Modify (✗)</span>
            </div>
            <div className="p-2 rounded-lg bg-muted/40 border">
              <strong className="block text-[11px] text-foreground">Renter</strong>
              <span className="text-[10px] text-muted-foreground font-mono">No Modify (✗)</span>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
