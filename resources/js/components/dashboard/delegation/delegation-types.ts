import {
  Clock,
  Calendar,
  CalendarRange,
  SlidersHorizontal,
  RefreshCw,
  Lock,
  Building,
  HeartPulse,
  Sparkles,
  Waves,
  Car,
  HardHat,
  Home,
  ShieldCheck,
  Users,
  Key,
  UserCheck,
  UserX,
  QrCode,
} from 'lucide-react';

export interface AuthorizedPerson {
  id: string;
  sourceType: 'user' | 'delegation' | 'renter' | 'visitor' | 'household_member';
  sourceId: number;
  categoryKey:
    | 'residents'
    | 'long_term_occupants'
    | 'short_term_rentals'
    | 'legacy_contacts'
    | 'family_members'
    | 'caregivers'
    | 'property_managers'
    | 'domestic_staff'
    | 'staff'
    | 'contractors'
    | 'visitors'
    | 'former_occupants'
    | 'my_stay'
    | 'my_visitors'
    | 'my_access'
    | 'property_services';
  categoryLabel: string;
  categoryBadgeColor: string;
  name: string;
  email?: string | null;
  phone?: string | null;
  relationship: string;
  roleOrTitle: string;
  propertyLabel: string;
  propertyId?: number | null;
  status: string;
  isEmergencyActive?: boolean;
  startsAt?: string | null;
  expiresAt?: string | null;
  durationType?: string | null;
  durationLabel?: string | null;
  activationMethod?: string | null;
  approvalStatus?: string | null;
  permissions: Record<string, string> | string[];
  accessRules: Record<string, any>;
  credential?: {
    passId: string;
    category: string;
    categoryCode: string;
    shape: string;
    status: string;
    isActive: boolean;
    validFrom?: string | null;
    validUntil?: string | null;
  } | null;
  canManage?: boolean;
  raw: any;
}

export interface ContinuityPlan {
  id?: number;
  primary_delegate_id: number | null;
  secondary_delegate_id: number | null;
  activation_conditions: string[];
  required_verification: string;
  authorized_actions: string[];
  max_duration_days: number;
  requires_admin_approval: boolean;
  notify_homeowner_on_trigger: boolean;
  notify_community_security: boolean;
  special_instructions: string | null;
  primary_delegate?: { name: string; email: string };
  secondary_delegate?: { name: string; email: string };
}

export interface HomeownerSection {
  key: string;
  title: string;
  subtitle: string;
  description: string;
  count: number;
  items: AuthorizedPerson[];
  emptyMessage: string;
  actionLabel: string;
}

export interface AccessPermissions {
  canSee: {
    ownHousehold: boolean;
    ownVisitors: boolean;
    propertyStaff: boolean;
    propertyContractors: boolean;
    ownLegacyContacts: boolean;
    otherProperties: boolean;
    entireCommunity: boolean;
    homeownerFinancials: boolean;
    shortTermRentals: boolean;
    propertyOwnership: boolean;
  };
  canAct: {
    removeHomeowner: boolean;
    changePropertyOwnership: boolean;
    viewHomeownerFinancials: boolean;
    createPermanentCredentials: boolean;
    modifyCommunitySettings: boolean;
    preClearVisitor: boolean;
    authorizeStaff: boolean;
    authorizeContractor: boolean;
    addOccupants: boolean;
    manageLegacyContacts: boolean;
    revokeOwnCredentials: boolean;
  };
  isShortTerm?: boolean;
  isRenter: boolean;
  isHomeowner: boolean;
  isAdmin: boolean;
  roleLabel: string;
  leaseEndDate?: string | null;
  checkoutDate?: string | null;
  scopeBadge?: string;
}

export interface Props {
  authorizedPeople?: AuthorizedPerson[];
  categoryCounts?: Record<string, number>;
  homeownerSections?: HomeownerSection[];
  sections?: HomeownerSection[];
  permissions?: AccessPermissions;
  scopeBadge?: string;
  pageSubtitle?: string;
  unitLabel?: string;
  pageTitle?: string;
  contacts?: any[];
  delegates?: any[];
  longTermOccupants?: any[];
  allDelegations?: any[];
  continuityPlan: ContinuityPlan | null;
  properties: Array<{ id: number; lot_number: string; street_address: string }>;
  accessLevels: Record<string, string>;
  relationships: string[];
  availablePermissions: Record<string, string>;
  authorizationTypes?: Array<{
    value: string;
    label: string;
    tier: string;
    isResident: boolean;
    defaultRules: any;
  }>;
  continuityConditions: Record<string, string>;
  continuityVerifications: Record<string, string>;
  continuityActions: Record<string, string>;
  durationTypes?: Record<string, string>;
  ownershipEntity?: {
    id: number;
    legal_name: string;
    entity_type: string;
    jurisdiction?: string;
    ownerLabel: string;
    authorizedPeople?: Array<{
      id: number;
      user_id?: number | null;
      name: string;
      role: string;
      role_key: string;
      permissions?: string[];
      is_primary?: boolean;
      is_current_user?: boolean;
    }>;
  } | null;
}

export const DURATION_PRESETS = [
  {
    key: '2_hours',
    label: '2 Hours',
    shortLabel: '2 Hours',
    icon: Clock,
    badgeColor: 'bg-amber-500/10 text-amber-500 border-amber-500/30',
    description: 'Instant entry for deliveries, quick maintenance & urgent visits',
  },
  {
    key: '1_day',
    label: '1 Day',
    shortLabel: '1 Day',
    icon: Calendar,
    badgeColor: 'bg-blue-500/10 text-blue-500 border-blue-500/30',
    description: 'Single-day access for cleaners, babysitters, or party guests',
  },
  {
    key: '1_week',
    label: '1 Week',
    shortLabel: '1 Week',
    icon: CalendarRange,
    badgeColor: 'bg-purple-500/10 text-purple-500 border-purple-500/30',
    description: 'Extended short stay for contractors, project teams, or visiting relatives',
  },
  {
    key: 'custom',
    label: 'Custom Range',
    shortLabel: 'Custom',
    icon: SlidersHorizontal,
    badgeColor: 'bg-sky-500/10 text-sky-500 border-sky-500/30',
    description: 'Explicit start and expiration calendar dates with precision hours',
  },
  {
    key: 'recurring',
    label: 'Recurring Days',
    shortLabel: 'Recurring',
    icon: RefreshCw,
    badgeColor: 'bg-emerald-500/10 text-emerald-500 border-emerald-500/30',
    description: 'Scheduled weekly recurring days (e.g., Mon/Wed/Fri housekeepers & caregivers)',
  },
  {
    key: 'manual_revocation',
    label: 'Until Revoked',
    shortLabel: 'Until Revoked',
    icon: Lock,
    badgeColor: 'bg-teal-500/10 text-teal-500 border-teal-500/30',
    description: 'Continuous authorized access until homeowner or security manually revokes',
  },
];

export const RECURRING_WORKER_TEMPLATES = [
  {
    name: 'Domestic Worker',
    role: 'Housekeeper / Maid',
    relationship: 'Domestic Worker',
    categoryKey: 'domestic_staff',
    accessLevel: 'Domestic Staff',
    authType: 'domestic_staff',
    days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    startTime: '08:00',
    endTime: '17:00',
    icon: Building,
    badge: 'Mon–Fri 8am–5pm',
    desc: 'Regular household cleaning & upkeep',
  },
  {
    name: 'Caregiver',
    role: 'Private Caregiver / Nurse',
    relationship: 'Caregiver',
    categoryKey: 'caregivers',
    accessLevel: 'Caregiver',
    authType: 'caregiver',
    days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    startTime: '07:00',
    endTime: '19:00',
    icon: HeartPulse,
    badge: 'Mon–Fri 7am–7pm',
    desc: 'Patient assistance, medication & personal care',
  },
  {
    name: 'Gardener',
    role: 'Landscaper / Grounds',
    relationship: 'Gardener',
    categoryKey: 'domestic_staff',
    accessLevel: 'Domestic Staff',
    authType: 'domestic_staff',
    days: ['Mon', 'Wed', 'Fri'],
    startTime: '08:00',
    endTime: '16:00',
    icon: Sparkles,
    badge: 'Mon/Wed/Fri 8am–4pm',
    desc: 'Lawn, garden & outdoor grounds maintenance',
  },
  {
    name: 'Pool Maintenance',
    role: 'Pool Technician',
    relationship: 'Pool Maintenance',
    categoryKey: 'contractors',
    accessLevel: 'Contractor',
    authType: 'contractor',
    days: ['Tue', 'Fri'],
    startTime: '08:00',
    endTime: '13:00',
    icon: Waves,
    badge: 'Tue & Fri 8am–1pm',
    desc: 'Pool chemical balancing & cleaning service',
  },
  {
    name: 'Private Driver',
    role: 'Chauffeur / Driver',
    relationship: 'Driver',
    categoryKey: 'domestic_staff',
    accessLevel: 'Domestic Staff',
    authType: 'domestic_staff',
    days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
    startTime: '06:00',
    endTime: '20:00',
    icon: Car,
    badge: 'Mon–Sat 6am–8pm',
    desc: 'Dedicated resident transportation & errands',
  },
  {
    name: 'Service Provider',
    role: 'Long-Term Contractor',
    relationship: 'Long-Term Service Provider',
    categoryKey: 'contractors',
    accessLevel: 'Contractor',
    authType: 'contractor',
    days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    startTime: '08:00',
    endTime: '17:00',
    icon: HardHat,
    badge: 'Mon–Fri 8am–5pm',
    desc: 'Recurring facility, HVAC or technical contractor',
  },
];

export const HOMEOWNER_SECTIONS_CONFIG = [
  {
    key: 'residents',
    title: 'Residents',
    subtitle: 'Homeowner(s) • Household members',
    description: 'Primary homeowners and verified household residents living in the home',
    icon: Home,
    badgeColor: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    emptyText: 'No other household members currently registered for this property.',
    actionCategory: 'residents',
    actionLabel: 'Add Household Member',
  },
  {
    key: 'long_term_occupants',
    title: 'Long-Term Guests / Renters',
    subtitle: 'Authorized long-term occupants',
    description: 'Authorized extended occupants and long-term lease tenants residing in the home',
    icon: Key,
    badgeColor: 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
    emptyText: 'No active long-term occupants or lease tenants registered.',
    actionCategory: 'long_term_occupants',
    actionLabel: 'Add Long-Term Occupant',
  },
  {
    key: 'short_term_rentals',
    title: 'Short-Term Rental',
    subtitle: 'Active Airbnb/short-term occupants, if applicable',
    description: 'Active Airbnb reservations and temporary short-term occupants',
    icon: Sparkles,
    badgeColor: 'bg-pink-500/10 text-pink-400 border-pink-500/30',
    emptyText: 'No active Airbnb or short-term occupants currently staying.',
    actionCategory: 'short_term_rentals',
    actionLabel: 'Register Short-Term Guest',
  },
  {
    key: 'visitors',
    title: 'Visitors',
    subtitle: 'Current and upcoming visitors',
    description: 'Pre-cleared day visitors, deliveries, and expected guests',
    icon: UserCheck,
    badgeColor: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30',
    emptyText: 'No current or upcoming visitors scheduled.',
    actionCategory: 'visitors',
    actionLabel: 'Pre-Clear Visitor',
  },
  {
    key: 'staff',
    title: 'Staff',
    subtitle: 'Domestic workers, caregivers, drivers, property managers',
    description: 'Authorized household staff with recurring or designated work schedules',
    icon: Building,
    badgeColor: 'bg-teal-500/10 text-teal-400 border-teal-500/30',
    emptyText: 'No dedicated household staff currently authorized.',
    actionCategory: 'domestic_staff',
    actionLabel: 'Authorize Staff Member',
  },
  {
    key: 'contractors',
    title: 'Contractors',
    subtitle: 'Electricians, plumbers, repair specialists',
    description: 'Authorized maintenance contractors and repair personnel',
    icon: HardHat,
    badgeColor: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
    emptyText: 'No active contractors currently cleared for entry.',
    actionCategory: 'contractors',
    actionLabel: 'Authorize Contractor',
  },
  {
    key: 'legacy_contacts',
    title: 'Legacy Contacts',
    subtitle: 'Active legacy/emergency delegates',
    description: 'Designated emergency contacts, attorneys, and succession delegates',
    icon: ShieldCheck,
    badgeColor: 'bg-purple-500/10 text-purple-400 border-purple-500/30',
    emptyText: 'No active legacy contacts or emergency delegates configured.',
    actionCategory: 'legacy_contacts',
    actionLabel: 'Add Legacy Contact',
  },
];

export const CATEGORY_TABS = [
  { key: 'all', label: 'All (7 Sections)', icon: Users, desc: 'Complete property access overview' },
  { key: 'residents', label: 'Residents', icon: Home, desc: 'Homeowner(s) & household members' },
  { key: 'long_term_occupants', label: 'Long-Term Guests / Renters', icon: Key, desc: 'Authorized long-term occupants' },
  { key: 'short_term_rentals', label: 'Short-Term Rental', icon: Sparkles, desc: 'Active Airbnb/short-term occupants' },
  { key: 'visitors', label: 'Visitors', icon: UserCheck, desc: 'Current and upcoming visitors' },
  { key: 'staff', label: 'Staff', icon: Building, desc: 'Staff authorized for this unit' },
  { key: 'contractors', label: 'Contractors', icon: HardHat, desc: 'Contractors authorized for this unit' },
  { key: 'legacy_contacts', label: 'Legacy Contacts', icon: ShieldCheck, desc: 'Active legacy/emergency delegates' },
  { key: 'former_occupants', label: 'Former Occupants', icon: UserX, desc: 'Expired and revoked records' },
];

export const RENTER_SECTIONS_CONFIG = [
  {
    key: 'residents',
    title: 'Household & Occupants',
    subtitle: 'Your household & authorized co-occupants',
    description: 'Verified co-occupants and household members residing under your lease',
    icon: Home,
    badgeColor: 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
    emptyText: 'No additional household members or authorized co-occupants registered for your lease.',
    actionCategory: 'long_term_occupants',
    actionLabel: 'Add Co-Occupant',
  },
  {
    key: 'visitors',
    title: 'Visitors',
    subtitle: 'Visitors authorized for your unit',
    description: 'Current and upcoming visitors invited by you',
    icon: UserCheck,
    badgeColor: 'bg-teal-500/10 text-teal-400 border-teal-500/30',
    emptyText: 'No current or upcoming visitors scheduled for your unit.',
    actionCategory: 'visitors',
    actionLabel: 'Pre-Clear Visitor',
  },
  {
    key: 'staff',
    title: 'Staff',
    subtitle: 'Staff authorized for your property',
    description: 'Domestic workers, cleaners, and service providers authorized for your unit',
    icon: Building,
    badgeColor: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    emptyText: 'No dedicated staff currently authorized for your unit.',
    actionCategory: 'domestic_staff',
    actionLabel: 'Authorize Staff Member',
  },
  {
    key: 'contractors',
    title: 'Contractors',
    subtitle: 'Contractors authorized for your property',
    description: 'Maintenance contractors, electricians, and technicians cleared for your unit',
    icon: HardHat,
    badgeColor: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
    emptyText: 'No active contractors currently cleared for your unit.',
    actionCategory: 'contractors',
    actionLabel: 'Authorize Contractor',
  },
  {
    key: 'legacy_contacts',
    title: 'Legacy Contacts',
    subtitle: 'Your emergency & continuity delegates',
    description: 'Designated personal emergency contacts and trusted delegates authorized for your tenancy',
    icon: ShieldCheck,
    badgeColor: 'bg-purple-500/10 text-purple-400 border-purple-500/30',
    emptyText: 'No emergency contacts configured for your lease.',
    actionCategory: 'legacy_contacts',
    actionLabel: 'Add Emergency Contact',
  },
];

export const RENTER_CATEGORY_TABS = [
  { key: 'all', label: 'All (5 Sections)', icon: Users, desc: 'Complete tenancy access overview' },
  { key: 'residents', label: 'Household & Occupants', icon: Home, desc: 'Your household & co-occupants' },
  { key: 'visitors', label: 'Visitors', icon: UserCheck, desc: 'Visitors invited by you' },
  { key: 'staff', label: 'Staff', icon: Building, desc: 'Staff authorized for your unit' },
  { key: 'contractors', label: 'Contractors', icon: HardHat, desc: 'Contractors authorized for your unit' },
  { key: 'legacy_contacts', label: 'Legacy Contacts', icon: ShieldCheck, desc: 'Your emergency delegates' },
  { key: 'former_occupants', label: 'Former Occupants', icon: UserX, desc: 'Expired and revoked records' },
];

export const SHORT_TERM_SECTIONS_CONFIG = [
  {
    key: 'my_stay',
    title: 'My Stay',
    subtitle: 'Reservation & Property Scope',
    description: 'Reservation lead details, host contact, check-in and checkout dates',
    icon: Calendar,
    badgeColor: 'bg-pink-500/10 text-pink-400 border-pink-500/30',
    emptyText: 'No reservation record found.',
    actionCategory: '',
    actionLabel: '',
  },
  {
    key: 'my_visitors',
    title: 'My Visitors',
    subtitle: 'Guests registered for your stay',
    description: 'Pre-cleared day guests and companions registered for your reservation',
    icon: UserCheck,
    badgeColor: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30',
    emptyText: 'No visitors registered for your stay.',
    actionCategory: 'visitors',
    actionLabel: 'Pre-Clear Visitor',
  },
  {
    key: 'my_access',
    title: 'My Access',
    subtitle: 'Digital Gate Pass & Scanner Clearance',
    description: 'Your verified dynamic QR credential for gate scanner ingress',
    icon: QrCode,
    badgeColor: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    emptyText: 'No gate credential active for this stay.',
    actionCategory: '',
    actionLabel: '',
  },
  {
    key: 'property_services',
    title: 'Property Services',
    subtitle: 'Authorized property staff & contractors',
    description: 'Authorized maintenance, housekeeping, or emergency contractor contacts for the property (read-only)',
    icon: Building,
    badgeColor: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
    emptyText: 'No external property services scheduled during your stay.',
    actionCategory: '',
    actionLabel: '',
  },
];

export const SHORT_TERM_CATEGORY_TABS = [
  { key: 'all', label: 'All (4 Sections)', icon: Users, desc: 'Complete stay overview' },
  { key: 'my_stay', label: 'My Stay', icon: Calendar, desc: 'Reservation & Property' },
  { key: 'my_visitors', label: 'My Visitors', icon: UserCheck, desc: 'Your registered visitors' },
  { key: 'my_access', label: 'My Access', icon: QrCode, desc: 'QR code & gate clearance' },
  { key: 'property_services', label: 'Property Services', icon: Building, desc: 'Authorized property staff' },
];
