import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  HeartHandshake,
  UserCheck,
  Shield,
  Clock,
  QrCode,
  KeyRound,
  Home,
  User,
  Users,
  AlertTriangle,
  CheckCircle2,
  Phone,
  Mail,
  Calendar,
  CreditCard,
  Building,
  Edit,
  Trash2,
  Copy,
  Check,
  ExternalLink,
  Wallet,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';
import { ProfileCustomQRCode } from '@/components/dashboard/ProfileCustomQRCode';
import type { PassCategory } from '@/lib/gate-pass-engine/types';

type MemberGatePass = {
  id: number;
  pass_id: string;
  category: string;
  status: string;
  holder_name: string;
  token?: string | null;
};

type HouseholdMember = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  role_in_household: 'homeowner' | 'spouse' | 'child' | 'long_term_occupant' | 'caregiver' | 'other';
  relationship_label: string;
  pass_category: string;
  credential_label: string;
  role_badge_class: string;
  permissions: string[];
  access_schedule: Record<string, any> | null;
  status: 'active' | 'suspended' | 'expired';
  valid_until: string | null;
  gate_pass: MemberGatePass | null;
};

type HouseholdData = {
  id: number;
  name: string;
  property_number: string;
  address: string | null;
  notes: string | null;
  members: HouseholdMember[];
};

type PermissionDefinition = {
  label: string;
  description: string;
};

type Props = {
  household: HouseholdData;
  availablePermissions: Record<string, PermissionDefinition>;
  isOwner: boolean;
};

export default function HouseholdPage({
  household,
  availablePermissions,
  isOwner,
}: Props) {
  const { toast } = useToast();

  const [addModalOpen, setAddModalOpen] = useState(false);
  const [editModalOpen, setEditModalOpen] = useState(false);
  const [qrModalOpen, setQrModalOpen] = useState(false);
  const [selectedMember, setSelectedMember] = useState<HouseholdMember | null>(null);

  // Add Member Form State
  const [newName, setNewName] = useState('');
  const [newEmail, setNewEmail] = useState('');
  const [newPhone, setNewPhone] = useState('');
  const [newRole, setNewRole] = useState<'homeowner' | 'spouse' | 'child' | 'long_term_occupant' | 'caregiver' | 'other'>('spouse');
  const [newRelationLabel, setNewRelationLabel] = useState('');
  const [newPermissions, setNewPermissions] = useState<string[]>([
    'manage_guests',
    'view_billing',
    'receive_emergency_alerts',
    'gate_access_24_7',
    'request_maintenance',
  ]);
  const [newValidUntil, setNewValidUntil] = useState('');
  const [submittingAdd, setSubmittingAdd] = useState(false);

  // Edit Member Form State
  const [editPermissions, setEditPermissions] = useState<string[]>([]);
  const [editStatus, setEditStatus] = useState<'active' | 'suspended'>('active');
  const [submittingEdit, setSubmittingEdit] = useState(false);

  // Copied token state
  const [copiedToken, setCopiedToken] = useState(false);

  // Handle Quick Role Select for Add Form
  const handleRoleChange = (role: 'homeowner' | 'spouse' | 'child' | 'long_term_occupant' | 'caregiver' | 'other') => {
    setNewRole(role);
    switch (role) {
      case 'homeowner':
        setNewRelationLabel('Homeowner (Primary Account Holder)');
        setNewPermissions([
          'manage_household',
          'manage_guests',
          'view_billing',
          'receive_emergency_alerts',
          'gate_access_24_7',
          'request_maintenance',
        ]);
        break;
      case 'spouse':
        setNewRelationLabel('Spouse (Co-Owner)');
        setNewPermissions([
          'manage_guests',
          'view_billing',
          'receive_emergency_alerts',
          'gate_access_24_7',
          'request_maintenance',
        ]);
        break;
      case 'child':
        setNewRelationLabel('Child (Resident Dependent)');
        setNewPermissions([
          'gate_access_24_7',
          'receive_emergency_alerts',
        ]);
        break;
      case 'long_term_occupant':
        setNewRelationLabel('Long-Term Occupant (Extended Family)');
        setNewPermissions([
          'gate_access_24_7',
          'receive_emergency_alerts',
          'request_maintenance',
        ]);
        break;
      case 'caregiver':
        setNewRelationLabel('Caregiver (Health & Household Support)');
        setNewPermissions([
          'gate_access_scheduled',
          'receive_emergency_alerts',
          'request_maintenance',
        ]);
        break;
      default:
        setNewRelationLabel('Household Member');
        setNewPermissions(['gate_access_24_7', 'receive_emergency_alerts']);
    }
  };

  // Submit Add Member
  const handleAddMember = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newName.trim()) return;

    setSubmittingAdd(true);
    router.post(
      '/dashboard/household/members',
      {
        name: newName,
        email: newEmail || null,
        phone: newPhone || null,
        role_in_household: newRole,
        relationship_label: newRelationLabel,
        permissions: newPermissions,
        valid_until: newValidUntil || null,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSubmittingAdd(false);
          setAddModalOpen(false);
          setNewName('');
          setNewEmail('');
          setNewPhone('');
          toast({
            title: 'Household Member Added',
            description: `${newName} has been registered and issued a digital gate credential.`,
          });
        },
        onError: (errs) => {
          setSubmittingAdd(false);
          toast({
            variant: 'destructive',
            title: 'Failed to Add Member',
            description: (Object.values(errs)[0] as string) || 'Could not save member.',
          });
        },
      }
    );
  };

  // Submit Edit Member
  const handleEditMember = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedMember) return;

    setSubmittingEdit(true);
    router.patch(
      `/dashboard/household/members/${selectedMember.id}`,
      {
        permissions: editPermissions,
        status: editStatus,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSubmittingEdit(false);
          setEditModalOpen(false);
          toast({
            title: 'Permissions Updated',
            description: `Permissions for ${selectedMember.name} were successfully saved.`,
          });
        },
        onError: () => {
          setSubmittingEdit(false);
          toast({
            variant: 'destructive',
            title: 'Update Failed',
            description: 'Could not update permissions.',
          });
        },
      }
    );
  };

  // Delete Member
  const handleDeleteMember = (member: HouseholdMember) => {
    if (member.role_in_household === 'homeowner') {
      toast({
        variant: 'destructive',
        title: 'Action Prohibited',
        description: 'Primary homeowner cannot be removed from their own household.',
      });
      return;
    }

    if (!confirm(`Are you sure you want to remove ${member.name} from your household and revoke their gate pass?`)) {
      return;
    }

    router.delete(`/dashboard/household/members/${member.id}`, {
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: 'Member Removed',
          description: `${member.name} was removed and their digital credential revoked.`,
        });
      },
    });
  };

  // Copy token helper
  const handleCopyToken = (token: string) => {
    navigator.clipboard.writeText(token);
    setCopiedToken(true);
    setTimeout(() => setCopiedToken(false), 2000);
    toast({
      title: 'Pass Credential Copied',
      description: 'Signed gate access token copied to clipboard.',
    });
  };

  return (
    <DashboardLayout>
      <Head title="Family & Household Management" />

      <div className="flex flex-col gap-6">
        {/* ════════════════════════════════════════════════════════════════
            1. TOP HEADER & ACTIONS
           ════════════════════════════════════════════════════════════════ */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2.5">
              <h1 className="font-headline text-3xl font-extrabold tracking-tight">
                Family &amp; Household Management
              </h1>
              <Badge variant="outline" className="text-xs uppercase font-mono tracking-wider border-primary/40 text-primary">
                {household.property_number}
              </Badge>
            </div>
            <p className="text-muted-foreground text-sm mt-1">
              Manage resident household co-owners, dependents, extended occupants, and domestic support. Each member receives their own verified gate credential and permissions.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <Link href="/dashboard/wallet">
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5 h-9 text-xs border-emerald-500/40 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/10"
                title="View full credentials in the Digital Access Wallet (QR, NFC & Mobile Wallet)"
              >
                <Wallet className="h-3.5 w-3.5" />
                <span>Open Access Wallet</span>
              </Button>
            </Link>

            <Button
              size="sm"
              onClick={() => {
                handleRoleChange('spouse');
                setAddModalOpen(true);
              }}
              className="gap-1.5 h-9 text-xs bg-primary text-primary-foreground font-bold shadow-sm"
            >
              <Users className="h-3.5 w-3.5" />
              <span>Add Household Member</span>
            </Button>
          </div>
        </div>

        {/* ════════════════════════════════════════════════════════════════
            2. HOUSEHOLD SUMMARY AT A GLANCE
           ════════════════════════════════════════════════════════════════ */}
        <Card className="border border-border/80 shadow-sm bg-gradient-to-r from-card via-card to-muted/20">
          <CardHeader className="p-4 pb-2">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
              <div className="flex items-center gap-2">
                <span className="p-2 rounded-xl bg-primary/10 text-primary">
                  <Home className="h-4 w-4" />
                </span>
                <div>
                  <CardTitle className="text-base font-bold">{household.name}</CardTitle>
                  <CardDescription className="text-xs">
                    {household.address || `${household.property_number}, Community Hub Estate`}
                  </CardDescription>
                </div>
              </div>

              <div className="flex items-center gap-2 text-xs font-mono">
                <Badge variant="secondary" className="px-2.5 py-0.5">
                  {household.members.length} Registered Occupant{household.members.length !== 1 ? 's' : ''}
                </Badge>
                <Badge variant="outline" className="border-emerald-500/30 text-emerald-600 dark:text-emerald-400 bg-emerald-500/5">
                  All Credentials Active
                </Badge>
              </div>
            </div>
          </CardHeader>
        </Card>

        {/* ════════════════════════════════════════════════════════════════
            3. HOUSEHOLD MEMBERS ROSTER (RICH CARDS)
           ════════════════════════════════════════════════════════════════ */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
              <Users className="w-5 h-5 text-primary" />
              <span>Household Roster &amp; Credentials</span>
            </h2>
            <span className="text-xs text-muted-foreground">
              {household.members.length} Members Enrolled
            </span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {household.members.map((member) => {
              const isOwnerRole = member.role_in_household === 'homeowner';
              const isCaregiver = member.role_in_household === 'caregiver';
              const isChild = member.role_in_household === 'child';
              const isLTO = member.role_in_household === 'long_term_occupant';

              return (
                <Card
                  key={member.id}
                  className={cn(
                    "border transition-all duration-200 hover:shadow-md flex flex-col justify-between overflow-hidden",
                    isOwnerRole && "border-blue-500/40 bg-gradient-to-b from-blue-500/5 via-card to-card",
                    member.role_in_household === 'spouse' && "border-indigo-500/40 bg-gradient-to-b from-indigo-500/5 via-card to-card",
                    isChild && "border-emerald-500/40 bg-gradient-to-b from-emerald-500/5 via-card to-card",
                    isLTO && "border-purple-500/40 bg-gradient-to-b from-purple-500/5 via-card to-card",
                    isCaregiver && "border-amber-500/40 bg-gradient-to-b from-amber-500/5 via-card to-card"
                  )}
                >
                  <CardHeader className="p-4 pb-2">
                    <div className="flex items-start justify-between gap-2">
                      <div className="flex items-center gap-2.5">
                        <div
                          className={cn(
                            "w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm shadow-xs border",
                            isOwnerRole && "bg-blue-600 text-white border-blue-400",
                            member.role_in_household === 'spouse' && "bg-indigo-600 text-white border-indigo-400",
                            isChild && "bg-emerald-600 text-white border-emerald-400",
                            isLTO && "bg-purple-600 text-white border-purple-400",
                            isCaregiver && "bg-amber-600 text-white border-amber-400"
                          )}
                        >
                          {member.name.split(' ').map((n) => n[0]).join('').slice(0, 2)}
                        </div>
                        <div>
                          <CardTitle className="text-base font-bold text-foreground">
                            {member.name}
                          </CardTitle>
                          <p className="text-[11px] text-muted-foreground font-medium">
                            {member.relationship_label}
                          </p>
                        </div>
                      </div>

                      <Badge
                        variant="outline"
                        className={cn("text-[9px] uppercase font-bold tracking-wider px-2 py-0.5", member.role_badge_class)}
                      >
                        {member.role_in_household.replace('_', ' ')}
                      </Badge>
                    </div>
                  </CardHeader>

                  <CardContent className="p-4 pt-1 space-y-3">
                    {/* Digital Gate Credential Badge Box */}
                    {member.gate_pass && (
                      <div className="p-2.5 rounded-xl border bg-muted/30 flex items-center justify-between text-xs">
                        <div className="space-y-0.5">
                          <div className="text-[10px] text-muted-foreground font-mono uppercase tracking-wider flex items-center gap-1">
                            <QrCode className="w-3 h-3 text-primary" />
                            <span>Digital Credential</span>
                          </div>
                          <div className="font-mono font-bold text-xs text-foreground">
                            {member.gate_pass.pass_id}
                          </div>
                          <div className="text-[10px] text-muted-foreground">
                            {member.credential_label}
                          </div>
                        </div>

                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => {
                            setSelectedMember(member);
                            setQrModalOpen(true);
                          }}
                          className="h-8 px-2.5 text-xs gap-1 border-primary/30 hover:bg-primary/10"
                        >
                          <QrCode className="w-3.5 h-3.5 text-primary" />
                          <span>QR Pass</span>
                        </Button>
                      </div>
                    )}

                    {/* Operational Schedule / Curfew Notes */}
                    {member.access_schedule && (
                      <div className="p-2 rounded-lg bg-background border text-[11px] space-y-1">
                        <div className="font-bold text-muted-foreground text-[10px] uppercase tracking-wider flex items-center gap-1">
                          <Clock className="w-3 h-3 text-amber-500" /> Operational Window:
                        </div>
                        {member.access_schedule.curfew_enabled && (
                          <div className="font-mono text-emerald-600 dark:text-emerald-400 font-semibold">
                            Curfew: {member.access_schedule.curfew_hours}
                          </div>
                        )}
                        {member.access_schedule.days && (
                          <div className="text-muted-foreground">
                            <span>Every {member.access_schedule.days.join(', ')}</span>
                            <br />
                            <span className="font-mono font-bold text-foreground">
                              {member.access_schedule.hours}
                            </span>
                          </div>
                        )}
                        {member.valid_until && (
                          <div className="text-[10px] text-muted-foreground">
                            Valid until: <strong>{member.valid_until}</strong>
                          </div>
                        )}
                      </div>
                    )}

                    {/* Granted Permissions List */}
                    <div className="space-y-1.5">
                      <div className="text-[10px] text-muted-foreground font-bold uppercase tracking-wider">
                        Active Privileges ({member.permissions.length}):
                      </div>
                      <div className="flex flex-wrap gap-1">
                        {member.permissions.map((permKey) => {
                          const def = availablePermissions[permKey];
                          return (
                            <Badge
                              key={permKey}
                              variant="secondary"
                              className="text-[10px] font-normal py-0 px-1.5 bg-muted/60"
                            >
                              ✓ {def?.label || permKey.replace('_', ' ')}
                            </Badge>
                          );
                        })}
                      </div>
                    </div>

                    {/* Contact details */}
                    {(member.phone || member.email) && (
                      <div className="pt-2 border-t border-border/40 text-[11px] text-muted-foreground space-y-0.5 font-mono">
                        {member.phone && (
                          <div className="flex items-center gap-1.5">
                            <Phone className="w-3 h-3" />
                            <span>{member.phone}</span>
                          </div>
                        )}
                        {member.email && (
                          <div className="flex items-center gap-1.5">
                            <Mail className="w-3 h-3" />
                            <span className="truncate">{member.email}</span>
                          </div>
                        )}
                      </div>
                    )}

                    {/* Card Actions Footer */}
                    <div className="pt-2 border-t border-border/60 flex items-center justify-between">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                          setSelectedMember(member);
                          setEditPermissions(member.permissions);
                          setEditStatus(member.status === 'suspended' ? 'suspended' : 'active');
                          setEditModalOpen(true);
                        }}
                        className="h-7 px-2 text-xs gap-1 text-primary hover:bg-primary/10"
                      >
                        <Edit className="w-3 h-3" />
                        <span>Edit Permissions</span>
                      </Button>

                      {!isOwnerRole && (
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => handleDeleteMember(member)}
                          className="h-7 px-2 text-xs text-rose-500 hover:text-rose-600 hover:bg-rose-500/10"
                          title="Revoke pass and remove"
                        >
                          <Trash2 className="w-3 h-3" />
                        </Button>
                      )}
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </div>

        {/* ════════════════════════════════════════════════════════════════
            ADD HOUSEHOLD MEMBER MODAL
           ════════════════════════════════════════════════════════════════ */}
        <Dialog open={addModalOpen} onOpenChange={setAddModalOpen}>
          <DialogContent className="sm:max-w-[540px] max-h-[90vh] overflow-y-auto">
            <form onSubmit={handleAddMember}>
              <DialogHeader>
                <div className="flex items-center gap-2 mb-1">
                  <span className="p-2 rounded-xl bg-primary/10 text-primary">
                    <Users className="w-5 h-5" />
                  </span>
                  <div>
                    <DialogTitle className="text-lg font-bold">
                      Add Household Member
                    </DialogTitle>
                    <DialogDescription className="text-xs">
                      Enrolls a family member, long-term occupant, or caregiver with dedicated gate pass credentials.
                    </DialogDescription>
                  </div>
                </div>
              </DialogHeader>

              <div className="space-y-4 py-3">
                {/* Role Preset Selector */}
                <div className="space-y-1.5">
                  <Label className="text-xs font-semibold">Household Role</Label>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <button
                      type="button"
                      onClick={() => handleRoleChange('spouse')}
                      className={cn(
                        "p-2 rounded-xl border text-xs font-bold text-center transition-all",
                        newRole === 'spouse' ? "border-indigo-500 bg-indigo-500/10 text-indigo-600 ring-1 ring-indigo-500" : "border-border bg-card"
                      )}
                    >
                      💍 Spouse
                    </button>
                    <button
                      type="button"
                      onClick={() => handleRoleChange('child')}
                      className={cn(
                        "p-2 rounded-xl border text-xs font-bold text-center transition-all",
                        newRole === 'child' ? "border-emerald-500 bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500" : "border-border bg-card"
                      )}
                    >
                      👶 Child / Dependent
                    </button>
                    <button
                      type="button"
                      onClick={() => handleRoleChange('long_term_occupant')}
                      className={cn(
                        "p-2 rounded-xl border text-xs font-bold text-center transition-all",
                        newRole === 'long_term_occupant' ? "border-purple-500 bg-purple-500/10 text-purple-600 ring-1 ring-purple-500" : "border-border bg-card"
                      )}
                    >
                      🧳 Long-term Occupant
                    </button>
                    <button
                      type="button"
                      onClick={() => handleRoleChange('caregiver')}
                      className={cn(
                        "p-2 rounded-xl border text-xs font-bold text-center transition-all",
                        newRole === 'caregiver' ? "border-amber-500 bg-amber-500/10 text-amber-600 ring-1 ring-amber-500" : "border-border bg-card"
                      )}
                    >
                      🩺 Caregiver / Staff
                    </button>
                    <button
                      type="button"
                      onClick={() => handleRoleChange('other')}
                      className={cn(
                        "p-2 rounded-xl border text-xs font-bold text-center transition-all col-span-2 sm:col-span-2",
                        newRole === 'other' ? "border-primary bg-primary/10 text-primary ring-1 ring-primary" : "border-border bg-card"
                      )}
                    >
                      👤 Other Resident Member
                    </button>
                  </div>
                </div>

                {/* Name */}
                <div className="space-y-1.5">
                  <Label htmlFor="mem-name" className="text-xs font-semibold">
                    Full Name <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="mem-name"
                    value={newName}
                    onChange={(e) => setNewName(e.target.value)}
                    placeholder="e.g. Mary Smith, Alex Smith, Maria Smith"
                    required
                    className="h-9 text-xs"
                  />
                </div>

                {/* Relationship Label */}
                <div className="space-y-1.5">
                  <Label htmlFor="mem-rel" className="text-xs font-semibold">
                    Relationship Label
                  </Label>
                  <Input
                    id="mem-rel"
                    value={newRelationLabel}
                    onChange={(e) => setNewRelationLabel(e.target.value)}
                    placeholder="e.g. Spouse, Son, Mother-in-Law, Private Nurse"
                    className="h-9 text-xs"
                  />
                </div>

                {/* Contact info */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="mem-phone" className="text-xs font-semibold">Phone Number</Label>
                    <Input
                      id="mem-phone"
                      value={newPhone}
                      onChange={(e) => setNewPhone(e.target.value)}
                      placeholder="+1 (876) 555-0102"
                      className="h-9 text-xs font-mono"
                    />
                  </div>

                  <div className="space-y-1.5">
                    <Label htmlFor="mem-email" className="text-xs font-semibold">Email Address</Label>
                    <Input
                      id="mem-email"
                      type="email"
                      value={newEmail}
                      onChange={(e) => setNewEmail(e.target.value)}
                      placeholder="name@example.com"
                      className="h-9 text-xs"
                    />
                  </div>
                </div>

                {/* Permissions Customization */}
                <div className="space-y-2 pt-1 border-t">
                  <Label className="text-xs font-bold uppercase tracking-wider text-foreground">
                    Assigned Privileges &amp; Access Controls
                  </Label>
                  <div className="space-y-2">
                    {Object.entries(availablePermissions).map(([key, def]) => {
                      const isChecked = newPermissions.includes(key);
                      return (
                        <div
                          key={key}
                          className="flex items-start space-x-2.5 p-2 rounded-lg border bg-muted/20"
                        >
                          <Checkbox
                            id={`add-perm-${key}`}
                            checked={isChecked}
                            onCheckedChange={(c) => {
                              if (c) {
                                setNewPermissions([...newPermissions, key]);
                              } else {
                                setNewPermissions(newPermissions.filter((p) => p !== key));
                              }
                            }}
                          />
                          <div className="space-y-0.5">
                            <label
                              htmlFor={`add-perm-${key}`}
                              className="text-xs font-bold leading-none cursor-pointer text-foreground"
                            >
                              {def.label}
                            </label>
                            <p className="text-[11px] text-muted-foreground leading-snug">
                              {def.description}
                            </p>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              </div>

              <DialogFooter className="gap-2 sm:gap-0 pt-2 border-t">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setAddModalOpen(false)}
                >
                  Cancel
                </Button>
                <Button
                  type="submit"
                  size="sm"
                  disabled={submittingAdd}
                  className="bg-primary text-primary-foreground font-bold"
                >
                  {submittingAdd ? 'Provisioning...' : 'Provision Member Credential'}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* ════════════════════════════════════════════════════════════════
            EDIT PERMISSIONS MODAL
           ════════════════════════════════════════════════════════════════ */}
        <Dialog open={editModalOpen} onOpenChange={setEditModalOpen}>
          <DialogContent className="sm:max-w-[480px]">
            <form onSubmit={handleEditMember}>
              <DialogHeader>
                <DialogTitle className="text-base font-bold">
                  Edit Permissions for {selectedMember?.name}
                </DialogTitle>
                <DialogDescription className="text-xs">
                  Modify the security clearance and privilege boundaries for this household member.
                </DialogDescription>
              </DialogHeader>

              <div className="space-y-3 py-3">
                <div className="space-y-2">
                  {Object.entries(availablePermissions).map(([key, def]) => {
                    const isChecked = editPermissions.includes(key);
                    return (
                      <div
                        key={key}
                        className="flex items-start space-x-2.5 p-2 rounded-lg border bg-muted/20"
                      >
                        <Checkbox
                          id={`edit-perm-${key}`}
                          checked={isChecked}
                          onCheckedChange={(c) => {
                            if (c) {
                              setEditPermissions([...editPermissions, key]);
                            } else {
                              setEditPermissions(editPermissions.filter((p) => p !== key));
                            }
                          }}
                        />
                        <div className="space-y-0.5">
                          <label
                            htmlFor={`edit-perm-${key}`}
                            className="text-xs font-bold leading-none cursor-pointer text-foreground"
                          >
                            {def.label}
                          </label>
                          <p className="text-[11px] text-muted-foreground leading-snug">
                            {def.description}
                          </p>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>

              <DialogFooter className="gap-2 sm:gap-0 pt-2 border-t">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setEditModalOpen(false)}
                >
                  Cancel
                </Button>
                <Button
                  type="submit"
                  size="sm"
                  disabled={submittingEdit}
                  className="bg-primary text-primary-foreground font-bold"
                >
                  {submittingEdit ? 'Saving...' : 'Save Permissions'}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* ════════════════════════════════════════════════════════════════
            VIEW DIGITAL CREDENTIAL PASS MODAL
           ════════════════════════════════════════════════════════════════ */}
        <Dialog open={qrModalOpen} onOpenChange={setQrModalOpen}>
          <DialogContent className="sm:max-w-[420px] text-center">
            <DialogHeader>
              <DialogTitle className="text-base font-bold">
                {selectedMember?.name}&rsquo;s Gate Credential
              </DialogTitle>
              <DialogDescription className="text-xs">
                {selectedMember?.relationship_label} • {household.property_number}
              </DialogDescription>
            </DialogHeader>

            {selectedMember?.gate_pass && (
              <div className="flex flex-col items-center justify-center py-4 space-y-3">
                <ProfileCustomQRCode
                  value={selectedMember.gate_pass.token || selectedMember.gate_pass.pass_id}
                  category={(selectedMember.gate_pass.category as PassCategory) || ('HOMEOWNER' as PassCategory)}
                  size={180}
                />

                <div className="space-y-1">
                  <Badge variant="outline" className="font-mono text-xs font-bold tracking-wider">
                    {selectedMember.gate_pass.pass_id}
                  </Badge>
                  <p className="text-xs text-muted-foreground font-medium">
                    {selectedMember.credential_label}
                  </p>
                </div>

                {selectedMember.gate_pass.token && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleCopyToken(selectedMember.gate_pass?.token || '')}
                    className="h-8 text-xs gap-1.5"
                  >
                    {copiedToken ? <Check className="w-3.5 h-3.5 text-emerald-500" /> : <Copy className="w-3.5 h-3.5" />}
                    <span>{copiedToken ? 'Token Copied' : 'Copy Signed Token'}</span>
                  </Button>
                )}
              </div>
            )}

            <DialogFooter className="pt-2 border-t">
              <Button size="sm" onClick={() => setQrModalOpen(false)}>
                Done
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </DashboardLayout>
  );
}
