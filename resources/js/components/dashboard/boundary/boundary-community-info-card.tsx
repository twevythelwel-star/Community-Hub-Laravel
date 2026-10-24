import React from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Building2, Crosshair, MapPin } from 'lucide-react';
import { toDMS } from '@/lib/geofence-utils';
import { BoundaryConfig, BoundaryPoint, CommunityInfo } from '@/lib/boundary-manager/types';

export interface BoundaryCommunityInfoCardProps {
  community: CommunityInfo;
  config: BoundaryConfig;
  points: BoundaryPoint[];
  onFocusPoint: (idx: number) => void;
}

export function BoundaryCommunityInfoCard({
  community,
  config,
  points,
  onFocusPoint,
}: BoundaryCommunityInfoCardProps) {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Building2 className="h-4 w-4 text-primary" />
            <CardTitle className="text-base font-bold">Community Information</CardTitle>
          </div>
          <Badge variant="secondary" className="font-mono text-[10px]">
            Version {config.version} ({config.status})
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="space-y-3 text-xs">
        <div className="grid grid-cols-2 gap-2">
          <div className="p-2.5 rounded-lg bg-muted/40 border">
            <span className="text-[10px] text-muted-foreground uppercase block font-semibold">Community</span>
            <strong className="text-foreground text-xs">{community.name}</strong>
          </div>
          <div className="p-2.5 rounded-lg bg-muted/40 border">
            <span className="text-[10px] text-muted-foreground uppercase block font-semibold">Jurisdiction</span>
            <strong className="text-foreground text-xs">{community.jurisdiction}</strong>
          </div>
        </div>

        <div className="p-2.5 rounded-lg bg-muted/40 border">
          <span className="text-[10px] text-muted-foreground uppercase block font-semibold">Survey Datum</span>
          <span className="text-foreground font-mono font-medium text-xs">{community.datum}</span>
        </div>

        {/* Benchmark Reference Coordinate Highlight */}
        <div className="p-3 rounded-xl bg-indigo-500/10 border border-indigo-500/30 text-indigo-950 dark:text-indigo-100">
          <div className="flex items-center justify-between mb-1.5 flex-wrap gap-1">
            <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300 flex items-center gap-1">
              <MapPin className="h-3 w-3" />
              Point 1 Reference Benchmark
            </span>
            <div className="flex items-center gap-1.5">
              <Badge variant="outline" className="text-[9px] h-4 border-indigo-500/40 text-indigo-700 dark:text-indigo-300">
                {points[0] ? `${toDMS(points[0].lat, true)} ${toDMS(points[0].lng, false)}` : '18°28\'36.7"N 77°55\'33.7"W'}
              </Badge>
              <Button
                type="button"
                size="sm"
                variant="secondary"
                onClick={() => onFocusPoint(0)}
                className="h-5 px-2 text-[10px] gap-1 font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-100/80 dark:bg-indigo-950/80 hover:bg-indigo-200 border border-indigo-300/40"
                title="Shift map view directly to Point 1"
              >
                <Crosshair className="h-2.5 w-2.5" />
                <span>Focus Point 1 on Map</span>
              </Button>
            </div>
          </div>
          <div className="font-mono text-xs font-bold text-foreground flex items-center justify-between">
            <span>
              Latitude: {points[0]?.lat ?? community.referenceCoord.lat} • Longitude: {points[0]?.lng ?? community.referenceCoord.lng}
            </span>
            <Badge variant="secondary" className="text-[9px] font-mono border text-emerald-600 bg-emerald-500/10">
              ✓ Held in Storage
            </Badge>
          </div>
          <p className="text-[11px] text-muted-foreground mt-0.5">
            {community.referenceCoord.description}
          </p>
        </div>
      </CardContent>
    </Card>
  );
}

export function BoundarySecurityMatrixCard() {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-2">
        <CardTitle className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
          Security &amp; Permission Enforcement Matrix
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
  );
}
