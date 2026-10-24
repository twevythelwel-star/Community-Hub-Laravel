import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  ArrowDown,
  ArrowUp,
  Check,
  Crosshair,
  Landmark,
  MapPin,
  MousePointerClick,
  Plus,
  Trash2,
} from 'lucide-react';
import { toDMS } from '@/lib/geofence-utils';
import { BoundaryPoint } from '@/lib/boundary-manager/types';

export interface BoundaryPointsListProps {
  canEditBoundary: boolean;
  points: BoundaryPoint[];
  validation: any;
  selectedPointIndex: number | null;
  onSelectPointIndex: (idx: number) => void;
  isClickToPlaceActive: boolean;
  onToggleClickToPlace: () => void;
  onLoadPreset: (presetType: 'montego' | 'quad' | 'octagon') => void;
  onInsertReferenceCoord: () => void;
  onFocusPoint: (idx: number) => void;
  directPasteInput: string;
  onDirectPasteInputChange: (val: string) => void;
  onApplyDirectPaste: () => void;
  directLatInput: string;
  onDirectLatInputChange: (val: string) => void;
  directLngInput: string;
  onDirectLngInputChange: (val: string) => void;
  onApplyDirectManual: () => void;
  inputBuffer: Record<number, { lat: string; lng: string }>;
  onLatChange: (index: number, val: string) => void;
  onLngChange: (index: number, val: string) => void;
  onCommitPoint: (index: number) => void;
  onMovePoint: (index: number, direction: 'up' | 'down') => void;
  onRemovePoint: (index: number) => void;
  onAddPoint: () => void;
  quickPasteInputs: Record<number, string>;
  onQuickPasteInputChange: (index: number, val: string) => void;
  onQuickPaste: (index: number, pasteStr: string) => void;
}

export function BoundaryPointsList({
  canEditBoundary,
  points,
  validation,
  selectedPointIndex,
  onSelectPointIndex,
  isClickToPlaceActive,
  onToggleClickToPlace,
  onLoadPreset,
  onInsertReferenceCoord,
  onFocusPoint,
  directPasteInput,
  onDirectPasteInputChange,
  onApplyDirectPaste,
  directLatInput,
  onDirectLatInputChange,
  directLngInput,
  onDirectLngInputChange,
  onApplyDirectManual,
  inputBuffer,
  onLatChange,
  onLngChange,
  onCommitPoint,
  onMovePoint,
  onRemovePoint,
  onAddPoint,
  quickPasteInputs,
  onQuickPasteInputChange,
  onQuickPaste,
}: BoundaryPointsListProps) {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div>
            <CardTitle className="text-base font-bold flex items-center gap-2">
              <MapPin className="h-4 w-4 text-emerald-600" />
              <span>Boundary Mapping</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Enter coordinates manually, paste from Google Maps, or drag pins on terrain.
            </CardDescription>
          </div>

          {/* 4-8 Points Rule Status Badge */}
          <Badge 
            variant={validation.pointCount >= 4 && validation.pointCount <= 8 ? 'default' : 'destructive'} 
            className="font-mono text-xs"
          >
            {validation.pointCount} of 8 Points
          </Badge>
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Template Preset Buttons & Manual Tools */}
        {canEditBoundary && (
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label className="text-[11px] font-semibold text-muted-foreground">Boundary Presets</Label>
              <button
                type="button"
                onClick={onToggleClickToPlace}
                className={`text-[11px] font-semibold flex items-center gap-1 px-2 py-0.5 rounded transition-colors ${
                  isClickToPlaceActive 
                    ? 'bg-emerald-500 text-white shadow-sm' 
                    : 'text-primary hover:underline'
                }`}
              >
                <MousePointerClick className="h-3 w-3" />
                <span>{isClickToPlaceActive ? 'Click-to-Place Active' : 'Enable Click-to-Place'}</span>
              </button>
            </div>

            <div className="grid grid-cols-3 gap-1.5">
              <Button 
                type="button" 
                variant="outline" 
                size="sm" 
                onClick={() => onLoadPreset('montego')} 
                className="text-[11px] h-8 px-2"
              >
                Standard (6 Pts)
              </Button>
              <Button 
                type="button" 
                variant="outline" 
                size="sm" 
                onClick={() => onLoadPreset('quad')} 
                className="text-[11px] h-8 px-2"
              >
                Min Quad (4 Pts)
              </Button>
              <Button 
                type="button" 
                variant="outline" 
                size="sm" 
                onClick={() => onLoadPreset('octagon')} 
                className="text-[11px] h-8 px-2"
              >
                Octagon (8 Pts)
              </Button>
            </div>
          </div>
        )}

        {/* ── Direct Point Coordinate Updater Widget ── */}
        {canEditBoundary && (
          <div className="p-3.5 rounded-xl bg-primary/5 border-2 border-primary/30 space-y-3">
            <div className="flex items-center justify-between flex-wrap gap-2">
              <div className="flex items-center gap-2">
                <span className="p-1.5 rounded-lg bg-primary text-primary-foreground">
                  <MapPin className="h-4 w-4" />
                </span>
                <div>
                  <h4 className="font-bold text-xs text-foreground flex items-center gap-1.5">
                    Direct Coordinate Updater
                    <Badge variant="outline" className="text-[9px] h-4 font-mono border-primary/40 text-primary">
                      Target: {points[selectedPointIndex ?? 0]?.label}
                    </Badge>
                  </h4>
                  <p className="text-[10px] text-muted-foreground">Select a boundary point, then paste Google Maps coordinates or type exact values</p>
                </div>
              </div>

              <div className="flex items-center gap-1.5">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={onInsertReferenceCoord}
                  className="h-7 text-xs gap-1 font-semibold text-indigo-600 dark:text-indigo-400 border-indigo-500/30 hover:bg-indigo-50 dark:hover:bg-indigo-950/40"
                  title="Insert Supplied Benchmark Coordinate (18.4766, -77.9257)"
                >
                  <Landmark className="h-3.5 w-3.5" />
                  <span>Insert Supplied Benchmark</span>
                </Button>

                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => onFocusPoint(selectedPointIndex ?? 0)}
                  className="h-7 text-xs gap-1 font-semibold text-primary border-primary/30 hover:bg-primary/10"
                  title="Center map on currently selected point"
                >
                  <Crosshair className="h-3.5 w-3.5" />
                  <span>Focus Point {(selectedPointIndex ?? 0) + 1} on Map</span>
                </Button>
              </div>
            </div>

            {/* Point Selectors */}
            <div>
              <div className="text-[10px] text-muted-foreground font-semibold uppercase mb-1">Select Point to Update:</div>
              <div className="flex items-center gap-1.5 flex-wrap">
                {points.map((pt, i) => (
                  <Button
                    key={pt.id}
                    type="button"
                    size="sm"
                    variant={selectedPointIndex === i ? 'default' : 'outline'}
                    onClick={() => onSelectPointIndex(i)}
                    className="h-7 px-2.5 text-xs font-mono"
                  >
                    Point {i + 1}
                  </Button>
                ))}
              </div>
            </div>

            {/* Single-string paste from Google Maps */}
            <div className="space-y-1">
              <Label className="text-[11px] font-semibold text-foreground flex items-center justify-between">
                <span>Paste from Google Maps (Coordinates or URL)</span>
                <span className="text-[10px] font-mono text-muted-foreground font-normal">e.g. 18.44976, -77.9144841</span>
              </Label>
              <div className="flex gap-1.5">
                <Input
                  type="text"
                  value={directPasteInput}
                  onChange={(e) => onDirectPasteInputChange(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      onApplyDirectPaste();
                    }
                  }}
                  placeholder="Paste e.g. 18.44976, -77.9144841 or @18.44976,-77.9144841"
                  className="h-8 text-xs font-mono bg-background"
                />
                <Button
                  type="button"
                  size="sm"
                  onClick={onApplyDirectPaste}
                  className="h-8 px-3 text-xs font-semibold gap-1 shrink-0 bg-primary"
                >
                  <Check className="h-3.5 w-3.5" />
                  <span>Update Point {(selectedPointIndex ?? 0) + 1}</span>
                </Button>
              </div>
            </div>

            {/* Separate Lat and Lng inputs */}
            <div className="pt-2 border-t border-border/60">
              <div className="text-[10px] text-muted-foreground font-semibold uppercase mb-1">Or enter Latitude & Longitude manually:</div>
              <div className="grid grid-cols-1 sm:grid-cols-12 gap-2 items-end">
                <div className="sm:col-span-5">
                  <Label className="text-[10px] font-mono text-muted-foreground">Latitude (°N)</Label>
                  <Input
                    type="text"
                    inputMode="decimal"
                    value={directLatInput}
                    onChange={(e) => onDirectLatInputChange(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        onApplyDirectManual();
                      }
                    }}
                    placeholder="e.g. 18.449760"
                    className="h-8 text-xs font-mono bg-background"
                  />
                </div>
                <div className="sm:col-span-5">
                  <Label className="text-[10px] font-mono text-muted-foreground">Longitude (°W)</Label>
                  <Input
                    type="text"
                    inputMode="decimal"
                    value={directLngInput}
                    onChange={(e) => onDirectLngInputChange(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        onApplyDirectManual();
                      }
                    }}
                    placeholder="e.g. -77.914484"
                    className="h-8 text-xs font-mono bg-background"
                  />
                </div>
                <div className="sm:col-span-2">
                  <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    onClick={onApplyDirectManual}
                    className="w-full h-8 text-xs font-semibold"
                  >
                    Apply
                  </Button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Point Cards List (1 to 8) with Direct Manual Inputs & Paste Bars */}
        <div className="space-y-3 max-h-[440px] overflow-y-auto pr-1">
          {points.map((pt, idx) => {
            const isPointOne = idx === 0;
            const isRequired = idx < 4;
            const isSelected = selectedPointIndex === idx;
            const currentLatInput = inputBuffer[idx]?.lat ?? pt.lat.toString();
            const currentLngInput = inputBuffer[idx]?.lng ?? pt.lng.toString();

            return (
              <div
                key={pt.id}
                onClick={() => onSelectPointIndex(idx)}
                className={`p-3 rounded-xl border transition-all cursor-pointer ${
                  isSelected 
                    ? 'border-primary shadow-sm bg-primary/5 ring-1 ring-primary' 
                    : 'border-border bg-card hover:border-muted-foreground/30'
                }`}
              >
                <div className="flex items-center justify-between mb-2">
                  <div className="flex items-center gap-2">
                    <span className={`w-6 h-6 rounded-full flex items-center justify-center font-bold text-xs text-white ${
                      isPointOne 
                        ? 'bg-indigo-600' 
                        : isSelected 
                        ? 'bg-primary' 
                        : 'bg-emerald-600'
                    }`}>
                      {idx + 1}
                    </span>
                    <span className="font-semibold text-xs text-foreground">
                      {pt.label} {isPointOne ? '(Supplied Benchmark)' : ''}
                    </span>
                  </div>

                  <div className="flex items-center gap-1.5">
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={(e) => {
                        e.stopPropagation();
                        onFocusPoint(idx);
                      }}
                      className="h-5 px-1.5 text-[10px] gap-1 text-primary hover:bg-primary/10 font-medium"
                      title={`Shift map view to focus on Point ${idx + 1}`}
                    >
                      <Crosshair className="h-3 w-3" />
                      <span>Focus</span>
                    </Button>

                    {canEditBoundary && (
                      <div className="flex items-center gap-0.5 border rounded-md p-0.5 bg-background">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          disabled={idx === 0}
                          onClick={(e) => {
                            e.stopPropagation();
                            onMovePoint(idx, 'up');
                          }}
                          className="h-5 w-5 text-muted-foreground hover:text-foreground disabled:opacity-30"
                          title={`Move Point ${idx + 1} Up`}
                        >
                          <ArrowUp className="h-3 w-3" />
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          disabled={idx === points.length - 1}
                          onClick={(e) => {
                            e.stopPropagation();
                            onMovePoint(idx, 'down');
                          }}
                          className="h-5 w-5 text-muted-foreground hover:text-foreground disabled:opacity-30"
                          title={`Move Point ${idx + 1} Down`}
                        >
                          <ArrowDown className="h-3 w-3" />
                        </Button>
                      </div>
                    )}

                    <Badge 
                      variant={isRequired ? 'default' : 'secondary'} 
                      className="text-[10px] h-5 font-mono"
                    >
                      {isRequired ? 'Required' : 'Optional'}
                    </Badge>

                    {/* Delete button (only for optional points 5..8, or when points > 4) */}
                    {canEditBoundary && points.length > 4 && idx >= 4 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={(e) => {
                          e.stopPropagation();
                          onRemovePoint(idx);
                        }}
                        className="h-6 w-6 text-destructive hover:bg-destructive/10"
                        title="Remove Point"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    )}
                  </div>
                </div>

                {/* Manual Coordinate Input Fields */}
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <Label className="text-[10px] text-muted-foreground font-mono">Latitude (°N)</Label>
                    <Input
                      type="text"
                      inputMode="decimal"
                      value={currentLatInput}
                      disabled={!canEditBoundary}
                      onChange={(e) => onLatChange(idx, e.target.value)}
                      onBlur={() => onCommitPoint(idx)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          e.preventDefault();
                          onCommitPoint(idx);
                        }
                      }}
                      placeholder="e.g. 18.476861"
                      className="h-8 text-xs font-mono bg-background"
                    />
                  </div>
                  <div>
                    <Label className="text-[10px] text-muted-foreground font-mono">Longitude (°W)</Label>
                    <Input
                      type="text"
                      inputMode="decimal"
                      value={currentLngInput}
                      disabled={!canEditBoundary}
                      onChange={(e) => onLngChange(idx, e.target.value)}
                      onBlur={() => onCommitPoint(idx)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          e.preventDefault();
                          onCommitPoint(idx);
                        }
                      }}
                      placeholder="e.g. -77.926028"
                      className="h-8 text-xs font-mono bg-background"
                    />
                  </div>
                </div>

                {/* Apply Coordinate button for this point */}
                {canEditBoundary && (
                  <div className="mt-2 flex items-center justify-between">
                    <span className="text-[10px] text-muted-foreground">Press Enter or click Apply to save coordinates</span>
                    <Button
                      type="button"
                      size="sm"
                      variant="secondary"
                      onClick={(e) => {
                        e.stopPropagation();
                        onCommitPoint(idx);
                      }}
                      className="h-6 px-2.5 text-[10px] font-semibold"
                    >
                      Apply Point {idx + 1}
                    </Button>
                  </div>
                )}

                {/* Direct Google Maps Paste Box for this point */}
                {canEditBoundary && (
                  <div className="mt-2 pt-2 border-t flex items-center gap-1">
                    <div className="relative flex-1">
                      <Input
                        type="text"
                        placeholder={`Paste "18.476861, -77.926028" or DMS...`}
                        value={quickPasteInputs[idx] || ''}
                        onChange={(e) => onQuickPasteInputChange(idx, e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') {
                            e.preventDefault();
                            onQuickPaste(idx, quickPasteInputs[idx] || '');
                          }
                        }}
                        className="h-7 text-[10px] font-mono pr-12 pl-2 bg-muted/30"
                      />
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onQuickPaste(idx, quickPasteInputs[idx] || '')}
                        disabled={!quickPasteInputs[idx]?.trim()}
                        className="absolute right-0.5 top-0.5 h-6 px-2 text-[10px] font-semibold text-primary"
                      >
                        Set
                      </Button>
                    </div>
                  </div>
                )}

                {/* DMS Readout & Map Draggable notice */}
                <div className="mt-1.5 flex items-center justify-between text-[10px] text-muted-foreground font-mono">
                  <span>DMS: {toDMS(pt.lat, true)} {toDMS(pt.lng, false)}</span>
                  {canEditBoundary && (
                    <span className="text-emerald-600 font-sans">
                      {isSelected && isClickToPlaceActive ? '🎯 Click map to place' : '⇄ Draggable on map'}
                    </span>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* Add Point Button (Active if points < 8) */}
        {canEditBoundary && (
          <Button
            type="button"
            variant="outline"
            onClick={onAddPoint}
            disabled={points.length >= 8}
            className="w-full h-9 text-xs gap-1.5 font-semibold border-dashed hover:border-primary"
          >
            <Plus className="h-4 w-4" />
            <span>
              {points.length < 8 
                ? `Add Point ${points.length + 1} (Up to 8 Points)` 
                : 'Maximum 8 Points Limit Reached'}
            </span>
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
