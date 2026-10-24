import React from 'react';
import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmergencyBroadcastDialog } from '@/components/dashboard/emergency-broadcast-dialog';
import {
  Calendar,
  FileSpreadsheet,
  Map as MapIcon,
  Network,
  Radio,
  RefreshCw,
  Siren,
  Sparkles,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { ActiveMusterData, OccupancySummary } from './occupancy-types';

export interface OccupancyHeaderProps {
  activeMuster?: ActiveMusterData | null;
  canManage: boolean;
  isRefreshing: boolean;
  onRefresh: () => void;
  onOpenStartMuster: () => void;
  activeViewMode: 'inside' | 'expected' | 'map';
  onViewModeChange: (mode: 'inside' | 'expected' | 'map') => void;
  currentSummary: OccupancySummary;
  currentExpectedSummary: OccupancySummary;
  unitFilter: string;
  userUnit?: string | null;
  onOpenUnitModal: (unit: string) => void;
}

export function OccupancyHeader({
  activeMuster,
  canManage,
  isRefreshing,
  onRefresh,
  onOpenStartMuster,
  activeViewMode,
  onViewModeChange,
  currentSummary,
  currentExpectedSummary,
  unitFilter,
  userUnit,
  onOpenUnitModal,
}: OccupancyHeaderProps) {
  return (
    <>
      {/* 1. TOP HEADER & TELEMETRY STATUS */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="font-headline text-3xl font-extrabold tracking-tight">
              Community Access Command Center
            </h1>
            <span className="relative flex h-3 w-3">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex rounded-full h-3 w-3 bg-emerald-500" />
            </span>
            {activeMuster && (
              <Badge variant="destructive" className="animate-pulse gap-1 font-bold text-xs uppercase tracking-wider">
                <Siren className="w-3.5 h-3.5" /> Emergency Muster Active
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground text-sm mt-1">
            Real-time estate census, gate access telemetry, property drill-down, and emergency evacuation muster.
          </p>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          <Button
            variant="outline"
            size="sm"
            onClick={onRefresh}
            disabled={isRefreshing}
            className="gap-1.5 h-9 text-xs"
          >
            <RefreshCw className={cn('h-3.5 w-3.5', isRefreshing && 'animate-spin')} />
            <span>{isRefreshing ? 'Refreshing...' : 'Live Ingress Feed'}</span>
          </Button>

          {/* Quick Link to GIS Map */}
          <Link href="/dashboard/map">
            <Button size="sm" variant="outline" className="gap-1.5 h-9 text-xs">
              <MapIcon className="h-3.5 w-3.5 text-blue-500" />
              <span>Cadastral Map</span>
            </Button>
          </Link>

          {/* Emergency Broadcast Trigger */}
          {canManage && (
            <EmergencyBroadcastDialog
              community="Community Hub"
              triggerButton={
                <Button size="sm" variant="outline" className="gap-1.5 h-9 text-xs border-rose-500/40 text-rose-600 dark:text-rose-400 hover:bg-rose-500/10">
                  <Radio className="h-3.5 w-3.5" />
                  <span>Emergency Alert</span>
                </Button>
              }
            />
          )}

          {/* Emergency Muster Activation Trigger */}
          {canManage && !activeMuster && (
            <Button
              size="sm"
              onClick={onOpenStartMuster}
              className="gap-1.5 h-9 text-xs bg-rose-600 hover:bg-rose-700 text-white font-bold shadow-md shadow-rose-600/20"
            >
              <Siren className="h-3.5 w-3.5" />
              <span>Initiate Emergency Muster</span>
            </Button>
          )}

          {/* Evacuation Roster Export */}
          <a href="/dashboard/occupancy/export" download>
            <Button size="sm" variant="default" className="gap-1.5 h-9 text-xs font-semibold shadow-xs">
              <FileSpreadsheet className="h-3.5 w-3.5" />
              <span>Export Evacuation Roster</span>
            </Button>
          </a>
        </div>
      </div>

      {/* 2. MODE NAVIGATION: Currently Inside vs Expected Today vs Map */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3 p-2 bg-muted/30 border rounded-2xl">
        <div className="flex items-center gap-1.5 w-full sm:w-auto">
          <Button
            size="sm"
            variant={activeViewMode === 'inside' ? 'default' : 'ghost'}
            onClick={() => onViewModeChange('inside')}
            className={cn(
              'h-9 px-4 text-xs font-bold rounded-xl gap-2',
              activeViewMode === 'inside' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground'
            )}
          >
            <span className="relative flex h-2 w-2">
              <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
            </span>
            <span>Currently Inside Community</span>
            <Badge variant="secondary" className="px-1.5 py-0 text-[10px] font-mono ml-1">
              {currentSummary.total}
            </Badge>
          </Button>

          <Button
            size="sm"
            variant={activeViewMode === 'expected' ? 'default' : 'ghost'}
            onClick={() => onViewModeChange('expected')}
            className={cn(
              'h-9 px-4 text-xs font-bold rounded-xl gap-2',
              activeViewMode === 'expected' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground'
            )}
          >
            <Calendar className="w-3.5 h-3.5 text-amber-500" />
            <span>Expected Today</span>
            <Badge variant="secondary" className="px-1.5 py-0 text-[10px] font-mono ml-1">
              {currentExpectedSummary.total}
            </Badge>
          </Button>

          <Button
            size="sm"
            variant={activeViewMode === 'map' ? 'default' : 'ghost'}
            onClick={() => onViewModeChange('map')}
            className={cn(
              'h-9 px-4 text-xs font-bold rounded-xl gap-2',
              activeViewMode === 'map' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground'
            )}
          >
            <Network className="w-3.5 h-3.5 text-blue-500" />
            <span>Community Map &amp; Property Tree</span>
          </Button>
        </div>

        {/* Quick Spotlight */}
        {(unitFilter || userUnit) && (
          <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
            <Button
              size="sm"
              variant="outline"
              onClick={() => onOpenUnitModal((unitFilter || userUnit) as string)}
              className="h-8 px-3 text-xs font-bold gap-1.5 rounded-xl border-primary/40 bg-primary/5 hover:bg-primary/10 text-primary"
            >
              <Sparkles className="w-3.5 h-3.5" />
              <span>Who&apos;s inside {unitFilter || userUnit}?</span>
            </Button>
          </div>
        )}
      </div>
    </>
  );
}
