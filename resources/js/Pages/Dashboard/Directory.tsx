import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAuth } from '@/context/auth-context';
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion';
import { MoreHorizontal, PlusCircle, Edit, Search, MapPin, Mail, ShieldAlert, UserMinus, UserCheck, Home } from 'lucide-react';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
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
import {
  CreateUserForm,
  type DirectoryUser,
  type UserFormValues,
} from '@/components/dashboard/create-user-form';
import { useToast } from '@/hooks/use-toast';
import { ManagePropertiesDialog } from '@/components/dashboard/manage-properties-dialog';

/**
 * Community Directory.
 *
 * Was 175 lines of mock users in `useState` with create, edit, status toggle
 * and delete all mutating that array and raising a toast.
 *
 * Two things are deliberately different:
 *
 *   - **Delete is now Deactivate.** The old menu item offered a permanent
 *     delete behind a confirmation dialog, with no endpoint behind it. Hard
 *     deletion would cascade through a person's visitors, warning responses and
 *     activity log — the access history a gated community exists to keep. The
 *     app already models this properly as `status` + `deactivated_at`, and
 *     `EnsureUserIsActive` logs a deactivated account out on its next request.
 *   - **Permission comes from the server.** `canManageDirectory` was
 *     `user?.role === 'System Admin' || user?.role === 'Admin'` in JSX, and a
 *     hardcoded `ROOT_SYS_ADMIN_EMAILS` list guarded the seeded admin accounts
 *     by email address. Both are gone: the page reads `canManageUsers` from the
 *     `manageUsers` gate, and `DirectoryController::updateUser()` refuses to let
 *     anyone but a System Admin touch a System Admin.
 */

type Props = {
  residents: DirectoryUser[];
  canManageUsers: boolean;
  roles: string[];
};

// Staff are registered against a property on the Gate Pass page, not community
// accounts, so they were never listed here.
const ROLE_ORDER = [
  'System Admin',
  'Admin',
  'Homeowner',
  'Temporary Homeowner',
  'Security',
] as const;

function initials(name: string): string {
  if (!name) return 'U';
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
  return name.slice(0, 2).toUpperCase();
}

function roleAccent(role: string): string {
  switch (role) {
    case 'System Admin':
      return 'border-purple-500/30 text-purple-600 dark:text-purple-400 bg-purple-500/10';
    case 'Admin':
      return 'border-sky-500/30 text-sky-600 dark:text-sky-400 bg-sky-500/10';
    case 'Security':
      return 'border-amber-500/30 text-amber-600 dark:text-amber-400 bg-amber-500/10';
    case 'Homeowner':
      return 'border-emerald-500/30 text-emerald-600 dark:text-emerald-400 bg-emerald-500/10';
    case 'Temporary Homeowner':
      return 'border-blue-500/30 text-blue-600 dark:text-blue-400 bg-blue-500/10';
    default:
      return 'border-muted text-muted-foreground bg-muted/40';
  }
}

export default function DirectoryPage({ residents, canManageUsers }: Props) {
  const { user } = useAuth();
  const { toast } = useToast();

  const [isFormOpen, setFormOpen] = useState(false);
  const [selected, setSelected] = useState<DirectoryUser | undefined>();
  const [statusTarget, setStatusTarget] = useState<DirectoryUser | null>(null);
  // An id, not the person: looked up in `residents` so the list refreshes after each change.
  const [propertiesFor, setPropertiesFor] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [query, setQuery] = useState('');

  const isSystemAdmin = user?.role === 'System Admin';

  const openForm = (target?: DirectoryUser) => {
    setSelected(target);
    setFormOpen(true);
  };

  const closeForm = () => {
    setSelected(undefined);
    setFormOpen(false);
  };

  /**
   * Mirrors the server: an Admin may not modify an Admin or a System Admin, and
   * nobody manages their own account from here. `updateUser` enforces the same
   * rules, so this only decides whether the control is offered.
   */
  const canManagePerson = (person: DirectoryUser): boolean => {
    if (!canManageUsers || person.isSelf) return false;
    if (isSystemAdmin) return true;
    return person.role !== 'Admin' && person.role !== 'System Admin';
  };

  const save = (values: UserFormValues, id?: number) => {
    setSubmitting(true);

    const done = {
      preserveScroll: true,
      onSuccess: () => {
        closeForm();
        toast({
          title: id ? 'User updated' : 'User created',
          description: id
            ? `${values.name}'s profile has been updated.`
            : `${values.name} has been added as a new ${values.role}.`,
        });
      },
      onError: (errors: Record<string, string>) => {
        toast({
          variant: 'destructive',
          title: id ? 'Could not update user' : 'Could not create user',
          description: Object.values(errors)[0] ?? 'Please check the form and try again.',
        });
      },
      onFinish: () => setSubmitting(false),
    };

    if (id) {
      // updateUser takes display_name and never touches credentials or email.
      router.patch(
        `/dashboard/directory/users/${id}`,
        {
          display_name: values.name,
          role: values.role,
          status: values.status,
          lot: values.lotNumber || null,
          street: values.streetName || null,
        },
        done,
      );
      return;
    }

    router.post(
      '/dashboard/directory/users',
      {
        name: values.name,
        email: values.email,
        role: values.role,
        lot: values.lotNumber || null,
        street: values.streetName || null,
        password: values.password,
        password_confirmation: values.passwordConfirmation,
      },
      done,
    );
  };

  const setStatus = (person: DirectoryUser, status: 'Active' | 'Inactive') => {
    router.patch(
      `/dashboard/directory/users/${person.id}`,
      { status },
      {
        preserveScroll: true,
        onSuccess: () => {
          setStatusTarget(null);
          toast({
            title: status === 'Inactive' ? 'Account deactivated' : 'Account reactivated',
            description:
              status === 'Inactive'
                ? `${person.name} has been signed out and can no longer access the community.`
                : `${person.name} can sign in again.`,
          });
        },
      },
    );
  };

  const filtered = useMemo(() => {
    const q = query.toLowerCase().trim();
    if (!q) return residents;

    return residents.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        (p.email ?? '').toLowerCase().includes(q) ||
        (p.lot ?? '').toLowerCase().includes(q) ||
        (p.street ?? '').toLowerCase().includes(q),
    );
  }, [residents, query]);

  const grouped = useMemo(() => {
    const acc: Record<string, DirectoryUser[]> = {};
    for (const person of filtered) {
      if (!ROLE_ORDER.includes(person.role as (typeof ROLE_ORDER)[number])) continue;
      (acc[person.role] ??= []).push(person);
    }
    return acc;
  }, [filtered]);

  const visibleRoles = ROLE_ORDER.filter((role) => grouped[role]?.length);

  /*
   * Unreachable in normal use — the route carries `can:manageUsers`. Kept as a
   * visible failure rather than a blank screen if that gate is ever relaxed.
   */
  if (!canManageUsers) {
    return (
      <DashboardLayout>
        <Head title="Community Directory" />
        <Card className="max-w-2xl mx-auto border-border/80">
          <CardHeader>
            <div className="flex items-center gap-2 text-destructive">
              <ShieldAlert className="h-5 w-5" />
              <CardTitle>Access Denied</CardTitle>
            </div>
            <CardDescription>You do not have permission to view this page.</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">
              Only authorized community administrators can view and manage the resident directory.
            </p>
          </CardContent>
        </Card>
      </DashboardLayout>
    );
  }

  return (
    <DashboardLayout>
      <Head title="Community Directory" />

      <div className="grid gap-8 max-w-7xl mx-auto">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="font-headline text-3xl font-bold tracking-tight">Community Directory</h1>
            <p className="text-muted-foreground">
              View, search, and manage registered profiles across the community.
            </p>
          </div>
          <div className="flex items-center gap-3">
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search by name, email, lot..."
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                className="pl-8 h-9 text-xs"
              />
            </div>
            <CreateUserForm
              open={isFormOpen}
              onOpenChange={(open) => (open ? setFormOpen(true) : closeForm())}
              onSave={save}
              userToEdit={selected}
              submitting={submitting}
            >
              <Button size="sm" className="gap-1.5 h-9" onClick={() => openForm()}>
                <PlusCircle className="h-4 w-4" />
                <span>Add Member</span>
              </Button>
            </CreateUserForm>
          </div>
        </div>

        <Card className="border-border/80 shadow-sm">
          <CardHeader className="pb-4">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-lg">Registered Member Profiles</CardTitle>
                <CardDescription>
                  Profiles categorized by administrative and residential role.
                </CardDescription>
              </div>
              <Badge variant="outline" className="text-xs">
                {filtered.length} {filtered.length === 1 ? 'Profile' : 'Profiles'} Listed
              </Badge>
            </div>
          </CardHeader>

          <CardContent>
            {visibleRoles.length === 0 && (
              <p className="py-10 text-center text-sm text-muted-foreground">
                {query ? `No profiles match “${query}”.` : 'No profiles yet.'}
              </p>
            )}

            <Accordion type="multiple" defaultValue={[...ROLE_ORDER]} className="w-full space-y-4">
              {visibleRoles.map((role) => (
                <AccordionItem
                  value={role}
                  key={role}
                  className="border border-border/50 rounded-lg overflow-hidden"
                >
                  <AccordionTrigger className="bg-muted/40 hover:bg-muted/70 px-4 py-3 text-base font-semibold transition-colors">
                    <div className="flex items-center gap-2">
                      <span>{role}s</span>
                      <Badge variant="secondary" className="text-xs px-2 py-0 h-5">
                        {grouped[role].length}
                      </Badge>
                    </div>
                  </AccordionTrigger>

                  <AccordionContent className="p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {grouped[role].map((person) => {
                      const manageable = canManagePerson(person);

                      return (
                        <Card
                          key={person.id}
                          className="border-border/70 shadow-none hover:border-primary/40 transition-colors"
                        >
                          <CardHeader className="flex-row gap-3.5 items-center !pb-2">
                            <Avatar className={`h-11 w-11 border-2 ${roleAccent(person.role)}`}>
                              <AvatarFallback className="font-bold text-xs">
                                {initials(person.name)}
                              </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0 flex-1">
                              <p className="font-semibold text-sm truncate">{person.name}</p>
                              {person.email && (
                                <div className="flex items-center gap-1 text-xs text-muted-foreground truncate">
                                  <Mail className="w-3 h-3 shrink-0" />
                                  <span className="truncate">{person.email}</span>
                                </div>
                              )}
                              <Badge
                                className="mt-1.5 text-[10px] py-0 h-4"
                                variant={person.status === 'Active' ? 'secondary' : 'outline'}
                              >
                                {person.status}
                              </Badge>
                            </div>
                          </CardHeader>

                          <CardContent className="pt-2 text-xs">
                            {(person.lot || person.street) && (
                              <div className="flex items-center gap-1.5 text-muted-foreground mb-3 bg-muted/30 p-1.5 rounded text-[11px]">
                                <MapPin className="w-3.5 h-3.5 shrink-0 text-primary/70" />
                                <span className="truncate">
                                  {[person.lot, person.street].filter(Boolean).join(', ')}
                                </span>
                                {(person.properties?.length ?? 0) > 1 && (
                                  <Badge variant="secondary" className="ml-auto shrink-0 text-[10px] py-0 h-4">
                                    {person.properties!.length} properties
                                  </Badge>
                                )}
                              </div>
                            )}

                            {manageable ? (
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full text-xs h-8"
                                  >
                                    <MoreHorizontal className="h-3.5 w-3.5 mr-1.5" />
                                    Manage Account
                                  </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-48">
                                  <DropdownMenuLabel className="text-xs">
                                    Profile Actions
                                  </DropdownMenuLabel>
                                  <DropdownMenuItem
                                    onClick={() => openForm(person)}
                                    className="text-xs"
                                  >
                                    <Edit className="mr-2 h-3.5 w-3.5" /> Edit Details
                                  </DropdownMenuItem>
                                  {['Homeowner', 'Admin', 'System Admin'].includes(person.role) && (
                                    <DropdownMenuItem
                                      onClick={() => setPropertiesFor(person.id)}
                                      className="text-xs"
                                    >
                                      <Home className="mr-2 h-3.5 w-3.5" /> Manage Properties
                                    </DropdownMenuItem>
                                  )}
                                  <DropdownMenuSeparator />
                                  {person.status === 'Active' ? (
                                    <DropdownMenuItem
                                      onClick={() => setStatusTarget(person)}
                                      className="text-destructive text-xs"
                                    >
                                      <UserMinus className="mr-2 h-3.5 w-3.5" /> Deactivate Account
                                    </DropdownMenuItem>
                                  ) : (
                                    <DropdownMenuItem
                                      onClick={() => setStatus(person, 'Active')}
                                      className="text-xs"
                                    >
                                      <UserCheck className="mr-2 h-3.5 w-3.5" /> Reactivate Account
                                    </DropdownMenuItem>
                                  )}
                                </DropdownMenuContent>
                              </DropdownMenu>
                            ) : (
                              <Button
                                variant="outline"
                                size="sm"
                                className="w-full text-xs h-8 text-muted-foreground opacity-70"
                                disabled
                                title={
                                  person.isSelf
                                    ? 'You cannot manage your own profile here.'
                                    : 'You do not have permission to manage this member.'
                                }
                              >
                                {person.isSelf ? 'Your Account' : 'Protected'}
                              </Button>
                            )}
                          </CardContent>
                        </Card>
                      );
                    })}
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          </CardContent>
        </Card>
      </div>

      <ManagePropertiesDialog
        person={residents.find((p) => p.id === propertiesFor) ?? null}
        onOpenChange={(open) => !open && setPropertiesFor(null)}
      />

      <AlertDialog
        open={statusTarget !== null}
        onOpenChange={(open) => !open && setStatusTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Deactivate this account?</AlertDialogTitle>
            <AlertDialogDescription>
              &ldquo;{statusTarget?.name}&rdquo; will be signed out and refused entry at the gate,
              and their gate pass stops validating. Their history stays on record and you can
              reactivate them at any time.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                if (statusTarget) setStatus(statusTarget, 'Inactive');
              }}
            >
              Deactivate
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </DashboardLayout>
  );
}
