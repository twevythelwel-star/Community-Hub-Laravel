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
import { MoreHorizontal, PlusCircle, Search, Scan, Filter, Layers, ShieldOff, FileDown } from 'lucide-react';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { StaffGatePassDialog } from '@/components/dashboard/staff-gate-pass-dialog';
import { StaffForm, type StaffFormValues } from '@/components/dashboard/staff-form';
import { useToast } from '@/hooks/use-toast';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { GatePassDisplay, type OwnPass } from '@/components/dashboard/gate-pass-display';
import { GatePassCard } from '@/components/dashboard/gate-pass-card';
import { GateScannerDialog } from '@/components/dashboard/gate-scanner-dialog';
import type { PassCategory, GateId } from '@/lib/gate-pass-engine/types';
import {
  hydrateCategoryConfigs,
  getCategoryConfig,
  PASS_CATEGORIES,
  type AccessPolicy,
  type CategoryVisualConfig,
  type ColorVariant,
} from '@/lib/gate-pass-engine/config';
import { CategoryShapeIcon } from '@/lib/gate-pass-engine/shapes';

type DirectoryEntry = {
  id: number;
  passId: string;
  category: PassCategory;
  userName: string;
  role: string;
  property: string;
  gate: GateId;
  status: 'ACTIVE' | 'REVOKED';
  colorVariant: ColorVariant;
};

type StaffRow = {
  id: number;
  name: string;
  job: string;
  idType: string;
  idExpiry: string;
  property: string;
  status: 'Active' | 'Inactive' | 'Expired ID';
  photoUrl: string | null;
  category: PassCategory;
};

type Props = {
  pass: OwnPass;
  visual: { variant: ColorVariant; shape: string };
  policy: AccessPolicy;
  categoryConfigs: Record<PassCategory, CategoryVisualConfig>;
  directory: DirectoryEntry[];
  staff: StaffRow[];
  windowSeconds: number;
  gates: Record<string, string>;
  can: { scan: boolean; manageSecurity: boolean; manageUsers: boolean };
};

function statusVariant(status: StaffRow['status']) {
  switch (status) {
    case 'Active':
      return 'default';
    case 'Inactive':
      return 'secondary';
    case 'Expired ID':
      return 'destructive';
    default:
      return 'outline';
  }
}

export default function GatePassPage({
  pass,
  visual,
  policy,
  categoryConfigs,
  directory,
  staff,
  windowSeconds,
  can,
}: Props) {
  const { toast } = useToast();

  // Load the server's visual config before any pass UI renders. This replaces
  // the CATEGORY_CONFIGS constant that used to live in the bundled engine.
  hydrateCategoryConfigs(categoryConfigs);

  const [scannerOpen, setScannerOpen] = useState(false);
  const [scannerInitialToken, setScannerInitialToken] = useState<string | undefined>();
  const [viewingPass, setViewingPass] = useState<DirectoryEntry | null>(null);
  const [selectedStaff, setSelectedStaff] = useState<StaffRow | null>(null);
  const [staffToEdit, setStaffToEdit] = useState<StaffRow | undefined>();
  const [isFormOpen, setFormOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [categoryFilter, setCategoryFilter] = useState<'ALL' | PassCategory>('ALL');

  const filteredDirectory = useMemo(() => {
    const needle = searchTerm.trim().toLowerCase();

    return directory.filter((entry) => {
      const matchesSearch =
        !needle ||
        entry.userName.toLowerCase().includes(needle) ||
        entry.property.toLowerCase().includes(needle) ||
        entry.passId.toLowerCase().includes(needle);

      const matchesCategory = categoryFilter === 'ALL' || entry.category === categoryFilter;

      return matchesSearch && matchesCategory;
    });
  }, [directory, searchTerm, categoryFilter]);

  const openScanner = (token?: string) => {
    setScannerInitialToken(token);
    setScannerOpen(true);
  };

  const handleSaveStaff = (values: StaffFormValues, id?: number | string) => {
    // The form speaks camelCase; DirectoryController validates snake_case.
    const payload = {
      name: values.name,
      job: values.job,
      property: values.property,
      status: values.status,
      id_type: values.idType,
      id_number: values.idNumber,
      id_expiry: values.idExpiry instanceof Date
        ? values.idExpiry.toISOString().slice(0, 10)
        : values.idExpiry,
      photo_url: values.photoUrl || null,
    };

    const options = {
      preserveScroll: true,
      onSuccess: () => setFormOpen(false),
      onError: () =>
        toast({
          variant: 'destructive',
          title: 'Could not save staff member',
          description: 'Check the highlighted fields and try again.',
        }),
    };

    if (id) {
      router.patch(`/dashboard/directory/staff/${id}`, payload, options);
    } else {
      router.post('/dashboard/directory/staff', payload, options);
    }
  };

  const handleRevokeStaffAccess = (row: StaffRow) => {
    router.patch(
      `/dashboard/directory/staff/${row.id}`,
      { status: 'Inactive' },
      {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            variant: 'destructive',
            title: 'Access Revoked',
            description: `${row.name} is now inactive and will be refused at the gate.`,
          }),
      },
    );
  };

  const handleRevokePass = (entry: DirectoryEntry) => {
    const reason = window.prompt(`Reason for revoking ${entry.passId}?`);

    if (!reason) return;

    router.post(
      `/dashboard/gate-pass/${entry.id}/revoke`,
      { reason },
      {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            variant: 'destructive',
            title: 'Pass Revoked',
            description: `${entry.passId} will be denied at every gate from the next scan.`,
          }),
      },
    );
  };

  return (
    <DashboardLayout>
      <Head title="Digital Gate Pass" />

      <div className="grid gap-8 pb-12">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="font-headline text-3xl font-bold tracking-tight">
              Digital Gate Pass Visual Identity Engine
            </h1>
            <p className="text-muted-foreground text-sm">
              {can.manageSecurity
                ? 'Profile shape and approved colour credentials, verified against the server-side cryptographic engine.'
                : 'Your secure dynamic gate pass for community entrance and amenity clearance.'}
            </p>
          </div>

          {can.scan && (
            <Button
              onClick={() => openScanner()}
              className="gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow-md self-start sm:self-auto"
            >
              <Scan className="w-4 h-4" />
              <span>Gate Scanner Console</span>
            </Button>
          )}
        </div>

        {/* The viewer's own pass — the only card that polls for a live token. */}
        <div className="flex flex-col items-center justify-center gap-3">
          <div className="w-full max-w-sm">
            <GatePassDisplay
              pass={pass}
              variant={visual.variant}
              policy={policy}
              windowSeconds={windowSeconds}
              canScan={can.scan}
            />
          </div>
          <Button asChild variant="outline" size="sm" className="gap-2 shadow-sm text-xs">
            <a href={`/dashboard/gate-pass/pdf/${pass.id}`} target="_blank" rel="noopener noreferrer">
              <FileDown className="w-3.5 h-3.5" />
              Download Vehicle Permit (PDF)
            </a>
          </Button>
        </div>

        {can.manageSecurity && (
          <>
            {/* Preview of another holder's pass. No live token is issued here:
                the token endpoint only ever serves the signed-in user. */}
            {viewingPass && (
              <Dialog open={!!viewingPass} onOpenChange={(open) => !open && setViewingPass(null)}>
                <DialogContent className="sm:max-w-md p-0 overflow-hidden border-0 bg-transparent shadow-none">
                  <GatePassCard
                    category={viewingPass.category}
                    userName={viewingPass.userName}
                    property={viewingPass.property}
                    passId={viewingPass.passId}
                    gate={viewingPass.gate}
                    status={viewingPass.status}
                    initialColorVariant={viewingPass.colorVariant}
                    windowSeconds={windowSeconds}
                    isOwnPass={false}
                  />
                </DialogContent>
              </Dialog>
            )}

            {selectedStaff && (
              <StaffGatePassDialog
                staff={selectedStaff}
                open={!!selectedStaff}
                onOpenChange={() => setSelectedStaff(null)}
              />
            )}

            <Tabs defaultValue="directory" className="w-full space-y-6">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <TabsList className="grid w-full sm:w-80 grid-cols-2">
                  <TabsTrigger value="directory">Identity Directory</TabsTrigger>
                  <TabsTrigger value="staff">Staff Personnel</TabsTrigger>
                </TabsList>
              </div>

              {/* ── Identity Directory ── */}
              <TabsContent value="directory" className="space-y-4">
                <Card>
                  <CardHeader className="pb-3">
                    <div>
                      <CardTitle className="text-xl font-bold flex items-center gap-2">
                        <Layers className="w-5 h-5 text-primary" />
                        Gate Pass Identity Directory
                      </CardTitle>
                      <CardDescription className="text-xs">
                        Issued credentials grouped by profile shape and clearance class.
                        {directory.length > 0 && ` ${directory.length} registered.`}
                      </CardDescription>
                    </div>

                    <div className="flex items-center gap-1.5 flex-wrap pt-3 border-t">
                      <span className="text-[11px] font-mono font-bold text-muted-foreground mr-1 flex items-center gap-1">
                        <Filter className="w-3 h-3" />
                        CATEGORY:
                      </span>

                      <button
                        type="button"
                        onClick={() => setCategoryFilter('ALL')}
                        className={cn(
                          'px-2.5 py-1 rounded-full text-xs font-semibold transition-colors border',
                          categoryFilter === 'ALL'
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'bg-muted text-muted-foreground border-transparent hover:text-foreground',
                        )}
                      >
                        All ({PASS_CATEGORIES.length} Categories)
                      </button>

                      {PASS_CATEGORIES.map((cat) => {
                        const conf = getCategoryConfig(cat);
                        const isSelected = categoryFilter === cat;

                        return (
                          <button
                            key={cat}
                            type="button"
                            onClick={() => setCategoryFilter(cat)}
                            className={cn(
                              'px-2.5 py-1 rounded-full text-xs font-semibold transition-colors border flex items-center gap-1.5',
                              isSelected
                                ? 'bg-primary text-primary-foreground border-primary shadow-xs'
                                : 'bg-muted/40 border-border text-muted-foreground hover:text-foreground hover:bg-muted',
                            )}
                          >
                            <CategoryShapeIcon
                              shape={conf.shape}
                              className="w-3 h-3"
                              color={isSelected ? 'white' : conf.themeColor}
                            />
                            <span>
                              {conf.shapeLabel} • {cat.replace('_', ' ')}
                            </span>
                          </button>
                        );
                      })}
                    </div>
                  </CardHeader>

                  <CardContent className="space-y-4">
                    <div className="relative">
                      <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                      <Input
                        placeholder="Search by name, property (e.g. 'Lot 42'), or Pass ID..."
                        className="pl-8 text-xs bg-muted/20"
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        aria-label="Search the gate pass directory"
                      />
                    </div>

                    {filteredDirectory.length === 0 ? (
                      <p className="py-8 text-center text-sm text-muted-foreground">
                        No passes match the current filters.
                      </p>
                    ) : (
                      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {filteredDirectory.map((entry) => {
                          const conf = getCategoryConfig(entry.category);
                          const activeColor = entry.colorVariant?.hex || conf.themeColor;

                          return (
                            <Card
                              key={entry.id}
                              className={cn(
                                'overflow-hidden border hover:border-primary/50 transition-all duration-200 shadow-sm flex flex-col justify-between',
                                entry.status === 'REVOKED' && 'opacity-60 bg-red-500/5 border-red-500/30',
                              )}
                            >
                              <div className="p-4 space-y-3">
                                <div className="flex items-center justify-between">
                                  <div className="flex items-center gap-2">
                                    <div
                                      className="w-7 h-7 rounded-lg flex items-center justify-center text-white shadow-xs border border-white/20"
                                      style={{ backgroundColor: activeColor }}
                                    >
                                      <CategoryShapeIcon shape={conf.shape} className="w-4 h-4" />
                                    </div>
                                    <div>
                                      <span className="font-mono text-[10px] uppercase font-bold text-muted-foreground block leading-tight">
                                        {conf.shape} • {entry.category.replace('_', ' ')}
                                      </span>
                                      <span className="font-mono text-xs font-bold text-foreground">
                                        {entry.passId}
                                      </span>
                                    </div>
                                  </div>

                                  <Badge
                                    variant="outline"
                                    className={cn(
                                      'text-[10px] font-bold',
                                      entry.status === 'ACTIVE'
                                        ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                                        : 'border-red-500/40 text-red-600 bg-red-500/10',
                                    )}
                                  >
                                    {entry.status}
                                  </Badge>
                                </div>

                                <div>
                                  <h4 className="font-bold text-sm text-foreground">{entry.userName}</h4>
                                  <p className="text-xs text-muted-foreground mt-0.5">{entry.role}</p>
                                  <p className="text-xs font-medium text-foreground/80 mt-1">{entry.property}</p>

                                  <div className="flex items-center gap-1.5 mt-2.5 pt-2 border-t text-[11px] font-mono text-muted-foreground">
                                    <span
                                      className="w-2.5 h-2.5 rounded-full inline-block border border-black/10 shrink-0 shadow-xs"
                                      style={{ backgroundColor: activeColor }}
                                    />
                                    <span className="font-semibold text-foreground">
                                      {entry.colorVariant?.name}
                                    </span>
                                    <span>•</span>
                                    <span className="text-emerald-600 font-medium">
                                      WCAG {entry.colorVariant?.contrastRatio}:1
                                    </span>
                                  </div>
                                </div>
                              </div>

                              <div className="p-3 pt-0 flex items-center gap-2 border-t bg-muted/15">
                                <Button
                                  variant="outline"
                                  size="sm"
                                  onClick={() => setViewingPass(entry)}
                                  className="flex-1 text-xs h-8 font-semibold"
                                >
                                  View Pass
                                </Button>

                                {entry.status === 'ACTIVE' && (
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => handleRevokePass(entry)}
                                    className="h-8 text-xs font-semibold gap-1 text-destructive hover:bg-destructive/10"
                                    title="Revoke this pass"
                                  >
                                    <ShieldOff className="w-3.5 h-3.5" />
                                    <span>Revoke</span>
                                  </Button>
                                )}
                              </div>
                            </Card>
                          );
                        })}
                      </div>
                    )}
                  </CardContent>
                </Card>
              </TabsContent>

              {/* ── Staff Personnel ── */}
              <TabsContent value="staff">
                <Card>
                  <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                      <CardTitle className="text-xl font-bold">Registered Staff Personnel</CardTitle>
                      <CardDescription className="text-xs">
                        Housekeeping, maintenance and domestic staff carrying diamond or house-hex identity tokens.
                      </CardDescription>
                    </div>

                    {can.manageUsers && (
                      <StaffForm
                        open={isFormOpen}
                        onOpenChange={setFormOpen}
                        onSave={handleSaveStaff}
                        staff={staffToEdit}
                      >
                        <Button
                          size="sm"
                          className="gap-1.5"
                          onClick={() => {
                            setStaffToEdit(undefined);
                            setFormOpen(true);
                          }}
                        >
                          <PlusCircle className="h-4 w-4" />
                          <span>Add Staff Member</span>
                        </Button>
                      </StaffForm>
                    )}
                  </CardHeader>

                  <CardContent>
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Staff Member</TableHead>
                          <TableHead>Role &amp; Category</TableHead>
                          <TableHead>Assigned Property</TableHead>
                          <TableHead>ID Expiry</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                      </TableHeader>

                      <TableBody>
                        {staff.length === 0 && (
                          <TableRow>
                            <TableCell colSpan={6} className="py-8 text-center text-sm text-muted-foreground">
                              No staff registered yet.
                            </TableCell>
                          </TableRow>
                        )}

                        {staff.map((row) => {
                          // Category comes from the server, which knows whether the
                          // member is attached to a residence. The old page guessed
                          // from a substring of the addedBy id.
                          const conf = getCategoryConfig(row.category);

                          return (
                            <TableRow key={row.id}>
                              <TableCell className="font-bold text-foreground">{row.name}</TableCell>
                              <TableCell>
                                <div className="flex items-center gap-1.5">
                                  <CategoryShapeIcon
                                    shape={conf.shape}
                                    className="w-3.5 h-3.5"
                                    color={conf.themeColor}
                                  />
                                  <span className="text-xs font-semibold">{row.job}</span>
                                  <span className="text-[10px] text-muted-foreground font-mono">
                                    ({conf.shape})
                                  </span>
                                </div>
                              </TableCell>
                              <TableCell className="text-xs text-muted-foreground">{row.property}</TableCell>
                              <TableCell className="text-xs font-mono">
                                <ClientFormattedDate date={row.idExpiry} formatString="MMM d, yyyy" />
                              </TableCell>
                              <TableCell>
                                <Badge variant={statusVariant(row.status)}>{row.status}</Badge>
                              </TableCell>
                              <TableCell className="text-right">
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <Button size="icon" variant="ghost" className="h-8 w-8">
                                      <MoreHorizontal className="h-4 w-4" />
                                      <span className="sr-only">Staff actions</span>
                                    </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                    <DropdownMenuItem onClick={() => setSelectedStaff(row)}>
                                      View Pass
                                    </DropdownMenuItem>
                                    {can.manageUsers && (
                                      <>
                                        <DropdownMenuItem
                                          onClick={() => {
                                            setStaffToEdit(row);
                                            setFormOpen(true);
                                          }}
                                        >
                                          Edit Details
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                          className="text-destructive"
                                          onClick={() => handleRevokeStaffAccess(row)}
                                          disabled={row.status === 'Inactive'}
                                        >
                                          Revoke Access
                                        </DropdownMenuItem>
                                      </>
                                    )}
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              </TableCell>
                            </TableRow>
                          );
                        })}
                      </TableBody>
                    </Table>
                  </CardContent>
                </Card>
              </TabsContent>
            </Tabs>
          </>
        )}

        {can.scan && (
          <GateScannerDialog
            open={scannerOpen}
            onOpenChange={setScannerOpen}
            initialToken={scannerInitialToken}
          />
        )}
      </div>
    </DashboardLayout>
  );
}
