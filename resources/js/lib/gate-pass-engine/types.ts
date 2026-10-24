/**
 * Enterprise Digital Gate Pass Engine - Type Definitions
 * Standardized across Community Hub and connected ecosystem APIs.
 */

export type PassCategory = 
  | 'SYSADMIN'
  | 'ADMIN' 
  | 'HOMEOWNER' 
  | 'RENTER' 
  | 'STAFF' 
  | 'SECURITY' 
  | 'HOMEOWNER_STAFF'
  | 'VISITOR'
  | 'CONTRACTOR'
  | 'DELEGATE'
  | 'LONG_TERM_OCCUPANT';

export type QRShape = 
  | 'STAR_8'          // ⭐ 8-point / star frame (Highest administrative tier)
  | 'OCTAGON'         // 🛡️ Octagonal frame (Property administration)
  | 'HEXAGON'         // ⬡ Hexagonal frame (Property owner)
  | 'ROUNDED_SQUARE'  // ▢ Rounded square frame (Resident/renter)
  | 'CIRCLE'          // ◉ Circle frame (Registered visitor)
  | 'DIAMOND'         // ◆ Diamond frame (Community staff)
  | 'SHIELD'          // 🛡 Shield frame (Security personnel)
  | 'HOUSE_HEX'       // ⬢ Custom hex/house frame (Staff assigned to homeowner)
  | 'PENTAGON'        // ⬟ Pentagon frame (Approved contractor)
  | 'TICKET'          // 🎟 Notched ticket frame (Authorized delegate)
  | 'ARCH';           // ⌂ Doorway arch frame (Long-term occupant)

/** Where a pass is in its life. Mirrors App\Enums\PassStatus. */
export type PassStatus =
  | 'REQUESTED'
  | 'APPROVED'
  | 'ISSUED'
  | 'ACTIVE'
  | 'CHECKED_IN'
  | 'CHECKED_OUT'
  | 'REJECTED'
  | 'CANCELLED'
  | 'REVOKED'
  | 'EXPIRED'
  | 'SUSPENDED';

/** What the server tells the guard to do with a scan. */
export type ScanDecision = 'CHECK_IN' | 'CHECK_OUT' | 'REJECT';

export type GateId = 'GATE-01' | 'GATE-02' | 'GATE-ANY';

export interface ClearanceZone {
  id: string;
  name: string;
  description: string;
  accessLevel: 'STANDARD' | 'RESTRICTED' | 'OVERRIDE_ONLY';
}

export interface AccessPolicy {
  category: PassCategory;
  title: string;
  description: string;
  authorizedZones: string[];
  allowedGates: GateId[];
  operationalHours: {
    is24Hours: boolean;
    startHour?: number; // e.g. 7 for 07:00
    endHour?: number;   // e.g. 17 for 17:00
    daysOfWeek?: number[]; // 0=Sun, 1=Mon, ..., 6=Sat
  };
  privileges: {
    canManageGuests: boolean;
    canAssociateVehicles: boolean;
    hasEmergencyOverride: boolean;
    hasGateOperationOverride: boolean;
    restrictedFromHomeownerFunctions: boolean;
  };
}

export interface CategoryVisualConfig {
  category: PassCategory;
  displayName: string;
  shape: QRShape;
  shapeLabel: string;
  themeColor: string;       // Default brand hex
  contrastBg: string;       // High contrast background
  accentColor: string;      // Accent / highlight hex (dual-tone)
  badgeBorder: string;      // Border styling
  gradient: string;         // Tailwind gradient class
  iconName: string;         // Descriptive icon name
  description: string;
}

/**
 * Approved, WCAG AA-tested color variant for controlled randomization.
 */
export interface ApprovedColorVariant {
  id: string;
  name: string;
  hex: string;
  accentHex: string;
  contrastRatio: number;    // Against #FFFFFF (minimum 4.5:1 required)
  wcagPass: boolean;
}

/**
 * Profile palette configuration.
 */
export interface ProfilePaletteConfig {
  category: PassCategory;
  shape: QRShape;
  shapeLabel: string;
  palette: ApprovedColorVariant[];
  randomizationEnabled: boolean;
}

/**
 * Standardized payload stored inside the signed QR code.
 * Recognized by Digital Gate Pass Engine (GPE).
 */
export interface GatePassTokenPayload {
  gpe: string;        // Gate Pass Engine identifier ("GPE")
  cid: string;        // Community ID e.g. "CID-CYPRESS-BAY"
  v: number;          // Protocol version (1)
  pid: string;        // Pass ID e.g. GP-SYS-001
  cat: PassCategory;  // Profile Type
  zone: string;       // Access Zone e.g. "ZONE-ALL", "ZONE-RESIDENTIAL"
  uid: string;        // Anonymized user ID
  nam: string;        // User display name
  prop: string;       // Property identifier
  gate: GateId;       // Designated gate or GATE-ANY
  vf: number;         // Valid From unix timestamp (seconds)
  vu: number;         // Valid Until unix timestamp (seconds)
  nonce: string;      // Cryptographic anti-replay nonce
  t: number;          // Issuance timestamp (seconds)
  sig: string;        // HMAC-SHA256 signature
  cvar?: string;      // Assigned approved color variant name/id
  seq?: number;       // Visual regeneration / rotation sequence
}

export type ValidationStatus = 'ALLOW' | 'DENY';

export type DenyReason = 
  | 'INVALID_QR_STRUCTURE'
  | 'NOT_A_GPE_GATE_PASS'
  | 'UNAUTHORIZED_COMMUNITY_ID'
  | 'CRYPTOGRAPHIC_SIGNATURE_MISMATCH'
  | 'TOKEN_EXPIRED'
  | 'TOKEN_NOT_YET_VALID'
  | 'REPLAY_ATTACK_DETECTED'
  | 'PASS_REVOKED_BY_ADMIN'
  | 'OUTSIDE_PERMITTED_HOURS'
  | 'UNAUTHORIZED_GATE'
  | 'USER_NOT_ACTIVE';

export interface ValidationStageResult {
  passed: boolean;
  stage: 'STRUCTURE' | 'SIGNATURE' | 'SERVER_CACHE' | 'ACCESS_POLICY';
  message: string;
  timestamp: string;
}

/**
 * A resident's silent request for help, raised by a duress PIN or a credential
 * they armed with the discreet QR gesture. Shown to the guard only.
 */
export interface SilentAssistanceSignal {
  alertId: number;
  assistance: 'police' | 'medical' | 'fire' | 'security' | 'contact';
  channel: string;
  instruction: string;
}

export interface GatePassValidationReport {
  /** Present only when the passholder has silently asked for help. */
  silentAssistance?: SilentAssistanceSignal;
  status: ValidationStatus;
  /** What the guard should do. Decided by the server from the pass's state. */
  decision?: ScanDecision;
  denyReason?: string | null;
  passStatus?: PassStatus | null;
  primaryReason: string;
  category: PassCategory;
  profile?: string;
  shape: QRShape;
  communityId: string;
  accessZone: string;
  passId: string;
  property: string;
  userName: string;
  person?: string;
  host?: string;
  passType?: string;
  gate?: string;
  gateChecked: GateId;
  validUntil?: string;
  photoUrl?: string | null;
  stages: {
    structure: ValidationStageResult;
    cryptography: ValidationStageResult;
    serverCache: ValidationStageResult;
    accessPolicy: ValidationStageResult;
  };
  checks: {
    shapeCategoryMatch: boolean;
    colorClassMatch: boolean;
    payloadAuthenticity: boolean;
    cryptographicSignature: boolean;
    physicalGateAuth: boolean;
    temporalTimeAuth: boolean;
    communityMatch?: boolean;
    passRegistered?: boolean;
    profileMatch?: boolean;
    personMatch?: boolean;
    propertyMatch?: boolean;
    notRevoked?: boolean;
    statusEligible?: boolean;
    validityPeriod?: boolean;
    personVerified?: boolean;
    replayFree?: boolean;
    zoneAuth?: boolean;
  };
  visualIdentity?: {
    shape: QRShape;
    colorVariantName: string;
    colorHex: string;
    isApprovedPalette: boolean;
    contrastRatio: number;
    wcagPass: boolean;
    rotationSequence?: number;
    securityNote: string;
  };
  policy: AccessPolicy;
  issuedAt: Date;
  expiresAt: Date;
  secondsRemaining: number;
}


