import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Check, Copy, Shield, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { OccupancySummary } from './occupancy-types';

export interface OccupancyScorecardSectionProps {
  unitFilter: string;
  onClearUnitFilter: () => void;
  currentSummary: OccupancySummary;
  currentExpectedSummary: OccupancySummary;
  activeDisplaySummary: OccupancySummary;
  activeCategory: string;
  onCategoryChange: (cat: string) => void;
  activeViewMode: 'inside' | 'expected' | 'map';
  categoriesList: Array<{ key: string; label: string }>;
  categoryConfig: Record<string, { label: string; icon: any; color: string; badgeClass: string; desc: string }>;
  copiedLedger: boolean;
  onCopyLedger: () => void;
}

export function OccupancyScorecardSection({
  unitFilter,
  onClearUnitFilter,
  currentSummary,
  currentExpectedSummary,
  activeDisplaySummary,
  activeCategory,
  onCategoryChange,
  activeViewMode,
  categoriesList,
  categoryConfig,
  copiedLedger,
  onCopyLedger,
}: OccupancyScorecardSectionProps) {
  return (
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      {/* Main Visual Census Scorecard (2 cols) */}
      <Card className="lg:col-span-2 border-border/80 shadow-md bg-gradient-to-br from-card via-card to-muted/20 overflow-hidden">
        <CardHeader className="pb-3 border-b border-border/40">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <span className="p-1.5 rounded-lg bg-primary/10 text-primary">
                <Users className="h-4 w-4" />
              </span>
              <div>
                <CardTitle className="text-lg font-bold">
                  Access Overview · {unitFilter || 'Community'}
                </CardTitle>
                <CardDescription className="text-xs">
                  Live check-ins are separate from valid credentials expected to enter today.
                </CardDescription>
              </div>
            </div>

            {unitFilter && (
              <Button
                variant="ghost"
                size="sm"
                onClick={onClearUnitFilter}
                className="text-xs text-primary font-medium hover:underline h-7 px-2"
              >
                ← Entire Community View
              </Button>
            )}
          </div>
        </CardHeader>

        <CardContent className="pt-4">
          <div className="grid grid-cols-2 gap-3">
            <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-3">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Currently Inside</p>
              <p className="mt-1 text-2xl font-extrabold font-mono text-foreground">{currentSummary.total}</p>
            </div>
            <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Expected Today</p>
              <p className="mt-1 text-2xl font-extrabold font-mono text-foreground">{currentExpectedSummary.total}</p>
            </div>
          </div>

          <div className="mt-4">
            <div className="grid grid-cols-[minmax(0,1fr)_4.5rem_4.5rem] gap-2 border-b border-border px-2 pb-2 text-[10px] font-bold uppercase tracking-wider text-muted-foreground">
              <span>Category</span>
              <span className="text-right">Inside</span>
              <span className="text-right">Expected</span>
            </div>
            {categoriesList.map((catItem) => {
              const cfg = categoryConfig[catItem.key] || categoryConfig.visitors;
              const IconComp = cfg.icon;
              const isSelected = activeCategory === catItem.key;

              return (
                <button
                  key={catItem.key}
                  type="button"
                  onClick={() => onCategoryChange(catItem.key)}
                  className={cn(
                    'grid w-full grid-cols-[minmax(0,1fr)_4.5rem_4.5rem] items-center gap-2 rounded-md border-b border-border/60 px-2 py-2 text-left transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    isSelected && 'bg-muted/50'
                  )}
                  aria-label={`${catItem.label}: ${currentSummary[catItem.key as keyof OccupancySummary] ?? 0} currently inside, ${currentExpectedSummary[catItem.key as keyof OccupancySummary] ?? 0} expected today. Filter roster by ${catItem.label}.`}
                >
                  <span className="flex min-w-0 items-center gap-2 text-sm font-medium text-foreground">
                    <IconComp className={cn('h-4 w-4 shrink-0', cfg.color.split(' ')[0])} aria-hidden />
                    <span className="truncate">{catItem.label === 'Long-Term Guests / Renters' ? 'Renters' : catItem.label}</span>
                  </span>
                  <span className="text-right font-mono text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                    {currentSummary[catItem.key as keyof OccupancySummary] ?? 0}
                  </span>
                  <span className="text-right font-mono text-sm font-semibold text-amber-700 dark:text-amber-400">
                    {currentExpectedSummary[catItem.key as keyof OccupancySummary] ?? 0}
                  </span>
                </button>
              );
            })}
          </div>
        </CardContent>
      </Card>

      {/* Security Guard / Admin Monospace Tally Ledger Box */}
      <Card className="border border-foreground/20 shadow-md bg-neutral-950 text-neutral-100 font-mono flex flex-col justify-between overflow-hidden">
        <CardHeader className="pb-2 border-b border-neutral-800">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase tracking-wider text-neutral-400 font-bold flex items-center gap-1.5">
              <Shield className="w-3.5 h-3.5 text-emerald-400" /> Command Center Telemetry
            </span>
            <Badge variant="outline" className="text-[10px] text-emerald-400 border-emerald-500/30">
              {activeViewMode === 'expected' ? 'EXPECTED TODAY' : 'CURRENTLY INSIDE'}
            </Badge>
          </div>
          <CardTitle className="text-base text-neutral-100 font-bold mt-1">
            {activeViewMode === 'expected' ? 'Expected Today Community' : 'Currently Inside Community'}
          </CardTitle>
        </CardHeader>

        <CardContent className="py-3 text-xs leading-relaxed space-y-1.5">
          <div className="grid grid-cols-2 text-[11px] font-bold text-neutral-400 pb-1 border-b border-neutral-800 uppercase tracking-wider">
            <span>Category</span>
            <span className="text-right">{activeViewMode === 'expected' ? 'Expected' : 'Inside'}</span>
          </div>

          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Residents</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.residents}</span>
          </div>
          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Long-Term Guests / Renters</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.long_term_guests}</span>
          </div>
          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Short-Term Rental Guests</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.short_term_guests || 0}</span>
          </div>
          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Visitors</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.visitors}</span>
          </div>
          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Staff</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.staff}</span>
          </div>
          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Contractors</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.contractors}</span>
          </div>
          <div className="flex justify-between items-center py-0.5 border-b border-neutral-900">
            <span className="text-neutral-300">Legacy Contacts</span>
            <span className="font-bold text-white text-sm">{activeDisplaySummary.legacy_contacts}</span>
          </div>
          <div className="text-neutral-500 tracking-tighter">───────────────────────────────────</div>
          <div className="flex justify-between items-center pt-1 font-extrabold text-sm text-emerald-400">
            <span className="uppercase tracking-wider">TOTAL</span>
            <span className="text-xl font-black">{activeDisplaySummary.total}</span>
          </div>
        </CardContent>

        <div className="p-3 bg-neutral-900/60 border-t border-neutral-800 flex items-center justify-between text-[11px]">
          <span className="text-neutral-400">Shift Telemetry Log</span>
          <Button
            size="sm"
            variant="outline"
            onClick={onCopyLedger}
            className="h-7 px-2.5 text-[11px] gap-1 bg-neutral-900 border-neutral-700 text-neutral-200 hover:text-white hover:bg-neutral-800"
          >
            {copiedLedger ? <Check className="w-3 h-3 text-emerald-400" /> : <Copy className="w-3 h-3" />}
            <span>{copiedLedger ? 'Copied' : 'Copy Tally'}</span>
          </Button>
        </div>
      </Card>
    </div>
  );
}
