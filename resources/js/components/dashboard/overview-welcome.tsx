import { Link } from '@inertiajs/react';
import {
  BadgeCheck,
  CalendarDays,
  CheckCircle2,
  CreditCard,
  Scan,
  Siren,
  UserPlus,
  type LucideIcon,
} from 'lucide-react';
import { useAuth } from '@/context/auth-context';
import type { UserRole } from '@/types';
import { useIsClient } from '@/hooks/use-is-client';
import { cn } from '@/lib/utils';

type QuickAction = {
  href: string;
  label: string;
  hint: string;
  icon: LucideIcon;
  tone: string;
  isVisible: (ctx: { hasRole: (...roles: UserRole[]) => boolean; can: ReturnType<typeof useAuth>['can'] }) => boolean;
};

const BILLED_ROLES: UserRole[] = ['System Admin', 'Admin', 'Homeowner', 'Temporary Homeowner'];

/*
 * Each action mirrors the role list of its sidebar entry in DashboardLayout, so
 * a shortcut never leads somewhere the menu would have hidden.
 */
const QUICK_ACTIONS: QuickAction[] = [
  {
    href: '/dashboard/visitors',
    label: 'Register a visitor',
    hint: 'Send a gate pass',
    icon: UserPlus,
    tone: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    isVisible: ({ can }) => can.registerVisitors,
  },
  {
    href: '/dashboard/gate-pass',
    label: 'My gate pass',
    hint: 'Show your QR code',
    icon: BadgeCheck,
    tone: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    isVisible: () => true,
  },
  {
    href: '/dashboard/gate-scanner',
    label: 'Scan at the gate',
    hint: 'Check a pass',
    icon: Scan,
    tone: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    isVisible: ({ can }) => can.scanPasses,
  },
  {
    href: '/dashboard/billing',
    label: 'Pay dues',
    hint: 'Invoices & receipts',
    icon: CreditCard,
    tone: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    isVisible: ({ hasRole }) => hasRole(...BILLED_ROLES),
  },
  {
    href: '/dashboard/calendar',
    label: 'Events',
    hint: 'What’s coming up',
    icon: CalendarDays,
    tone: 'bg-primary/15 text-primary',
    isVisible: ({ hasRole }) => hasRole(...BILLED_ROLES),
  },
  {
    href: '/dashboard/warnings',
    label: 'Report a concern',
    hint: 'Raise a safety alert',
    icon: Siren,
    tone: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    isVisible: () => true,
  },
];

function greetingFor(hour: number): string {
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
}

const jmd = (amount: number) =>
  amount.toLocaleString('en-JM', { style: 'currency', currency: 'JMD' });

export function OverviewWelcome({ myOutstandingBalance }: { myOutstandingBalance: number }) {
  const { user, can, hasRole } = useAuth();
  // The greeting and date depend on the viewer's clock, so they wait for the
  // client rather than guessing a timezone.
  const isClient = useIsClient();

  const firstName = (user?.displayName || user?.name || '').split(' ')[0];
  const now = new Date();
  const isBilled = hasRole(...BILLED_ROLES);
  const actions = QUICK_ACTIONS.filter((action) => action.isVisible({ can, hasRole }));

  return (
    <section
      aria-labelledby="overview-welcome-heading"
      className="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-primary/15 via-card to-emerald-500/10 p-5 sm:p-6 shadow-sm"
    >
      {/* Decorative glow; hidden from assistive tech. */}
      <div
        aria-hidden
        className="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-primary/20 blur-3xl"
      />

      <div className="relative flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-col gap-1">
          <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {isClient
              ? now.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })
              : ' '}
          </p>
          <h1 id="overview-welcome-heading" className="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl">
            {isClient ? greetingFor(now.getHours()) : 'Welcome'}
            {firstName && `, ${firstName}`}
          </h1>
          <p className="text-sm text-muted-foreground">
            {user?.lot ? `Lot ${user.lot} · ` : ''}
            Here’s what’s happening in your community today.
          </p>
        </div>

        {isBilled && (
          <Link
            href="/dashboard/billing"
            className={cn(
              'flex items-center gap-3 self-start rounded-xl border px-4 py-3 transition-colors lg:self-auto',
              myOutstandingBalance > 0
                ? 'border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/15'
                : 'border-emerald-500/40 bg-emerald-500/10 hover:bg-emerald-500/15',
            )}
          >
            {myOutstandingBalance > 0 ? (
              <CreditCard className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
            ) : (
              <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            )}
            <div className="leading-tight">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                Your balance
              </p>
              <p className="text-base font-bold text-foreground">
                {myOutstandingBalance > 0 ? `${jmd(myOutstandingBalance)} due` : 'All paid up'}
              </p>
            </div>
          </Link>
        )}
      </div>

      <nav aria-label="Quick actions" className="relative mt-5">
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
          {actions.map(({ href, label, hint, icon: Icon, tone }) => (
            <li key={href}>
              <Link
                href={href}
                className="group flex h-full items-center gap-3 rounded-xl border border-border bg-card/80 p-3 backdrop-blur transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <span className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', tone)}>
                  <Icon className="h-5 w-5" aria-hidden />
                </span>
                <span className="min-w-0 leading-tight">
                  <span className="block truncate text-sm font-semibold text-foreground group-hover:text-primary">
                    {label}
                  </span>
                  <span className="block truncate text-[11px] text-muted-foreground">{hint}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </nav>
    </section>
  );
}
