import React from 'react';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Building, Check, ChevronRight, Copy, Map as MapIcon, Network } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface OccupancyMapTreeViewProps {
  propertyList: any[];
  selectedTreeUnit: string;
  onSelectTreeUnit: (unit: string) => void;
  asciiTree: string;
  copiedTree: boolean;
  onCopyTree: () => void;
  unitFilter: string;
  onOpenUnitModal: (unit: string) => void;
  onFilterCensus: (unit: string) => void;
}

export function OccupancyMapTreeView({
  propertyList,
  selectedTreeUnit,
  onSelectTreeUnit,
  asciiTree,
  copiedTree,
  onCopyTree,
  unitFilter,
  onOpenUnitModal,
  onFilterCensus,
}: OccupancyMapTreeViewProps) {
  return (
    <Card className="border shadow-md overflow-hidden bg-gradient-to-b from-card via-card to-muted/10">
      <CardHeader className="border-b bg-muted/20">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="flex items-center gap-2.5">
            <div className="p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
              <Network className="w-5 h-5" />
            </div>
            <div>
              <CardTitle className="text-lg font-bold">
                Community Map &amp; Property Hierarchy Explorer
              </CardTitle>
              <CardDescription className="text-xs">
                Spatial organizational tree mapping persons to specific properties, with live on-site and expected occupancy.
              </CardDescription>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Link href="/dashboard/map">
              <Button size="sm" variant="default" className="h-8 text-xs gap-1.5 font-semibold">
                <MapIcon className="w-3.5 h-3.5" />
                <span>Open Interactive GIS Map</span>
              </Button>
            </Link>
          </div>
        </div>
      </CardHeader>

      <CardContent className="p-6 space-y-6">
        {/* Interactive Property Selection & Topology Bar */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3 bg-muted/40 rounded-xl border">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs font-bold text-foreground flex items-center gap-1.5">
              <MapIcon className="w-4 h-4 text-primary" />
              <span>Select Property:</span>
            </span>

            {/* Quick Select Buttons: the first two real units */}
            {propertyList.slice(0, 2).map((p: any) => (
              <Button
                key={p.id ?? p.unit}
                size="sm"
                variant={selectedTreeUnit.toLowerCase() === String(p.unit).toLowerCase() ? 'default' : 'outline'}
                onClick={() => onSelectTreeUnit(p.unit)}
                className="h-7 px-3 text-xs font-mono font-bold rounded-lg"
              >
                {String(p.unit).toUpperCase()}
              </Button>
            ))}

            {/* Dropdown for All Other Community Units */}
            <select
              value={selectedTreeUnit}
              onChange={(e) => onSelectTreeUnit(e.target.value)}
              className="h-7 text-xs font-mono font-medium rounded-lg bg-background border px-2 py-0 text-foreground focus:ring-1 focus:ring-primary"
            >
              {propertyList.length === 0 && <option value="">No units</option>}
              {propertyList.map((p: any) => (
                <option key={p.id ?? p.unit} value={p.unit}>
                  {p.unit} ({p.insideCount ?? 0} inside)
                </option>
              ))}
            </select>
          </div>

          <div className="flex items-center gap-2">
            <Badge variant="outline" className="text-[11px] font-mono bg-emerald-500/10 text-emerald-600 border-emerald-500/30">
              {propertyList.length} {propertyList.length === 1 ? 'property' : 'properties'}
            </Badge>
          </div>
        </div>

        {/* Community Map: a text tree of the selected unit, from real data only */}
        <div className="rounded-xl border bg-neutral-950 text-neutral-100">
          <div className="flex items-center justify-between px-4 py-2 border-b border-neutral-800">
            <span className="text-[11px] font-mono uppercase tracking-wider text-neutral-400">Community Map</span>
            <Button
              size="sm"
              variant="ghost"
              className="h-7 px-2 text-[11px] text-neutral-300 hover:text-white gap-1"
              onClick={onCopyTree}
            >
              {copiedTree ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
              <span>{copiedTree ? 'Copied' : 'Copy'}</span>
            </Button>
          </div>
          <pre className="p-4 text-xs font-mono leading-relaxed overflow-x-auto">{asciiTree}</pre>
        </div>

        {/* Property Grid Selection */}
        <div>
          <h3 className="text-sm font-bold text-foreground mb-3 flex items-center justify-between">
            <span>Authorized Community Parcels</span>
            <span className="text-xs text-muted-foreground font-normal">
              Select any property to inspect occupants or drill into specific access records
            </span>
          </h3>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {propertyList.length === 0 && (
              <p className="text-xs text-muted-foreground col-span-full">No occupancy data is available right now.</p>
            )}
            {propertyList.map((prop: any) => (
              <Card
                key={prop.id}
                className={cn(
                  'p-4 border transition-all hover:border-primary/50 hover:shadow-md cursor-pointer',
                  unitFilter === prop.unit ? 'border-primary ring-1 ring-primary bg-primary/5' : 'bg-card'
                )}
                onClick={() => onOpenUnitModal(prop.unit)}
              >
                <div className="flex items-center justify-between pb-2 border-b">
                  <div>
                    <div className="font-extrabold text-base text-foreground flex items-center gap-1.5">
                      <Building className="w-4 h-4 text-primary" />
                      <span>{prop.unit}</span>
                    </div>
                    <div className="text-xs text-muted-foreground">{prop.address}</div>
                  </div>

                  <div className="text-right">
                    <Badge className="bg-emerald-500/10 text-emerald-600 border border-emerald-500/30 text-xs font-bold">
                      {prop.insideCount} Inside
                    </Badge>
                    <div className="text-[10px] text-muted-foreground font-mono mt-0.5">
                      {prop.expectedCount} Expected
                    </div>
                  </div>
                </div>

                {/* Property Ownership & Occupancy Summary */}
                <div className="py-2 border-b text-xs space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground font-medium">Ownership:</span>
                    <span className="font-bold text-foreground truncate max-w-[170px]" title={prop.owner}>
                      {prop.owner || 'Unassigned'}
                    </span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground font-medium">Type:</span>
                    <Badge variant="outline" className="text-[10px] font-semibold py-0 px-1.5 border-indigo-500/30 text-indigo-600 dark:text-indigo-400 bg-indigo-500/10">
                      {prop.ownershipType || 'Individual'}
                    </Badge>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground font-medium">Occupancy:</span>
                    <span className="font-medium text-foreground truncate max-w-[170px]" title={prop.primaryOccupant || 'Not Specified'}>
                      {prop.primaryOccupant || 'Not Specified'}
                    </span>
                  </div>
                  <div className="flex items-center justify-between">
                    <span className="text-[11px] text-muted-foreground">Status:</span>
                    <Badge
                      variant="secondary"
                      className={cn(
                        'text-[10px] font-semibold py-0 px-1.5',
                        prop.isOwnerOccupied
                          ? 'bg-emerald-500/10 text-emerald-600 border border-emerald-500/30'
                          : 'bg-amber-500/10 text-amber-600 border border-amber-500/30'
                      )}
                    >
                      {prop.isOwnerOccupied ? 'Owner-Occupied' : (prop.isCorporate ? 'Entity-Owned' : 'Tenant / Non-Owner Occupied')}
                    </Badge>
                  </div>
                  {prop.representatives && prop.representatives.length > 0 && (
                    <div className="pt-1 border-t border-border/30">
                      <span className="text-[11px] font-semibold text-muted-foreground block mb-1">
                        Owner Representatives ({prop.representatives.length}):
                      </span>
                      <div className="space-y-0.5">
                        {prop.representatives.map((rep: any) => (
                          <div key={rep.id} className="text-[11px] text-muted-foreground truncate">
                            <span className="font-medium text-foreground">{rep.name}</span>
                            <span className="text-muted-foreground/80"> — {rep.role}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>

                {/* Category Breakdown Tree List */}
                <div className="py-2.5 space-y-1 font-mono text-xs">
                  {(prop.breakdown ?? []).map((item: any, idx: number) => (
                    <div key={idx} className="flex items-center justify-between text-muted-foreground">
                      <span className="flex items-center gap-1.5">
                        <span className="text-neutral-400">├─</span>
                        <span>{item.inside} {item.label}</span>
                      </span>
                      {item.expected > item.inside && (
                        <span className="text-[10px] text-amber-500 font-semibold">
                          +{item.expected - item.inside} exp
                        </span>
                      )}
                    </div>
                  ))}
                </div>

                <div className="pt-2 border-t flex items-center justify-between">
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={(e) => {
                      e.stopPropagation();
                      onFilterCensus(prop.unit);
                    }}
                    className="h-7 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                  >
                    Filter Census
                  </Button>

                  <Button
                    size="sm"
                    variant="default"
                    onClick={(e) => {
                      e.stopPropagation();
                      onOpenUnitModal(prop.unit);
                    }}
                    className="h-7 px-2.5 text-[11px] font-bold gap-1"
                  >
                    <span>Who&apos;s inside?</span>
                    <ChevronRight className="w-3 h-3" />
                  </Button>
                </div>
              </Card>
            ))}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
