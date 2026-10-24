import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Download, ShieldX, X } from 'lucide-react';
import { ClientFormattedDate } from '@/components/client-formatted-date';

/**
 * Access Log.
 *
 * Was five hardcoded rows with no outcome column. Every mock entry was a
 * successful entry, so a page whose purpose is security review showed the
 * people who got in and never the ones who were refused — although `result`
 * and `deny_reason` have always been recorded.
 *
 * The filters and the CSV export existed on the server and had no controls.
 */

type LogRow = {
  id: number;
  userName: string;
  userRole: string;
  method: string;
  gate: string;
  passId: string | null;
  result: 'ALLOW' | 'DENY' | 'CHECK_IN' | 'CHECK_OUT';
  denyReason: string | null;
  timestamp: string;
};

/** ALLOW is a scan that passed; CHECK_IN and CHECK_OUT are what the guard then confirmed. */
function resultLabel(result: string): string {
  switch (result) {
    case 'DENY':
      return 'Refused';
    case 'CHECK_IN':
      return 'Checked in';
    case 'CHECK_OUT':
      return 'Checked out';
    default:
      return 'Scan passed';
  }
}

type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Filters = {
  gate?: string;
  result?: string;
  from?: string;
  to?: string;
};

type Props = {
  entries: Paginated<LogRow>;
  stats: { total: number; denied: number };
  filters: Filters;
  gates: string[];
  results: string[];
  canExport: boolean;
};

// Radix Select cannot hold an empty string as a value, so "no filter" needs a
// sentinel that is stripped before the query goes to the server.
const ANY = '__any__';

function roleBadgeVariant(role: string) {
  switch (role) {
    case 'System Admin':
    case 'Admin':
      return 'destructive' as const;
    case 'Homeowner':
      return 'default' as const;
    case 'Temporary Homeowner':
      return 'secondary' as const;
    default:
      return 'outline' as const;
  }
}

/** `TOKEN_EXPIRED: Dynamic credential expired…` → `Token expired`. */
function readableReason(reason: string): string {
  const code = reason.split(':')[0].trim();
  return code.charAt(0) + code.slice(1).toLowerCase().replace(/_/g, ' ');
}

export default function AccessLogPage({
  entries,
  stats,
  filters,
  gates,
  results,
  canExport,
}: Props) {
  const [draft, setDraft] = useState<Filters>(filters);

  const apply = (next: Filters) => {
    setDraft(next);

    const query: Record<string, string> = {};
    for (const [key, value] of Object.entries(next)) {
      if (value && value !== ANY) query[key] = value;
    }

    router.get('/dashboard/access-log', query, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const hasFilters = Object.values(draft).some((v) => v && v !== ANY);

  const exportHref = () => {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(draft)) {
      if (value && value !== ANY) query.set(key, value);
    }
    const qs = query.toString();
    return `/dashboard/access-log/export${qs ? `?${qs}` : ''}`;
  };

  return (
    <DashboardLayout>
      <Head title="Access Log" />

      <div className="grid gap-8">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="font-headline text-3xl font-bold">Access Log</h1>
            <p className="text-muted-foreground">
              A record of every entry attempt at the gate, allowed and refused.
            </p>
          </div>

          {canExport && (
            <Button asChild variant="outline" size="sm" className="gap-1.5">
              {/*
                A plain link, not router.get: this is a streamed file download,
                and Inertia would try to parse the CSV as a page response. The
                current filters ride along so the file matches the table.
              */}
              <a href={exportHref()} download>
                <Download className="h-4 w-4" />
                Export CSV
              </a>
            </Button>
          )}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Card>
            <CardHeader className="pb-2">
              <CardDescription>Entries {hasFilters ? 'matching filter' : 'recorded'}</CardDescription>
              <CardTitle className="text-3xl">{stats.total.toLocaleString()}</CardTitle>
            </CardHeader>
          </Card>
          <Card className={stats.denied > 0 ? 'border-destructive/40' : undefined}>
            <CardHeader className="pb-2">
              <CardDescription className="flex items-center gap-1.5">
                <ShieldX className="h-3.5 w-3.5" /> Refused
              </CardDescription>
              <CardTitle
                className={`text-3xl ${stats.denied > 0 ? 'text-destructive' : ''}`}
              >
                {stats.denied.toLocaleString()}
              </CardTitle>
            </CardHeader>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Recent Activity</CardTitle>
            <CardDescription>
              This log shows all access events recorded by the gate system.
              {entries.total > 0 && ` Showing ${entries.from}–${entries.to} of ${entries.total}.`}
            </CardDescription>

            <div className="grid gap-3 pt-4 sm:grid-cols-2 lg:grid-cols-5 items-end">
              <div className="grid gap-1.5">
                <Label className="text-xs">Gate</Label>
                <Select
                  value={draft.gate || ANY}
                  onValueChange={(value) => apply({ ...draft, gate: value })}
                >
                  <SelectTrigger className="h-9">
                    <SelectValue placeholder="Any gate" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ANY}>Any gate</SelectItem>
                    {gates.map((gate) => (
                      <SelectItem key={gate} value={gate}>
                        {gate}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="grid gap-1.5">
                <Label className="text-xs">Result</Label>
                <Select
                  value={draft.result || ANY}
                  onValueChange={(value) => apply({ ...draft, result: value })}
                >
                  <SelectTrigger className="h-9">
                    <SelectValue placeholder="Any result" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ANY}>Any result</SelectItem>
                    {results.map((result) => (
                      <SelectItem key={result} value={result}>
                        {resultLabel(result)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="from" className="text-xs">
                  From
                </Label>
                <Input
                  id="from"
                  type="date"
                  className="h-9"
                  value={draft.from ?? ''}
                  onChange={(e) => apply({ ...draft, from: e.target.value })}
                />
              </div>

              <div className="grid gap-1.5">
                <Label htmlFor="to" className="text-xs">
                  To
                </Label>
                <Input
                  id="to"
                  type="date"
                  className="h-9"
                  value={draft.to ?? ''}
                  onChange={(e) => apply({ ...draft, to: e.target.value })}
                />
              </div>

              {hasFilters && (
                <Button variant="ghost" size="sm" className="h-9 gap-1.5" onClick={() => apply({})}>
                  <X className="h-3.5 w-3.5" />
                  Clear
                </Button>
              )}
            </div>
          </CardHeader>

          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>User</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead>Method</TableHead>
                  <TableHead>Gate</TableHead>
                  <TableHead>Result</TableHead>
                  <TableHead>Timestamp</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {entries.data.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                      No entries match these filters.
                    </TableCell>
                  </TableRow>
                )}

                {entries.data.map((entry) => (
                  <TableRow
                    key={entry.id}
                    className={entry.result === 'DENY' ? 'bg-destructive/5' : undefined}
                  >
                    <TableCell className="font-medium">
                      {entry.userName}
                      {entry.passId && (
                        <span className="block text-[11px] text-muted-foreground font-normal">
                          {entry.passId}
                        </span>
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge variant={roleBadgeVariant(entry.userRole)}>{entry.userRole}</Badge>
                    </TableCell>
                    <TableCell>{entry.method}</TableCell>
                    <TableCell>{entry.gate}</TableCell>
                    <TableCell>
                      {entry.result === 'DENY' ? (
                        <div className="flex flex-col gap-0.5">
                          <Badge variant="destructive" className="w-fit">
                            Refused
                          </Badge>
                          {entry.denyReason && (
                            <span
                              className="text-[11px] text-muted-foreground"
                              title={entry.denyReason}
                            >
                              {readableReason(entry.denyReason)}
                            </span>
                          )}
                        </div>
                      ) : (
                        <Badge variant={entry.result === 'ALLOW' ? 'secondary' : 'default'}>{resultLabel(entry.result)}</Badge>
                      )}
                    </TableCell>
                    <TableCell>
                      <ClientFormattedDate
                        date={entry.timestamp}
                        formatString="MMM d, yyyy, h:mm:ss a"
                      />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {entries.links.length > 3 && (
              <nav
                className="flex flex-wrap items-center justify-center gap-1 pt-4"
                aria-label="Pagination"
              >
                {entries.links.map((link, index) => (
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
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
