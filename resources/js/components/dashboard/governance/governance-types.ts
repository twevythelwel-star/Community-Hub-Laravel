export interface RiskIncident {
  id: number;
  pass_id: string | null;
  user_id: number | null;
  gate: string;
  flag_type: string;
  risk_level: 'CRITICAL' | 'HIGH' | 'MEDIUM' | 'LOW';
  title: string;
  description: string;
  evidence: Record<string, any> | null;
  status: 'NEW' | 'REVIEWED' | 'RESOLVED' | 'LOCKED_DOWN';
  resolution_notes: string | null;
  occurred_at: string;
  created_at: string;
  user?: { name: string; email: string };
  resolver?: { name: string };
}

export interface ApprovalRequest {
  id: number;
  request_number: string;
  category: string;
  applicant_name: string;
  applicant_email: string | null;
  applicant_phone: string | null;
  property_number: string;
  workflow_type: string;
  current_stage: string;
  status: 'pending' | 'approved' | 'rejected';
  approval_chain: Array<{
    stage: string;
    action: string;
    actor_name: string;
    timestamp: string;
    note?: string;
  }>;
  valid_from: string | null;
  valid_until: string | null;
  notes: string | null;
  pass_id: number | null;
  created_at: string;
  homeowner?: { name: string };
  pass?: { pass_id: string; status: string };
}

export interface ComplianceDocument {
  id: number;
  title: string;
  document_type: string;
  holder_name: string;
  file_name: string;
  status: 'valid' | 'expiring_soon' | 'expired';
  issued_at: string | null;
  expires_at: string;
  verification_status: string;
  gate_pass_id: number | null;
  gate_pass?: { pass_id: string; status: string; holder_name: string };
}

export interface AuditTimelineEvent {
  id: number;
  pass_id: string;
  holder_name: string;
  gate: string;
  event_type: string;
  severity: 'INFO' | 'NOTICE' | 'WARNING' | 'CRITICAL';
  headline: string;
  description: string | null;
  actor_type: string;
  telemetry: Record<string, any> | null;
  occurred_at: string;
}

export interface VisibilityRule {
  role: string;
  own_property: string | boolean;
  other_properties: boolean;
  entire_community: string | boolean;
  can_see: string[];
  can_act: string[];
  cannot_act: string[];
}

export interface UserScope {
  role: string;
  associatedProperties: string[];
}

export interface AccessGovernanceMetrics {
  activeIncidents: number;
  criticalIncidents: number;
  pendingApprovals: number;
  expiringDocuments: number;
  expiredPassesToday: number;
}

export interface AccessGovernanceProps {
  incidents: RiskIncident[];
  approvalRequests: ApprovalRequest[];
  documents: ComplianceDocument[];
  recentTimeline: AuditTimelineEvent[];
  visibilityMatrix?: VisibilityRule[];
  userScope?: UserScope | null;
  metrics: AccessGovernanceMetrics;
}
