export type OccupancySummary = {
  residents: number;
  long_term_guests: number;
  short_term_guests?: number;
  visitors: number;
  staff: number;
  contractors: number;
  legacy_contacts: number;
  total: number;
};

export type UnitSummaryItem = {
  unit: string;
  total: number;
  currentlyInside?: number;
  expectedToday?: number;
  residents: number;
  long_term_guests: number;
  short_term_guests?: number;
  visitors: number;
  staff: number;
  contractors: number;
  legacy_contacts: number;
};

export type Occupant = {
  id: string;
  type: string;
  passId: string;
  name: string;
  categoryKey: string;
  categoryLabel: string;
  role: string;
  property: string;
  unit: string;
  host: string | null;
  authorizedBy?: string | null;
  accessType?: string;
  qrStatus?: string;
  checkedInAt: string | null;
  checkedInTime: string;
  checkedInDuration: string;
  gate: string;
  vehicle: string | null;
  contact: string | null;
  avatarUrl: string | null;
  status: string;
  checkInStatus?: 'INSIDE' | 'EXPECTED' | 'OUT';
  expiration?: string;
  relationshipChain?: {
    person: string;
    property: string;
    relationship: string;
    authorizedBy: string;
    accessType: string;
    visibilityScope: string;
    can: string[];
    cannot: string[];
    summary: string;
  };
};

export type MusterRollCallItem = {
  id: number;
  occupant_id: string;
  name: string;
  pass_id: string | null;
  unit: string | null;
  category: string;
  status: 'safe' | 'missing' | 'evacuated' | 'needs_assistance' | 'checked_out' | 'unverified';
  status_label: string;
  contact: string | null;
  vehicle: string | null;
  notes: string | null;
  marked_at: string | null;
  marked_by_name: string | null;
};

export type EnclosureZone = {
  id: string;
  name: string;
  type: string;
  description: string;
  icon: string;
  status: string;
  badge: string;
  occupant_count: number;
};

export type ActiveMusterData = {
  id: number;
  incident_type: 'fire' | 'hurricane' | 'earthquake' | 'flood' | 'security_incident' | 'drill' | 'other';
  incident_label: string;
  title: string;
  assembly_point: string;
  notes: string | null;
  started_at: string;
  started_time: string;
  initiator_name: string;
  counts: {
    total: number;
    safe: number;
    missing: number;
    evacuated: number;
    needs_assistance: number;
    checked_out: number;
    unverified: number;
  };
  roll_calls: MusterRollCallItem[];
};

export type CommunityPropertyNode = {
  id: string;
  unit: string;
  lot: string;
  address: string;
  owner?: string;
  ownershipType?: string;
  isCorporate?: boolean;
  representatives?: Array<{
    id: number;
    name: string;
    role: string;
    role_key?: string;
    is_primary?: boolean;
    permissions?: string[];
  }>;
  insideCount: number;
  expectedCount: number;
  categoriesInside: Record<string, number>;
  categoriesExpected: Record<string, number>;
  breakdown: Array<{
    key: string;
    label: string;
    inside: number;
    expected: number;
  }>;
  occupants: Array<{
    name: string;
    category: string;
    status: string;
  }>;
};

export type CommunityHierarchyData = {
  communityName: string;
  isScoped: boolean;
  scopedUnit?: string | null;
  totalInside: number;
  totalExpected: number;
  properties: CommunityPropertyNode[];
};

export type OccupancyPageProps = {
  summary: OccupancySummary;
  expectedSummary?: OccupancySummary | null;
  unitSummary?: OccupancySummary | null;
  unitExpectedSummary?: OccupancySummary | null;
  units: UnitSummaryItem[];
  selectedUnit?: string | null;
  selectedCategory?: string | null;
  search?: string | null;
  activeTab?: string;
  communityHierarchy?: CommunityHierarchyData | null;
  occupants: {
    data: Occupant[];
    total: number;
    current_page: number;
    per_page: number;
    last_page: number;
  };
  canManage: boolean;
  userUnit?: string | null;
  activeMuster?: ActiveMusterData | null;
  enclosureZones?: EnclosureZone[];
  lastUpdated: string;
};
