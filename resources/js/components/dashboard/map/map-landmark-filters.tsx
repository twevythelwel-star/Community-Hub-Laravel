import React from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { 
  Building2, 
  Calendar, 
  Plus, 
  Navigation, 
  Trash2, 
  Sparkles 
} from 'lucide-react';
import type { CommunityLandmark, LandmarkCategory } from '@/lib/geofence-utils';

interface MapLandmarkFiltersProps {
  filteredLandmarks: CommunityLandmark[];
  categoryCounts: {
    all: number;
    'Security Gate': number;
    'Community Center': number;
    'Park': number;
  };
  pinFilterCategory: 'all' | LandmarkCategory;
  onSelectCategory: (category: 'all' | LandmarkCategory) => void;
  onReserveFacility: (amenityName?: string) => void;
  onOpenAddPin: () => void;
  onJumpToLandmark: (coords: [number, number]) => void;
  onRemoveLandmark: (id: string | number, name: string) => void;
}

export function MapLandmarkFilters({
  filteredLandmarks,
  categoryCounts,
  pinFilterCategory,
  onSelectCategory,
  onReserveFacility,
  onOpenAddPin,
  onJumpToLandmark,
  onRemoveLandmark,
}: MapLandmarkFiltersProps) {
  return (
    <div className="md:col-span-2 space-y-3">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
        <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
          <Building2 className="h-4 w-4 text-primary" />
          Community Pins & Infrastructure
        </h2>
        <span className="text-xs text-muted-foreground">Click card to pan map • Manage pins</span>
      </div>

      {/* Category Filter Tabs Bar */}
      <div className="flex items-center gap-1.5 overflow-x-auto pb-1 flex-wrap">
        <Button
          size="sm"
          variant={pinFilterCategory === 'all' ? 'default' : 'outline'}
          onClick={() => onSelectCategory('all')}
          className="h-7 text-xs rounded-full px-3 font-medium"
        >
          All Pins ({categoryCounts.all})
        </Button>
        <Button
          size="sm"
          variant={pinFilterCategory === 'Security Gate' ? 'default' : 'outline'}
          onClick={() => onSelectCategory('Security Gate')}
          className={`h-7 text-xs rounded-full px-3 font-medium gap-1.5 ${
            pinFilterCategory === 'Security Gate'
              ? 'bg-emerald-600 hover:bg-emerald-700 text-white font-semibold'
              : 'text-emerald-700 dark:text-emerald-400 border-emerald-500/30'
          }`}
        >
          <span className="w-2 h-2 rounded-full bg-emerald-500 shrink-0" />
          Security Gates ({categoryCounts['Security Gate']})
        </Button>
        <Button
          size="sm"
          variant={pinFilterCategory === 'Community Center' ? 'default' : 'outline'}
          onClick={() => onSelectCategory('Community Center')}
          className={`h-7 text-xs rounded-full px-3 font-medium gap-1.5 ${
            pinFilterCategory === 'Community Center'
              ? 'bg-rose-600 hover:bg-rose-700 text-white font-semibold'
              : 'text-rose-700 dark:text-rose-400 border-rose-500/30'
          }`}
        >
          <span className="w-2 h-2 rounded-full bg-rose-500 shrink-0" />
          Community Centers ({categoryCounts['Community Center']})
        </Button>
        <Button
          size="sm"
          variant={pinFilterCategory === 'Park' ? 'default' : 'outline'}
          onClick={() => onSelectCategory('Park')}
          className={`h-7 text-xs rounded-full px-3 font-medium gap-1.5 ${
            pinFilterCategory === 'Park'
              ? 'bg-blue-600 hover:bg-blue-700 text-white font-semibold'
              : 'text-blue-700 dark:text-blue-400 border-blue-500/30'
          }`}
        >
          <span className="w-2 h-2 rounded-full bg-blue-500 shrink-0" />
          Parks ({categoryCounts['Park']})
        </Button>

        <div className="ml-auto flex items-center gap-1.5 pl-2">
          <Button
            size="sm"
            variant="outline"
            onClick={() => onReserveFacility()}
            className="h-7 text-xs border-emerald-500/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 gap-1 px-2.5 rounded-lg"
          >
            <Calendar className="h-3.5 w-3.5 text-emerald-600" />
            <span>Reserve Facility</span>
          </Button>
          <Button
            size="sm"
            onClick={onOpenAddPin}
            className="h-7 text-xs bg-emerald-600 hover:bg-emerald-700 text-white gap-1 px-3 rounded-lg"
          >
            <Plus className="h-3.5 w-3.5" />
            <span>Add Pin</span>
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {filteredLandmarks.map((lm) => {
          const isSecurity = lm.category === 'Security Gate';
          const isCommunityCenter = lm.category === 'Community Center';
          const badgeStyle = isSecurity
            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30'
            : isCommunityCenter
            ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/30'
            : 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/30';

          return (
            <div
              key={lm.id}
              onClick={() => onJumpToLandmark(lm.coordinates)}
              className="p-3.5 rounded-xl border bg-card hover:bg-muted/40 transition-all cursor-pointer shadow-sm hover:shadow group flex flex-col justify-between relative"
            >
              <div>
                <div className="flex items-start justify-between gap-2 mb-2">
                  <div className="flex items-center gap-2">
                    <span
                      className="w-3.5 h-3.5 rounded-full shrink-0 shadow-sm border border-white/60 flex items-center justify-center text-[8px]"
                      style={{ backgroundColor: lm.color }}
                    />
                    <h3 className="font-semibold text-sm text-foreground group-hover:text-primary transition-colors">
                      {lm.name}
                    </h3>
                  </div>
                  <Badge variant="outline" className={`text-[10px] shrink-0 font-medium ${badgeStyle}`}>
                    {lm.category}
                  </Badge>
                </div>

                <p className="text-xs text-muted-foreground line-clamp-2 mb-3">
                  {lm.description}
                </p>
              </div>

              <div className="flex items-center justify-between pt-2 border-t text-[11px] font-mono text-muted-foreground">
                <span className="flex items-center gap-1.5">
                  <span className="font-medium text-foreground">{lm.elevation}m MSL</span>
                  <span>•</span>
                  <span>{lm.coordinates[0].toFixed(4)}°, {lm.coordinates[1].toFixed(4)}°</span>
                </span>

                <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                  {!isSecurity && (
                    <button
                      type="button"
                      onClick={() => onReserveFacility(lm.name)}
                      className="text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1 hover:underline text-xs"
                      title={`Reserve ${lm.name}`}
                    >
                      <Sparkles className="h-3 w-3" />
                      Reserve
                    </button>
                  )}

                  <button
                    type="button"
                    onClick={() => onJumpToLandmark(lm.coordinates)}
                    className="text-primary font-medium flex items-center gap-1 hover:underline text-xs"
                  >
                    Jump <Navigation className="h-3 w-3" />
                  </button>

                  <button
                    type="button"
                    onClick={() => onRemoveLandmark(lm.id, lm.name)}
                    className="text-muted-foreground hover:text-destructive p-1 rounded hover:bg-destructive/10 transition-colors"
                    title="Delete pin"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
          );
        })}

        {/* Quick Add Pin Card */}
        <div
          onClick={onOpenAddPin}
          className="p-3.5 rounded-xl border border-dashed border-emerald-500/40 bg-emerald-500/5 hover:bg-emerald-500/10 transition-all cursor-pointer flex flex-col items-center justify-center text-center gap-2 group min-h-[130px]"
        >
          <div className="w-8 h-8 rounded-full bg-emerald-500/15 flex items-center justify-center text-emerald-600 group-hover:scale-110 transition-transform">
            <Plus className="h-4 w-4" />
          </div>
          <div>
            <h3 className="font-semibold text-xs text-foreground">Add Custom Pin</h3>
            <p className="text-[11px] text-muted-foreground">🟢 Security Gate • 🔴 Community Center • 🔵 Park</p>
          </div>
        </div>
      </div>
    </div>
  );
}
