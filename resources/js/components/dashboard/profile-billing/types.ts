export interface SavedMethod {
  id: number;
  methodType: string;
  walletType?: string | null;
  brand?: string;
  lastFour?: string;
  displayName?: string;
  isDefault: boolean;
}

export interface PaymentPreferencesData {
  preferred_payment?: 'apple_pay' | 'google_pay' | 'samsung_pay' | 'card' | 'bank_transfer' | string;
  default_payment_method?: string;
  notifications?: {
    payment_confirmation?: boolean;
    receipt?: boolean;
    failed_payment?: boolean;
    refund?: boolean;
  };
}

export interface BillingData {
  outstandingBalance: number;
  currency: string;
  currencySymbol: string;
  latestInvoice?: {
    id: number;
    invoiceNumber: string;
    amountMinor: number;
    balanceRemainingMinor: number;
    dueDate?: string;
  };
  savedPaymentMethods: SavedMethod[];
  config: {
    isConfigured: boolean;
    isTestMode: boolean;
    cardProvider: string;
    enabledWallets: string[];
    publicKey?: string | null;
    hasInPersonProvider?: boolean;
    inPersonProvider?: string;
  };
  paymentPreferences?: PaymentPreferencesData;
}
