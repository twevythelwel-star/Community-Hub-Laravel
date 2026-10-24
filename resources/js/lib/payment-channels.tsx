import {
  Banknote,
  CreditCard,
  Landmark,
  QrCode,
  Smartphone,
  Nfc,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

/**
 * The payment methods the estate offers, shared by Billing and Fundraising.
 *
 * Both pages receive the same list from
 * `PaymentOrchestratorService::getAvailableChannels()`, which reads
 * `payment_channel_settings` — so an administrator disabling a channel,
 * renaming it or setting a surcharge affects both places at once.
 *
 * `payment-center-hero.tsx` used to take `availableChannels` as a prop and then
 * render eleven hardcoded buttons regardless of it, so a disabled channel was
 * still offered and a custom label never appeared. The metadata below supplies
 * only the icon and a fallback label; everything else comes from the server.
 */

export type PaymentChannel = {
  key: string;
  label: string;
  instructions?: string | null;
  account_identifier?: string | null;
  fee_surcharge_percent?: number;
  /** True for channels the office must confirm before the payment counts. */
  requires_confirmation?: boolean;
};

type ChannelPresentation = {
  icon: LucideIcon;
  /** Used only when the server sends no display label for the channel. */
  fallbackLabel: string;
  /** Short note about how the method works, shown under the label. */
  hint?: string;
};

export const CHANNEL_PRESENTATION: Record<string, ChannelPresentation> = {
  card: { icon: CreditCard, fallbackLabel: 'Debit / Credit Card' },
  apple_pay: { icon: Smartphone, fallbackLabel: 'Apple Pay' },
  google_pay: { icon: Smartphone, fallbackLabel: 'Google Pay' },
  samsung_wallet: { icon: Smartphone, fallbackLabel: 'Samsung Wallet' },
  bank_wire: { icon: Landmark, fallbackLabel: 'Bank Transfer' },
  zelle: { icon: Smartphone, fallbackLabel: 'Zelle' },
  cash_app: { icon: Smartphone, fallbackLabel: 'Cash App' },
  qr_code: { icon: QrCode, fallbackLabel: 'QR Code' },
  nfc_pos: { icon: Nfc, fallbackLabel: 'Tap to Pay / Contactless', hint: 'Card-present terminal reader required' },
  cash_office: { icon: Banknote, fallbackLabel: 'Cash at the Office', hint: 'Pay in person' },
  wallet: { icon: Wallet, fallbackLabel: 'Community Wallet' },
};

export function channelIcon(key: string): LucideIcon {
  return CHANNEL_PRESENTATION[key]?.icon ?? CreditCard;
}

export function channelLabel(channel: PaymentChannel): string {
  return channel.label || CHANNEL_PRESENTATION[channel.key]?.fallbackLabel || channel.key;
}

export function channelHint(key: string): string | undefined {
  return CHANNEL_PRESENTATION[key]?.hint;
}

/**
 * The surcharge a channel adds, as a percentage.
 *
 * Returned rather than hidden: `payment_channel_settings.fee_surcharge_percent`
 * was passed to the payment UI and never displayed, while a badge next to it
 * read "Zero Surcharges". A fee that exists in the schema and is not shown to
 * the person paying it is a hidden fee.
 */
export function channelSurcharge(channel: PaymentChannel): number {
  return Number(channel.fee_surcharge_percent ?? 0);
}

/** The amount actually taken once a channel's surcharge is applied. */
export function totalWithSurcharge(amount: number, channel: PaymentChannel): number {
  const surcharge = channelSurcharge(channel);

  if (surcharge <= 0) {
    return amount;
  }

  return Math.round(amount * (1 + surcharge / 100) * 100) / 100;
}
