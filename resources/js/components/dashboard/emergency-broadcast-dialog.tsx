import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { useToast } from '@/hooks/use-toast';
import {
  Siren,
  Send,
  AlertTriangle,
  Radio,
  Smartphone,
  MessageSquare,
  Mail,
  BellRing,
  Building,
  Home,
  Users,
  Shield,
  HeartHandshake,
  UserCheck,
  Flame,
  Wind,
  Waves,
  Sparkles,
  Layers,
} from 'lucide-react';
import { cn } from '@/lib/utils';

interface Props {
  community: string;
  triggerButton?: React.ReactNode;
  defaultProperty?: string;
}

export function EmergencyBroadcastDialog({ community, triggerButton, defaultProperty }: Props) {
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [severity, setSeverity] = useState<'emergency' | 'advisory' | 'info'>('emergency');
  const [audience, setAudience] = useState<
    'all' | 'building' | 'property' | 'residents' | 'staff' | 'security' | 'legacy_contacts' | 'long_term_guests' | 'visitors'
  >(defaultProperty ? 'property' : 'all');
  const [targetBuilding, setTargetBuilding] = useState('');
  const [targetProperty, setTargetProperty] = useState(defaultProperty || '');

  // 5 Multi-Channel Toggles
  const [sendPush, setSendPush] = useState(true);
  const [sendSms, setSendSms] = useState(true);
  const [sendWhatsApp, setSendWhatsApp] = useState(true);
  const [sendEmail, setSendEmail] = useState(true);
  const [sendInApp, setSendInApp] = useState(true);

  const [submitting, setSubmitting] = useState(false);
  const [confirmSafety, setConfirmSafety] = useState(false);

  // Pre-configured Emergency Templates
  const applyTemplate = (tmpl: { title: string; content: string; severity: 'emergency' | 'advisory'; audience: any }) => {
    setTitle(tmpl.title);
    setContent(tmpl.content);
    setSeverity(tmpl.severity);
    setAudience(tmpl.audience);
  };

  const templates = [
    {
      label: 'Hurricane Warning',
      icon: Wind,
      title: 'Severe Hurricane / Cyclone Alert',
      content: 'Hurricane advisory in effect. Secure all outdoor items, shutters, and balconies. Shelter in place; emergency muster station at Central Park East.',
      severity: 'emergency' as const,
      audience: 'all' as const,
    },
    {
      label: 'Flash Flood',
      icon: Waves,
      title: 'Flash Flood & Road Inundation Advisory',
      content: 'Heavy rainfall and coastal surge. Low-lying estate roadways are experiencing pooling. Avoid lower access lanes and remain indoors.',
      severity: 'advisory' as const,
      audience: 'all' as const,
    },
    {
      label: 'Fire Evacuation',
      icon: Flame,
      title: 'Structure Fire & Evacuation Directive',
      content: 'Fire reported in the vicinity. Evacuate immediately via designated emergency paths. Report to Muster Marshals at Gate 01 field.',
      severity: 'emergency' as const,
      audience: 'all' as const,
    },
    {
      label: 'Security Lockdown',
      icon: Shield,
      title: 'Perimeter Security Incident — Shelter in Place',
      content: 'Security perimeter breach detected. Gate entry and exit temporarily suspended. Please stay inside your residence while security teams sweep the grounds.',
      severity: 'emergency' as const,
      audience: 'all' as const,
    },
  ];

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim() || !content.trim()) {
      toast({
        variant: 'destructive',
        title: 'Missing Fields',
        description: 'Please enter both an alert title and message content.',
      });
      return;
    }

    if (severity === 'emergency' && !confirmSafety) {
      toast({
        variant: 'destructive',
        title: 'Confirmation Required',
        description: 'Please acknowledge the emergency authorization confirmation before broadcasting.',
      });
      return;
    }

    if (!sendPush && !sendSms && !sendWhatsApp && !sendEmail && !sendInApp) {
      toast({
        variant: 'destructive',
        title: 'Select At Least One Channel',
        description: 'Please select at least one delivery channel (Push, SMS, WhatsApp, Email, or In-App).',
      });
      return;
    }

    setSubmitting(true);
    router.post(
      '/dashboard/notifications/broadcast',
      {
        title,
        content,
        severity,
        audience,
        target_building: audience === 'building' ? targetBuilding : undefined,
        target_property: audience === 'property' ? targetProperty : undefined,
        send_push: sendPush,
        send_sms: sendSms,
        send_whatsapp: sendWhatsApp,
        send_email: sendEmail,
        send_in_app: sendInApp,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSubmitting(false);
          setOpen(false);
          setTitle('');
          setContent('');
          setConfirmSafety(false);
          toast({
            title: 'Emergency Alert Dispatched',
            description: 'Multi-channel broadcast sent across Push, SMS, WhatsApp, Email, and In-App.',
          });
        },
        onError: (errs) => {
          setSubmitting(false);
          toast({
            variant: 'destructive',
            title: 'Broadcast Failed',
            description: (Object.values(errs)[0] as string) || 'Unable to dispatch broadcast.',
          });
        },
      }
    );
  };

  const activeChannelsCount = [sendPush, sendSms, sendWhatsApp, sendEmail, sendInApp].filter(Boolean).length;

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        {triggerButton ? (
          triggerButton
        ) : (
          <Button
            type="button"
            size="sm"
            className="gap-2 bg-rose-600 hover:bg-rose-700 text-white font-bold shadow-md shadow-rose-600/20"
          >
            <Siren className="h-4 w-4 animate-pulse" />
            <span>Community Emergency Alert</span>
          </Button>
        )}
      </DialogTrigger>

      <DialogContent className="sm:max-w-[620px] max-h-[90vh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-2 mb-1">
              <span className="p-2 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400">
                <Siren className="h-5 w-5" />
              </span>
              <div>
                <DialogTitle className="text-lg font-bold text-foreground flex items-center gap-2">
                  <span>Community Emergency Broadcast</span>
                  <Badge variant="destructive" className="text-[10px] uppercase font-bold tracking-wider">
                    High Priority
                  </Badge>
                </DialogTitle>
                <DialogDescription className="text-xs">
                  Dispatch instant emergency alerts across 5 delivery channels to {community}.
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="space-y-4 py-3">
            {/* Quick Templates Bar */}
            <div className="space-y-1">
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span className="font-semibold flex items-center gap-1">
                  <Sparkles className="w-3.5 h-3.5 text-amber-500" /> Caribbean Emergency Templates:
                </span>
              </div>
              <div className="flex items-center gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                {templates.map((t) => {
                  const Icon = t.icon;
                  return (
                    <Button
                      key={t.label}
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => applyTemplate(t)}
                      className="h-7 px-2.5 text-[11px] gap-1 shrink-0 rounded-full hover:border-primary/50"
                    >
                      <Icon className="w-3 h-3 text-primary" />
                      <span>{t.label}</span>
                    </Button>
                  );
                })}
              </div>
            </div>

            {/* Severity Picker */}
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold">Alert Severity Level</Label>
              <div className="grid grid-cols-3 gap-2">
                <button
                  type="button"
                  onClick={() => setSeverity('emergency')}
                  className={cn(
                    'p-2.5 rounded-xl border text-xs font-bold text-center transition-all cursor-pointer',
                    severity === 'emergency'
                      ? 'border-rose-500 bg-rose-500/10 text-rose-600 dark:text-rose-400 ring-2 ring-rose-500/20 shadow-xs'
                      : 'border-border bg-card hover:bg-muted/40 text-muted-foreground'
                  )}
                >
                  🚨 Emergency
                </button>
                <button
                  type="button"
                  onClick={() => setSeverity('advisory')}
                  className={cn(
                    'p-2.5 rounded-xl border text-xs font-bold text-center transition-all cursor-pointer',
                    severity === 'advisory'
                      ? 'border-amber-500 bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-2 ring-amber-500/20 shadow-xs'
                      : 'border-border bg-card hover:bg-muted/40 text-muted-foreground'
                  )}
                >
                  ⚠️ Advisory
                </button>
                <button
                  type="button"
                  onClick={() => setSeverity('info')}
                  className={cn(
                    'p-2.5 rounded-xl border text-xs font-bold text-center transition-all cursor-pointer',
                    severity === 'info'
                      ? 'border-blue-500 bg-blue-500/10 text-blue-600 dark:text-blue-400 ring-2 ring-blue-500/20 shadow-xs'
                      : 'border-border bg-card hover:bg-muted/40 text-muted-foreground'
                  )}
                >
                  📢 Notice
                </button>
              </div>
            </div>

            {/* Target Audience */}
            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <Label className="text-xs font-semibold">Target Audience</Label>
                <span className="text-[11px] text-muted-foreground font-medium">
                  {audience === 'all' && 'All residents, occupants, staff & active visitors'}
                  {audience === 'residents' && 'Homeowners & Renters only'}
                  {audience === 'building' && 'Filtered by building name'}
                  {audience === 'property' && 'Filtered by specific property'}
                  {audience === 'staff' && 'Maintenance & Estate Staff'}
                  {audience === 'security' && 'Gatehouse & Security Officers'}
                  {audience === 'legacy_contacts' && 'Authorized Caregivers & Legacy Contacts'}
                  {audience === 'long_term_guests' && 'Extended Guests & Long-Term Occupants'}
                  {audience === 'visitors' && 'Visitors currently logged on property'}
                </span>
              </div>
              <select
                value={audience}
                onChange={(e) => setAudience(e.target.value as any)}
                className="w-full h-9 rounded-lg border border-input bg-background px-3 text-xs font-medium focus:ring-2 focus:ring-primary"
              >
                <option value="all">🌐 Everyone (Entire Community &amp; Active Visitors)</option>
                <option value="building">🏢 Specific Building / Block</option>
                <option value="property">🏠 Specific Property / Unit (e.g. Unit 14)</option>
                <option value="residents">🏡 Residents Only (Homeowners &amp; Renters)</option>
                <option value="staff">👷 Staff Only (Maintenance &amp; Facility Operations)</option>
                <option value="security">🛡️ Security Personnel Only</option>
                <option value="legacy_contacts">🤝 Legacy Contacts &amp; Caregivers</option>
                <option value="long_term_guests">👤 Long-Term Occupants</option>
                <option value="visitors">🚗 Active Visitors Currently Inside</option>
              </select>

              {/* Conditional Sub-Inputs for Building or Property */}
              {audience === 'building' && (
                <div className="mt-2">
                  <Input
                    placeholder="Enter Building / Block name (e.g. Building A, Block 3)..."
                    value={targetBuilding}
                    onChange={(e) => setTargetBuilding(e.target.value)}
                    className="h-8 text-xs"
                    required
                  />
                </div>
              )}

              {audience === 'property' && (
                <div className="mt-2">
                  <Input
                    placeholder="Enter Property / Unit (e.g. Unit 14, Lot 42)..."
                    value={targetProperty}
                    onChange={(e) => setTargetProperty(e.target.value)}
                    className="h-8 text-xs font-mono"
                    required
                  />
                </div>
              )}
            </div>

            {/* Alert Title */}
            <div className="space-y-1.5">
              <Label htmlFor="alert-title" className="text-xs font-semibold">
                Alert Title <span className="text-destructive">*</span>
              </Label>
              <Input
                id="alert-title"
                placeholder="e.g. Severe Tropical Storm Warning, Main Gate Malfunction"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                maxLength={160}
                required
                className="h-9 text-xs"
              />
            </div>

            {/* Alert Content */}
            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <Label htmlFor="alert-content" className="text-xs font-semibold">
                  Broadcast Message Body <span className="text-destructive">*</span>
                </Label>
                <span className="text-[10px] text-muted-foreground font-mono">
                  {content.length}/1000 characters
                </span>
              </div>
              <Textarea
                id="alert-content"
                placeholder="Provide concise emergency directives, assembly muster instructions, and emergency numbers..."
                value={content}
                onChange={(e) => setContent(e.target.value)}
                maxLength={1000}
                required
                rows={3}
                className="text-xs resize-none"
              />
            </div>

            {/* 5 Delivery Channels Selector */}
            <div className="p-3 rounded-xl border bg-muted/20 space-y-2">
              <div className="flex items-center justify-between">
                <Label className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                  <Layers className="w-3.5 h-3.5 text-primary" /> Delivery Channels ({activeChannelsCount}/5 Active)
                </Label>
                <span className="text-[10px] text-muted-foreground">Select channels to dispatch</span>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 text-xs">
                {/* 1. Push Notification */}
                <div className="flex items-center space-x-2 p-1.5 rounded-lg border bg-background">
                  <Checkbox
                    id="chan-push"
                    checked={sendPush}
                    onCheckedChange={(c) => setSendPush(Boolean(c))}
                  />
                  <label htmlFor="chan-push" className="flex items-center gap-1.5 cursor-pointer font-medium">
                    <Radio className="w-3.5 h-3.5 text-blue-500" />
                    <span>Push Notification</span>
                  </label>
                </div>

                {/* 2. SMS */}
                <div className="flex items-center space-x-2 p-1.5 rounded-lg border bg-background">
                  <Checkbox
                    id="chan-sms"
                    checked={sendSms}
                    onCheckedChange={(c) => setSendSms(Boolean(c))}
                  />
                  <label htmlFor="chan-sms" className="flex items-center gap-1.5 cursor-pointer font-medium">
                    <Smartphone className="w-3.5 h-3.5 text-emerald-500" />
                    <span>SMS (Twilio)</span>
                  </label>
                </div>

                {/* 3. WhatsApp */}
                <div className="flex items-center space-x-2 p-1.5 rounded-lg border bg-background">
                  <Checkbox
                    id="chan-whatsapp"
                    checked={sendWhatsApp}
                    onCheckedChange={(c) => setSendWhatsApp(Boolean(c))}
                  />
                  <label htmlFor="chan-whatsapp" className="flex items-center gap-1.5 cursor-pointer font-medium">
                    <MessageSquare className="w-3.5 h-3.5 text-green-600" />
                    <span>WhatsApp Alert</span>
                  </label>
                </div>

                {/* 4. Email */}
                <div className="flex items-center space-x-2 p-1.5 rounded-lg border bg-background">
                  <Checkbox
                    id="chan-email"
                    checked={sendEmail}
                    onCheckedChange={(c) => setSendEmail(Boolean(c))}
                  />
                  <label htmlFor="chan-email" className="flex items-center gap-1.5 cursor-pointer font-medium">
                    <Mail className="w-3.5 h-3.5 text-indigo-500" />
                    <span>Email Broadcast</span>
                  </label>
                </div>

                {/* 5. In-App Notification */}
                <div className="flex items-center space-x-2 p-1.5 rounded-lg border bg-background sm:col-span-2">
                  <Checkbox
                    id="chan-inapp"
                    checked={sendInApp}
                    onCheckedChange={(c) => setSendInApp(Boolean(c))}
                  />
                  <label htmlFor="chan-inapp" className="flex items-center gap-1.5 cursor-pointer font-medium">
                    <BellRing className="w-3.5 h-3.5 text-amber-500" />
                    <span>In-App High Priority Banner &amp; Activity Notification</span>
                  </label>
                </div>
              </div>
            </div>

            {/* Confirmation for Emergency */}
            {severity === 'emergency' && (
              <div className="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-800 dark:text-rose-300 text-xs space-y-2">
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id="confirm-safety"
                    checked={confirmSafety}
                    onCheckedChange={(checked) => setConfirmSafety(Boolean(checked))}
                  />
                  <label
                    htmlFor="confirm-safety"
                    className="font-bold leading-none cursor-pointer"
                  >
                    I authorize dispatching this high-priority emergency broadcast immediately.
                  </label>
                </div>
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:gap-0 pt-2 border-t">
            <Button type="button" variant="outline" size="sm" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button
              type="submit"
              size="sm"
              disabled={submitting || (severity === 'emergency' && !confirmSafety)}
              className="gap-2 bg-rose-600 hover:bg-rose-700 text-white font-bold"
            >
              <Send className="h-4 w-4" />
              <span>{submitting ? 'Broadcasting...' : `Send Broadcast (${activeChannelsCount} Channels)`}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
