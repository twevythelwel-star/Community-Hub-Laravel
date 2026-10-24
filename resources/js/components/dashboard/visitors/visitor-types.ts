import type { PassCategory, PassStatus } from '@/lib/gate-pass-engine/types';
import type { VisitorStatus } from '@/types';
import type { HostProperty } from '@/components/dashboard/register-visitor-dialog';

export type VisitorRow = {
  id: number;
  name: string;
  contact: string | null;
  vehicle: string | null;
  idType: string | null;
  type: string;
  status: VisitorStatus;
  expectedAt: string;
  dateRange: string | null;
  homeowner: string | null;
  hostLot?: string | null;
  timeDisplay?: string | null;
  idImageUrl: string | null;
  isBlocked: boolean;
  expired: boolean;
  checkedInAt?: string | null;
  checkedOutAt?: string | null;
  shareToken?: string | null;
  guestPassUrl?: string | null;
  notify_email?: boolean;
  notify_sms?: boolean;
  notify_whatsapp?: boolean;
  arrivalWindow?: string | null;
  arrivalWindowStart?: string | null;
  arrivalWindowEnd?: string | null;
  parkingInstructions?: string | null;
  communityRules?: string[] | null;
  emergencyInfo?: Record<string, string> | null;
  notes?: string | null;
  /** The visitor's gate pass. Absent for visitors registered before passes existed. */
  pass?: VisitorPass | null;
};

export type VisitorPass = {
  id: number;
  passId: string;
  category: PassCategory;
  status: PassStatus;
  statusLabel: string;
  validFrom: string | null;
  validUntil: string | null;
  singleEntry: boolean;
  color: { hex: string; name: string };
  /** The lifecycle moves this viewer may make, decided by the server. */
  actions: { status: PassStatus; label: string }[];
};

/** Passes whose holder cannot be admitted right now, whatever the button says. */
export const NOT_ADMISSIBLE: PassStatus[] = ['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED', 'REVOKED', 'EXPIRED', 'SUSPENDED'];

/** Moves that must say why. */
export const NEEDS_REASON: PassStatus[] = ['REJECTED', 'SUSPENDED', 'REVOKED'];

export type Paginated<T> = {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  total: number;
  from: number | null;
  to: number | null;
};

export type VisitorsPageProps = {
  visitors: Paginated<VisitorRow>;
  filters: { status?: string; search?: string; tab?: string };
  tabCounts?: {
    scan?: number;
    expected: number;
    inside: number;
    checkedOut: number;
    rejected: number;
    history: number;
  };
  securityStats?: {
    visitorsToday: number;
    expected: number;
    currentlyInside: number;
    checkedOut: number;
    rejected: number;
    suspiciousAttempts: number;
  };
  activeTab?: string;
  canManage: boolean;
  canRegister: boolean;
  graceHours: number;
  /** The host's properties; with several, registering asks which is visited. */
  hostProperties?: HostProperty[];
  userStay?: {
    stayType: string;
    leaseStart: string;
    leaseEnd: string;
    expired?: boolean;
  } | null;
};

export function statusVariant(status: VisitorStatus): 'default' | 'secondary' | 'outline' {
  switch (status) {
    case 'Checked In':
      return 'default';
    case 'Checked Out':
      return 'secondary';
    default:
      return 'outline';
  }
}
