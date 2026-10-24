/** Shapes the billing page receives from BillingController and hands to its tabs. */

export type OutstandingItem = {
  id: number;
  reference: string;
  homeowner: string;
  lot: string;
  amount: number;
  currency: string;
  dueOn: string;
  daysOverdue: number;
  status: string;
  lastRemindedAt?: string | null;
  reminderCount?: number;
  reminderStatus?: 'queued' | 'sent' | 'delivered' | 'failed' | null;
};

export type PaymentPlanItem = {
  id: number;
  invoiceId: number;
  invoiceReference: string;
  homeowner: string;
  lot: string;
  totalInstallments: number;
  remainingInstallments: number;
  installmentAmount: number;
  frequency: string;
  status: string;
};

export type ReceiptItem = {
  id: number;
  receiptNumber: string;
  reference: string;
  homeowner: string;
  lot: string;
  amount: number;
  currency: string;
  channel: string;
  date: string;
  receiptUrl: string;
};

export type RefundItem = {
  id: number;
  reference: string;
  homeowner: string;
  lot: string;
  amount: number;
  currency: string;
  channel: string;
  date: string;
  notes: string | null;
};

export type ChannelCheck = {
  name: string;
  passed: boolean;
  message: string;
};

export type ChannelReport = {
  channel_key: string;
  label: string;
  enabled: boolean;
  is_ready: boolean;
  status: 'ready' | 'needs_configuration';
  integration_mode?: 'API' | 'HOSTED_CHECKOUT' | 'WEBHOOK' | 'BANK_RECONCILIATION' | 'MANUAL_VERIFICATION';
  checks: ChannelCheck[];
  account_identifier?: string;
  instructions?: string;
  validated_at: string;
};

export type AutoPayPortfolio = {
  enrolledCount: number;
  cadenceBreakdown: Record<string, number>;
  nextRunDate: string;
};

export type PaymentEventItem = {
  id: number;
  eventId: string;
  type: string;
  status: string;
  errorMessage: string | null;
  date: string;
};

export type TriPartyReconciliation = {
  currency: string;
  communityHubLedger: {
    total_minor: number;
    total: number;
    settled_transactions_count: number;
    status: string;
  };
  paymentProvider: {
    name: string;
    total_minor: number;
    total: number;
    status: string;
  };
  bank: {
    name: string;
    total_minor: number;
    total: number;
    statement_date: string;
    status: string;
  };
  variance: {
    amount_minor: number;
    amount: number;
    is_balanced: boolean;
    state: string;
  };
};

export type LedgerAccount = {
  id: number;
  code: string;
  name: string;
  type: string;
  currency: string;
  balance: number;
  entriesCount: number;
  description: string;
};

export const formatMoney = (amount: number, currency: string = 'JMD') =>
  `${currency} ${Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
