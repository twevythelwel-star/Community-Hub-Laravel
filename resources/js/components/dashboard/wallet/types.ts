export type WalletCredential = {
  id: number;
  pass_id: string;
  holder_name: string;
  relationship_label: string;
  role_in_household: string;
  category: string;
  category_label: string;
  shape: string;
  variant: {
    id: string;
    label: string;
    colors: {
      background: string;
      foreground: string;
      accent: string;
      border: string;
    };
  };
  property: string;
  status: string;
  status_label: string;
  is_active: boolean;
  offline_pin: string;
  qr: {
    token: string | null;
    valid_until: string | null;
    seconds_remaining: number;
    envelope_prefix: string;
    standard: string;
  };
  nfc: {
    card_uid: string;
    payload: string;
    protocol: string;
    frequency: string;
    status: string;
    ndef_mime: string;
  };
  wallet_integrations: {
    apple_wallet: {
      available: boolean;
      file_name: string;
      download_url: string;
      badge_label: string;
    };
    google_wallet: {
      available: boolean;
      save_url: string;
      badge_label: string;
    };
  };
  schedule?: {
    curfew_enabled?: boolean;
    curfew_hours?: string;
    days?: string[];
    hours?: string;
    valid_until?: string;
  } | null;
  permissions?: string[];
  designated_gate: string;
  expiry_date: string;
};

export type RevokedCredential = {
  id: number;
  pass_id: string;
  holder_name: string;
  category: string;
  category_label: string;
  property: string;
  status: string;
  revoked_at?: string;
  revoked_at_formatted?: string;
  revocation_reason?: string;
  offline_pin?: string;
};
