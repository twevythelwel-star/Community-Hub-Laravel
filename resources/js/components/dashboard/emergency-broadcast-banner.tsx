import React, { useState, useEffect } from 'react';
import { usePage, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  Siren,
  X,
  ChevronRight,
  ShieldAlert,
  PhoneCall,
  Clock,
  ExternalLink,
  CheckCircle2,
  Info
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export interface EmergencyAlertData {
  id?: string | number;
  title: string;
  description: string;
  author_name?: string;
  issued_at?: string;
  severity?: 'critical' | 'warning' | 'advisory';
  actionUrl?: string;
}

interface EmergencyBroadcastBannerProps {
  alert?: EmergencyAlertData | null;
  className?: string;
}

export function EmergencyBroadcastBanner({ alert, className }: EmergencyBroadcastBannerProps) {
  const { props } = usePage<{
    activeAlert?: EmergencyAlertData | null;
  }>();

  // Pick up alert from prop or global Inertia shared props
  const currentAlert: EmergencyAlertData | null = alert || props.activeAlert || null;

  const [isDismissed, setIsDismissed] = useState<boolean>(true); // start true to prevent flash before checking storage
  const [detailsOpen, setDetailsOpen] = useState<boolean>(false);

  const alertStorageKey = currentAlert?.id ? `community_alert_dismissed_${currentAlert.id}` : null;

  useEffect(() => {
    if (!currentAlert) {
      setIsDismissed(true);
      return;
    }

    if (alertStorageKey && typeof window !== 'undefined') {
      const dismissed = localStorage.getItem(alertStorageKey);
      setIsDismissed(dismissed === 'true');
    } else {
      setIsDismissed(false);
    }
  }, [currentAlert, alertStorageKey]);

  const handleDismiss = (e: React.MouseEvent) => {
    e.stopPropagation();
    setIsDismissed(true);
    if (alertStorageKey && typeof window !== 'undefined') {
      localStorage.setItem(alertStorageKey, 'true');
    }
  };

  if (!currentAlert || isDismissed) {
    return null;
  }

  const isCritical = currentAlert.severity === 'critical';
  const isWarning = currentAlert.severity === 'warning' || !currentAlert.severity;

  return (
    <>
      <aside
        role="alert"
        aria-live="polite"
        className={cn(
          'relative w-full overflow-hidden rounded-xl border p-3 sm:px-4 sm:py-3 transition-all duration-200 shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3',
          isCritical
            ? 'bg-rose-500/10 border-rose-500/30 text-rose-950 dark:text-rose-100'
            : isWarning
            ? 'bg-amber-500/10 border-amber-500/30 text-amber-950 dark:text-amber-100'
            : 'bg-blue-500/10 border-blue-500/30 text-blue-950 dark:text-blue-100',
          className
        )}
      >
        <div className="flex items-center gap-3 min-w-0 flex-1">
          <div
            className={cn(
              'p-2 rounded-lg shrink-0 flex items-center justify-center',
              isCritical
                ? 'bg-rose-600 text-white animate-pulse'
                : isWarning
                ? 'bg-amber-500 text-white'
                : 'bg-blue-600 text-white'
            )}
          >
            {isCritical ? (
              <Siren className="w-4 h-4" />
            ) : (
              <AlertTriangle className="w-4 h-4" />
            )}
          </div>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <Badge
                variant="outline"
                className={cn(
                  'text-[10px] font-bold uppercase tracking-wider px-1.5 py-0',
                  isCritical
                    ? 'border-rose-600 text-rose-600 dark:border-rose-400 dark:text-rose-300'
                    : 'border-amber-600 text-amber-700 dark:border-amber-400 dark:text-amber-300'
                )}
              >
                {isCritical ? 'Emergency Broadcast' : 'Estate Advisory'}
              </Badge>
              <span className="font-semibold text-xs sm:text-sm truncate">
                {currentAlert.title}
              </span>
            </div>
            <p className="text-xs opacity-90 truncate max-w-3xl mt-0.5">
              {currentAlert.description}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 shrink-0 self-end sm:self-center">
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setDetailsOpen(true)}
            className="h-8 text-xs font-semibold gap-1 px-2.5 hover:bg-background/40"
          >
            <span>View Details</span>
            <ChevronRight className="w-3.5 h-3.5" />
          </Button>

          <Button
            size="icon"
            variant="ghost"
            onClick={handleDismiss}
            className="h-8 w-8 rounded-full hover:bg-background/40 text-muted-foreground hover:text-foreground"
            title="Dismiss notification"
          >
            <X className="w-4 h-4" />
            <span className="sr-only">Dismiss banner</span>
          </Button>
        </div>
      </aside>

      {/* Advisory Details Modal */}
      <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-base sm:text-lg font-bold">
              <ShieldAlert
                className={cn(
                  'w-5 h-5',
                  isCritical ? 'text-rose-600' : 'text-amber-600'
                )}
              />
              <span>{currentAlert.title}</span>
            </DialogTitle>
            <DialogDescription className="text-xs">
              Official notice published to all residents and security gate personnel.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2 text-xs sm:text-sm">
            <div className="rounded-xl border border-border/80 bg-muted/40 p-4 leading-relaxed whitespace-pre-wrap">
              {currentAlert.description}
            </div>

            <div className="grid grid-cols-2 gap-2 text-xs">
              <div className="flex items-center gap-2 rounded-lg border p-2.5">
                <Clock className="w-4 h-4 text-muted-foreground shrink-0" />
                <div>
                  <span className="text-muted-foreground block text-[10px]">Issued By</span>
                  <span className="font-semibold">{currentAlert.author_name || 'Estate Security Operations'}</span>
                </div>
              </div>

              <div className="flex items-center gap-2 rounded-lg border p-2.5">
                <PhoneCall className="w-4 h-4 text-emerald-600 shrink-0" />
                <div>
                  <span className="text-muted-foreground block text-[10px]">Gate Dispatch</span>
                  <span className="font-semibold font-mono">Ext. 101 / Gate 1</span>
                </div>
              </div>
            </div>

            <div className="rounded-lg bg-emerald-500/10 border border-emerald-500/30 p-3 text-xs text-emerald-950 dark:text-emerald-100 flex items-start gap-2">
              <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
              <span>
                Safety protocol is active. Please follow gate marshal guidance and verify your visitors through the Community Hub.
              </span>
            </div>
          </div>

          <DialogFooter className="flex-col sm:flex-row gap-2">
            <Button variant="outline" asChild className="sm:w-auto w-full text-xs">
              <Link href="/dashboard/warnings">
                Safety Alerts Feed
                <ExternalLink className="w-3.5 h-3.5 ml-1.5" />
              </Link>
            </Button>
            <Button
              type="button"
              className="sm:w-auto w-full bg-primary text-primary-foreground text-xs"
              onClick={() => setDetailsOpen(false)}
            >
              Understood
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
