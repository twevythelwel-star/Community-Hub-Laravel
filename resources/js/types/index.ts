
export type UserRole = "System Admin" | "Admin" | "Homeowner" | "Temporary Homeowner" | "Security" | "Staff";

export type Homeowner = {
  id: string;
  name: string;
  lot: string;
  contact: string;
  active: boolean;
};

export type Renter = {
  id: string | number;
  name: string;
  stayType?: 'Long-term (Renter)' | 'Short-term (Airbnb)';
  contact?: string | null;
  notes?: string | null;
  status: 'Active' | 'Inactive' | 'Expired';
  leaseStart: Date | string;
  leaseEnd: Date | string;
  lot?: string | null;
  street?: string | null;
  expired?: boolean;
  homeownerName?: string | null;
  homeownerId?: string | number | null;
};

export type VisitorStatus = "Expected" | "Checked In" | "Checked Out";
export type EntryType = "onetime" | "recurring";

export type Visitor = {
  id: string | number;
  name: string;
  contact?: string | null;
  vehicle?: string | null;
  idType?: string | null;
  type: "One-time" | "Recurring";
  status: VisitorStatus;
  expectedAt: Date | string;
  dateRange: string | null;
  homeowner: string | null;
  idImageUrl?: string | null;
  isBlocked: boolean;
  expired?: boolean;
  shareToken?: string | null;
  guestPassUrl?: string | null;
};

export type Staff = {
  id: string;
  name: string;
  job: string;
  idType: "National ID" | "Driver's License" | "Passport";
  idNumber: string;
  idExpiry: Date;
  idImageUrl?: string;
  property: string; // e.g. "Lot 42, Main St"
  addedBy: string; // UID of homeowner/renter/admin
  status: 'Active' | 'Inactive' | 'Expired ID';
  photoUrl?: string;
}

export type Notification = {
  id: string;
  title: string;
  content: string;
  timestamp: Date;
  author: string;
};

export type Warning = {
  id:string;
  title: string;
  description: string;
  timestamp: Date;
  author: string;
  confirms: number;
  denies: number;
  userStatus: 'confirmed' | 'denied' | null;
};

export type CommunityEvent = {
  id: string;
  title: string;
  description: string;
  startDate: Date;
  endDate?: Date;
  imageUrl?: string;
};

export type CommunityUpdate = {
  id: string;
  title: string;
  date: Date;
  summary: string;
};

export type Feedback = {
  id: string;
  submittedBy: string;
  userRole: UserRole;
  timestamp: Date;
  type: 'Issue' | 'Suggestion';
  subject: string;
  status: 'New' | 'In Progress' | 'Resolved';
};

export type ActivityLogEntry = {
    id: string;
    timestamp: Date;
    action: string;
}

export type AdminUser = {
    id: string;
    name: string;
    email: string;
    status: 'Active' | 'Inactive';
    createdAt: Date;
    activity: ActivityLogEntry[];
}

/*
 * `ManagedUser` was removed with the directory wiring. Its `lotNumber` /
 * `streetName` fields never matched the server, which sends `lot` and `street`,
 * so keeping it around only invited the next page to adopt the wrong shape.
 * The directory's row type now lives beside its form, in
 * components/dashboard/create-user-form.tsx, as `DirectoryUser`.
 */

export type BlocklistEntry = {
    id: string;
    name: string;
    photoUrl: string | null;
    reason: string;
    dateAdded: Date;
    expiryDate: Date | null; // null for permanent
    addedBy: string;
}

export type Business = {
  id: string | number;
  name: string;
  logoUrl?: string | null;
  aiHint?: string | null;
}

export type Voucher = {
  id: string | number;
  businessId?: string | number;
  title: string;
  description: string;
  expiresAt?: string | null;
}

export type FoodApp = {
  id: string | number;
  name: string;
  logoUrl?: string | null;
  websiteUrl: string;
  aiHint?: string | null;
  couponPercentage?: number | null;
}

/*
 * `Fundraiser` and `Donation` were removed with the fundraising wiring, for the
 * same reason as `ManagedUser` and `AccessLogEntry`: they described something
 * the server does not send. `Fundraiser` had no `raised`, `progress` or
 * `donorCount`, pinned `goalCurrency` to the literal 'JMD', and typed `id` as a
 * string. `Donation` allowed five currencies on a single fundraiser, which is
 * precisely the shape that made the raised total wrong — the server sums minor
 * units and converts nothing. The row type now lives with its component, in
 * components/dashboard/fundraiser-progress-card.tsx, as `FundraiserRow`.
 */

export type Guideline = {
  id: string;
  category: string;
  title: string;
  description: string;
};

/*
 * `AccessLogEntry` was removed with the access-log wiring, for the same reason
 * as `ManagedUser`: it described something the server does not send. It had no
 * `result`, `denyReason` or `passId` — so the type itself was part of why the
 * page could not show a refused entry — typed `id` as a string, and closed
 * `gate` to three literals when the column is free text that the visitor API
 * will accept anything for. The row type now lives with its page, in
 * Pages/Dashboard/AccessLog.tsx, as `LogRow`.
 */

declare global {
  function route(name?: string, params?: any, absolute?: boolean): string;
}
