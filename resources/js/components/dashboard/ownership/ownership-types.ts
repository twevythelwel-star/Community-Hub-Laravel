export interface Representative {
  id: number;
  user_id: number | null;
  name: string;
  role: string;
  role_label: string;
  email: string | null;
  phone: string | null;
  id_document_type: string | null;
  id_document_number: string | null;
  permissions: string[];
  is_primary_contact: boolean;
  is_signatory: boolean;
  status: string;
  is_active: boolean;
  valid_until: string | null;
  authorized_properties: Array<{
    id: number;
    lot_number: string;
    street_address: string;
  }>;
}

export interface HouseholdMember {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  role_in_household: string;
  relationship_label: string | null;
  pass_category: string;
  credential_label: string;
  role_badge_class: string;
  permissions: string[];
  status: string;
  valid_until: string | null;
  gate_pass: {
    id: number;
    pass_id: string;
    category: string;
    status: string;
    token: string | null;
  } | null;
}

export interface HierarchyPillarItem {
  id: string;
  source_id: number;
  pillar: 'entity_people' | 'occupants' | 'access_relationships' | 'credentials';
  functional_type: string;
  answers: {
    who: {
      name: string;
      email: string | null;
      phone: string | null;
      id_document: string;
      user_id: number | null;
    };
    relationship: {
      type: string;
      label: string;
      is_signatory: boolean;
      is_primary_contact: boolean;
    };
    scope: {
      entity_name: string;
      entity_type: string;
      properties: string[];
    };
    permissions: {
      granted: string[];
      summary: string;
    };
    validity: {
      valid_from: string;
      valid_until: string;
      status: string;
      is_active: boolean;
    };
    authorizer: {
      name: string;
      authority: string;
      source: string;
    };
  };
}

export interface OwnershipProps {
  ownershipType: 'company' | 'individual' | 'trust' | 'estate';
  moduleTitle: string;
  moduleSubtitle: string;
  moduleBadge: string;
  moduleIcon?: string;
  activeEntity: {
    id: number;
    legal_name: string;
    trade_name: string | null;
    entity_type: string;
    entity_type_label: string;
    registration_number: string | null;
    tax_id: string | null;
    jurisdiction: string | null;
    status: string;
    verification_status: string;
    is_verified: boolean;
    metadata: Record<string, any> | null;
  } | null;
  hierarchy: {
    entity: {
      id: number;
      name: string;
      type: string;
      type_label: string;
      registration_number: string | null;
      status: string;
    };
    properties_count: number;
    pillars: {
      entity_people: HierarchyPillarItem[];
      occupants: HierarchyPillarItem[];
      access_relationships: HierarchyPillarItem[];
      credentials: HierarchyPillarItem[];
    };
    summary: {
      total_entity_people: number;
      total_occupants: number;
      total_access_relationships: number;
      total_credentials: number;
    };
  } | null;
  verification: {
    is_verified: boolean;
    verification_status: string;
    verified_at: string | null;
    verified_by: string | null;
    verification_source: string | null;
    checklist: Record<string, boolean>;
    corporate_profile?: {
      registration_number?: string;
      incorporation_date?: string;
      good_standing?: boolean;
      directors?: Array<{ name: string; position?: string }>;
      shareholders?: Array<{ name: string; percentage?: number; type?: string }>;
    };
    trust_profile?: {
      trust_deed_reference?: string;
      settlement_date?: string;
      trustees?: Array<{ name: string }>;
    };
    estate_profile?: {
      grant_type?: string;
      court_suit_number?: string;
      deceased_name?: string;
      executors?: Array<{ name: string }>;
    };
    individual_profile?: {
      national_id_verified?: boolean;
      trn_verified?: boolean;
      title_reference?: string;
    };
  } | null;
  beneficialOwnership: {
    is_restricted: boolean;
    confidentiality_notice: string;
    data: {
      declaration_reference?: string;
      filing_date?: string;
      beneficial_owners?: Array<{
        full_name: string;
        nationality?: string;
        ownership_percentage?: number;
        nature_of_control?: string;
      }>;
    } | null;
  } | null;
  canViewBeneficialOwnership: boolean;
  representatives: Representative[];
  household: {
    id: number;
    name: string;
    property_number: string;
    address: string;
    members: HouseholdMember[];
  } | null;
  properties: Array<{
    id: number;
    lot_number: string;
    property_code: string;
    street_address: string;
    property_type: string;
    status: string;
    primary_occupant: { name: string; email: string } | null;
  }>;
  availableEntities: Array<{
    id: number;
    legal_name: string;
    entity_type: string;
    entity_type_label: string;
    property_count: number;
  }>;
  availableRoles: Array<{ value: string; label: string }>;
  availableHouseholdPermissions: Record<string, string>;
  canManage: boolean;
}
