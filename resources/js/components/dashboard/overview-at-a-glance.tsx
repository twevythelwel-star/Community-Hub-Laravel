import { Link } from '@inertiajs/react';
import {
  ArrowUpRight,
  CalendarCheck,
  DoorOpen,
  KeyRound,
  Users,
  Wallet,
  Users2,
  Building2,
  UserCheck,
  HardHat,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '@/context/auth-context';
import { cn } from '@/lib/utils';

export type GlanceStats = {
  month: string;
  activeResidents: number | null;
  residentsJoinedThisMonth: number | null;
  totalCollected: number;
  collectedHouseholds: number;
  outstandingDues: number;
  outstandingHouseholds: number;
  upcomingVisitors: number;
  visitorsToday: number;
  visitorsOnSite: number;
  entriesToday: number | null;
  deniedToday: number | null;
  myRentersCount?: number | null;
  occupancy?: {
    currentlyInside: {
      total: number;
      residents: number;
      renters: number;
      visitors: number;
      staff: number;
      contractors: number;
      legacyContacts: number;
    };
    expectedToday: {
      total: number;
      residents: number;
      renters: number;
      visitors: number;
      staff: number;
      contractors: number;
      legacyContacts: number;
    };
  };
};

type Props = {
  stats: GlanceStats;
  permissions: {
    viewActiveResidents: boolean;
    viewUpcomingVisitors: boolean;
    viewBilling: boolean;
  };
};

const jmd = (amount: number) =>
  amount.toLocaleString('en-JM', { style: 'currency', currency: 'JMD', maximumFractionDigits: 0 });

const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

/** Percentage of `part` in `whole`, kept visible (≥ 2%) whenever `part` is non-zero. */
const share = (part: number, whole: number) =>
  whole <= 0 || part <= 0 ? 0 : Math.max(2, Math.round((part / whole) * 100));

function Tile({
  href,
  label,
  icon: Icon,
  tone,
  className,
  children,
}: {
  /** Omitted when the viewer may see the number but not the page behind it. */
  href?: string;
  label: string;
  icon: LucideIcon;
  tone: string;
  className?: string;
  children: React.ReactNode;
}) {
  const body = (
    <>
      <div className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-2.5">
          <span className={cn('flex h-9 w-9 items-center justify-center rounded-lg', tone)}>
            <Icon className="h-[18px] w-[18px]" aria-hidden />
          </span>
          <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{label}</span>
        </span>
        {href && (
          <ArrowUpRight
            className="h-4 w-4 text-muted-foreground/50 transition-colors group-hover:text-primary"
            aria-hidden
          />
        )}
      </div>
      {children}
    </>
  );

  const base = 'group relative flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 text-card-foreground shadow-sm';

  return href ? (
    <Link
      href={href}
      className={cn(
        base,
        'transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        className,
      )}
    >
      {body}
    </Link>
  ) : (
    <div className={cn(base, className)}>{body}</div>
  );
}

/** Two-segment horizontal bar with an accessible text equivalent. */
function SplitBar({
  left,
  right,
  leftClass,
  rightClass,
  label,
}: {
  left: number;
  right: number;
  leftClass: string;
  rightClass: string;
  label: string;
}) {
  const total = left + right;
  const leftPct = share(left, total);

  return (
    <div role="img" aria-label={label} className="flex h-2.5 w-full overflow-hidden rounded-full bg-muted">
      {total > 0 && (
        <>
          <div className={cn('h-full transition-[width] duration-700', leftClass)} style={{ width: `${leftPct}%` }} />
          <div className={cn('h-full flex-1', right > 0 ? rightClass : '')} />
        </>
      )}
    </div>
  );
}

export function OverviewAtAGlance({ stats, permissions }: Props) {
  const { can } = useAuth();
  const showGate = stats.entriesToday !== null && stats.deniedToday !== null;
  const showResidents = permissions.viewActiveResidents && stats.activeResidents !== null;
  const showRenters = stats.myRentersCount !== null && stats.myRentersCount !== undefined;

  if (!permissions.viewBilling && !showGate && !showResidents && !permissions.viewUpcomingVisitors && !showRenters) {
    return null;
  }

  const allowedToday = showGate ? Math.max(0, (stats.entriesToday ?? 0) - (stats.deniedToday ?? 0)) : 0;

  return (
    <section aria-labelledby="at-a-glance-heading" className="flex flex-col gap-3">
      <h2 id="at-a-glance-heading" className="text-sm font-bold uppercase tracking-wider text-muted-foreground">
        At a glance
      </h2>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {permissions.viewBilling && (
          <Tile
            href="/dashboard/billing"
            label="Community dues"
            icon={Wallet}
            tone="bg-emerald-500/15 text-emerald-700 dark:text-emerald-300"
            className="sm:col-span-2"
          >
            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-2xl font-extrabold text-foreground">{jmd(stats.totalCollected)}</p>
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                  <span className="h-2 w-2 rounded-full bg-emerald-500" aria-hidden />
                  Collected in {stats.month} · {plural(stats.collectedHouseholds, 'household', 'households')}
                </p>
              </div>
              <div>
                <p className="text-2xl font-extrabold text-foreground">{jmd(stats.outstandingDues)}</p>
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                  <span className="h-2 w-2 rounded-full bg-amber-500" aria-hidden />
                  Still owed · {plural(stats.outstandingHouseholds, 'household', 'households')}
                </p>
              </div>
            </div>
            <SplitBar
              left={stats.totalCollected}
              right={stats.outstandingDues}
              leftClass="bg-emerald-500"
              rightClass="bg-amber-400"
              label={`${jmd(stats.totalCollected)} collected in ${stats.month} against ${jmd(stats.outstandingDues)} of open invoices`}
            />
          </Tile>
        )}

        {showGate && (
          <Tile
            href="/dashboard/access-log"
            label="Gate today"
            icon={DoorOpen}
            tone="bg-violet-500/15 text-violet-700 dark:text-violet-300"
          >
            <div className="flex items-baseline gap-2">
              <p className="text-2xl font-extrabold text-foreground">{stats.entriesToday}</p>
              <p className="text-xs text-muted-foreground">{stats.entriesToday === 1 ? 'check' : 'checks'}</p>
            </div>
            <SplitBar
              left={allowedToday}
              right={stats.deniedToday ?? 0}
              leftClass="bg-emerald-500"
              rightClass="bg-destructive"
              label={`${allowedToday} allowed and ${stats.deniedToday} denied at the gate today`}
            />
            <p className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
              <span className="flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-full bg-emerald-500" aria-hidden />
                {allowedToday} allowed
              </span>
              <span className="flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-full bg-destructive" aria-hidden />
                {stats.deniedToday} denied
              </span>
            </p>
          </Tile>
        )}

        {permissions.viewUpcomingVisitors && (
          <Tile
            href="/dashboard/visitors"
            label="Visitors"
            icon={CalendarCheck}
            tone="bg-sky-500/15 text-sky-700 dark:text-sky-300"
          >
            <div className="flex items-baseline gap-2">
              <p className="text-2xl font-extrabold text-foreground">{stats.upcomingVisitors}</p>
              <p className="text-xs text-muted-foreground">expected</p>
            </div>
            <p className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
              <span>{stats.visitorsToday} today</span>
              {stats.visitorsOnSite > 0 && (
                <span className="flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                  <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-500" aria-hidden />
                  {stats.visitorsOnSite} on site now
                </span>
              )}
            </p>
          </Tile>
        )}

        {showResidents && (
          <Tile
            href={can.manageUsers ? '/dashboard/directory' : undefined}
            label="Residents"
            icon={Users}
            tone="bg-primary/15 text-primary"
          >
            <p className="text-2xl font-extrabold text-foreground">{stats.activeResidents}</p>
            <p className="text-xs text-muted-foreground">
              {stats.residentsJoinedThisMonth
                ? `+${stats.residentsJoinedThisMonth} joined this month`
                : 'No new accounts this month'}
            </p>
          </Tile>
        )}

        {showRenters && (
          <Tile
            href="/dashboard/renters"
            label="My renters"
            icon={KeyRound}
            tone="bg-amber-500/15 text-amber-700 dark:text-amber-300"
          >
            <p className="text-2xl font-extrabold text-foreground">{stats.myRentersCount}</p>
            <p className="text-xs text-muted-foreground">
              {plural(stats.myRentersCount ?? 0, 'active tenant', 'active tenants')}
            </p>
          </Tile>
        )}

        {stats.occupancy && permissions.viewActiveResidents && (
          <Tile
            label="Unit occupancy"
            icon={Users2}
            tone="bg-indigo-500/15 text-indigo-700 dark:text-indigo-300"
            className="sm:col-span-2 xl:col-span-4"
          >
            <div className="grid grid-cols-2 gap-6">
              <div>
                <p className="text-sm font-semibold text-muted-foreground mb-2">Currently Inside</p>
                <p className="text-3xl font-extrabold text-foreground">{stats.occupancy.currentlyInside.total}</p>
                <div className="flex flex-wrap gap-x-3 gap-y-1 mt-2 text-xs text-muted-foreground">
                  <span className="flex items-center gap-1.5">
                    <Users className="h-3 w-3" aria-hidden />
                    {stats.occupancy.currentlyInside.residents} residents
                  </span>
                  <span className="flex items-center gap-1.5">
                    <Building2 className="h-3 w-3" aria-hidden />
                    {stats.occupancy.currentlyInside.renters} renters
                  </span>
                  <span className="flex items-center gap-1.5">
                    <CalendarCheck className="h-3 w-3" aria-hidden />
                    {stats.occupancy.currentlyInside.visitors} visitors
                  </span>
                  <span className="flex items-center gap-1.5">
                    <UserCheck className="h-3 w-3" aria-hidden />
                    {stats.occupancy.currentlyInside.staff} staff
                  </span>
                </div>
              </div>
              <div>
                <p className="text-sm font-semibold text-muted-foreground mb-2">Expected Today</p>
                <p className="text-3xl font-extrabold text-foreground">{stats.occupancy.expectedToday.total}</p>
                <div className="flex flex-wrap gap-x-3 gap-y-1 mt-2 text-xs text-muted-foreground">
                  <span className="flex items-center gap-1.5">
                    <Users className="h-3 w-3" aria-hidden />
                    {stats.occupancy.expectedToday.residents} residents
                  </span>
                  <span className="flex items-center gap-1.5">
                    <Building2 className="h-3 w-3" aria-hidden />
                    {stats.occupancy.expectedToday.renters} renters
                  </span>
                  <span className="flex items-center gap-1.5">
                    <CalendarCheck className="h-3 w-3" aria-hidden />
                    {stats.occupancy.expectedToday.visitors} visitors
                  </span>
                  <span className="flex items-center gap-1.5">
                    <UserCheck className="h-3 w-3" aria-hidden />
                    {stats.occupancy.expectedToday.staff} staff
                  </span>
                  {stats.occupancy.expectedToday.contractors > 0 && (
                    <span className="flex items-center gap-1.5">
                      <HardHat className="h-3 w-3" aria-hidden />
                      {stats.occupancy.expectedToday.contractors} contractors
                    </span>
                  )}
                </div>
              </div>
            </div>
            <SplitBar
              left={stats.occupancy.currentlyInside.total}
              right={stats.occupancy.expectedToday.total - stats.occupancy.currentlyInside.total}
              leftClass="bg-indigo-500"
              rightClass="bg-slate-300 dark:bg-slate-600"
              label={`${stats.occupancy.currentlyInside.total} currently inside out of ${stats.occupancy.expectedToday.total} expected today`}
            />
          </Tile>
        )}
      </div>
    </section>
  );
}
