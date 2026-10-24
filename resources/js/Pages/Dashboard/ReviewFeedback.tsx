import { useEffect, useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
  } from "@/components/ui/table";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
  } from "@/components/ui/dropdown-menu";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { MoreHorizontal } from "lucide-react";
import { ClientFormattedDate } from "@/components/client-formatted-date";
import { useToast } from "@/hooks/use-toast";
import { submit } from "@/lib/submit";

/*
 | The page used to hold three hardcoded submissions in React state, so status
 | changes vanished on reload and real feedback never appeared. It also never
 | showed the body of a submission, so an administrator could see a subject
 | line and nothing else, and had no way to write a response. (The response is
 | stored and sent to the submitter's Feedback page as `adminResponse`, but that
 | page is not wired to its props yet, so submitters do not see it today.)
 |
 | "Delete" is gone rather than wired. There is no endpoint for it, and a
 | submission is also the submitter's record of what they reported and what
 | the estate answered; resolving it is how it leaves the queue.
 */

type Status = 'New' | 'In Progress' | 'Resolved';
type FeedbackType = 'Issue' | 'Suggestion';

type Submission = {
  id: number;
  submittedBy: string;
  userRole: string;
  type: FeedbackType;
  subject: string;
  body: string;
  status: Status;
  adminResponse: string | null;
  timestamp: string;
};

type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Props = {
  submissions: Paginated<Submission>;
  filters: { status?: string; type?: string };
  counts: { new: number; inProgress: number; resolved: number };
};

const ANY = 'any';
const STATUSES: Status[] = ['New', 'In Progress', 'Resolved'];

const getStatusVariant = (status: Status) => {
    switch (status) {
        case 'New':
            return 'default';
        case 'In Progress':
            return 'secondary';
        case 'Resolved':
            return 'outline';
        default:
            return 'default';
    }
};

export default function ReviewFeedbackPage({ submissions, filters, counts }: Props) {
    const { toast } = useToast();
    const [reviewing, setReviewing] = useState<Submission | null>(null);
    const [status, setStatus] = useState<Status>('New');
    const [response, setResponse] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (reviewing) {
            setStatus(reviewing.status);
            setResponse(reviewing.adminResponse ?? '');
        }
    }, [reviewing]);

    const applyFilter = (key: 'status' | 'type', value: string) => {
        const next = { ...filters, [key]: value === ANY ? undefined : value };
        const query: Record<string, string> = {};
        for (const [k, v] of Object.entries(next)) {
            if (v) query[k] = v;
        }
        router.get('/dashboard/review-feedback', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const changeStatus = (item: Submission, next: Status) => {
        // Only the status is sent, so an existing response is left as it is.
        submit('patch', `/dashboard/review-feedback/${item.id}`, { status: next }).then(
            () => toast({ title: 'Status Updated', description: `"${item.subject}" is now ${next}.` }),
            () => toast({ variant: 'destructive', title: 'Status not updated', description: 'The server refused the change. Please try again.' }),
        );
    };

    const saveReview = async () => {
        if (!reviewing) return;
        setSaving(true);
        try {
            await submit('patch', `/dashboard/review-feedback/${reviewing.id}`, {
                status,
                admin_response: response.trim() === '' ? null : response,
            });
            toast({ title: 'Feedback Updated', description: `Your review of "${reviewing.subject}" has been saved.` });
            setReviewing(null);
        } catch (message) {
            toast({
                variant: 'destructive',
                title: 'Feedback not updated',
                description: typeof message === 'string' ? message : 'The server refused the change. Please try again.',
            });
        } finally {
            setSaving(false);
        }
    };

  return (
    <DashboardLayout>
      <Head title="Review Feedback" />
      <div className="grid gap-8 max-w-7xl mx-auto pb-12">
        <div>
          <h1 className="font-headline text-3xl font-bold">Review Feedback</h1>
          <p className="text-muted-foreground">Manage and review user-submitted issues and suggestions.</p>
        </div>

        <div className="grid gap-4 sm:grid-cols-3">
          {([
            ['New', counts.new],
            ['In Progress', counts.inProgress],
            ['Resolved', counts.resolved],
          ] as const).map(([label, count]) => (
            <Card key={label}>
              <CardHeader className="pb-2">
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-3xl">{count}</CardTitle>
              </CardHeader>
            </Card>
          ))}
        </div>

         <Card>
          <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="space-y-1.5">
              <CardTitle>Submitted Feedback</CardTitle>
              <CardDescription>
                Review and take action on feedback from all users.
              </CardDescription>
            </div>
            <div className="flex flex-wrap gap-3">
              <div className="grid gap-1.5">
                <Label htmlFor="filter-status" className="text-xs">Status</Label>
                <Select value={filters.status ?? ANY} onValueChange={(v) => applyFilter('status', v)}>
                  <SelectTrigger id="filter-status" className="w-40"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ANY}>All statuses</SelectItem>
                    {STATUSES.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="filter-type" className="text-xs">Type</Label>
                <Select value={filters.type ?? ANY} onValueChange={(v) => applyFilter('type', v)}>
                  <SelectTrigger id="filter-type" className="w-40"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ANY}>All types</SelectItem>
                    <SelectItem value="Issue">Issue</SelectItem>
                    <SelectItem value="Suggestion">Suggestion</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardHeader>
          <CardContent>
              <Table>
                  <TableHeader>
                      <TableRow>
                          <TableHead>Submitted By</TableHead>
                          <TableHead>Subject</TableHead>
                          <TableHead>Type</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead>Date</TableHead>
                          <TableHead>
                              <span className="sr-only">Actions</span>
                          </TableHead>
                      </TableRow>
                  </TableHeader>
                  <TableBody>
                      {submissions.data.length === 0 && (
                          <TableRow>
                              <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                  No feedback matches these filters.
                              </TableCell>
                          </TableRow>
                      )}
                      {submissions.data.map((item) => (
                          <TableRow key={item.id}>
                              <TableCell>
                                  <div className="font-medium">{item.submittedBy}</div>
                                  <div className="text-sm text-muted-foreground">{item.userRole}</div>
                              </TableCell>
                              <TableCell className="font-medium">
                                  <button
                                      type="button"
                                      className="text-left hover:underline"
                                      onClick={() => setReviewing(item)}
                                  >
                                      {item.subject}
                                  </button>
                              </TableCell>
                              <TableCell>{item.type}</TableCell>
                              <TableCell>
                                  <Badge variant={getStatusVariant(item.status)}>{item.status}</Badge>
                              </TableCell>
                              <TableCell>
                                 <ClientFormattedDate date={item.timestamp} formatString="MMM d, yyyy" />
                              </TableCell>
                               <TableCell>
                                  <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                      <Button aria-haspopup="true" size="icon" variant="ghost">
                                      <MoreHorizontal className="h-4 w-4" />
                                      <span className="sr-only">Toggle menu</span>
                                      </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                      <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                      <DropdownMenuItem onClick={() => setReviewing(item)}>View &amp; Respond</DropdownMenuItem>
                                      <DropdownMenuSeparator />
                                      {STATUSES.filter((s) => s !== item.status).map((s) => (
                                          <DropdownMenuItem key={s} onClick={() => changeStatus(item, s)}>
                                              Mark as {s}
                                          </DropdownMenuItem>
                                      ))}
                                  </DropdownMenuContent>
                                  </DropdownMenu>
                              </TableCell>
                          </TableRow>
                      ))}
                  </TableBody>
              </Table>

              {submissions.links.length > 3 && (
                <nav className="flex flex-wrap items-center justify-center gap-1 pt-4" aria-label="Pagination">
                  {submissions.links.map((link, index) => (
                    <Button
                      key={index}
                      size="sm"
                      variant={link.active ? 'default' : 'outline'}
                      disabled={!link.url}
                      onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true, preserveState: true })}
                      className="h-8 min-w-8 px-2 text-xs"
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ))}
                </nav>
              )}
          </CardContent>
        </Card>
      </div>

      <Dialog open={reviewing !== null} onOpenChange={(open) => !open && setReviewing(null)}>
        <DialogContent className="sm:max-w-lg">
          {reviewing && (
            <>
              <DialogHeader>
                <DialogTitle>{reviewing.subject}</DialogTitle>
                <DialogDescription>
                  {reviewing.type} from {reviewing.submittedBy} ({reviewing.userRole}),{' '}
                  <ClientFormattedDate date={reviewing.timestamp} formatString="MMM d, yyyy 'at' h:mm a" />
                </DialogDescription>
              </DialogHeader>

              <div className="space-y-4">
                <p className="max-h-60 overflow-y-auto whitespace-pre-wrap rounded-md border bg-muted/40 p-3 text-sm">
                  {reviewing.body}
                </p>

                <div className="grid gap-2">
                  <Label htmlFor="review-status">Status</Label>
                  <Select value={status} onValueChange={(v) => setStatus(v as Status)}>
                    <SelectTrigger id="review-status"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {STATUSES.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="review-response">Response to {reviewing.submittedBy}</Label>
                  <Textarea
                    id="review-response"
                    maxLength={5000}
                    rows={4}
                    placeholder="Your reply to this submission."
                    value={response}
                    onChange={(e) => setResponse(e.target.value)}
                  />
                </div>
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setReviewing(null)}>Cancel</Button>
                <Button type="button" onClick={saveReview} disabled={saving}>
                  {saving ? 'Saving…' : 'Save Review'}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>
    </DashboardLayout>
  );
}
