export type GateOption = {
  id: string;
  name: string;
};

export type RecentScan = {
  id: number;
  userName: string;
  userRole: string;
  result: string;
  gate: string;
  occurredAt: string;
  denyReason?: string | null;
};

export type ScanOutcome = {
  decision: 'CHECK_IN' | 'CHECK_OUT' | 'REJECT';
  status: string;
  scanId: string | null;
  isPassbackViolation?: boolean;
  canOverride?: boolean;
  checkedInAt?: string | null;
  report: {
    passId: string;
    category: string;
    userName: string;
    property?: string;
    gate?: string;
    primaryReason?: string;
    denyReason?: string;
    isPassbackViolation?: boolean;
    isPassbackOverride?: boolean;
    overrideReason?: string;
    checkedInAt?: string;
    details?: string[];
  };
  occurredAt: string;
};

export interface SensorEvent {
  id: number;
  gate: string;
  is_tailgating: boolean;
  resolution_status: string;
  authorized_occupants: number;
  detected_occupants: number;
  occurred_at: string;
  sensor_metadata?: { device_name?: string } | null;
}

export type GateScannerProps = {
  gates: GateOption[];
  recentScans: RecentScan[];
  guard: {
    name: string;
    role: string;
  };
};
