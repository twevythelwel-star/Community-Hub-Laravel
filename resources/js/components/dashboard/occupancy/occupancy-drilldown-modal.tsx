import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RefreshCw, ShieldCheck, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Occupant } from './occupancy-types';

interface OccupancyDrilldownModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  drillDownData: any | null;
  drillDownUnit: string;
  drillDownLoading: boolean;
  drillDownError: string | null;
  onSelectPerson: (person: Occupant) => void;
  onFilterMainRegistry: (unit: string) => void;
}

const CATEGORIES_LIST = [
  { key: 'residents', label: 'Residents' },
  { key: 'long_term_guests', label: 'Long-Term Guests / Renters' },
  { key: 'short_term_guests', label: 'Short-Term Rental Guests' },
  { key: 'visitors', label: 'Visitors' },
  { key: 'staff', label: 'Staff' },
  { key: 'contractors', label: 'Contractors' },
  { key: 'legacy_contacts', label: 'Legacy Contacts' },
];

export function OccupancyDrilldownModal({
  open,
  onOpenChange,
  drillDownData,
  drillDownUnit,
  drillDownLoading,
  drillDownError,
  onSelectPerson,
  onFilterMainRegistry,
}: OccupancyDrilldownModalProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[620px] max-h-[90vh] overflow-y-auto">
        <DialogHeader className="border-b pb-3">
          <div className="flex items-center justify-between">
            <div>
              <DialogTitle className="text-xl font-black font-mono tracking-wide text-foreground">
                {drillDownData?.unit || drillDownUnit}
              </DialogTitle>
              <DialogDescription className="text-xs">
                Detailed occupant roster and access status for this property address.
              </DialogDescription>
            </div>

            <div className="flex items-center gap-2">
              <Badge className="bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 font-mono font-bold text-xs">
                INSIDE: {drillDownData?.totalCurrentlyInside ?? drillDownData?.total ?? '—'}
              </Badge>
              <Badge variant="outline" className="text-muted-foreground font-mono font-bold text-xs">
                EXPECTED: {drillDownData?.totalExpectedToday ?? '—'}
              </Badge>
            </div>
          </div>
        </DialogHeader>

        {drillDownError && !drillDownLoading ? (
          <div role="alert" className="py-10 text-center text-sm text-destructive">
            {drillDownError}
          </div>
        ) : drillDownLoading ? (
          <div className="py-12 flex flex-col items-center justify-center gap-2 text-muted-foreground">
            <RefreshCw className="w-6 h-6 animate-spin text-primary" />
            <span className="text-xs font-mono">Querying estate telemetry for {drillDownUnit}...</span>
          </div>
        ) : (
          <div className="space-y-4 py-2">
            {/* Monospace Terminal View Matching Specification */}
            <div className="font-mono text-xs border rounded-xl p-4 bg-neutral-950 text-neutral-100 space-y-3">
              <div className="space-y-1">
                <div className="text-white font-bold text-sm tracking-wider">
                  {(drillDownData?.unit || drillDownUnit || '—').toUpperCase()}
                </div>
                <div className="text-neutral-600 font-mono text-xs select-none">
                  ────────────────────────────
                </div>
              </div>

              {/* Property Legal Owner & Authorized Representatives Card */}
              {drillDownData?.ownership && (
                <div className="p-3.5 rounded-lg bg-neutral-900 border border-neutral-800 font-sans space-y-3">
                  <div className="flex items-center justify-between pb-2 border-b border-neutral-800">
                    <div>
                      <span className="text-[10px] font-mono uppercase tracking-wider text-neutral-400">Architecture</span>
                      <h4 className="text-xs font-bold text-white uppercase tracking-wide">
                        Ownership &amp; Occupancy Decoupled
                      </h4>
                    </div>
                    <Badge
                      variant="outline"
                      className={cn(
                        "text-[10px] font-semibold py-0.5 px-2",
                        drillDownData.ownership.isOwnerOccupied
                          ? "border-emerald-500/40 text-emerald-400 bg-emerald-500/10"
                          : "border-indigo-500/40 text-indigo-400 bg-indigo-500/10"
                      )}
                    >
                      {drillDownData.ownership.isOwnerOccupied
                        ? "Owner-Occupied"
                        : (drillDownData.ownership.isCorporate ? "Corporate Owned" : "Tenant / Entity Occupied")}
                    </Badge>
                  </div>

                  {/* Decoupled 7-Branch Architecture Tree */}
                  <div className="font-mono text-xs space-y-2 text-neutral-300">
                    <div>
                      <span className="text-indigo-400 font-bold">PROPERTY</span>
                      <span className="text-neutral-400"> ({drillDownData.unit})</span>
                    </div>

                    <div className="pl-2 border-l border-neutral-700 space-y-1">
                      {/* Branch 1: OWNERSHIP */}
                      <div className="flex items-center gap-1.5">
                        <span className="text-neutral-500">├──</span>
                        <span className="text-purple-400 font-semibold">OWNERSHIP</span>
                      </div>
                      <div className="pl-6 space-y-0.5 text-[11px]">
                        <div className="text-white font-medium flex items-center gap-2">
                          <span>└── {drillDownData.ownership.owner}</span>
                          <span className="text-[10px] px-1.5 py-0.2 rounded bg-neutral-800 text-neutral-400">
                            {drillDownData.ownership.ownershipType}
                          </span>
                        </div>
                        {drillDownData.ownership.representatives && drillDownData.ownership.representatives.length > 0 && (
                          <div className="pl-4 pt-1 text-[10px] text-neutral-400 space-y-0.5">
                            <div className="text-neutral-400 uppercase tracking-wider font-semibold">Authorized Representatives:</div>
                            {drillDownData.ownership.representatives.map((rep: any) => (
                              <div key={rep.id} className="text-neutral-300 flex items-center gap-1.5">
                                <span className="text-neutral-600">•</span>
                                <span className="text-white">{rep.name}</span>
                                <span className="text-neutral-400">({rep.role})</span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>

                      {/* Branch 2: OCCUPANCY */}
                      <div className="flex items-center gap-1.5 pt-1">
                        <span className="text-neutral-500">├──</span>
                        <span className="text-emerald-400 font-semibold">OCCUPANCY</span>
                      </div>
                      <div className="pl-6 text-[11px] text-white">
                        <span>└── {drillDownData.ownership.primaryOccupant || 'Vacant / Unspecified'}</span>
                        <span className="text-neutral-400 text-[10px] ml-2">
                          {drillDownData.ownership.isOwnerOccupied ? '(Owner-Occupied)' : '(Primary Resident)'}
                        </span>
                      </div>

                      {/* Branch 3: LONG-TERM RENTAL */}
                      <div className="flex items-center gap-1.5 pt-1">
                        <span className="text-neutral-500">├──</span>
                        <span className="text-cyan-400 font-semibold">LONG-TERM RENTAL</span>
                      </div>
                      <div className="pl-6 text-[11px] text-neutral-300">
                        {drillDownData.propertyEcosystem?.long_term_rental && drillDownData.propertyEcosystem.long_term_rental.length > 0 ? (
                          drillDownData.propertyEcosystem.long_term_rental.map((r: any, idx: number) => (
                            <div key={idx} className="text-white">
                              └── {r.name} {r.type ? `(${r.type})` : ''}
                            </div>
                          ))
                        ) : (
                          <div className="text-neutral-400">└── None registered</div>
                        )}
                      </div>

                      {/* Branch 4: SHORT-TERM RENTAL */}
                      <div className="flex items-center gap-1.5 pt-1">
                        <span className="text-neutral-500">├──</span>
                        <span className="text-amber-400 font-semibold">SHORT-TERM RENTAL</span>
                      </div>
                      <div className="pl-6 text-[11px] text-neutral-300">
                        {drillDownData.propertyEcosystem?.short_term_rental && drillDownData.propertyEcosystem.short_term_rental.length > 0 ? (
                          drillDownData.propertyEcosystem.short_term_rental.map((r: any, idx: number) => (
                            <div key={idx} className="text-white">
                              └── {r.name} {r.type ? `(${r.type})` : ''}
                            </div>
                          ))
                        ) : (
                          <div className="text-neutral-400">└── None registered</div>
                        )}
                      </div>

                      {/* Branch 5: STAFF */}
                      <div className="flex items-center gap-1.5 pt-1">
                        <span className="text-neutral-500">├──</span>
                        <span className="text-blue-400 font-semibold">STAFF</span>
                      </div>
                      <div className="pl-6 text-[11px] text-neutral-300">
                        {drillDownData.propertyEcosystem?.staff && drillDownData.propertyEcosystem.staff.length > 0 ? (
                          drillDownData.propertyEcosystem.staff.map((s: any, idx: number) => (
                            <div key={idx} className="text-white">
                              └── {s.name} {s.role ? `(${s.role})` : ''}
                            </div>
                          ))
                        ) : (
                          <div className="text-neutral-400">└── None registered</div>
                        )}
                      </div>

                      {/* Branch 6: CONTRACTORS */}
                      <div className="flex items-center gap-1.5 pt-1">
                        <span className="text-neutral-500">├──</span>
                        <span className="text-orange-400 font-semibold">CONTRACTORS</span>
                      </div>
                      <div className="pl-6 text-[11px] text-neutral-300">
                        {drillDownData.propertyEcosystem?.contractors && drillDownData.propertyEcosystem.contractors.length > 0 ? (
                          drillDownData.propertyEcosystem.contractors.map((c: any, idx: number) => (
                            <div key={idx} className="text-white">
                              └── {c.name} {c.trade ? `(${c.trade})` : ''}
                            </div>
                          ))
                        ) : (
                          <div className="text-neutral-400">└── None registered</div>
                        )}
                      </div>

                      {/* Branch 7: LEGACY CONTACTS */}
                      <div className="flex items-center gap-1.5 pt-1">
                        <span className="text-neutral-500">└──</span>
                        <span className="text-rose-400 font-semibold">LEGACY CONTACTS</span>
                      </div>
                      <div className="pl-6 text-[11px] text-neutral-300">
                        {drillDownData.propertyEcosystem?.legacy_contacts && drillDownData.propertyEcosystem.legacy_contacts.length > 0 ? (
                          drillDownData.propertyEcosystem.legacy_contacts.map((l: any, idx: number) => (
                            <div key={idx} className="text-white">
                              └── {l.name} {l.relationship ? `(${l.relationship})` : ''}
                            </div>
                          ))
                        ) : (
                          <div className="text-neutral-400">└── None registered</div>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              )}

              {/* Security Directive: Ownership ≠ Presence */}
              {drillDownData?.ownership && (
                <div className="p-3 rounded-lg bg-emerald-950/40 border border-emerald-800/50 text-xs space-y-1 font-sans">
                  <div className="flex items-center gap-2 text-emerald-400 font-bold tracking-wide uppercase text-[11px]">
                    <ShieldCheck className="w-4 h-4 text-emerald-400" />
                    <span>Security Directive: Ownership ≠ Presence</span>
                  </div>
                  <p className="text-neutral-300 text-[11px] leading-relaxed">
                    <strong className="text-white">{drillDownData.ownership.owner}</strong> is the registered titleholder, not a physical human inside the property. Real-time presence below reflects living individuals holding active gate credentials currently verified on-site.
                  </p>
                </div>
              )}

              {/* 7 Categories */}
              {CATEGORIES_LIST.map((cat) => {
                const rosterItems: any[] = drillDownData?.rosterByCategory?.[cat.key] || [];
                const displayLabel = cat.key === 'long_term_guests'
                  ? 'Long-Term Guests'
                  : cat.key === 'short_term_guests'
                  ? 'Short-Term Rental'
                  : cat.label;

                return (
                  <div key={cat.key} className="space-y-1">
                    <div className="text-[12px] font-bold text-neutral-300">
                      {displayLabel}
                    </div>

                    {rosterItems.length === 0 ? (
                      <div className="text-neutral-500 pl-4 py-0.5">None</div>
                    ) : (
                      <div className="space-y-1 pl-4">
                        {rosterItems.map((person, pIdx) => (
                          <div
                            key={pIdx}
                            onClick={() => onSelectPerson(person)}
                            className="flex items-center justify-between p-1 rounded-md hover:bg-neutral-900 cursor-pointer transition-colors group"
                            title="Click to inspect underlying access details"
                          >
                            <div className="flex items-center gap-2">
                              <span className="text-neutral-200 group-hover:text-white group-hover:underline">
                                {person.name}
                              </span>
                              {person.role && person.role !== person.name && (
                                <span className="text-[10px] text-neutral-500">({person.role})</span>
                              )}
                            </div>
                            <div className="flex items-center gap-2">
                              <span
                                className={cn(
                                  "text-xs font-bold font-mono",
                                  person.status === 'IN'
                                    ? "text-emerald-400"
                                    : person.status === 'OUT'
                                    ? "text-neutral-500"
                                    : "text-amber-400"
                                )}
                              >
                                {person.status}
                              </span>
                              <ChevronRight className="w-3 h-3 text-neutral-600 group-hover:text-neutral-300" />
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                );
              })}

              <div className="pt-2 space-y-1">
                <div className="text-neutral-600 font-mono text-xs select-none">
                  ────────────────────────────
                </div>
                <div className="flex items-center justify-between font-bold text-xs pt-1">
                  <span className="text-emerald-400 uppercase tracking-wider">
                    TOTAL CURRENTLY INSIDE:
                  </span>
                  <span className="text-emerald-400 text-sm font-black">
                    {drillDownData?.totalCurrentlyInside ?? drillDownData?.total ?? '—'}
                  </span>
                </div>
              </div>
            </div>

            <p className="text-[11px] text-muted-foreground italic text-center">
              Click any individual above to inspect their underlying access relationship, QR validity, and boundary permissions.
            </p>
          </div>
        )}

        <DialogFooter className="border-t pt-3 flex items-center justify-between">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
          >
            Close Drill-down
          </Button>

          <Button
            type="button"
            size="sm"
            onClick={() => {
              onOpenChange(false);
              onFilterMainRegistry(drillDownData?.unit || drillDownUnit);
            }}
            className="gap-1.5 font-bold"
          >
            <span>Filter Main Registry to {drillDownData?.unit || drillDownUnit}</span>
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
