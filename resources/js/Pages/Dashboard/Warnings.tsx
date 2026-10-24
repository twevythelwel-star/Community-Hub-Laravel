import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Button } from '@/components/ui/button';
import { WarningList, type WarningRow } from '@/components/dashboard/warning-list';
import { WarningForm } from '@/components/dashboard/warning-form';

/**
 * Community Safety Alerts.
 *
 * The feed was two hardcoded objects and every vote lived in React state. Both
 * halves now go through WarningController.
 */

type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Props = {
  warnings: Paginated<WarningRow>;
  can: { remove: boolean };
};

export default function WarningsPage({ warnings, can }: Props) {
  return (
    <DashboardLayout>
      <Head title="Community Safety Alerts" />

      <div className="flex flex-col gap-8">
        <div className="flex items-center">
          <div className="flex-1">
            <h1 className="font-headline text-3xl font-bold">Community Safety Alerts</h1>
            <p className="text-muted-foreground">
              Send and validate urgent alerts within the community.
              {warnings.total > 0 &&
                ` Showing ${warnings.from}–${warnings.to} of ${warnings.total}.`}
            </p>
          </div>
          <WarningForm />
        </div>

        <WarningList warnings={warnings.data} canRemove={can.remove} />

        {warnings.links.length > 3 && (
          <nav
            className="flex flex-wrap items-center justify-center gap-1"
            aria-label="Pagination"
          >
            {warnings.links.map((link, index) => (
              <Button
                key={index}
                size="sm"
                variant={link.active ? 'default' : 'outline'}
                disabled={!link.url}
                onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                className="h-8 min-w-8 px-2 text-xs"
                dangerouslySetInnerHTML={{ __html: link.label }}
              />
            ))}
          </nav>
        )}
      </div>
    </DashboardLayout>
  );
}
