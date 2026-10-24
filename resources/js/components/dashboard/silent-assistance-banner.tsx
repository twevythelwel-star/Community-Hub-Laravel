import { ShieldAlert } from 'lucide-react';
import type { SilentAssistanceSignal } from '@/lib/gate-pass-engine/types';

const ASSISTANCE_LABEL: Record<SilentAssistanceSignal['assistance'], string> = {
  police: 'Police',
  medical: 'Medical',
  fire: 'Fire',
  security: 'Security',
  contact: 'Emergency contact',
};

/**
 * Shown on the guard's screen when a resident has silently asked for help.
 *
 * Deliberately quiet: no sound, no flashing, nothing someone standing at the
 * gate would notice. The guard carries on as normal; the security desk has
 * already been alerted.
 */
export function SilentAssistanceBanner({ signal }: { signal?: SilentAssistanceSignal | null }) {
  if (!signal) return null;

  return (
    <div
      role="status"
      className="flex items-start gap-3 rounded-lg border border-red-600/40 bg-red-600/10 p-3 text-sm text-red-700 dark:text-red-300"
    >
      <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <div className="space-y-0.5">
        <p className="font-semibold">Silent assistance request · {ASSISTANCE_LABEL[signal.assistance] ?? signal.assistance}</p>
        <p className="text-xs opacity-90">{signal.instruction}</p>
        <p className="text-[11px] opacity-75">
          {signal.channel} · alert #{signal.alertId}
        </p>
      </div>
    </div>
  );
}
