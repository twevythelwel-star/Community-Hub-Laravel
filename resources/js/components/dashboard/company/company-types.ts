export interface VerificationSummary {
  status: string;
  is_verified: boolean;
  verified_at: string | null;
  verifier_name: string;
  source: string;
  notes: string | null;
  checklist: Record<string, any>;
  entity_type: string;
  entity_type_label: string;
  coj_details: {
    registration_number: string;
    company_type: string;
    incorporation_date: string;
    directors: { name: string; role: string; appointed_at: string; coj_verified: boolean }[];
    shareholders: { name: string; shares: string; voting_pct: string }[];
    registered_office: string;
    annual_return_filed: boolean;
    last_return_date: string;
  } | null;
  trust_details: any | null;
  estate_details: any | null;
  individual_details: any | null;
}

export interface BeneficialOwnerItem {
  full_name: string;
  nationality: string;
  id_trn: string;
  percentage_interest: number;
  control_nature: string;
  pep_status: boolean;
  verified_at: string;
}

export interface BeneficialOwnershipProfile {
  is_restricted: boolean;
  statutory_notice: string;
  confidentiality_standard: string;
  declaration_reference: string | null;
  filing_date: string | null;
  coj_form_reference: string | null;
  beneficial_owners: BeneficialOwnerItem[];
}

export interface CompanyInfo {
  id: number;
  legal_name: string;
  trade_name: string | null;
  entity_type: string;
  entity_type_label: string;
  registration_number: string | null;
  jurisdiction: string | null;
  primary_email: string | null;
  primary_phone: string | null;
  billing_address: string | null;
  status: string;
  verification_status?: string;
  is_verified?: boolean;
  verification_source?: string;
  metadata: Record<string, any> | null;
}

export interface PropertyItem {
  id: number;
  lot_number: string;
  property_code: string;
  street_address: string;
  property_type: string;
  status: 'occupied' | 'vacant' | 'rented';
  status_label: string;
  status_color: 'emerald' | 'amber' | 'blue';
  is_selected: boolean;
  occupant_name: string;
  occupant: {
    id: number;
    name: string;
    phone: string | null;
    email: string;
  } | null;
  renter: {
    id: number;
    name: string;
    stay_type: string;
    lease_end: string | null;
  } | null;
  stats: {
    residents_count: number;
    renters_count: number;
    visitors_count: number;
    staff_count: number;
    contractors_count: number;
    vehicles_count: number;
    parking_count: number;
    pending_balance_minor: number;
  };
}

export interface ResidentItem {
  id: string;
  name: string;
  role: string;
  unit: string;
  property_id: number;
  email: string;
  phone: string;
  status: string;
  residing_since: string;
  badge: string;
}

export interface RenterItem {
  id: number;
  name: string;
  unit: string;
  property_id: number;
  stay_type: string;
  contact: string;
  status: string;
  lease_start: string;
  lease_end: string;
  is_expired: boolean;
  notes: string | null;
}

export interface VisitorItem {
  id: number;
  name: string;
  type: string;
  unit: string;
  property_id: number;
  status: string;
  status_label: string;
  expected_at: string;
  checked_in_at: string | null;
  host_name: string | null;
  is_blocked: boolean;
}

export interface StaffItem {
  id: number;
  name: string;
  role: string;
  access_level: string;
  unit: string;
  property_id: number;
  phone: string;
  email: string;
  status: string;
  schedule: string[];
  time_window: string;
}

export interface ContractorItem {
  id: number;
  name: string;
  trade: string;
  unit: string;
  property_id: number;
  phone: string;
  email: string;
  status: string;
  starts_at: string;
  expires_at: string;
  notes: string;
}

export interface LegacyContactItem {
  id: number;
  name: string;
  relationship: string;
  unit: string;
  property_id: number;
  phone: string;
  email: string;
  status: string;
  notes: string;
}

export interface RepresentativeItem {
  id: number;
  user_id: number | null;
  name: string;
  role: string;
  role_label: string;
  corporate_title: string;
  email: string | null;
  phone: string | null;
  is_primary_contact: boolean;
  is_signatory: boolean;
  verification_status: string;
  status: string;
  permissions: string[];
  managed_properties_summary: string;
  authorized_property_ids: number[] | null;
}

export interface VehicleItem {
  id: number;
  license_plate: string;
  make: string;
  model: string;
  year: number | null;
  color: string;
  unit: string;
  parking_location: string;
  is_ev: boolean;
  anpr_enabled: boolean;
  status: string;
  description: string;
}

export interface ParkingPassItem {
  id: number;
  pass_id: string;
  category: string;
  category_label: string;
  license_plate: string;
  unit: string;
  assigned_bay: string;
  holder_name: string;
  valid_from: string;
  valid_until: string;
  status: string;
  is_ev_charging: boolean;
}

export interface CredentialItem {
  id: number;
  pass_id: string;
  category: string;
  category_label: string;
  holder_name: string;
  unit: string;
  access_zone: string;
  designated_gate: string;
  status: string;
  valid_from: string;
  valid_until: string;
  credential_type: string;
}

export interface IncidentItem {
  id: number;
  title: string;
  gate: string;
  flag_type: string;
  risk_level: string;
  description: string;
  status: string;
  resolution_notes: string | null;
  occurred_at: string;
}

export interface PaymentItem {
  id: number;
  reference: string;
  unit: string;
  property_id: number;
  amount_formatted: string;
  amount_minor: number;
  currency: string;
  due_on: string;
  paid_at: string | null;
  status: string;
  status_label: string;
  period: string;
}

export interface DocumentItem {
  id: number;
  title: string;
  document_type: string;
  document_type_label: string;
  holder_name: string;
  file_name: string;
  file_size_formatted: string;
  mime_type: string;
  issued_at: string;
  expires_at: string;
  verification_status: string;
  status: string;
}

export interface SummaryKpis {
  total_properties: number;
  occupied_count: number;
  vacant_count: number;
  rented_count: number;
  total_residents: number;
  total_renters: number;
  total_visitors: number;
  total_staff: number;
  total_contractors: number;
  total_legacy_contacts: number;
  total_representatives: number;
  total_vehicles: number;
  total_parking: number;
  total_credentials: number;
  total_incidents: number;
  total_documents: number;
  total_pending_dues_minor: number;
  total_paid_dues_minor: number;
}

export interface UserAuth {
  is_admin: boolean;
  role: string;
  role_label: string;
  is_signatory: boolean;
  is_primary_contact: boolean;
  permissions: string[];
  can_manage_visitors: boolean;
  can_manage_renters: boolean;
  can_manage_staff: boolean;
  can_manage_contractors: boolean;
  can_manage_vehicles: boolean;
  can_manage_billing: boolean;
}

export interface CompanyDashboardProps {
  company: CompanyInfo;
  available_companies: { id: number; legal_name: string; trade_name: string | null; status: string }[];
  properties: PropertyItem[];
  selected_property_id: number | 'all';
  selected_property: { id: number; lot_number: string; property_code: string; street_address: string; status: string; occupant_name: string } | null;
  summary: SummaryKpis;
  people_and_access: {
    residents: ResidentItem[];
    renters: RenterItem[];
    visitors: VisitorItem[];
    staff: StaffItem[];
    contractors: ContractorItem[];
    legacy_contacts: LegacyContactItem[];
    representatives: RepresentativeItem[];
  };
  operations: {
    vehicles: VehicleItem[];
    parking: ParkingPassItem[];
    credentials: CredentialItem[];
    incidents: IncidentItem[];
    payments: PaymentItem[];
    documents: DocumentItem[];
  };
  current_user_authorization: UserAuth;
  verification?: VerificationSummary;
  beneficial_ownership?: BeneficialOwnershipProfile;
  can_view_beneficial_ownership?: boolean;
}
