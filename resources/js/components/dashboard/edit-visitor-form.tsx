import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { useMessaging } from '@/lib/messaging';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { useToast } from '@/hooks/use-toast';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { CalendarIcon, Edit, ShieldAlert } from 'lucide-react';
import { Calendar } from '@/components/ui/calendar';
import { cn } from '@/lib/utils';
import { format, parseISO } from 'date-fns';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { DateRange } from 'react-day-picker';

type VisitorItem = {
  id: number | string;
  name: string;
  contact?: string | null;
  vehicle?: string | null;
  type: string;
  expectedAt: string | Date;
  dateRange: string | null;
  notify_email?: boolean;
  notify_sms?: boolean;
  notify_whatsapp?: boolean;
};

type UserStay = {
  stayType: string;
  leaseStart: string;
  leaseEnd: string;
  expired?: boolean;
} | null;

type EditVisitorFormProps = {
  visitor: VisitorItem | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  userStay?: UserStay;
};

export function EditVisitorForm({ visitor, open, onOpenChange, userStay }: EditVisitorFormProps) {
  const { toast } = useToast();
  const messaging = useMessaging();
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [name, setName] = useState('');
  const [contact, setContact] = useState('');
  const [vehicle, setVehicle] = useState('');
  const [type, setType] = useState<'One-time' | 'Recurring'>('One-time');
  const [expectedDate, setExpectedDate] = useState<Date | undefined>(undefined);
  const [hour, setHour] = useState('10');
  const [minute, setMinute] = useState('00');
  const [meridiem, setMeridiem] = useState('AM');
  const [dateRange, setDateRange] = useState<DateRange | undefined>(undefined);
  const [notifyEmail, setNotifyEmail] = useState(true);
  const [notifySms, setNotifySms] = useState(false);
  const [notifyWhatsapp, setNotifyWhatsapp] = useState(false);

  const stayStart = userStay ? parseISO(userStay.leaseStart) : null;
  const stayEnd = userStay ? parseISO(userStay.leaseEnd) : null;

  useEffect(() => {
    if (visitor) {
      setName(visitor.name);
      setContact(visitor.contact || '');
      setVehicle(visitor.vehicle || '');
      setType(visitor.type === 'Recurring' ? 'Recurring' : 'One-time');

      const expDate = typeof visitor.expectedAt === 'string' ? parseISO(visitor.expectedAt) : visitor.expectedAt;
      setExpectedDate(expDate);

      let h = expDate.getHours();
      const m = expDate.getMinutes();
      const isPm = h >= 12;
      if (h > 12) h -= 12;
      if (h === 0) h = 12;

      setHour(`${h}`);
      setMinute(m < 10 ? `0${m}` : `${m}`);
      setMeridiem(isPm ? 'PM' : 'AM');

      // Set notification preferences with defaults for existing visitors
      setNotifyEmail(visitor.notify_email ?? true);
      setNotifySms(visitor.notify_sms ?? false);
      setNotifyWhatsapp(visitor.notify_whatsapp ?? false);
    }
  }, [visitor]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!visitor) return;

    if (!name.trim()) {
      setErrors({ name: 'Visitor name is required.' });
      return;
    }

    let isoExpected = '';
    if (type === 'One-time') {
      if (!expectedDate) {
        setErrors({ expected_at: 'Visit date is required.' });
        return;
      }
      let h = parseInt(hour, 10);
      if (meridiem === 'PM' && h < 12) h += 12;
      if (meridiem === 'AM' && h === 12) h = 0;

      const d = new Date(expectedDate);
      d.setHours(h, parseInt(minute, 10), 0, 0);

      // Stay bounds validation for Temporary Homeowners
      if (stayStart && stayEnd) {
        const checkD = new Date(d);
        checkD.setHours(0, 0, 0, 0);
        const sStart = new Date(stayStart);
        sStart.setHours(0, 0, 0, 0);
        const sEnd = new Date(stayEnd);
        sEnd.setHours(23, 59, 59, 999);

        if (checkD < sStart || checkD > sEnd) {
          setErrors({
            expected_at: `Date must be within your authorized stay timeframe (${format(stayStart, 'MMM d, yyyy')} to ${format(stayEnd, 'MMM d, yyyy')}).`,
          });
          return;
        }
      }

      isoExpected = d.toISOString();
    } else {
      const from = dateRange?.from || expectedDate || new Date();
      isoExpected = from.toISOString();
    }

    setSubmitting(true);
    setErrors({});

    router.put(
      `/dashboard/visitors/${visitor.id}`,
      {
        name,
        contact,
        vehicle,
        type,
        expected_at: isoExpected,
        date_range:
          type === 'Recurring' && dateRange?.from && dateRange?.to
            ? `${format(dateRange.from, 'yyyy-MM-dd')} to ${format(dateRange.to, 'yyyy-MM-dd')}`
            : undefined,
        notify_email: notifyEmail,
        notify_sms: notifySms,
        notify_whatsapp: notifyWhatsapp,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast({
            title: 'Visitor Updated',
            description: `Visitor entry for ${name} has been updated.`,
          });
          onOpenChange(false);
          setSubmitting(false);
        },
        onError: (errs) => {
          setErrors(errs);
          setSubmitting(false);
        },
      }
    );
  };

  if (!visitor) return null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[500px]">
        <form onSubmit={handleSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Edit className="h-5 w-5 text-primary" />
              Edit Visitor Information
            </DialogTitle>
            <DialogDescription>
              Update visitor details, arrival date, vehicle information, or clearance pass type.
            </DialogDescription>
          </DialogHeader>

          {userStay && (
            <div className="flex items-center gap-2 p-2.5 rounded-lg bg-amber-500/10 border border-amber-500/20 text-xs text-amber-700 dark:text-amber-300">
              <ShieldAlert className="h-4 w-4 shrink-0" />
              <span>
                Authorized Stay: <strong>{format(parseISO(userStay.leaseStart), 'MMM d, yyyy')}</strong> to{' '}
                <strong>{format(parseISO(userStay.leaseEnd), 'MMM d, yyyy')}</strong>. Clearance dates must fall within this period.
              </span>
            </div>
          )}

          <div className="space-y-3 py-1">
            <div className="space-y-1">
              <Label htmlFor="edit-v-name">Visitor Name</Label>
              <Input
                id="edit-v-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
              />
              {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
            </div>

            <div className="space-y-1">
              <Label htmlFor="edit-v-contact">Contact Phone or Email</Label>
              <Input
                id="edit-v-contact"
                placeholder="Phone or email address"
                value={contact}
                onChange={(e) => setContact(e.target.value)}
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="edit-v-vehicle">Vehicle Details (Optional)</Label>
              <Input
                id="edit-v-vehicle"
                placeholder="e.g. Silver Honda Civic (Plate #ABC-123)"
                value={vehicle}
                onChange={(e) => setVehicle(e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label>Send Pass Notifications</Label>
              <div className="space-y-2">
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="edit-notify-email"
                    checked={notifyEmail}
                    onChange={(e) => setNotifyEmail(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                  />
                  <Label htmlFor="edit-notify-email" className="text-sm font-normal cursor-pointer">
                    Email (QR code attached)
                  </Label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="edit-notify-sms"
                    checked={messaging.sms && notifySms}
                    disabled={!messaging.sms}
                    onChange={(e) => setNotifySms(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                  />
                  <Label htmlFor="edit-notify-sms" className="text-sm font-normal cursor-pointer">
                    SMS Text Message
                    {!messaging.sms && <span className="text-muted-foreground"> (not set up)</span>}
                  </Label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="edit-notify-whatsapp"
                    checked={messaging.whatsapp && notifyWhatsapp}
                    disabled={!messaging.whatsapp}
                    onChange={(e) => setNotifyWhatsapp(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500 disabled:opacity-50"
                  />
                  <Label htmlFor="edit-notify-whatsapp" className="text-sm font-normal cursor-pointer">
                    WhatsApp
                    {!messaging.whatsapp && <span className="text-muted-foreground"> (not set up)</span>}
                  </Label>
                </div>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label>Access Clearance Type</Label>
              <RadioGroup
                value={type}
                onValueChange={(v) => setType(v as 'One-time' | 'Recurring')}
                className="grid grid-cols-2 gap-3"
              >
                <div
                  className={cn(
                    'flex items-center space-x-2 p-2.5 rounded-lg border cursor-pointer text-sm',
                    type === 'One-time'
                      ? 'border-primary bg-primary/5 font-semibold text-primary'
                      : 'border-muted'
                  )}
                  onClick={() => setType('One-time')}
                >
                  <RadioGroupItem value="One-time" id="edit-type-1" />
                  <Label htmlFor="edit-type-1" className="cursor-pointer">
                    One-time Entry
                  </Label>
                </div>

                <div
                  className={cn(
                    'flex items-center space-x-2 p-2.5 rounded-lg border cursor-pointer text-sm',
                    type === 'Recurring'
                      ? 'border-primary bg-primary/5 font-semibold text-primary'
                      : 'border-muted'
                  )}
                  onClick={() => setType('Recurring')}
                >
                  <RadioGroupItem value="Recurring" id="edit-type-2" />
                  <Label htmlFor="edit-type-2" className="cursor-pointer">
                    Multiple-entry
                  </Label>
                </div>
              </RadioGroup>
            </div>

            {type === 'One-time' && (
              <div className="space-y-2">
                <Label>Arrival Date &amp; Time</Label>
                <div className="flex gap-2">
                  <Popover>
                    <PopoverTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        className={cn(
                          'w-full justify-start text-left font-normal',
                          !expectedDate && 'text-muted-foreground'
                        )}
                      >
                        <CalendarIcon className="mr-2 h-4 w-4 opacity-70" />
                        {expectedDate ? format(expectedDate, 'PPP') : <span>Pick a date</span>}
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                      <Calendar
                        mode="single"
                        selected={expectedDate}
                        onSelect={setExpectedDate}
                        disabled={(date) => {
                          if (stayStart && stayEnd) {
                            return date < stayStart || date > stayEnd;
                          }
                          return false;
                        }}
                        initialFocus
                      />
                    </PopoverContent>
                  </Popover>

                  <Select value={hour} onValueChange={setHour}>
                    <SelectTrigger className="w-[75px]"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {Array.from({ length: 12 }, (_, i) => i + 1).map((h) => (
                        <SelectItem key={h} value={`${h}`}>{h}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  <Select value={minute} onValueChange={setMinute}>
                    <SelectTrigger className="w-[75px]"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {['00', '15', '30', '45'].map((m) => (
                        <SelectItem key={m} value={m}>{m}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  <Select value={meridiem} onValueChange={setMeridiem}>
                    <SelectTrigger className="w-[75px]"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="AM">AM</SelectItem>
                      <SelectItem value="PM">PM</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                {errors.expected_at && <p className="text-xs text-destructive">{errors.expected_at}</p>}
              </div>
            )}
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={submitting}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Updating...' : 'Update Visitor'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
