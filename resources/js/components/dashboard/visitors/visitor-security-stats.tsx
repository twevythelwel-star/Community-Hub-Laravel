import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Camera, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { VisitorsPageProps } from './visitor-types';

type VisitorSecurityStatsProps = {
  canManage: boolean;
  currentTab: string;
  tabCounts?: VisitorsPageProps['tabCounts'];
  securityStats?: VisitorsPageProps['securityStats'];
  onTabChange: (tab: string) => void;
  onOpenScanner: () => void;
};

export function VisitorSecurityStats({
  canManage,
  currentTab,
  tabCounts,
  securityStats,
  onTabChange,
  onOpenScanner,
}: VisitorSecurityStatsProps) {
  if (!canManage) return null;

  return (
    <>
      {/* ════════════════════════════════════════════════════════════════
          SECURITY DASHBOARD: TOP LIVE COUNTERS
         ════════════════════════════════════════════════════════════════ */}
      {(tabCounts || securityStats) && (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
          {/* Counter 1: VISITORS TODAY */}
          <div className="p-4 sm:p-5 rounded-2xl border bg-card text-left shadow-xs">
            <span className="text-[11px] font-mono uppercase tracking-wider text-muted-foreground font-bold block">
              Visitors Today
            </span>
            <span className="text-3xl sm:text-4xl font-black text-foreground mt-1 block">
              {securityStats?.visitorsToday ?? tabCounts?.history ?? 0}
            </span>
            <span className="text-[10px] text-muted-foreground mt-1 block">Scheduled or active</span>
          </div>

          {/* Counter 2: EXPECTED */}
          <button
            type="button"
            onClick={() => onTabChange('expected')}
            className={cn(
              'p-4 sm:p-5 rounded-2xl border text-left transition-all hover:scale-[1.01] shadow-xs cursor-pointer',
              currentTab === 'expected'
                ? 'bg-amber-500/10 border-amber-500/40 ring-2 ring-amber-500/20'
                : 'bg-card hover:bg-muted/40'
            )}
          >
            <span className="text-[11px] font-mono uppercase tracking-wider text-muted-foreground font-bold block">
              Expected
            </span>
            <span className="text-3xl sm:text-4xl font-black text-amber-600 dark:text-amber-400 mt-1 block">
              {securityStats?.expected ?? tabCounts?.expected ?? 0}
            </span>
            <span className="text-[10px] text-muted-foreground mt-1 block">Scheduled clearances</span>
          </button>

          {/* Counter 3: CURRENTLY INSIDE */}
          <button
            type="button"
            onClick={() => onTabChange('inside')}
            className={cn(
              'p-4 sm:p-5 rounded-2xl border text-left transition-all hover:scale-[1.01] shadow-xs cursor-pointer',
              currentTab === 'inside'
                ? 'bg-emerald-500/10 border-emerald-500/40 ring-2 ring-emerald-500/20'
                : 'bg-card hover:bg-muted/40'
            )}
          >
            <span className="text-[11px] font-mono uppercase tracking-wider text-muted-foreground font-bold block">
              Currently Inside
            </span>
            <span className="text-3xl sm:text-4xl font-black text-emerald-600 dark:text-emerald-400 mt-1 block">
              {securityStats?.currentlyInside ?? tabCounts?.inside ?? 0}
            </span>
            <span className="text-[10px] text-muted-foreground mt-1 block">Currently on-site</span>
          </button>

          {/* Counter 4: CHECKED OUT */}
          <button
            type="button"
            onClick={() => onTabChange('checked-out')}
            className={cn(
              'p-4 sm:p-5 rounded-2xl border text-left transition-all hover:scale-[1.01] shadow-xs cursor-pointer',
              currentTab === 'checked-out'
                ? 'bg-blue-500/10 border-blue-500/40 ring-2 ring-blue-500/20'
                : 'bg-card hover:bg-muted/40'
            )}
          >
            <span className="text-[11px] font-mono uppercase tracking-wider text-muted-foreground font-bold block">
              Checked Out
            </span>
            <span className="text-3xl sm:text-4xl font-black text-blue-600 dark:text-blue-400 mt-1 block">
              {securityStats?.checkedOut ?? tabCounts?.checkedOut ?? 0}
            </span>
            <span className="text-[10px] text-muted-foreground mt-1 block">Departed estate</span>
          </button>

          {/* Counter 5: REJECTED */}
          <button
            type="button"
            onClick={() => onTabChange('rejected')}
            className={cn(
              'p-4 sm:p-5 rounded-2xl border text-left transition-all hover:scale-[1.01] shadow-xs cursor-pointer',
              currentTab === 'rejected'
                ? 'bg-rose-500/10 border-rose-500/40 ring-2 ring-rose-500/20'
                : 'bg-card hover:bg-muted/40'
            )}
          >
            <span className="text-[11px] font-mono uppercase tracking-wider text-muted-foreground font-bold block">
              Rejected
            </span>
            <span className="text-3xl sm:text-4xl font-black text-rose-600 dark:text-rose-400 mt-1 block">
              {securityStats?.rejected ?? tabCounts?.rejected ?? 0}
            </span>
            <span className="text-[10px] text-muted-foreground mt-1 block">Denied / Lapsed</span>
          </button>

          {/* Counter 6: SUSPICIOUS ATTEMPTS */}
          <div className="p-4 sm:p-5 rounded-2xl border bg-card text-left shadow-xs border-amber-500/30">
            <span className="text-[11px] font-mono uppercase tracking-wider text-amber-600 dark:text-amber-400 font-bold block flex items-center justify-between">
              <span>Suspicious</span>
              <ShieldAlert className="w-3.5 h-3.5" />
            </span>
            <span className="text-3xl sm:text-4xl font-black text-amber-600 dark:text-amber-400 mt-1 block">
              {securityStats?.suspiciousAttempts ?? 0}
            </span>
            <span className="text-[10px] text-muted-foreground mt-1 block">Replay / Tamper alerts</span>
          </div>
        </div>
      )}

      {/* ════════════════════════════════════════════════════════════════
          SECURITY VISITORS SUB-NAVIGATION TABS
         ════════════════════════════════════════════════════════════════ */}
      <div className="flex flex-wrap items-center gap-1.5 border-b pb-2 pt-1">
        <Button
          type="button"
          variant={currentTab === 'scan' ? 'default' : 'outline'}
          size="sm"
          onClick={onOpenScanner}
          className="rounded-xl font-bold gap-1.5 h-9 bg-emerald-600 hover:bg-emerald-700 text-white"
        >
          <Camera className="w-4 h-4" />
          <span>SCAN QR</span>
        </Button>

        <Button
          type="button"
          variant={currentTab === 'expected' ? 'default' : 'ghost'}
          size="sm"
          onClick={() => onTabChange('expected')}
          className="rounded-xl font-semibold gap-1.5 h-9"
        >
          <span>EXPECTED</span>
          {tabCounts && (
            <Badge variant={currentTab === 'expected' ? 'secondary' : 'outline'} className="px-1.5 py-0 text-[10px]">
              {tabCounts.expected}
            </Badge>
          )}
        </Button>

        <Button
          type="button"
          variant={currentTab === 'inside' ? 'default' : 'ghost'}
          size="sm"
          onClick={() => onTabChange('inside')}
          className="rounded-xl font-semibold gap-1.5 h-9"
        >
          <span>INSIDE</span>
          {tabCounts && (
            <Badge variant={currentTab === 'inside' ? 'secondary' : 'outline'} className="px-1.5 py-0 text-[10px]">
              {tabCounts.inside}
            </Badge>
          )}
        </Button>

        <Button
          type="button"
          variant={currentTab === 'checked-out' ? 'default' : 'ghost'}
          size="sm"
          onClick={() => onTabChange('checked-out')}
          className="rounded-xl font-semibold gap-1.5 h-9"
        >
          <span>CHECKED OUT</span>
          {tabCounts && (
            <Badge variant={currentTab === 'checked-out' ? 'secondary' : 'outline'} className="px-1.5 py-0 text-[10px]">
              {tabCounts.checkedOut}
            </Badge>
          )}
        </Button>

        <Button
          type="button"
          variant={currentTab === 'rejected' ? 'default' : 'ghost'}
          size="sm"
          onClick={() => onTabChange('rejected')}
          className="rounded-xl font-semibold gap-1.5 h-9"
        >
          <span>REJECTED</span>
          {tabCounts && (
            <Badge variant={currentTab === 'rejected' ? 'secondary' : 'outline'} className="px-1.5 py-0 text-[10px] text-rose-500">
              {tabCounts.rejected}
            </Badge>
          )}
        </Button>

        <Button
          type="button"
          variant={currentTab === 'history' ? 'default' : 'ghost'}
          size="sm"
          onClick={() => onTabChange('history')}
          className="rounded-xl font-semibold gap-1.5 h-9"
        >
          <span>HISTORY</span>
          {tabCounts && (
            <Badge variant={currentTab === 'history' ? 'secondary' : 'outline'} className="px-1.5 py-0 text-[10px]">
              {tabCounts.history}
            </Badge>
          )}
        </Button>

        {currentTab !== 'all' && (
          <Button
            type="button"
            variant="link"
            size="sm"
            onClick={() => onTabChange('all')}
            className="text-xs text-muted-foreground ml-auto"
          >
            View All Records
          </Button>
        )}
      </div>
    </>
  );
}
