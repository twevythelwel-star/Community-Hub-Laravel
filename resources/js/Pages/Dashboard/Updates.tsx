import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { PlusCircle } from "lucide-react";
import { format, parseISO } from 'date-fns';
import { CommunityUpdateForm } from '@/components/dashboard/community-update-form';
import { submit } from '@/lib/submit';

type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Update = {
  id: number;
  title: string;
  /** A calendar date, `yyyy-MM-dd`, with no time or zone. */
  date: string;
  summary: string;
};

type Props = {
  updates: Paginated<Update>;
  canManage: boolean;
};

export default function UpdatesPage({ updates, canManage }: Props) {
    const [isFormOpen, setFormOpen] = useState(false);

  return (
    <DashboardLayout>
      <Head title="Community Updates" />
      <div className="grid gap-8 max-w-7xl mx-auto pb-12">
        <div className="flex items-center justify-between">
            <div>
                <h1 className="font-headline text-3xl font-bold">Community Updates</h1>
                <p className="text-muted-foreground">Summaries of recent meetings and community activities.</p>
            </div>
             {canManage && (
                <CommunityUpdateForm
                    open={isFormOpen}
                    onOpenChange={setFormOpen}
                    onSave={(data) =>
                        submit('post', '/dashboard/updates', {
                            title: data.title,
                            // Sent as a calendar date: toISOString() would shift
                            // an evening pick to the next day for anyone west of UTC.
                            date: format(data.date, 'yyyy-MM-dd'),
                            summary: data.summary,
                        })
                    }
                >
                    <Button size="sm" className="gap-1">
                        <PlusCircle className="h-3.5 w-3.5" />
                        <span className="sr-only sm:not-sr-only sm:whitespace-nowrap">
                            Add Update
                        </span>
                    </Button>
                </CommunityUpdateForm>
             )}
        </div>

        {updates.data.length === 0 ? (
            <Card>
                <CardContent className="py-12 text-center text-muted-foreground">
                    No community updates have been posted yet.
                </CardContent>
            </Card>
        ) : (
            <div className="space-y-6">
                {updates.data.map(update => (
                     <Card key={update.id}>
                        <CardHeader>
                            <CardTitle>{update.title}</CardTitle>
                            <CardDescription>
                               {/* parseISO reads a bare date as local midnight; new Date() would read it as UTC. */}
                               Meeting/Activity Date: {format(parseISO(update.date), 'MMMM d, yyyy')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground whitespace-pre-wrap">{update.summary}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>
        )}

        {updates.links.length > 3 && (
          <nav className="flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
            {updates.links.map((link, index) => (
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
