import React, { useState, useMemo, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  AlertTriangle,
  Ban,
  Building,
  Check,
  CheckCircle2,
  ChevronRight,
  Clock,
  Info,
  Layers,
  Lock,
  Plus,
  QrCode,
  Search,
  Shield,
  ShieldAlert,
  ShieldCheck,
  SlidersHorizontal,
  Sparkles,
  Users,
} from 'lucide-react';
import { useToast } from '@/hooks/use-toast';
import {
  AuthorizedPerson,
  CATEGORY_TABS,
  HOMEOWNER_SECTIONS_CONFIG,
  Props,
  RECURRING_WORKER_TEMPLATES,
  RENTER_CATEGORY_TABS,
  RENTER_SECTIONS_CONFIG,
  SHORT_TERM_CATEGORY_TABS,
  SHORT_TERM_SECTIONS_CONFIG,
} from '@/components/dashboard/delegation/delegation-types';
import {
  DelegationPersonCard,
  renderCredentialShapeIcon,
} from '@/components/dashboard/delegation/delegation-person-card';
import { DelegationCredentialModal } from '@/components/dashboard/delegation/delegation-credential-modal';
import { DelegationAuthorizeModal } from '@/components/dashboard/delegation/delegation-authorize-modal';
import { DelegationEditRulesModal } from '@/components/dashboard/delegation/delegation-edit-rules-modal';
import {
  DelegationAuditModal,
  DelegationContinuityModal,
  DelegationEmergencyModal,
  DelegationRevokeModal,
} from '@/components/dashboard/delegation/delegation-action-modals';

export default function DelegatedAccessCenter({
  authorizedPeople = [],
  categoryCounts = {},
  permissions,
  scopeBadge,
  pageSubtitle,
  unitLabel = '',
  pageTitle,
  continuityPlan,
  properties = [],
  continuityConditions = {},
  continuityActions = {},
  ownershipEntity = null,
}: Props) {
  const { toast } = useToast();

  const isShortTerm = permissions?.isShortTerm ?? false;
  const isRenter = (!isShortTerm && permissions?.isRenter) ?? false;
  const activeSectionsConfig = isShortTerm
    ? SHORT_TERM_SECTIONS_CONFIG
    : isRenter
    ? RENTER_SECTIONS_CONFIG
    : HOMEOWNER_SECTIONS_CONFIG;
  const activeCategoryTabs = isShortTerm
    ? SHORT_TERM_CATEGORY_TABS
    : isRenter
    ? RENTER_CATEGORY_TABS
    : CATEGORY_TABS;

  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [viewMode, setViewMode] = useState<'sections' | 'cards' | 'table'>('sections');
  const [showScopeMatrix, setShowScopeMatrix] = useState(false);

  // Modals state
  const [isAuthorizeModalOpen, setIsAuthorizeModalOpen] = useState(false);
  const [isCredentialModalOpen, setIsCredentialModalOpen] = useState(false);
  const [isEditRulesModalOpen, setIsEditRulesModalOpen] = useState(false);
  const [isEmergencyModalOpen, setIsEmergencyModalOpen] = useState(false);
  const [isAuditModalOpen, setIsAuditModalOpen] = useState(false);
  const [isRevokeModalOpen, setIsRevokeModalOpen] = useState(false);
  const [isContinuityModalOpen, setIsContinuityModalOpen] = useState(false);

  // Active Person selected for details/actions
  const [activePerson, setActivePerson] = useState<AuthorizedPerson | null>(null);
  const [auditEvents, setAuditEvents] = useState<any[]>([]);
  const [loadingAudit, setLoadingAudit] = useState(false);

  // Live Slot Timer for QR Credential Modal
  const [secondsRemaining, setSecondsRemaining] = useState(30);

  useEffect(() => {
    const timer = setInterval(() => {
      const nowSec = Math.floor(Date.now() / 1000);
      const remaining = 30 - (nowSec % 30);
      setSecondsRemaining(remaining);
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  // Form State: Authorize Person
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    relationship: 'Family/Friend',
    categoryKey: 'long_term_occupants',
    access_level: 'Long-Term Occupant',
    authorization_type: 'long_term_occupant',
    duration_type: 'custom',
    permissions: ['gate_access'],
    property_id: properties[0]?.id ? String(properties[0].id) : '',
    activation_method: 'immediate',
    starts_at: new Date().toISOString().split('T')[0],
    expires_at: new Date(Date.now() + 180 * 86400000).toISOString().split('T')[0],
    security_notes: '',
    access_rules: {
      gate_access: true,
      pool_access: true,
      gym_access: false,
      clubhouse_access: true,
      parking_allocated: true,
      can_invite_visitors: false,
      requires_id_verification: true,
      allowed_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
      entry_start_time: '06:00',
      entry_end_time: '22:00',
    },
  });

  // Form State: Edit Rules
  const [editRulesData, setEditRulesData] = useState({
    duration_type: 'custom',
    starts_at: '',
    expires_at: '',
    permissions: [] as string[],
    access_rules: {
      gate_access: true,
      pool_access: false,
      gym_access: false,
      clubhouse_access: false,
      parking_allocated: false,
      can_invite_visitors: false,
      requires_id_verification: false,
      allowed_days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
      entry_start_time: '00:00',
      entry_end_time: '23:59',
    },
  });

  // Action states
  const [emergencyReason, setEmergencyReason] = useState('');
  const [emergencyDurationDays, setEmergencyDurationDays] = useState(7);
  const [emergencyNotes, setEmergencyNotes] = useState('');
  const [revokeReason, setRevokeReason] = useState('');

  // Continuity Plan Form State
  const [planForm, setPlanForm] = useState({
    primary_delegate_id: continuityPlan?.primary_delegate_id ? String(continuityPlan.primary_delegate_id) : '',
    secondary_delegate_id: continuityPlan?.secondary_delegate_id ? String(continuityPlan.secondary_delegate_id) : '',
    activation_conditions: continuityPlan?.activation_conditions || ['unreachable_72h', 'declared_medical_emergency'],
    required_verification: continuityPlan?.required_verification || 'two_factor_plus_admin',
    authorized_actions: continuityPlan?.authorized_actions || ['manage_gate_passes', 'authorize_visitors', 'emergency_lockdown'],
    max_duration_days: continuityPlan?.max_duration_days || 30,
    requires_admin_approval: continuityPlan?.requires_admin_approval ?? true,
    notify_homeowner_on_trigger: continuityPlan?.notify_homeowner_on_trigger ?? true,
    notify_community_security: continuityPlan?.notify_community_security ?? true,
    special_instructions: continuityPlan?.special_instructions || '',
  });

  const handleSelectDurationPreset = (presetKey: string) => {
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    const currentYear = today.getFullYear();

    let expiresStr = '';
    if (presetKey === '2_hours' || presetKey === '1_day') {
      expiresStr = todayStr;
    } else if (presetKey === '1_week') {
      const nextWeek = new Date(today.getTime() + 7 * 86400000);
      expiresStr = nextWeek.toISOString().split('T')[0];
    } else if (presetKey === 'recurring') {
      expiresStr = `${currentYear}-12-31`;
    } else if (presetKey === 'manual_revocation') {
      expiresStr = '';
    } else {
      const defaultCustom = new Date(today.getTime() + 30 * 86400000);
      expiresStr = defaultCustom.toISOString().split('T')[0];
    }

    setFormData((prev) => ({
      ...prev,
      duration_type: presetKey,
      starts_at: prev.starts_at || todayStr,
      expires_at: expiresStr,
    }));
  };

  const handleSelectEditRulesDurationPreset = (presetKey: string) => {
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    const currentYear = today.getFullYear();

    let expiresStr = '';
    if (presetKey === '2_hours' || presetKey === '1_day') {
      expiresStr = todayStr;
    } else if (presetKey === '1_week') {
      const nextWeek = new Date(today.getTime() + 7 * 86400000);
      expiresStr = nextWeek.toISOString().split('T')[0];
    } else if (presetKey === 'recurring') {
      expiresStr = `${currentYear}-12-31`;
    } else if (presetKey === 'manual_revocation') {
      expiresStr = '';
    } else {
      const defaultCustom = new Date(today.getTime() + 30 * 86400000);
      expiresStr = defaultCustom.toISOString().split('T')[0];
    }

    setEditRulesData((prev) => ({
      ...prev,
      duration_type: presetKey,
      starts_at: prev.starts_at || todayStr,
      expires_at: expiresStr,
    }));
  };

  const handleApplyRecurringWorkerTemplate = (template: (typeof RECURRING_WORKER_TEMPLATES)[0]) => {
    const currentYear = new Date().getFullYear();
    const todayStr = new Date().toISOString().split('T')[0];

    setFormData((prev) => ({
      ...prev,
      relationship: template.relationship,
      categoryKey: template.categoryKey,
      access_level: template.accessLevel,
      authorization_type: template.authType,
      duration_type: 'recurring',
      starts_at: prev.starts_at || todayStr,
      expires_at: `${currentYear}-12-31`,
      access_rules: {
        ...prev.access_rules,
        allowed_days: template.days,
        entry_start_time: template.startTime,
        entry_end_time: template.endTime,
      },
    }));
  };

  const setEndOfYearExpiration = (target: 'form' | 'edit') => {
    const currentYear = new Date().getFullYear();
    const endOfYear = `${currentYear}-12-31`;
    if (target === 'form') {
      setFormData((prev) => ({ ...prev, expires_at: endOfYear }));
    } else {
      setEditRulesData((prev) => ({ ...prev, expires_at: endOfYear }));
    }
  };

  const applyQuickSchedule = (shiftKey: string, target: 'form' | 'edit') => {
    let days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    let start = '08:00';
    let end = '17:00';

    if (shiftKey === 'mon_fri') {
      days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
      start = '08:00';
      end = '17:00';
    } else if (shiftKey === 'mon_sat') {
      days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
      start = '06:00';
      end = '20:00';
    } else if (shiftKey === 'tue_thu') {
      days = ['Tue', 'Thu'];
      start = '08:00';
      end = '16:00';
    } else if (shiftKey === 'weekends') {
      days = ['Sat', 'Sun'];
      start = '09:00';
      end = '18:00';
    } else if (shiftKey === 'everyday') {
      days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      start = '00:00';
      end = '23:59';
    }

    if (target === 'form') {
      setFormData((prev) => ({
        ...prev,
        access_rules: {
          ...prev.access_rules,
          allowed_days: days,
          entry_start_time: start,
          entry_end_time: end,
        },
      }));
    } else {
      setEditRulesData((prev) => ({
        ...prev,
        access_rules: {
          ...prev.access_rules,
          allowed_days: days,
          entry_start_time: start,
          entry_end_time: end,
        },
      }));
    }
  };

  const applyCategoryDefaults = (categoryKey: string) => {
    let access_level = 'Long-Term Occupant';
    let authorization_type = 'long_term_occupant';
    let duration_type = 'custom';
    let relationship = 'Family/Friend';

    if (categoryKey === 'visitors') {
      access_level = 'Visitor';
      authorization_type = 'visitor';
      duration_type = '1_day';
      relationship = 'Visitor';
    } else if (categoryKey === 'domestic_staff') {
      access_level = 'Domestic Staff';
      authorization_type = 'domestic_staff';
      duration_type = 'recurring';
      relationship = 'Domestic Worker';
    } else if (categoryKey === 'contractors') {
      access_level = 'Contractor';
      authorization_type = 'contractor';
      duration_type = '1_week';
      relationship = 'Contractor';
    } else if (categoryKey === 'legacy_contacts') {
      access_level = 'Emergency Contact';
      authorization_type = 'legacy_contact';
      duration_type = 'manual_revocation';
      relationship = 'Emergency Contact';
    } else if (categoryKey === 'caregivers') {
      access_level = 'Caregiver';
      authorization_type = 'caregiver';
      duration_type = 'recurring';
      relationship = 'Caregiver';
    } else if (categoryKey === 'property_managers') {
      access_level = 'Property Delegate';
      authorization_type = 'property_manager';
      duration_type = 'custom';
      relationship = 'Property Manager';
    }

    setFormData((prev) => ({
      ...prev,
      categoryKey,
      access_level,
      authorization_type,
      duration_type,
      relationship,
    }));
  };

  const handleOpenAuthorizeModal = (categoryKey = 'long_term_occupants') => {
    applyCategoryDefaults(categoryKey);
    setIsAuthorizeModalOpen(true);
  };

  const handleOpenCredentialModal = (person: AuthorizedPerson) => {
    setActivePerson(person);
    setIsCredentialModalOpen(true);
  };

  const handleOpenEditRulesModal = (person: AuthorizedPerson) => {
    setActivePerson(person);
    setEditRulesData({
      duration_type: person.durationType || 'custom',
      starts_at: person.startsAt ? person.startsAt.split('T')[0] : '',
      expires_at: person.expiresAt ? person.expiresAt.split('T')[0] : '',
      permissions: Array.isArray(person.permissions) ? person.permissions : Object.keys(person.permissions),
      access_rules: {
        gate_access: person.accessRules?.gate_access ?? true,
        pool_access: person.accessRules?.pool_access ?? false,
        gym_access: person.accessRules?.gym_access ?? false,
        clubhouse_access: person.accessRules?.clubhouse_access ?? false,
        parking_allocated: person.accessRules?.parking_allocated ?? false,
        can_invite_visitors: person.accessRules?.can_invite_visitors ?? false,
        requires_id_verification: person.accessRules?.requires_id_verification ?? false,
        allowed_days: person.accessRules?.allowed_days || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        entry_start_time: person.accessRules?.entry_start_time || '00:00',
        entry_end_time: person.accessRules?.entry_end_time || '23:59',
      },
    });
    setIsEditRulesModalOpen(true);
  };

  const handleOpenEmergencyModal = (person: AuthorizedPerson) => {
    setActivePerson(person);
    setEmergencyReason('');
    setEmergencyDurationDays(7);
    setEmergencyNotes('');
    setIsEmergencyModalOpen(true);
  };

  const handleOpenRevokeModal = (person: AuthorizedPerson) => {
    setActivePerson(person);
    setRevokeReason('');
    setIsRevokeModalOpen(true);
  };

  const handleViewAudit = async (person: AuthorizedPerson) => {
    setActivePerson(person);
    setIsAuditModalOpen(true);
    setLoadingAudit(true);
    try {
      const res = await fetch(`/dashboard/delegation/${person.sourceId}/audit`);
      if (res.ok) {
        const data = await res.json();
        setAuditEvents(data.events || []);
      }
    } catch {
      toast({ title: 'Error', description: 'Could not load audit events.', variant: 'destructive' });
    } finally {
      setLoadingAudit(false);
    }
  };

  const handleReissuePass = (person: AuthorizedPerson) => {
    router.post(
      `/dashboard/delegation/${person.sourceId}/reissue-pass`,
      {},
      {
        onSuccess: () => {
          toast({
            title: 'QR Credential Reissued',
            description: `Fresh secure pass token minted for ${person.name}. Previous QR code revoked.`,
          });
        },
      }
    );
  };

  const handleCreateAuthorization = (e: React.FormEvent) => {
    e.preventDefault();
    router.post('/dashboard/delegation', formData, {
      onSuccess: () => {
        toast({
          title: 'Person Authorized',
          description: `${formData.name} is now registered under ${formData.access_level} with digital credentials.`,
        });
        setIsAuthorizeModalOpen(false);
      },
      onError: (err) => {
        toast({
          title: 'Authorization Failed',
          description: (Object.values(err)[0] as string) || 'Please verify form values.',
          variant: 'destructive',
        });
      },
    });
  };

  const handleSaveRules = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activePerson) return;
    router.patch(`/dashboard/delegation/${activePerson.sourceId}/rules`, editRulesData, {
      onSuccess: () => {
        toast({
          title: 'Rules Updated',
          description: `Access schedule and permissions updated for ${activePerson.name}.`,
        });
        setIsEditRulesModalOpen(false);
      },
      onError: () => {
        toast({
          title: 'Update Failed',
          description: 'Could not update access rules.',
          variant: 'destructive',
        });
      },
    });
  };

  const handleActivateEmergency = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activePerson) return;

    router.post(
      `/dashboard/delegation/${activePerson.sourceId}/emergency/activate`,
      {
        reason: emergencyReason,
        duration_days: emergencyDurationDays,
        notes: emergencyNotes,
      },
      {
        onSuccess: () => {
          toast({
            title: 'Emergency Access Activated',
            description: `Emergency override activated for ${activePerson.name} (${emergencyDurationDays} days). Estate security alerted.`,
          });
          setIsEmergencyModalOpen(false);
        },
        onError: () => {
          toast({
            title: 'Activation Failed',
            description: 'Could not activate emergency override.',
            variant: 'destructive',
          });
        },
      }
    );
  };

  const handleDeactivateEmergency = (person: AuthorizedPerson) => {
    router.post(
      `/dashboard/delegation/${person.sourceId}/emergency/deactivate`,
      {},
      {
        onSuccess: () => {
          toast({
            title: 'Emergency Deactivated',
            description: `Access for ${person.name} returned to standard limits.`,
          });
        },
      }
    );
  };

  const handleRevoke = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activePerson) return;

    router.post(
      `/dashboard/delegation/${activePerson.sourceId}/revoke`,
      { reason: revokeReason },
      {
        onSuccess: () => {
          toast({
            title: 'Access Revoked',
            description: `Access credentials for ${activePerson.name} were permanently invalidated.`,
          });
          setIsRevokeModalOpen(false);
        },
        onError: () => {
          toast({
            title: 'Revoke Failed',
            description: 'Could not revoke access.',
            variant: 'destructive',
          });
        },
      }
    );
  };

  const handleSaveContinuityPlan = (e: React.FormEvent) => {
    e.preventDefault();
    router.post('/dashboard/delegation/continuity-plan', planForm, {
      onSuccess: () => {
        toast({
          title: 'Continuity Plan Updated',
          description: 'Emergency and incapacity succession parameters locked in with estate administration.',
        });
        setIsContinuityModalOpen(false);
      },
      onError: () => {
        toast({
          title: 'Update Failed',
          description: 'Please verify all continuity requirements.',
          variant: 'destructive',
        });
      },
    });
  };

  const filteredPeople = useMemo(() => {
    return authorizedPeople.filter((p) => {
      if (selectedCategory !== 'all') {
        if (selectedCategory === 'staff') {
          if (!['staff', 'domestic_staff', 'caregivers', 'property_managers'].includes(p.categoryKey)) {
            return false;
          }
        } else if (selectedCategory === 'legacy_contacts') {
          if (!['legacy_contacts', 'family_members'].includes(p.categoryKey)) {
            return false;
          }
        } else if (selectedCategory === 'my_visitors') {
          if (!['my_visitors', 'visitors'].includes(p.categoryKey)) {
            return false;
          }
        } else if (selectedCategory === 'property_services') {
          if (!['property_services', 'staff', 'domestic_staff', 'caregivers', 'property_managers', 'contractors'].includes(p.categoryKey)) {
            return false;
          }
        } else if (p.categoryKey !== selectedCategory) {
          return false;
        }
      }

      if (statusFilter === 'active' && p.status !== 'active') return false;
      if (statusFilter === 'pending' && p.status !== 'pending') return false;
      if (statusFilter === 'revoked_expired' && !['expired', 'revoked'].includes(p.status)) return false;

      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase();
        const matchesName = p.name.toLowerCase().includes(q);
        const matchesEmail = p.email?.toLowerCase().includes(q);
        const matchesPhone = p.phone?.toLowerCase().includes(q);
        const matchesPassId = p.credential?.passId.toLowerCase().includes(q);
        const matchesRel = p.relationship.toLowerCase().includes(q);
        const matchesCat = p.categoryLabel.toLowerCase().includes(q);
        if (!matchesName && !matchesEmail && !matchesPhone && !matchesPassId && !matchesRel && !matchesCat) {
          return false;
        }
      }

      return true;
    });
  }, [authorizedPeople, selectedCategory, statusFilter, searchQuery]);

  const getTabCount = (tabKey: string) => {
    if (tabKey === 'all') {
      return categoryCounts.all ?? authorizedPeople.length;
    }
    if (tabKey === 'staff') {
      return (categoryCounts.staff ?? 0) || ((categoryCounts.domestic_staff ?? 0) + (categoryCounts.caregivers ?? 0) + (categoryCounts.property_managers ?? 0));
    }
    if (tabKey === 'legacy_contacts') {
      return (categoryCounts.legacy_contacts ?? 0) + (categoryCounts.family_members ?? 0);
    }
    if (tabKey === 'my_visitors') {
      return (categoryCounts.my_visitors ?? 0) || (categoryCounts.visitors ?? 0) || authorizedPeople.filter((p) => ['my_visitors', 'visitors'].includes(p.categoryKey)).length;
    }
    if (tabKey === 'property_services') {
      return categoryCounts.property_services ?? authorizedPeople.filter((p) => ['property_services', 'staff', 'domestic_staff', 'caregivers', 'property_managers', 'contractors'].includes(p.categoryKey)).length;
    }
    return categoryCounts[tabKey] ?? authorizedPeople.filter((p) => p.categoryKey === tabKey).length;
  };

  return (
    <DashboardLayout>
      <Head title={pageTitle || `${unitLabel || 'My Property'} — People & Access | Community Hub`} />

      <div className="space-y-6 max-w-7xl mx-auto pb-16">
        {/* Header Bar */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border/40 pb-5">
          <div>
            <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground mb-1.5">
              <span>{unitLabel || 'My Property'}</span>
              <ChevronRight className="w-3.5 h-3.5 text-muted-foreground/60" />
              <span className="text-foreground font-semibold">
                {isRenter ? 'My Access Network' : 'People & Access'}
              </span>
              <Badge variant="outline" className={`ml-2 text-[10px] py-0 px-2 ${isRenter ? 'border-indigo-500/30 text-indigo-400 bg-indigo-500/10' : 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10'}`}>
                {scopeBadge || permissions?.scopeBadge || (isRenter ? 'Tenant Access Scope • Bounded by Lease' : 'Zero Cross-Unit Leakage • Scoped')}
              </Badge>
            </div>
            <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">
              {pageTitle || (isRenter ? `${unitLabel || 'My Property'} — My Access Network` : `${unitLabel || 'My Property'} — People & Access`)}
            </h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              {pageSubtitle || (isRenter
                ? `Authorized occupants, visitors, staff, and contractors for your tenancy at ${unitLabel || 'your property'}.`
                : `Authorized occupants, guests, staff, contractors, and legacy delegates strictly associated with ${unitLabel || 'your property'}.`
              )}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            {!isRenter && !isShortTerm && (permissions?.isHomeowner || permissions?.isAdmin) && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setIsContinuityModalOpen(true)}
                className="gap-2 border-amber-500/30 hover:border-amber-500/60 text-amber-400 hover:text-amber-300"
              >
                <ShieldAlert className="w-4 h-4 text-amber-500" />
                <span>Continuity Plan</span>
              </Button>
            )}

            <Button
              size="sm"
              onClick={() => handleOpenAuthorizeModal(selectedCategory !== 'all' ? selectedCategory : (isShortTerm ? 'visitors' : 'long_term_occupants'))}
              className="gap-2 bg-primary hover:bg-primary/90 shadow-sm"
            >
              <Plus className="w-4 h-4" />
              <span>{isShortTerm ? 'Pre-Clear Visitor' : 'Authorize Person'}</span>
            </Button>
          </div>
        </div>

        {/* Ownership Entity Banner */}
        {ownershipEntity && (
          <div className="p-4 sm:p-5 rounded-2xl border border-indigo-500/30 bg-gradient-to-r from-indigo-950/40 via-card to-background shadow-xs">
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
              <div className="flex items-start gap-3.5">
                <div className="p-3 rounded-xl bg-indigo-500/15 border border-indigo-500/30 text-indigo-400 shrink-0">
                  <Building className="w-6 h-6" />
                </div>
                <div>
                  <div className="flex flex-wrap items-center gap-2 mb-1">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-indigo-400">Owner Entity</span>
                    <Badge variant="outline" className="text-[10px] py-0.5 bg-indigo-500/10 text-indigo-300 border-indigo-500/30">
                      Ownership Type: {ownershipEntity.entity_type === 'company' ? 'Company' : (ownershipEntity.entity_type.charAt(0).toUpperCase() + ownershipEntity.entity_type.slice(1))}
                    </Badge>
                    <Badge variant="outline" className="text-[10px] py-0.5 bg-muted/60 text-muted-foreground border-border/40">
                      Property: {unitLabel || '—'}
                    </Badge>
                  </div>
                  <h2 className="text-xl font-bold text-foreground tracking-tight">
                    {ownershipEntity.legal_name}
                  </h2>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    Legal owner of {unitLabel || 'this property'}. Representatives act on behalf of the owner without becoming the legal owner.
                  </p>
                </div>
              </div>

              {ownershipEntity.authorizedPeople && ownershipEntity.authorizedPeople.length > 0 && (
                <div className="flex flex-col gap-1.5 pt-2 lg:pt-0 lg:border-l lg:border-border/30 lg:pl-5">
                  <div className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                    <Users className="w-3.5 h-3.5 text-indigo-400" />
                    <span>Representatives ({ownershipEntity.authorizedPeople.length})</span>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    {ownershipEntity.authorizedPeople.map((rep) => (
                      <div
                        key={rep.id}
                        className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs ${
                          rep.is_current_user
                            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300 font-medium'
                            : 'bg-muted/40 border-border/50 text-foreground'
                        }`}
                      >
                        <span className="font-semibold">{rep.name}</span>
                        <span className="text-[11px] text-muted-foreground">— {rep.role}</span>
                        {rep.is_current_user && (
                          <span className="text-[9px] px-1.5 py-0.2 rounded-full bg-emerald-500/20 text-emerald-400 font-bold uppercase tracking-wider">
                            You
                          </span>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Short-Term / Renter Scope Banners */}
        {isShortTerm && (
          <div className="space-y-3">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl border border-pink-500/30 bg-pink-500/10 text-pink-300 shadow-xs">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-pink-500/20 text-pink-400 shrink-0">
                  <Sparkles className="w-5 h-5" />
                </div>
                <div>
                  <p className="text-xs sm:text-sm font-semibold text-foreground">Short-Term Guest Access Network Active</p>
                  <p className="text-[11px] sm:text-xs text-pink-300/80">
                    Scoped strictly for your stay at {unitLabel || 'your unit'}. Bounded by reservation departure {permissions?.checkoutDate ? `(checkout: ${permissions.checkoutDate})` : ''}.
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2 self-start sm:self-auto shrink-0">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setShowScopeMatrix(!showScopeMatrix)}
                  className="h-7 text-xs px-2 text-pink-300 hover:text-white hover:bg-pink-500/20 gap-1 border border-pink-500/20"
                >
                  <Lock className="w-3 h-3 text-pink-400" />
                  <span>{showScopeMatrix ? 'Hide Scope Matrix' : 'Permissions & Scope'}</span>
                </Button>
                <Badge variant="outline" className="border-pink-500/30 text-pink-400 bg-pink-500/10 text-[10px]">
                  Reservation Bounded
                </Badge>
              </div>
            </div>

            {showScopeMatrix && (
              <Card className="border border-pink-500/20 bg-card/95 backdrop-blur-sm overflow-hidden">
                <CardHeader className="p-4 pb-2 border-b border-border/30">
                  <CardTitle className="text-sm font-bold flex items-center gap-2 text-foreground">
                    <Shield className="w-4 h-4 text-pink-400" />
                    <span>Short-Term Rental Scope & Isolated Access Boundaries</span>
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-4 pt-3 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                  <div className="space-y-2 p-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5">
                    <div className="flex items-center gap-1.5 text-emerald-400 font-semibold text-xs">
                      <CheckCircle2 className="w-4 h-4" />
                      <span>Authorized Visibility & Stay Privileges</span>
                    </div>
                    <ul className="space-y-1.5 text-muted-foreground">
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>My Stay:</strong> Reservation check-in / check-out window</span></li>
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>My Visitors:</strong> Pre-clear social visitors</span></li>
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>My Access:</strong> Dynamic QR gate credential</span></li>
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>Property Services:</strong> Authorized housekeeping (read-only)</span></li>
                    </ul>
                  </div>
                  <div className="space-y-2 p-3 rounded-lg border border-rose-500/20 bg-rose-500/5">
                    <div className="flex items-center gap-1.5 text-rose-400 font-semibold text-xs">
                      <Lock className="w-4 h-4" />
                      <span>Strictly Excluded & Restricted Boundaries</span>
                    </div>
                    <ul className="space-y-1.5 text-muted-foreground">
                      <li className="flex items-center gap-2"><Ban className="w-3.5 h-3.5 text-rose-400 shrink-0" /><span><strong>Homeowner Legacy Contacts:</strong> Excluded</span></li>
                      <li className="flex items-center gap-2"><Ban className="w-3.5 h-3.5 text-rose-400 shrink-0" /><span><strong>Unrelated Residents & Co-Owners:</strong> Excluded</span></li>
                      <li className="flex items-center gap-2"><Ban className="w-3.5 h-3.5 text-rose-400 shrink-0" /><span><strong>Unrestricted Credentials:</strong> Clamped to checkout date</span></li>
                    </ul>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>
        )}

        {isRenter && (
          <div className="space-y-3">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-xl border border-indigo-500/30 bg-indigo-500/10 text-indigo-300 shadow-xs">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-indigo-500/20 text-indigo-400 shrink-0">
                  <ShieldCheck className="w-5 h-5" />
                </div>
                <div>
                  <p className="text-xs sm:text-sm font-semibold text-foreground">Tenant Access Network Active</p>
                  <p className="text-[11px] sm:text-xs text-indigo-300/80">
                    Scoped strictly for your tenancy at {unitLabel || 'your property'}. Authorized passes bounded by your lease {permissions?.leaseEndDate ? `(expires ${permissions.leaseEndDate})` : 'term'}.
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2 self-start sm:self-auto shrink-0">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setShowScopeMatrix(!showScopeMatrix)}
                  className="h-7 text-xs px-2 text-indigo-300 hover:text-white hover:bg-indigo-500/20 gap-1 border border-indigo-500/20"
                >
                  <Lock className="w-3 h-3 text-indigo-400" />
                  <span>{showScopeMatrix ? 'Hide Scope Matrix' : 'Permissions & Scope'}</span>
                </Button>
                <Badge variant="outline" className="border-indigo-500/30 text-indigo-400 bg-indigo-500/10 text-[10px]">
                  Lease Bounded
                </Badge>
              </div>
            </div>

            {showScopeMatrix && (
              <Card className="border border-indigo-500/20 bg-card/95 backdrop-blur-sm overflow-hidden">
                <CardHeader className="p-4 pb-2 border-b border-border/30">
                  <CardTitle className="text-sm font-bold flex items-center gap-2 text-foreground">
                    <Shield className="w-4 h-4 text-indigo-400" />
                    <span>Tenant Access Scope & Enforced Privilege Separation</span>
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-4 pt-3 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                  <div className="space-y-2 p-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5">
                    <div className="flex items-center gap-1.5 text-emerald-400 font-semibold text-xs">
                      <CheckCircle2 className="w-4 h-4" />
                      <span>Authorized Visibility & Tenancy Privileges</span>
                    </div>
                    <ul className="space-y-1.5 text-muted-foreground">
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>Own Household & Occupants:</strong> Co-occupants residing under lease</span></li>
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>Invited Visitors:</strong> Pre-cleared guests</span></li>
                      <li className="flex items-center gap-2"><Check className="w-3.5 h-3.5 text-emerald-400 shrink-0" /><span><strong>Staff & Contractors:</strong> Helpers cleared for {unitLabel}</span></li>
                    </ul>
                  </div>
                  <div className="space-y-2 p-3 rounded-lg border border-amber-500/20 bg-amber-500/5">
                    <div className="flex items-center gap-1.5 text-amber-400 font-semibold text-xs">
                      <Lock className="w-4 h-4" />
                      <span>Separate Homeowner Capabilities (Restricted)</span>
                    </div>
                    <ul className="space-y-1.5 text-muted-foreground">
                      <li className="flex items-center gap-2"><Ban className="w-3.5 h-3.5 text-amber-400 shrink-0" /><span><strong>Remove Homeowner:</strong> Restricted</span></li>
                      <li className="flex items-center gap-2"><Ban className="w-3.5 h-3.5 text-amber-400 shrink-0" /><span><strong>Property Ownership:</strong> Immutable</span></li>
                      <li className="flex items-center gap-2"><Ban className="w-3.5 h-3.5 text-amber-400 shrink-0" /><span><strong>Unrestricted Credentials:</strong> Clamped to lease end</span></li>
                    </ul>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>
        )}

        {/* Category Navigation Bar */}
        <div className="overflow-x-auto pb-2 -mx-4 px-4 sm:mx-0 sm:px-0">
          <div className="flex items-center gap-1.5 min-w-max p-1 bg-muted/30 rounded-xl border border-border/40">
            {activeCategoryTabs.map((tab) => {
              const Icon = tab.icon;
              const count = getTabCount(tab.key);
              const isSelected = selectedCategory === tab.key;

              return (
                <button
                  key={tab.key}
                  onClick={() => {
                    setSelectedCategory(tab.key);
                    if (tab.key !== 'all' && viewMode === 'sections') {
                      setViewMode('cards');
                    }
                  }}
                  className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${
                    isSelected
                      ? 'bg-background text-foreground shadow-sm font-semibold border border-border/80'
                      : 'text-muted-foreground hover:text-foreground hover:bg-background/40'
                  }`}
                >
                  <Icon className={`w-3.5 h-3.5 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`} />
                  <span>{tab.label}</span>
                  <span
                    className={`ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-bold ${
                      isSelected
                        ? 'bg-primary/15 text-primary'
                        : 'bg-muted text-muted-foreground'
                    }`}
                  >
                    {count}
                  </span>
                </button>
              );
            })}
          </div>
        </div>

        {/* Filter & View Bar */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-card/60 p-3 rounded-xl border border-border/40">
          <div className="relative flex-1 min-w-[240px]">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="text"
              placeholder="Search by name, pass ID (GP-...), email, or role..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-9 h-9 text-xs bg-background/60"
            />
          </div>

          <div className="flex items-center gap-2">
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger className="w-[140px] h-9 text-xs bg-background/60">
                <SelectValue placeholder="All Statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active Only</SelectItem>
                <SelectItem value="pending">Pending Approval</SelectItem>
                <SelectItem value="revoked_expired">Expired / Revoked</SelectItem>
              </SelectContent>
            </Select>

            <div className="flex items-center rounded-lg border border-border/40 p-0.5 bg-muted/20">
              {selectedCategory === 'all' && (
                <Button
                  variant={viewMode === 'sections' ? 'secondary' : 'ghost'}
                  size="sm"
                  onClick={() => setViewMode('sections')}
                  className="h-8 px-2.5 text-xs gap-1.5"
                >
                  <Layers className="w-3.5 h-3.5 text-primary" />
                  <span>{isRenter ? '5 Sections' : '7 Sections'}</span>
                </Button>
              )}
              <Button
                variant={viewMode === 'cards' ? 'secondary' : 'ghost'}
                size="sm"
                onClick={() => setViewMode('cards')}
                className="h-8 px-2.5 text-xs"
              >
                Cards
              </Button>
              <Button
                variant={viewMode === 'table' ? 'secondary' : 'ghost'}
                size="sm"
                onClick={() => setViewMode('table')}
                className="h-8 px-2.5 text-xs"
              >
                Table
              </Button>
            </div>
          </div>
        </div>

        {/* Emergency Alert */}
        {authorizedPeople.some((p) => p.isEmergencyActive) && (
          <div className="flex items-center justify-between p-4 rounded-xl border border-red-500/40 bg-red-500/10 text-red-400">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-red-500/20 text-red-500">
                <AlertTriangle className="w-5 h-5 animate-pulse" />
              </div>
              <div>
                <p className="text-sm font-semibold">Active Emergency Access Override Declared</p>
                <p className="text-xs text-red-300/80">
                  Elevated security clearance is actively unlocked for designated emergency delegates.
                </p>
              </div>
            </div>
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                const active = authorizedPeople.find((p) => p.isEmergencyActive);
                if (active) handleDeactivateEmergency(active);
              }}
              className="border-red-500/40 hover:bg-red-500/20 text-red-300 text-xs"
            >
              Deactivate Emergency
            </Button>
          </div>
        )}

        {/* Main Render Views */}
        {viewMode === 'sections' && selectedCategory === 'all' ? (
          <div className="space-y-6">
            {activeSectionsConfig.map((sec) => {
              const SectionIcon = sec.icon;
              let secItems: AuthorizedPerson[] = [];
              if (sec.key === 'residents') secItems = filteredPeople.filter((p) => p.categoryKey === 'residents');
              else if (sec.key === 'long_term_occupants') secItems = filteredPeople.filter((p) => p.categoryKey === 'long_term_occupants');
              else if (sec.key === 'short_term_rentals') secItems = filteredPeople.filter((p) => p.categoryKey === 'short_term_rentals');
              else if (sec.key === 'visitors') secItems = filteredPeople.filter((p) => p.categoryKey === 'visitors');
              else if (sec.key === 'staff') secItems = filteredPeople.filter((p) => ['staff', 'domestic_staff', 'caregivers', 'property_managers'].includes(p.categoryKey));
              else if (sec.key === 'contractors') secItems = filteredPeople.filter((p) => p.categoryKey === 'contractors');
              else if (sec.key === 'legacy_contacts') secItems = filteredPeople.filter((p) => ['legacy_contacts', 'family_members'].includes(p.categoryKey));
              else if (sec.key === 'my_stay') secItems = filteredPeople.filter((p) => p.categoryKey === 'my_stay');
              else if (sec.key === 'my_visitors') secItems = filteredPeople.filter((p) => p.categoryKey === 'my_visitors' || p.categoryKey === 'visitors');
              else if (sec.key === 'my_access') secItems = filteredPeople.filter((p) => p.categoryKey === 'my_access');
              else if (sec.key === 'property_services') secItems = filteredPeople.filter((p) => p.categoryKey === 'property_services' || ['staff', 'domestic_staff', 'caregivers', 'property_managers', 'contractors'].includes(p.categoryKey));

              return (
                <div key={sec.key} className="space-y-3.5 border border-border/50 bg-card/40 rounded-2xl p-5 shadow-xs transition-all">
                  <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-border/30 pb-3">
                    <div className="flex items-center gap-3">
                      <div className={`p-2 rounded-xl border ${sec.badgeColor}`}>
                        <SectionIcon className="w-5 h-5" />
                      </div>
                      <div>
                        <div className="flex items-center gap-2">
                          <h2 className="text-base sm:text-lg font-bold tracking-tight text-foreground">{sec.title}</h2>
                          <Badge variant="outline" className={`text-[10px] font-semibold ${sec.badgeColor}`}>
                            {secItems.length} {secItems.length === 1 ? 'Person' : 'People'}
                          </Badge>
                        </div>
                        <p className="text-xs text-muted-foreground font-medium">{sec.subtitle}</p>
                      </div>
                    </div>

                    {sec.actionLabel && (
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleOpenAuthorizeModal(sec.actionCategory)}
                        className="h-8 text-xs gap-1.5 self-start sm:self-auto border-border/60 hover:bg-muted/30"
                      >
                        <Plus className="w-3.5 h-3.5" />
                        <span>{sec.actionLabel}</span>
                      </Button>
                    )}
                  </div>

                  {secItems.length > 0 ? (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 pt-1">
                      {secItems.map((person) => (
                        <DelegationPersonCard
                          key={person.id}
                          person={person}
                          onViewPass={handleOpenCredentialModal}
                          onEditRules={handleOpenEditRulesModal}
                          onReissuePass={handleReissuePass}
                          onViewAudit={handleViewAudit}
                          onTriggerEmergency={handleOpenEmergencyModal}
                          onDeactivateEmergency={handleDeactivateEmergency}
                          onRevoke={handleOpenRevokeModal}
                        />
                      ))}
                    </div>
                  ) : (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 rounded-xl border border-dashed border-border/60 bg-muted/10 text-xs text-muted-foreground">
                      <div className="flex items-center gap-2.5">
                        <Info className="w-4 h-4 text-muted-foreground/70 shrink-0" />
                        <span>{sec.emptyText}</span>
                      </div>
                      {sec.actionLabel && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleOpenAuthorizeModal(sec.actionCategory)}
                          className="h-7 text-xs text-primary hover:text-primary/80 gap-1"
                        >
                          <span>{sec.actionLabel}</span>
                          <ChevronRight className="w-3 h-3" />
                        </Button>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        ) : viewMode === 'cards' ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {filteredPeople.map((person) => (
              <DelegationPersonCard
                key={person.id}
                person={person}
                onViewPass={handleOpenCredentialModal}
                onEditRules={handleOpenEditRulesModal}
                onReissuePass={handleReissuePass}
                onViewAudit={handleViewAudit}
                onTriggerEmergency={handleOpenEmergencyModal}
                onDeactivateEmergency={handleDeactivateEmergency}
                onRevoke={handleOpenRevokeModal}
              />
            ))}
          </div>
        ) : (
          /* Table View */
          <div className="overflow-x-auto rounded-xl border border-border/40 bg-card/60">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/40 border-b border-border/40 text-muted-foreground uppercase tracking-wider text-[10px]">
                <tr>
                  <th className="py-3 px-4">Person & Role</th>
                  <th className="py-3 px-4">Category</th>
                  <th className="py-3 px-4">Schedule & Rules</th>
                  <th className="py-3 px-4">Credential</th>
                  <th className="py-3 px-4">Status</th>
                  <th className="py-3 px-4 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/20">
                {filteredPeople.map((person) => {
                  const isActive = person.status === 'active' && person.credential?.isActive;
                  return (
                    <tr key={person.id} className="hover:bg-muted/20 transition-colors">
                      <td className="py-3 px-4">
                        <div className="font-semibold text-foreground">{person.name}</div>
                        <div className="text-[11px] text-muted-foreground">{person.email || person.phone || person.relationship}</div>
                      </td>
                      <td className="py-3 px-4">
                        <Badge variant="outline" className={`text-[10px] ${person.categoryBadgeColor}`}>
                          {person.categoryLabel}
                        </Badge>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1.5 mb-1">
                          {person.durationLabel && (
                            <span
                              className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-semibold border ${
                                person.durationType === '2_hours'
                                  ? 'bg-amber-500/10 text-amber-400 border-amber-500/30'
                                  : person.durationType === '1_day'
                                  ? 'bg-blue-500/10 text-blue-400 border-blue-500/30'
                                  : person.durationType === '1_week'
                                  ? 'bg-purple-500/10 text-purple-400 border-purple-500/30'
                                  : person.durationType === 'recurring'
                                  ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                                  : person.durationType === 'manual_revocation'
                                  ? 'bg-teal-500/10 text-teal-400 border-teal-500/30'
                                  : 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30'
                              }`}
                            >
                              <Clock className="w-2.5 h-2.5" />
                              {person.durationLabel}
                            </span>
                          )}
                          <span className="font-medium text-foreground">{person.accessRules?.allowed_days?.join(', ') || 'Everyday'}</span>
                        </div>
                        <div className="text-[10px] text-muted-foreground">
                          {person.accessRules?.entry_start_time || '00:00'} - {person.accessRules?.entry_end_time || '23:59'}
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <div className="flex items-center gap-1.5 font-mono text-[11px]">
                          {renderCredentialShapeIcon(person.credential?.shape)}
                          <span>{person.credential?.passId || 'GP-PENDING'}</span>
                        </div>
                      </td>
                      <td className="py-3 px-4">
                        <span
                          className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium border ${
                            isActive
                              ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                              : 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30'
                          }`}
                        >
                          {person.status.toUpperCase()}
                        </span>
                      </td>
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => handleOpenCredentialModal(person)}
                            className="h-7 text-xs px-2"
                          >
                            <QrCode className="w-3.5 h-3.5 mr-1" /> View Pass
                          </Button>
                          {person.sourceType === 'delegation' && (
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleOpenEditRulesModal(person)}
                              className="h-7 text-xs px-2"
                            >
                              <SlidersHorizontal className="w-3.5 h-3.5" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {filteredPeople.length === 0 && (
          <div className="text-center py-16 border border-dashed border-border/60 rounded-xl bg-card/20 space-y-3">
            <Users className="w-10 h-10 text-muted-foreground/40 mx-auto" />
            <h3 className="text-base font-semibold">No authorized people found</h3>
            <p className="text-xs text-muted-foreground max-w-sm mx-auto">
              There are currently no authorized entries matching your filter criteria. Use the Authorize Person button to issue credentials.
            </p>
            <Button
              size="sm"
              onClick={() => handleOpenAuthorizeModal(selectedCategory !== 'all' ? selectedCategory : 'long_term_occupants')}
              className="gap-2"
            >
              <Plus className="w-4 h-4" /> Authorize New Person
            </Button>
          </div>
        )}

        {/* Subcomponent Modals */}
        <DelegationCredentialModal
          isOpen={isCredentialModalOpen}
          onOpenChange={setIsCredentialModalOpen}
          activePerson={activePerson}
          secondsRemaining={secondsRemaining}
          onCopyLink={(text) => {
            navigator.clipboard.writeText(text);
            toast({ title: 'Copied', description: 'Credential link copied to clipboard.' });
          }}
        />

        <DelegationAuthorizeModal
          isOpen={isAuthorizeModalOpen}
          onOpenChange={setIsAuthorizeModalOpen}
          formData={formData}
          setFormData={setFormData}
          onSubmit={handleCreateAuthorization}
          properties={properties}
          isShortTerm={isShortTerm}
          isRenter={isRenter}
          permissions={permissions}
          applyCategoryDefaults={applyCategoryDefaults}
          handleSelectDurationPreset={handleSelectDurationPreset}
          handleApplyRecurringWorkerTemplate={handleApplyRecurringWorkerTemplate}
          setEndOfYearExpiration={setEndOfYearExpiration}
          applyQuickSchedule={applyQuickSchedule}
        />

        <DelegationEditRulesModal
          isOpen={isEditRulesModalOpen}
          onOpenChange={setIsEditRulesModalOpen}
          activePerson={activePerson}
          editRulesData={editRulesData}
          setEditRulesData={setEditRulesData}
          onSubmit={handleSaveRules}
          isRenter={isRenter}
          permissions={permissions}
          handleSelectEditRulesDurationPreset={handleSelectEditRulesDurationPreset}
          setEndOfYearExpiration={setEndOfYearExpiration}
          applyQuickSchedule={applyQuickSchedule}
        />

        <DelegationEmergencyModal
          isOpen={isEmergencyModalOpen}
          onOpenChange={setIsEmergencyModalOpen}
          activePerson={activePerson}
          reason={emergencyReason}
          setReason={setEmergencyReason}
          durationDays={emergencyDurationDays}
          setDurationDays={setEmergencyDurationDays}
          notes={emergencyNotes}
          setNotes={setEmergencyNotes}
          onSubmit={handleActivateEmergency}
        />

        <DelegationRevokeModal
          isOpen={isRevokeModalOpen}
          onOpenChange={setIsRevokeModalOpen}
          activePerson={activePerson}
          reason={revokeReason}
          setReason={setRevokeReason}
          onSubmit={handleRevoke}
        />

        <DelegationAuditModal
          isOpen={isAuditModalOpen}
          onOpenChange={setIsAuditModalOpen}
          activePerson={activePerson}
          loading={loadingAudit}
          events={auditEvents}
        />

        <DelegationContinuityModal
          isOpen={isContinuityModalOpen}
          onOpenChange={setIsContinuityModalOpen}
          authorizedPeople={authorizedPeople}
          planForm={planForm}
          setPlanForm={setPlanForm}
          continuityConditions={continuityConditions}
          continuityActions={continuityActions}
          onSubmit={handleSaveContinuityPlan}
        />
      </div>
    </DashboardLayout>
  );
}
