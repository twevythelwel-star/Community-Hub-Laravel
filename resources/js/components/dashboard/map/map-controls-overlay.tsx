import React from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { 
  Mountain, 
  Map as MapIcon, 
  Satellite, 
  Search, 
  Eye, 
  EyeOff, 
  MapPin, 
  Plus, 
  Minus, 
  Crosshair, 
  Lock, 
  Unlock, 
  Minimize2, 
  Maximize2 
} from 'lucide-react';
import type { MapType } from '@/components/dashboard/LeafletMapFixed';

interface MapControlsOverlayProps {
  searchQuery: string;
  onSearchChange: (value: string) => void;
  onSearchSubmit: (e: React.FormEvent) => void;
  onClearSearch: () => void;
  mapType: MapType;
  onSelectMapType: (type: MapType) => void;
  showGeofenceMask: boolean;
  onToggleGeofenceMask: () => void;
  isClickToPlacePinActive: boolean;
  onToggleClickToPlacePin: () => void;
  onOpenAddPin: () => void;
  onZoom: (delta: number) => void;
  onRecenter: () => void;
  isLocked: boolean;
  onToggleLock: () => void;
  isFullscreen: boolean;
  onToggleFullscreen: () => void;
}

export function MapControlsOverlay({
  searchQuery,
  onSearchChange,
  onSearchSubmit,
  onClearSearch,
  mapType,
  onSelectMapType,
  showGeofenceMask,
  onToggleGeofenceMask,
  isClickToPlacePinActive,
  onToggleClickToPlacePin,
  onOpenAddPin,
  onZoom,
  onRecenter,
  isLocked,
  onToggleLock,
  isFullscreen,
  onToggleFullscreen,
}: MapControlsOverlayProps) {
  return (
    <>
      {/* Top Floating Navigation Overlay */}
      <div className="absolute top-4 left-4 right-4 z-[400] flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 pointer-events-none">
        {/* Search Input Box */}
        <form
          onSubmit={onSearchSubmit}
          className="pointer-events-auto flex items-center bg-card/95 backdrop-blur-md border border-border shadow-md rounded-full px-3 py-1.5 w-full md:w-96 transition-all focus-within:ring-2 focus-within:ring-primary focus-within:shadow-lg"
        >
          <Search className="h-4 w-4 text-muted-foreground ml-1 mr-2 shrink-0" />
          <Input
            type="text"
            placeholder="Search coordinates or landmark..."
            value={searchQuery}
            onChange={(e) => onSearchChange(e.target.value)}
            className="border-0 shadow-none focus-visible:ring-0 text-xs h-8 bg-transparent p-0"
          />
          {searchQuery && (
            <button
              type="button"
              onClick={onClearSearch}
              className="text-xs text-muted-foreground hover:text-foreground px-2"
              aria-label="Clear search"
            >
              ✕
            </button>
          )}
          <Button type="submit" size="sm" variant="ghost" className="h-7 px-3 text-xs rounded-full font-medium">
            Go
          </Button>
        </form>

        {/* Map Layer Switcher & Pin Controls (Top-Right) */}
        <div className="pointer-events-auto flex items-center bg-card/95 backdrop-blur-md border border-border shadow-md rounded-full p-1 gap-1 self-end md:self-auto flex-wrap">
          <button
            type="button"
            onClick={() => onSelectMapType('terrain')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all ${
              mapType === 'terrain'
                ? 'bg-primary text-primary-foreground shadow-sm'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
            }`}
          >
            <Mountain className="h-3.5 w-3.5" />
            <span>Terrain</span>
          </button>

          <button
            type="button"
            onClick={() => onSelectMapType('roadmap')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all ${
              mapType === 'roadmap'
                ? 'bg-primary text-primary-foreground shadow-sm'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
            }`}
          >
            <MapIcon className="h-3.5 w-3.5" />
            <span>Streets</span>
          </button>

          <button
            type="button"
            onClick={() => onSelectMapType('satellite')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all ${
              mapType === 'satellite'
                ? 'bg-primary text-primary-foreground shadow-sm'
                : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
            }`}
          >
            <Satellite className="h-3.5 w-3.5" />
            <span>Satellite</span>
          </button>

          {/* Geofence Mask Toggle Button */}
          <button
            type="button"
            onClick={onToggleGeofenceMask}
            className={`flex items-center gap-1 px-2.5 py-1.5 rounded-full text-xs font-medium transition-all border-l pl-2 ${
              showGeofenceMask
                ? 'text-emerald-600 bg-emerald-500/10 font-bold'
                : 'text-muted-foreground hover:text-foreground'
            }`}
            title="Toggle Geofence External Mask"
          >
            {showGeofenceMask ? <Eye className="h-3.5 w-3.5" /> : <EyeOff className="h-3.5 w-3.5" />}
            <span className="hidden sm:inline">Perimeter Mask</span>
          </button>

          {/* Drop Pin / Click-to-Place Mode Toggle */}
          <button
            type="button"
            onClick={onToggleClickToPlacePin}
            className={`flex items-center gap-1 px-2.5 py-1.5 rounded-full text-xs font-medium transition-all border-l pl-2 ${
              isClickToPlacePinActive
                ? 'bg-amber-500 text-white font-bold shadow-sm animate-pulse'
                : 'text-muted-foreground hover:text-foreground'
            }`}
            title="Click on map to drop pin"
          >
            <MapPin className="h-3.5 w-3.5" />
            <span className="hidden sm:inline">{isClickToPlacePinActive ? 'Click Map...' : 'Drop Pin'}</span>
          </button>

          {/* Quick Add Pin Dialog Trigger */}
          <button
            type="button"
            onClick={onOpenAddPin}
            className="flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition-all"
            title="Add Security Gate, Community Center, or Park Pin"
          >
            <Plus className="h-3.5 w-3.5" />
            <span>Add Pin</span>
          </button>
        </div>
      </div>

      {/* Floating Active Placement Banner */}
      {isClickToPlacePinActive && (
        <div className="absolute top-20 left-1/2 -translate-x-1/2 z-[401] bg-amber-500 text-white px-4 py-2 rounded-full shadow-xl text-xs font-semibold flex items-center gap-2 animate-in fade-in slide-in-from-top-3 pointer-events-auto border-2 border-white/40">
          <MapPin className="h-4 w-4 animate-bounce" />
          <span>Click anywhere on the map to place a Landmark Pin (Green / Red / Blue)</span>
          <button
            type="button"
            onClick={onToggleClickToPlacePin}
            className="ml-2 bg-black/20 hover:bg-black/40 rounded-full w-5 h-5 flex items-center justify-center text-xs font-bold"
            title="Cancel Pin Placement"
          >
            ✕
          </button>
        </div>
      )}

      {/* Custom Bottom-Right Controls (Zoom, Recenter, Lock, Fullscreen) */}
      <div className="absolute bottom-6 right-4 z-[400] flex flex-col items-center gap-2">
        <div className="bg-card/95 backdrop-blur-md border border-border shadow-lg rounded-xl overflow-hidden flex flex-col">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onZoom(1)}
            className="h-9 w-9 rounded-none border-b hover:bg-muted text-foreground"
            aria-label="Zoom In"
          >
            <Plus className="h-4 w-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onZoom(-1)}
            className="h-9 w-9 rounded-none hover:bg-muted text-foreground"
            aria-label="Zoom Out"
          >
            <Minus className="h-4 w-4" />
          </Button>
        </div>

        <Button
          variant="outline"
          size="icon"
          onClick={onRecenter}
          className="h-9 w-9 rounded-xl bg-card/95 backdrop-blur-md shadow-lg hover:bg-muted text-foreground"
          title="Re-center on Community Centroid"
          aria-label="Re-center map"
        >
          <Crosshair className="h-4 w-4 text-primary" />
        </Button>

        <Button
          variant="outline"
          size="icon"
          onClick={onToggleLock}
          className={`h-9 w-9 rounded-xl bg-card/95 backdrop-blur-md shadow-lg transition-colors ${
            isLocked ? 'text-emerald-600 border-emerald-500/40 bg-emerald-500/5' : 'text-amber-500'
          }`}
          title={isLocked ? "Unlock pan/zoom constraint" : "Lock strictly inside community geofence"}
          aria-label="Toggle boundary lock"
        >
          {isLocked ? <Lock className="h-4 w-4" /> : <Unlock className="h-4 w-4" />}
        </Button>

        <Button
          variant="outline"
          size="icon"
          onClick={onToggleFullscreen}
          className="h-9 w-9 rounded-xl bg-card/95 backdrop-blur-md shadow-lg hover:bg-muted text-foreground"
          title="Toggle Fullscreen"
          aria-label="Toggle fullscreen"
        >
          {isFullscreen ? <Minimize2 className="h-4 w-4" /> : <Maximize2 className="h-4 w-4" />}
        </Button>
      </div>
    </>
  );
}
