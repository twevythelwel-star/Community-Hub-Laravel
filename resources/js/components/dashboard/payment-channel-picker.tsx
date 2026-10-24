import {
  channelHint,
  channelIcon,
  channelLabel,
  channelSurcharge,
  type PaymentChannel,
} from '@/lib/payment-channels';
import { cn } from '@/lib/utils';

/**
 * Choose a payment method from the estate's configured channels.
 *
 * The list comes from the server, so Billing and Fundraising offer the same
 * methods and an administrator's channel settings apply to both.
 *
 * Each option shows its own surcharge. That is the point of rendering this from
 * data rather than from hardcoded buttons: the surcharge column has existed on
 * `payment_channel_settings` all along and was never shown to anyone paying.
 */

type PaymentChannelPickerProps = {
  channels: PaymentChannel[];
  value: string | null;
  onChange: (channelKey: string) => void;
  /** Labels the group for screen readers, e.g. "How would you like to pay?" */
  legend: string;
  disabled?: boolean;
};

export function PaymentChannelPicker({
  channels,
  value,
  onChange,
  legend,
  disabled = false,
}: PaymentChannelPickerProps) {
  if (channels.length === 0) {
    return (
      <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
        No payment methods are enabled. An administrator can turn them on from the payments
        settings.
      </p>
    );
  }

  return (
    <fieldset disabled={disabled} className="space-y-2">
      <legend className="text-sm font-medium">{legend}</legend>

      <div className="grid gap-2 sm:grid-cols-2">
        {channels.map((channel) => {
          const Icon = channelIcon(channel.key);
          const surcharge = channelSurcharge(channel);
          const hint = channelHint(channel.key);
          const selected = value === channel.key;
          const inputId = `channel-${channel.key}`;

          return (
            <label
              key={channel.key}
              htmlFor={inputId}
              className={cn(
                'flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 text-left transition-colors',
                'focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1',
                selected
                  ? 'border-primary bg-primary/5'
                  : 'border-border hover:border-primary/50 hover:bg-muted/40',
                disabled && 'cursor-not-allowed opacity-60',
              )}
            >
              {/*
                A real radio input rather than a styled div, so the group is
                reachable by keyboard and announced as a radio group.
              */}
              <input
                type="radio"
                id={inputId}
                name="payment-channel"
                value={channel.key}
                checked={selected}
                onChange={() => onChange(channel.key)}
                className="mt-0.5 h-4 w-4 shrink-0 accent-primary"
              />

              <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />

              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium leading-tight">
                  {channelLabel(channel)}
                </span>

                {hint && (
                  <span className="mt-0.5 block text-xs text-muted-foreground">{hint}</span>
                )}

                {surcharge > 0 ? (
                  <span className="mt-1 block text-xs font-medium text-amber-700 dark:text-amber-400">
                    Adds a {surcharge}% fee
                  </span>
                ) : (
                  <span className="mt-1 block text-xs text-muted-foreground">No added fee</span>
                )}

                {channel.account_identifier && (
                  <span className="mt-1 block truncate text-[11px] text-muted-foreground">
                    {channel.account_identifier}
                  </span>
                )}
              </span>
            </label>
          );
        })}
      </div>
    </fieldset>
  );
}
