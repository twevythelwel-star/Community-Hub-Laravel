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
import {
  MoreHorizontal,
  PlusCircle,
  Clock,
  Home,
  UserCheck,
  Calendar as CalendarIcon,
  ShieldCheck,
  Edit,
  Trash2,
} from 'lucide-react';
import type { Renter } from '@/types';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { RenterForm, type HomeownerOption } from '@/components/dashboard/renter-form';
import { useAuth } from '@/context/auth-context';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { useToast } from '@/hooks/use-toast';
import { differenceInDays, parseISO } from 'date-fns';

type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

type Props = {
  renters: Paginated<Renter>;
  isHomeowner: boolean;
  /** Only System Admins and Admins register residents. */
  canRegister: boolean;
  homeowners: HomeownerOption[];
};

export default function RentersPage({ renters, isHomeowner, canRegister, homeowners }: Props) {
  const { user } = useAuth();
  const { toast } = useToast();
  const [isCreateOpen, setCreateOpen] = useState(false);
  const [editingRenter, setEditingRenter] = useState<Renter | null>(null);
  const [deletingRenter, setDeletingRenter] = useState<Renter | null>(null);

  const handleDeleteConfirm = () => {
    if (!deletingRenter) return;

    router.delete(`/dashboard/renters/${deletingRenter.id}`, {
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: 'Temporary Homeowner Removed',
          description: `${deletingRenter.name} has been removed from your property.`,
        });
        setDeletingRenter(null);
      },
    });
  };

  const renterList = renters?.data || [];

  return (
    <DashboardLayout>
      <Head title="Temporary Homeowners & Renters" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-3xl font-bold font-headline tracking-tight flex items-center gap-2">
              <UserCheck className="h-7 w-7 text-primary" />
              Renters &amp; Temporary Homeowners
            </h1>
            <p className="text-sm text-muted-foreground mt-1">
              Manage temporary occupants (long-term renters and short-term Airbnb guests) registered under your property.
            </p>
          </div>

          {canRegister && (
            <div className="flex items-center gap-3">
              <RenterForm open={isCreateOpen} onOpenChange={setCreateOpen} homeowners={homeowners}>
                <Button className="gap-2 shadow-sm">
                  <PlusCircle className="h-4 w-4" />
                  Register Renter / Temporary Homeowner
                </Button>
              </RenterForm>
            </div>
          )}
        </div>

        {/* Security and Auto-Expiration Banner */}
        <div className="rounded-xl border border-primary/20 bg-primary/5 p-4 flex items-start gap-3">
          <ShieldCheck className="h-5 w-5 text-primary mt-0.5 shrink-0" />
          <div className="text-xs sm:text-sm text-muted-foreground space-y-1">
            <p className="font-medium text-foreground">
              Automatic Time-Restricted Access Control
            </p>
            <p>
              Temporary Homeowners are granted dashboard access strictly between their start and end dates. Once the expiration date passes, their login credentials and visitor permissions expire automatically. Temporary occupants cannot modify property settings or register other occupants.
            </p>
          </div>
        </div>

        {/* Renters Table Card */}
        <Card className="shadow-sm">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-lg">Registered Occupants</CardTitle>
                <CardDescription>
                  {renters.total} total {renters.total === 1 ? 'occupant' : 'occupants'} associated with your property.
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/40 hover:bg-muted/40">
                  <TableHead>Occupant</TableHead>
                  <TableHead>Stay Category</TableHead>
                  <TableHead>Access Status</TableHead>
                  <TableHead>Stay Timeframe</TableHead>
                  <TableHead className="hidden lg:table-cell">Contact &amp; Notes</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {renterList.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-12 text-muted-foreground">
                      <Home className="h-10 w-10 mx-auto text-muted-foreground/40 mb-3" />
                      <p className="font-medium text-foreground">No temporary homeowners registered</p>
                      <p className="text-sm mt-1">
                        {canRegister
                          ? 'Click "Register Renter / Temporary Homeowner" above to add a long-term renter or short-term Airbnb guest.'
                          : 'Renters and short-term guests are registered by the estate office. Contact them to add one to your property.'}
                      </p>
                    </TableCell>
                  </TableRow>
                ) : (
                  renterList.map((renter) => {
                    const start = typeof renter.leaseStart === 'string' ? parseISO(renter.leaseStart) : renter.leaseStart;
                    const end = typeof renter.leaseEnd === 'string' ? parseISO(renter.leaseEnd) : renter.leaseEnd;
                    const daysRemaining = differenceInDays(end, new Date());
                    const isExpired = renter.expired || daysRemaining < 0;

                    return (
                      <TableRow key={renter.id} className={isExpired ? 'opacity-70 bg-muted/20' : undefined}>
                        <TableCell>
                          <div className="font-semibold text-foreground">{renter.name}</div>
                          <div className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                            <Home className="h-3 w-3" />
                            {[renter.lot, renter.street].filter(Boolean).join(', ') || 'Address not recorded'}
                            {!isHomeowner && renter.homeownerName && <span>· {renter.homeownerName}</span>}
                          </div>
                        </TableCell>

                        <TableCell>
                          <Badge
                            variant="secondary"
                            className={
                              renter.stayType === 'Short-term (Airbnb)'
                                ? 'bg-rose-500/10 text-rose-700 dark:text-rose-400 border-rose-500/20'
                                : 'bg-blue-500/10 text-blue-700 dark:text-blue-400 border-blue-500/20'
                            }
                          >
                            {renter.stayType || 'Long-term (Renter)'}
                          </Badge>
                        </TableCell>

                        <TableCell>
                          {isExpired ? (
                            <Badge variant="outline" className="text-amber-600 border-amber-500/40 bg-amber-500/10 gap-1">
                              <Clock className="h-3 w-3" />
                              Expired Access
                            </Badge>
                          ) : renter.status === 'Active' ? (
                            <Badge className="bg-emerald-600 hover:bg-emerald-700 text-white gap-1">
                              <ShieldCheck className="h-3 w-3" />
                              Active
                            </Badge>
                          ) : (
                            <Badge variant="outline">{renter.status}</Badge>
                          )}
                        </TableCell>

                        <TableCell>
                          <div className="text-sm font-medium flex items-center gap-1.5">
                            <CalendarIcon className="h-3.5 w-3.5 text-muted-foreground" />
                            <ClientFormattedDate date={renter.leaseStart} formatString="MMM d, yyyy" />
                            <span className="text-muted-foreground">–</span>
                            <ClientFormattedDate date={renter.leaseEnd} formatString="MMM d, yyyy" />
                          </div>
                          <div className="text-xs text-muted-foreground mt-0.5">
                            {isExpired ? (
                              <span className="text-amber-600 dark:text-amber-400 font-medium">
                                Timeframe ended ({Math.abs(daysRemaining)} days ago)
                              </span>
                            ) : (
                              <span>{daysRemaining} days remaining</span>
                            )}
                          </div>
                        </TableCell>

                        <TableCell className="hidden lg:table-cell max-w-xs">
                          {renter.contact && (
                            <div className="text-xs font-medium text-foreground">{renter.contact}</div>
                          )}
                          {renter.notes ? (
                            <div className="text-xs text-muted-foreground truncate" title={renter.notes}>
                              {renter.notes}
                            </div>
                          ) : (
                            <span className="text-xs text-muted-foreground/60">—</span>
                          )}
                        </TableCell>

                        <TableCell className="text-right">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button variant="ghost" size="icon" className="h-8 w-8">
                                <MoreHorizontal className="h-4 w-4" />
                                <span className="sr-only">Actions for {renter.name}</span>
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuLabel>Manage Occupant</DropdownMenuLabel>
                              <DropdownMenuItem onClick={() => setEditingRenter(renter)}>
                                <Edit className="h-4 w-4 mr-2" />
                                Edit Timeframe &amp; Info
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                className="text-destructive focus:text-destructive"
                                onClick={() => setDeletingRenter(renter)}
                              >
                                <Trash2 className="h-4 w-4 mr-2" />
                                Remove Occupant
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Pagination */}
        {renters.links && renters.links.length > 3 && (
          <nav className="flex flex-wrap items-center justify-center gap-1 pt-2" aria-label="Pagination">
            {renters.links.map((link, index) => (
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

      {/* Edit Form Dialog */}
      <RenterForm
        renter={editingRenter}
        open={Boolean(editingRenter)}
        onOpenChange={(open) => !open && setEditingRenter(null)}
      />

      {/* Delete Confirmation Dialog */}
      <AlertDialog
        open={Boolean(deletingRenter)}
        onOpenChange={(open) => !open && setDeletingRenter(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove Temporary Homeowner?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to remove <strong>{deletingRenter?.name}</strong>? Their temporary access and permissions to manage visitors under your property profile will be revoked immediately.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={handleDeleteConfirm}
            >
              Remove Occupant
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
