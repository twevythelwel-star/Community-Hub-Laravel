import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Button } from '@/components/ui/button';
import { Camera, ShieldAlert, Users, Link as LinkIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { format, parseISO } from 'date-fns';
import { VisitorIdModal } from '@/components/dashboard/visitor-id-modal';
import { EditVisitorForm } from '@/components/dashboard/edit-visitor-form';
import { RegisterVisitorDialog } from '@/components/dashboard/register-visitor-dialog';
import { GateQrCameraScanner } from '@/components/dashboard/gate-qr-camera-scanner';
import {
  NEEDS_REASON,
  VisitorSecurityStats,
  VisitorTable,
  VisitorSharePassDialog,
  VisitorBulkEventDialog,
  VisitorRsvpDialog,
  type VisitorsPageProps,
  type VisitorRow,
} from '@/components/dashboard/visitors';
import type { PassStatus } from '@/lib/gate-pass-engine/types';

export default function VisitorsPage({
  visitors,
  filters,
  tabCounts,
  securityStats,
  activeTab = 'all',
  canManage,
  canRegister,
  graceHours,
  userStay,
  hostProperties = [],
}: VisitorsPageProps) {
  const { toast } = useToast();

  const [scannerOpen, setScannerOpen] = useState(activeTab === 'scan');
  const [selectedVisitor, setSelectedVisitor] = useState<VisitorRow | null>(null);
  const [editingVisitor, setEditingVisitor] = useState<VisitorRow | null>(null);
  const [sharePassVisitor, setSharePassVisitor] = useState<VisitorRow | null>(null);
  const [bulkDialogOpen, setBulkDialogOpen] = useState(false);
  const [rsvpDialogOpen, setRsvpDialogOpen] = useState(false);
  const [plateQuery, setPlateQuery] = useState('');

  const currentTab = filters.tab || activeTab || 'all';

  const handleTabChange = (tab: string) => {
    router.get(
      '/dashboard/visitors',
      { tab: tab === 'all' ? undefined : tab },
      { preserveScroll: true, preserveState: true }
    );
  };

  const handleServerResend = (visitor: VisitorRow, channel: 'sms' | 'whatsapp' | 'email') => {
    router.post(`/dashboard/visitors/${visitor.id}/resend`, { channel }, {
      preserveScroll: true,
      onSuccess: () => toast({ title: 'Pass Dispatched', description: `Pass sent to ${visitor.name} via ${channel.toUpperCase()}.` }),
      onError: (errs) => toast({ variant: 'destructive', title: 'Dispatch Failed', description: (Object.values(errs)[0] as string) || 'Unable to send pass.' }),
    });
  };

  const handleExtendPass = (visitor: VisitorRow, hours: number) => {
    router.post(`/dashboard/visitors/${visitor.id}/extend`, { hours }, {
      preserveScroll: true,
      onSuccess: () => toast({ title: 'Pass Extended', description: `Validity for ${visitor.name} extended by +${hours} hours.` }),
      onError: (errs) => toast({ variant: 'destructive', title: 'Extension Failed', description: (Object.values(errs)[0] as string) || 'Unable to extend pass.' }),
    });
  };

  /** Approve, reject, suspend, reinstate, cancel or revoke a pass. */
  const transitionPass = (visitor: VisitorRow, to: PassStatus, label: string) => {
    if (!visitor.pass) return;

    let reason: string | null = null;
    if (NEEDS_REASON.includes(to)) {
      reason = window.prompt(`${label} the pass for ${visitor.name}. Reason (required):`);
      if (!reason || reason.trim() === '') return;
    }

    router.post(
      `/dashboard/gate-pass/${visitor.pass.id}/transition`,
      { status: to, reason },
      {
        preserveScroll: true,
        onSuccess: () => toast({ title: 'Pass updated', description: `${visitor.name}: ${label.toLowerCase()} done.` }),
        onError: (errors) =>
          toast({
            variant: 'destructive',
            title: 'Pass not updated',
            description: errors.status ?? errors.reason ?? 'The server refused the change.',
          }),
      },
    );
  };

  const changeStatus = (visitor: VisitorRow, action: 'check-in' | 'check-out') => {
    router.post(
      `/dashboard/visitors/${visitor.id}/${action}`,
      {},
      {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            title: action === 'check-in' ? 'Visitor Checked In' : 'Visitor Checked Out',
            description: `${visitor.name} — ${visitor.homeowner ?? 'resident'} has been notified.`,
          }),
        onError: (errors) =>
          toast({
            variant: 'destructive',
            title: 'Check-In Denied',
            description:
              errors.visitor ?? `${visitor.name} could not be admitted. Contact security.`,
          }),
      },
    );
  };

  const deleteVisitor = (visitor: VisitorRow) => {
    if (!window.confirm(`Remove ${visitor.name} from the expected list?`)) return;

    router.delete(`/dashboard/visitors/${visitor.id}`, {
      preserveScroll: true,
      onSuccess: () => toast({ title: 'Visitor Removed' }),
    });
  };

  const reportMismatch = (visitor: VisitorRow) => {
    toast({
      variant: 'destructive',
      title: 'Security Alert: ID Mismatch',
      description: `${visitor.homeowner ?? 'The resident'} has been notified about a possible impersonation attempt by someone claiming to be ${visitor.name}.`,
    });
  };

  const copyPassLink = (visitor: VisitorRow) => {
    const url = visitor.guestPassUrl || `${window.location.origin}/guest/pass/${visitor.shareToken}`;
    navigator.clipboard.writeText(url);
    toast({
      title: 'Guest Pass Link Copied',
      description: 'You can now paste and send this pass link to your visitor.',
    });
  };

  const shareViaWhatsApp = (visitor: VisitorRow) => {
    const url = visitor.guestPassUrl || `${window.location.origin}/guest/pass/${visitor.shareToken}`;
    const text = encodeURIComponent(`Hello ${visitor.name}, here is your digital gate entry pass: ${url}`);
    window.open(`https://wa.me/?text=${text}`, '_blank');
  };

  const shareViaSms = (visitor: VisitorRow) => {
    const url = visitor.guestPassUrl || `${window.location.origin}/guest/pass/${visitor.shareToken}`;
    const text = encodeURIComponent(`Hello ${visitor.name}, here is your digital gate entry pass: ${url}`);
    window.open(`sms:?&body=${text}`, '_blank');
  };

  return (
    <DashboardLayout>
      <Head title="Visitor Management" />

      {/* QR Camera Scanner Dialog */}
      <GateQrCameraScanner
        open={scannerOpen}
        onOpenChange={setScannerOpen}
        onSuccessCheck={() => router.reload()}
      />

      {selectedVisitor && (
        <VisitorIdModal
          visitor={selectedVisitor}
          open={!!selectedVisitor}
          onOpenChange={(isOpen) => !isOpen && setSelectedVisitor(null)}
          onStatusChange={(_id, newStatus) =>
            changeStatus(selectedVisitor, newStatus === 'Checked In' ? 'check-in' : 'check-out')
          }
          onReportMismatch={() => reportMismatch(selectedVisitor)}
        />
      )}

      {/* Share Guest Pass Dialog */}
      <VisitorSharePassDialog
        visitor={sharePassVisitor}
        onClose={() => setSharePassVisitor(null)}
        onCopyLink={copyPassLink}
        onShareWhatsApp={shareViaWhatsApp}
        onShareSms={shareViaSms}
      />

      {/* Bulk Event Registration Dialog */}
      <VisitorBulkEventDialog
        open={bulkDialogOpen}
        onOpenChange={setBulkDialogOpen}
      />

      {/* Event RSVP Invite Link Dialog */}
      <VisitorRsvpDialog
        open={rsvpDialogOpen}
        onOpenChange={setRsvpDialogOpen}
      />

      <div className="flex flex-col gap-6">
        {/* Top Header with Title & Prominent Action Buttons */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="font-headline text-3xl font-bold tracking-tight">
              {canManage ? 'Security → Visitors' : 'Visitor Management'}
            </h1>
            <p className="text-muted-foreground text-sm">
              {canManage
                ? 'Review, verify credentials, and manage daily estate gate access.'
                : 'Register and manage authorized visitors for your property.'}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {/* High-Visibility Security Operator SCAN QR CODE Button */}
            {canManage && (
              <Button
                size="lg"
                onClick={() => setScannerOpen(true)}
                className="h-14 px-6 rounded-2xl font-black text-sm sm:text-base tracking-wide bg-emerald-600 hover:bg-emerald-500 active:bg-emerald-700 text-white shadow-xl shadow-emerald-600/30 gap-2.5 transition-all hover:scale-[1.02] active:scale-[0.98] border-2 border-emerald-400/40 cursor-pointer focus-visible:ring-4 focus-visible:ring-emerald-400"
                aria-label="Open Gate Pass QR Camera Scanner. Press Space or Click to Launch."
                title="Open Gate Pass Scanner (Shortcut: S)"
              >
                <Camera className="w-5 h-5 animate-pulse text-white" />
                <span className="uppercase tracking-wider">Scan Gate Pass</span>
                <span className="hidden sm:inline-block ml-1 px-2 py-0.5 rounded-md bg-black/25 text-[11px] font-mono text-emerald-100 font-bold border border-white/20">
                  [S]
                </span>
              </Button>
            )}

            {canRegister && (
              <RegisterVisitorDialog graceHours={graceHours} userStay={userStay} hostProperties={hostProperties} secondary={canManage} />
            )}

            {canRegister && (
              <>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setBulkDialogOpen(true)}
                  className="gap-1.5 h-11 rounded-xl border-dashed"
                  title="Register a batch of guests or contractors for an event"
                >
                  <Users className="h-4 w-4 text-primary" />
                  <span>Bulk Event Passes</span>
                </Button>

                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setRsvpDialogOpen(true)}
                  className="gap-1.5 h-11 rounded-xl border-dashed"
                  title="Generate a self-service RSVP link for guests to pre-register themselves"
                >
                  <LinkIcon className="h-4 w-4 text-primary" />
                  <span>Event RSVP Link</span>
                </Button>
              </>
            )}
          </div>
        </div>

        {userStay && (
          <div className="rounded-xl border border-blue-500/20 bg-blue-500/5 p-4 flex items-start gap-3">
            <ShieldAlert className="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0" />
            <div className="text-xs sm:text-sm space-y-1">
              <p className="font-semibold text-foreground flex items-center gap-2">
                Temporary Homeowner Access ({userStay.stayType})
                <Badge variant="outline" className="text-blue-600 border-blue-500/30 text-[11px]">
                  Stay Active
                </Badge>
              </p>
              <p className="text-muted-foreground">
                Authorized stay timeframe: <strong>{format(parseISO(userStay.leaseStart), 'MMM d, yyyy')}</strong> to{' '}
                <strong>{format(parseISO(userStay.leaseEnd), 'MMM d, yyyy')}</strong>. Registered visitors and edits are limited to dates within this timeframe.
              </p>
            </div>
          </div>
        )}

        {/* Security Live Counters & Tabs */}
        <VisitorSecurityStats
          canManage={canManage}
          currentTab={currentTab}
          tabCounts={tabCounts}
          securityStats={securityStats}
          onTabChange={handleTabChange}
          onOpenScanner={() => setScannerOpen(true)}
        />

        {/* Table & Filtering */}
        <VisitorTable
          visitors={visitors}
          filters={filters}
          canManage={canManage}
          currentTab={currentTab}
          plateQuery={plateQuery}
          setPlateQuery={setPlateQuery}
          onSelectVisitor={setSelectedVisitor}
          onEditVisitor={setEditingVisitor}
          onSharePass={setSharePassVisitor}
          onCopyPassLink={copyPassLink}
          onShareViaWhatsApp={shareViaWhatsApp}
          onServerResend={handleServerResend}
          onExtendPass={handleExtendPass}
          onDeleteVisitor={deleteVisitor}
          onChangeStatus={changeStatus}
          onTransitionPass={transitionPass}
        />
      </div>

      <EditVisitorForm
        visitor={editingVisitor}
        open={Boolean(editingVisitor)}
        onOpenChange={(open) => !open && setEditingVisitor(null)}
        userStay={userStay}
      />
    </DashboardLayout>
  );
}
