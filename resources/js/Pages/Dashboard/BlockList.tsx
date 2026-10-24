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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { MoreHorizontal, PlusCircle, ShieldAlert, Check, X } from 'lucide-react';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { BlocklistForm, type BlocklistFormValues } from '@/components/dashboard/blocklist-form';
import { RequestRemovalForm } from '@/components/dashboard/request-removal-form';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useToast } from '@/hooks/use-toast';
import { format, parseISO } from 'date-fns';

/**
 * Row shape. The sensitive half — reason, photo and author — is only present
 * for viewers who may manage the list; BlocklistController omits those fields
 * entirely rather than sending them and hiding them in the UI.
 */
export type BlocklistRow = {
  id: number;
  name: string;
  dateAdded: string;
  expiryDate: string | null;
  isPermanent: boolean;
  inForce: boolean;
  myRequestStatus: string | null;

  reason?: string;
  photoUrl?: string | null;
  addedBy?: string;
  pendingRequests?: number;
};

type RemovalRequest = {
  id: number;
  entryId: number;
  entryName: string | null;
  requestedBy: string | null;
  lot: string | null;
  reason: string;
  requestedAt: string;
};

type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Props = {
  entries: Paginated<BlocklistRow>;
  visitors: { id: number; name: string; idImageUrl: string | null }[];
  removalRequests: RemovalRequest[];
  can: { manage: boolean; requestRemoval: boolean };
};

export default function BlockListPage({ entries, visitors, removalRequests, can }: Props) {
  const { toast } = useToast();

  const [isFormOpen, setFormOpen] = useState(false);
  const [isRemovalFormOpen, setRemovalFormOpen] = useState(false);
  const [selectedEntry, setSelectedEntry] = useState<BlocklistRow | undefined>();

  const openForm = (entry?: BlocklistRow) => {
    setSelectedEntry(entry);
    setFormOpen(true);
  };

  const openRemovalForm = (entry: BlocklistRow) => {
    setSelectedEntry(entry);
    setRemovalFormOpen(true);
  };

  const handleSave = (values: BlocklistFormValues, id?: number) => {
    // The form speaks camelCase; BlocklistController validates snake_case.
    const payload = {
      name: values.name,
      reason: values.reason,
      photo_url: values.photoUrl || null,
      expiry_date: values.expiryDate
        ? format(
            values.expiryDate instanceof Date ? values.expiryDate : parseISO(values.expiryDate),
            'yyyy-MM-dd',
          )
        : null,
    };

    const options = {
      preserveScroll: true,
      onSuccess: () => {
        setFormOpen(false);
        setSelectedEntry(undefined);
        toast({
          title: id ? 'Entry Updated' : 'Entry Added',
          description: `${values.name} has been ${id ? 'updated on' : 'added to'} the blocklist.`,
        });
      },
      onError: () =>
        toast({
          variant: 'destructive',
          title: 'Could Not Save',
          description: 'Check the highlighted fields and try again.',
        }),
    };

    if (id) {
      router.patch(`/dashboard/block-list/${id}`, payload, options);
    } else {
      router.post('/dashboard/block-list', payload, options);
    }
  };

  const handleDelete = (entry: BlocklistRow) => {
    if (!window.confirm(`Remove ${entry.name} from the blocklist? They will be admitted again.`)) {
      return;
    }

    router.delete(`/dashboard/block-list/${entry.id}`, {
      preserveScroll: true,
      onSuccess: () =>
        toast({
          title: 'Entry Removed',
          description: `${entry.name} is no longer blocked at the gates.`,
        }),
    });
  };

  const reviewRequest = (request: RemovalRequest, status: 'Approved' | 'Declined') => {
    const confirmText =
      status === 'Approved'
        ? `Approve this request? ${request.entryName} will be removed from the blocklist and admitted again.`
        : `Decline this request? ${request.requestedBy} will be told it was reviewed.`;

    if (!window.confirm(confirmText)) return;

    router.patch(
      `/dashboard/block-list/requests/${request.id}`,
      { status },
      {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            title: status === 'Approved' ? 'Request Approved' : 'Request Declined',
            description:
              status === 'Approved'
                ? `${request.entryName} has been removed from the blocklist.`
                : 'The request has been closed.',
          }),
      },
    );
  };

  return (
    <DashboardLayout>
      <Head title="Block List" />

      <div className="grid gap-8 pb-12">
        <div>
          <h1 className="font-headline text-3xl font-bold">Block List</h1>
          <p className="text-muted-foreground">
            {can.manage
              ? 'Manage individuals who are denied entry to the community.'
              : 'Individuals who are denied entry to the community.'}
          </p>
        </div>

        {/* Pending resident requests, for the administrators who action them. */}
        {can.manage && removalRequests.length > 0 && (
          <Card className="border-amber-500/40 bg-amber-500/5">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <ShieldAlert className="h-4 w-4 text-amber-600" />
                Pending Removal Requests ({removalRequests.length})
              </CardTitle>
              <CardDescription className="text-xs">
                Residents have asked for these entries to be reviewed. Approving one removes the
                entry from the blocklist.
              </CardDescription>
            </CardHeader>

            <CardContent className="space-y-3">
              {removalRequests.map((request) => (
                <div
                  key={request.id}
                  className="rounded-lg border border-border bg-card p-3 flex flex-col sm:flex-row sm:items-start gap-3"
                >
                  <div className="flex-1 min-w-0 space-y-1">
                    <p className="text-sm font-semibold text-foreground">
                      {request.entryName}
                      <span className="ml-2 text-xs font-normal text-muted-foreground">
                        requested by {request.requestedBy}
                        {request.lot ? ` (${request.lot})` : ''}
                      </span>
                    </p>
                    <p className="text-xs text-muted-foreground">{request.reason}</p>
                    <p className="text-[10px] text-muted-foreground">
                      <ClientFormattedDate date={request.requestedAt} formatString="MMM d, yyyy" />
                    </p>
                  </div>

                  <div className="flex gap-2 shrink-0">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => reviewRequest(request, 'Approved')}
                      className="h-8 gap-1.5 text-xs border-emerald-500/40 text-emerald-700 hover:bg-emerald-500/10"
                    >
                      <Check className="h-3.5 w-3.5" />
                      Approve
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => reviewRequest(request, 'Declined')}
                      className="h-8 gap-1.5 text-xs"
                    >
                      <X className="h-3.5 w-3.5" />
                      Decline
                    </Button>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>Blocked Individuals</CardTitle>
              <CardDescription>
                Individuals on this list are refused at the gate.
                {entries.total > 0 && ` Showing ${entries.from}–${entries.to} of ${entries.total}.`}
              </CardDescription>
            </div>

            {can.manage && (
              <BlocklistForm
                open={isFormOpen}
                onOpenChange={setFormOpen}
                onSave={handleSave}
                entry={selectedEntry}
                visitors={visitors}
              >
                <Button size="sm" className="gap-1" onClick={() => openForm()}>
                  <PlusCircle className="h-3.5 w-3.5" />
                  <span className="sr-only sm:not-sr-only sm:whitespace-nowrap">Add Individual</span>
                </Button>
              </BlocklistForm>
            )}
          </CardHeader>

          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Individual</TableHead>
                  {can.manage && <TableHead>Reason</TableHead>}
                  <TableHead>Status</TableHead>
                  <TableHead>Date Added</TableHead>
                  {can.manage && <TableHead>Added By</TableHead>}
                  <TableHead>
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>

              <TableBody>
                {entries.data.length === 0 && (
                  <TableRow>
                    <TableCell
                      colSpan={can.manage ? 6 : 4}
                      className="py-8 text-center text-sm text-muted-foreground"
                    >
                      Nobody is currently blocked.
                    </TableCell>
                  </TableRow>
                )}

                {entries.data.map((entry) => (
                  <TableRow key={entry.id} className={entry.inForce ? undefined : 'opacity-60'}>
                    <TableCell className="font-medium">
                      <div className="flex items-center gap-3">
                        <Avatar>
                          {/* photoUrl is only present for managers. */}
                          {entry.photoUrl && (
                            <AvatarImage src={entry.photoUrl} alt="" data-ai-hint="person face" />
                          )}
                          <AvatarFallback>{entry.name.charAt(0)}</AvatarFallback>
                        </Avatar>
                        <span>{entry.name}</span>
                        {can.manage && (entry.pendingRequests ?? 0) > 0 && (
                          <Badge variant="outline" className="text-[10px] border-amber-500/40 text-amber-600">
                            {entry.pendingRequests} request{entry.pendingRequests === 1 ? '' : 's'}
                          </Badge>
                        )}
                      </div>
                    </TableCell>

                    {can.manage && (
                      <TableCell className="max-w-xs truncate">{entry.reason}</TableCell>
                    )}

                    <TableCell>
                      <Badge variant={entry.isPermanent ? 'destructive' : 'secondary'}>
                        {entry.isPermanent ? (
                          'Permanent'
                        ) : (
                          <>
                            {entry.inForce ? 'Expires ' : 'Expired '}
                            <ClientFormattedDate
                              date={entry.expiryDate!}
                              formatString="MMM d, yyyy"
                            />
                          </>
                        )}
                      </Badge>
                    </TableCell>

                    <TableCell>
                      <ClientFormattedDate date={entry.dateAdded} formatString="MMM d, yyyy" />
                    </TableCell>

                    {can.manage && <TableCell>{entry.addedBy}</TableCell>}

                    <TableCell>
                      {(can.manage || can.requestRemoval) && (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button aria-haspopup="true" size="icon" variant="ghost">
                              <MoreHorizontal className="h-4 w-4" />
                              <span className="sr-only">Actions for {entry.name}</span>
                            </Button>
                          </DropdownMenuTrigger>

                          <DropdownMenuContent align="end">
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>

                            {can.manage && (
                              <>
                                <DropdownMenuItem onClick={() => openForm(entry)}>
                                  Edit
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                  className="text-destructive"
                                  onClick={() => handleDelete(entry)}
                                >
                                  Remove from list
                                </DropdownMenuItem>
                              </>
                            )}

                            {can.requestRemoval && (
                              <DropdownMenuItem
                                onClick={() => openRemovalForm(entry)}
                                disabled={entry.myRequestStatus === 'Pending'}
                              >
                                {entry.myRequestStatus === 'Pending'
                                  ? 'Review Requested'
                                  : 'Request Removal'}
                              </DropdownMenuItem>
                            )}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {/* Server-side pagination; the original rendered every row at once. */}
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

        {selectedEntry && can.requestRemoval && (
          <RequestRemovalForm
            open={isRemovalFormOpen}
            onOpenChange={(isOpen) => {
              setRemovalFormOpen(isOpen);
              if (!isOpen) setSelectedEntry(undefined);
            }}
            entry={selectedEntry}
          />
        )}
      </div>
    </DashboardLayout>
  );
}
