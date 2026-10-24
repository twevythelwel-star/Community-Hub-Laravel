import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Users, Globe, Clock, Calendar as CalendarIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';

interface VisitorBulkEventDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const COMMON_TIMEZONES = [
  { value: 'America/Jamaica', label: 'America/Jamaica (EST / UTC-5)' },
  { value: 'America/New_York', label: 'America/New_York (Eastern / UTC-4 or -5)' },
  { value: 'America/Chicago', label: 'America/Chicago (Central / UTC-5 or -6)' },
  { value: 'America/Denver', label: 'America/Denver (Mountain / UTC-6 or -7)' },
  { value: 'America/Los_Angeles', label: 'America/Los_Angeles (Pacific / UTC-7 or -8)' },
  { value: 'Pacific/Honolulu', label: 'Pacific/Honolulu (Hawaii / UTC-10)' },
  { value: 'UTC', label: 'UTC (Coordinated Universal Time)' },
];

export function VisitorBulkEventDialog({ open, onOpenChange }: VisitorBulkEventDialogProps) {
  const { toast } = useToast();
  const [bulkDate, setBulkDate] = useState<Date | undefined>(new Date());
  const [bulkHour, setBulkHour] = useState('10');
  const [bulkMinute, setBulkMinute] = useState('00');
  const [bulkMeridiem, setBulkMeridiem] = useState<'AM' | 'PM'>('AM');
  const [bulkTimezone, setBulkTimezone] = useState(() => {
    try {
      const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
      return detected || 'America/Jamaica';
    } catch {
      return 'America/Jamaica';
    }
  });

  const [bulkCategory, setBulkCategory] = useState<'VISITOR' | 'CONTRACTOR'>('VISITOR');
  const [bulkText, setBulkText] = useState('');
  const [bulkSubmitting, setBulkSubmitting] = useState(false);

  const onSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!bulkText.trim()) {
      toast({
        variant: 'destructive',
        title: 'Empty attendee list',
        description: 'Enter at least one visitor (Name, Phone/Email, Vehicle Plate).',
      });
      return;
    }

    const lines = bulkText.split('\n').map(l => l.trim()).filter(Boolean);
    const parsedVisitors = lines.map(line => {
      const parts = line.split(/[,;\t]/).map(p => p.trim());
      return {
        name: parts[0] || 'Guest',
        contact: parts[1] || null,
        vehicle: parts[2] || null,
      };
    });

    if (parsedVisitors.length === 0) {
      toast({ variant: 'destructive', title: 'Invalid format', description: 'Could not parse any visitors.' });
      return;
    }

    let h = parseInt(bulkHour, 10) % 12;
    if (bulkMeridiem === 'PM') h += 12;
    const timeFormatted = `${String(h).padStart(2, '0')}:${bulkMinute}`;
    const dateFormatted = bulkDate ? format(bulkDate, 'yyyy-MM-dd') : format(new Date(), 'yyyy-MM-dd');
    const localDateTime = `${dateFormatted} ${timeFormatted}:00`;

    setBulkSubmitting(true);
    router.post('/dashboard/visitors/bulk', {
      expected_at: localDateTime,
      expected_date: dateFormatted,
      expected_time: timeFormatted,
      timezone: bulkTimezone,
      pass_category: bulkCategory,
      visitors: parsedVisitors,
      notify_email: true,
      notify_sms: false,
      notify_whatsapp: false,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setBulkSubmitting(false);
        onOpenChange(false);
        setBulkText('');
        toast({
          title: 'Bulk Event Passes Created',
          description: `Registered ${parsedVisitors.length} attendees with digital passes for ${dateFormatted} at ${bulkHour}:${bulkMinute} ${bulkMeridiem} (${bulkTimezone}).`,
        });
      },
      onError: (errors) => {
        setBulkSubmitting(false);
        toast({
          variant: 'destructive',
          title: 'Registration Error',
          description: (Object.values(errors)[0] as string) || 'Failed to register bulk attendees.',
        });
      },
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[560px]">
        <form onSubmit={onSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-2 mb-1">
              <span className="p-1.5 rounded-lg bg-primary/10 text-primary">
                <Users className="h-5 w-5" />
              </span>
              <DialogTitle className="text-lg font-bold">Bulk Event Guest Clearance</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Pre-clear a batch of attendees for private events or contractor teams. Collects local date &amp; time and synchronizes accurately to UTC.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-3">
            {/* Category and Date row */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Pass Category</Label>
                <Select
                  value={bulkCategory}
                  onValueChange={(val: 'VISITOR' | 'CONTRACTOR') => setBulkCategory(val)}
                >
                  <SelectTrigger className="h-9 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="VISITOR">Visitor (Immediate Pre-clear)</SelectItem>
                    <SelectItem value="CONTRACTOR">Contractor (Security Review)</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold flex items-center gap-1.5">
                  <CalendarIcon className="h-3.5 w-3.5 text-muted-foreground" />
                  <span>Expected Arrival Date</span>
                </Label>
                <Input
                  type="date"
                  value={bulkDate ? format(bulkDate, 'yyyy-MM-dd') : ''}
                  onChange={(e) => setBulkDate(e.target.value ? new Date(e.target.value + 'T00:00:00') : undefined)}
                  className="h-9 text-xs font-mono"
                />
              </div>
            </div>

            {/* Time and Timezone row */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold flex items-center gap-1.5">
                  <Clock className="h-3.5 w-3.5 text-muted-foreground" />
                  <span>Expected Arrival Time</span>
                </Label>
                <div className="flex items-center gap-1.5">
                  <Select value={bulkHour} onValueChange={setBulkHour}>
                    <SelectTrigger className="h-9 text-xs font-mono">
                      <SelectValue placeholder="HH" />
                    </SelectTrigger>
                    <SelectContent>
                      {['12', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11'].map((h) => (
                        <SelectItem key={h} value={h} className="font-mono text-xs">
                          {h}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <span className="text-xs font-bold text-muted-foreground">:</span>
                  <Select value={bulkMinute} onValueChange={setBulkMinute}>
                    <SelectTrigger className="h-9 text-xs font-mono">
                      <SelectValue placeholder="MM" />
                    </SelectTrigger>
                    <SelectContent>
                      {['00', '15', '30', '45'].map((m) => (
                        <SelectItem key={m} value={m} className="font-mono text-xs">
                          {m}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Select value={bulkMeridiem} onValueChange={(val: 'AM' | 'PM') => setBulkMeridiem(val)}>
                    <SelectTrigger className="h-9 text-xs font-mono w-20">
                      <SelectValue placeholder="AM/PM" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="AM" className="text-xs font-mono">AM</SelectItem>
                      <SelectItem value="PM" className="text-xs font-mono">PM</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold flex items-center gap-1.5">
                  <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                  <span>Intended Timezone</span>
                </Label>
                <Select value={bulkTimezone} onValueChange={setBulkTimezone}>
                  <SelectTrigger className="h-9 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {COMMON_TIMEZONES.map((tz) => (
                      <SelectItem key={tz.value} value={tz.value} className="text-xs">
                        {tz.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* Attendee text area */}
            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <Label className="text-xs font-semibold">Attendee List (1 per line)</Label>
                <span className="text-[10px] text-muted-foreground font-mono">Format: Name, Phone/Email, License Plate</span>
              </div>
              <textarea
                rows={5}
                value={bulkText}
                onChange={(e) => setBulkText(e.target.value)}
                placeholder={`Marcus Wright, 876-555-0192, 4821-JC\nElena Rostova, elena@example.com, 9012-AB\nDavid Chen, 876-555-8833`}
                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs font-mono shadow-xs placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-1 focus-visible:ring-ring"
              />
              <p className="text-[11px] text-muted-foreground">
                Tip: Comma or tab separated. Phone and vehicle plates are optional.
              </p>
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm" disabled={bulkSubmitting} className="gap-1.5">
              <Users className="h-4 w-4" />
              <span>{bulkSubmitting ? 'Issuing Passes...' : 'Generate Batch Passes'}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
