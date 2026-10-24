import { usePage } from '@inertiajs/react';

export type MessagingChannels = { sms: boolean; whatsapp: boolean };

/**
 * Which pass-delivery channels have a provider behind them, shared by
 * HandleInertiaRequests. The visitor forms disable SMS and WhatsApp when
 * these are false instead of offering an option that sends nothing.
 */
export function useMessaging(): MessagingChannels {
  const { messaging } = usePage<{ messaging?: MessagingChannels | null }>().props;

  return { sms: messaging?.sms ?? false, whatsapp: messaging?.whatsapp ?? false };
}
