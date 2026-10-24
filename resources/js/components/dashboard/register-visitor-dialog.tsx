import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import type { DateRange } from 'react-day-picker';
import { add, format, parseISO, startOfDay } from 'date-fns';
import { Calendar as CalendarIcon, PlusCircle } from 'lucide-react';
import { useMessaging } from '@/lib/messaging';
import { useToast } from '@/hooks/use-toast';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

/** Combines a calendar date and a 12-hour time selection into an ISO string. */
export function toIsoDateTime(date: Date | undefined, hour: string, minute: string, meridiem: string): string {
  if (!date) return '';

  let h = parseInt(hour, 10) % 12;
  if (meridiem === 'PM') h += 12;

  const composed = new Date(date);
  composed.setHours(h, parseInt(minute, 10), 0, 0);

  return composed.toISOString();
}

type Stay = { leaseStart: string; leaseEnd: string } | null | undefined;

const ID_TYPES = ["Driver's License", 'Passport', 'National ID', 'School ID', 'Work ID'];

const ARRIVAL_WINDOWS: { value: string; label: string; range?: [string, string] }[] = [
  { value: '2h', label: '2 hours from arrival time' },
  { value: '4h', label: '4 hours from arrival time' },
  { value: 'morning', label: 'Morning (8:00 AM – 12:00 PM)', range: ['08:00', '12:00'] },
  { value: 'afternoon', label: 'Afternoon (12:00 PM – 5:00 PM)', range: ['12:00', '17:00'] },
  { value: 'evening', label: 'Evening (5:00 PM – 10:00 PM)', range: ['17:00', '22:00'] },
  { value: 'allday', label: 'All day (6:00 AM – 11:00 PM)', range: ['06:00', '23:00'] },
  { value: 'custom', label: 'Custom window' },
];

// Blank means the estate's own visitor parking rules (config/visitor_pass.php)
// go on the pass; a host only overrides them for their own driveway or a note.
const PARKING_PRESETS: { value: string; label: string; text: string }[] = [
  { value: 'estate', label: "Estate's visitor parking rules", text: '' },
  { value: 'driveway', label: 'Host driveway only', text: 'Host driveway parking only. Please do not block neighboring access.' },
];

/** Dates a visit cannot start on: the past, and anything outside a renter's stay. */
function isUnavailable(date: Date, stay: Stay): boolean {
  if (date < startOfDay(new Date())) return true;
  if (stay?.leaseStart && stay?.leaseEnd) {
    return date < startOfDay(parseISO(stay.leaseStart)) || date > startOfDay(parseISO(stay.leaseEnd));
  }
  return false;
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="text-xs text-destructive">{message}</p> : null;
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <fieldset className="space-y-3">
      <legend className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</legend>
      {children}
    </fieldset>
  );
}

export type HostProperty = { id: number; label: string };

type Props = {
  graceHours: number;
  userStay?: Stay;
  /** The host's properties; with more than one, the form asks which is being visited. */
  hostProperties?: HostProperty[];
  /** Security's page leads with the scanner, so registering is the secondary action there. */
  secondary?: boolean;
};

export function RegisterVisitorDialog({ graceHours, userStay, hostProperties = [], secondary = false }: Props) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm" variant={secondary ? 'outline' : 'default'} className="gap-1.5 h-11 rounded-xl">
          <PlusCircle className="h-4 w-4" />
          <span>Register Visitor</span>
        </Button>
      </DialogTrigger>

      <DialogContent className="sm:max-w-[560px] max-h-[90vh] overflow-y-auto">
        {/* Mounted only while open, so every visit to the form starts clean. */}
        <RegisterVisitorForm graceHours={graceHours} userStay={userStay} hostProperties={hostProperties} onDone={() => setOpen(false)} />
      </DialogContent>
    </Dialog>
  );
}

function RegisterVisitorForm({ graceHours, userStay, hostProperties = [], onDone }: Omit<Props, 'secondary'> & { onDone: () => void }) {
  const { toast } = useToast();
  const messaging = useMessaging();

  const [expectedDate, setExpectedDate] = useState<Date | undefined>(new Date());
  const [dateRange, setDateRange] = useState<DateRange | undefined>({ from: new Date(), to: add(new Date(), { days: 7 }) });
  const [hour, setHour] = useState('10');
  const [minute, setMinute] = useState('00');
  const [meridiem, setMeridiem] = useState('AM');
  const [windowPreset, setWindowPreset] = useState('2h');
  const [customWindowStart, setCustomWindowStart] = useState('10:00');
  const [customWindowEnd, setCustomWindowEnd] = useState('12:00');
  const [parkingPreset, setParkingPreset] = useState('estate');

  const form = useForm({
    name: '',
    contact: '',
    vehicle: '',
    id_type: '',
    id_number: '',
    type: 'One-time' as 'One-time' | 'Recurring',
    expected_at: '',
    date_range: '',
    arrival_window_start: '' as string | null,
    arrival_window_end: '' as string | null,
    parking_instructions: PARKING_PRESETS[0].text,
    notes: '',
    notify_email: true,
    notify_sms: false,
    notify_whatsapp: false,
    pass_category: 'VISITOR' as 'VISITOR' | 'CONTRACTOR',
    // Only sent when there is a choice to make; otherwise the pass takes the host's address.
    property_id: hostProperties.length > 1 ? String(hostProperties[0].id) : '',
  });

  const isRecurring = form.data.type === 'Recurring';
  const errors = form.errors as Partial<Record<keyof typeof form.data, string>>;

  const arrivalWindow = (): [string | null, string | null] => {
    if (windowPreset === 'custom') {
      return [customWindowStart || null, customWindowEnd || null];
    }
    const fixed = ARRIVAL_WINDOWS.find((w) => w.value === windowPreset)?.range;
    if (fixed) {
      return fixed;
    }
    // 2h or 4h from the arrival time.
    let h = parseInt(hour, 10) % 12;
    if (meridiem === 'PM') h += 12;
    const endH = Math.min(23, h + (windowPreset === '4h' ? 4 : 2));
    return [`${String(h).padStart(2, '0')}:${minute}`, `${String(endH).padStart(2, '0')}:${minute}`];
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    // Recurring clearances start at the beginning of the range.
    const start = isRecurring ? dateRange?.from : expectedDate;
    const rangeLabel =
      isRecurring && dateRange?.from && dateRange?.to
        ? `${format(dateRange.from, 'yyyy-MM-dd')} - ${format(dateRange.to, 'yyyy-MM-dd')}`
        : start
          ? format(start, 'yyyy-MM-dd')
          : '';
    const [winStart, winEnd] = arrivalWindow();

    form.transform((data) => ({
      ...data,
      property_id: data.property_id ? Number(data.property_id) : null,
      expected_at: toIsoDateTime(start, hour, minute, meridiem),
      date_range: rangeLabel,
      arrival_window_start: winStart,
      arrival_window_end: winEnd,
    }));

    form.post('/dashboard/visitors', {
      preserveScroll: true,
      onSuccess: () => {
        const isContractor = form.data.pass_category === 'CONTRACTOR';
        onDone();
        toast({
          title: isContractor ? 'Contractor Registered' : 'Visitor Registered',
          description: isContractor
            ? 'Their pass is waiting for security approval. It will be sent once approved.'
            : 'Their pass has been issued. The gatehouse will scan it on arrival.',
        });
      },
      // A blocklist hit comes back as a validation error on `name`, so it is
      // surfaced inline by the field rather than swallowed.
    });
  };

  return (
    <form onSubmit={submit} className="space-y-6">
      <DialogHeader>
        <DialogTitle>Register New Visitor</DialogTitle>
        <DialogDescription>
          Their pass is sent once you save. Clearances expire {graceHours} hours after the expected
          time if the visitor never arrives.
        </DialogDescription>
      </DialogHeader>

      <Section title="Visitor">
        <div className="space-y-1.5">
          <Label htmlFor="visitor-name">Full name</Label>
          <Input
            id="visitor-name"
            placeholder="John Doe"
            value={form.data.name}
            onChange={(e) => form.setData('name', e.target.value)}
            aria-invalid={!!errors.name}
            required
            autoFocus
          />
          <FieldError message={errors.name} />
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="visitor-contact">Phone or email</Label>
            <Input
              id="visitor-contact"
              placeholder="+1 876 555 0100"
              value={form.data.contact}
              onChange={(e) => form.setData('contact', e.target.value)}
              aria-invalid={!!errors.contact}
            />
            <FieldError message={errors.contact} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="visitor-vehicle">
              Vehicle <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <Input
              id="visitor-vehicle"
              placeholder="Make, colour, plate"
              value={form.data.vehicle}
              onChange={(e) => form.setData('vehicle', e.target.value)}
              aria-invalid={!!errors.vehicle}
            />
            <FieldError message={errors.vehicle} />
          </div>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="visitor-id-type">
              ID type <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <Select value={form.data.id_type} onValueChange={(value) => form.setData('id_type', value)}>
              <SelectTrigger id="visitor-id-type" aria-invalid={!!errors.id_type}>
                <SelectValue placeholder="Select ID type" />
              </SelectTrigger>
              <SelectContent>
                {ID_TYPES.map((t) => (
                  <SelectItem key={t} value={t}>{t}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError message={errors.id_type} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="visitor-id-number">
              ID number <span className="font-normal text-muted-foreground">(optional)</span>
            </Label>
            <Input
              id="visitor-id-number"
              value={form.data.id_number}
              onChange={(e) => form.setData('id_number', e.target.value)}
              aria-invalid={!!errors.id_number}
            />
            <FieldError message={errors.id_number} />
          </div>
        </div>
      </Section>

      <Section title="Visit">
        {hostProperties.length > 1 && (
          <div className="space-y-1.5">
            <Label htmlFor="visit-property">Visiting</Label>
            <Select value={form.data.property_id} onValueChange={(value) => form.setData('property_id', value)}>
              <SelectTrigger id="visit-property" aria-invalid={!!errors.property_id}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {hostProperties.map((p) => (
                  <SelectItem key={p.id} value={String(p.id)}>{p.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError message={errors.property_id} />
          </div>
        )}

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <RadioGroup
            value={form.data.type}
            onValueChange={(value: string) => form.setData('type', value as 'One-time' | 'Recurring')}
            className="flex gap-4"
            aria-label="Entry type"
          >
            <div className="flex items-center gap-2">
              <RadioGroupItem value="One-time" id="visit-one-time" />
              <Label htmlFor="visit-one-time" className="font-normal">One-time</Label>
            </div>
            <div className="flex items-center gap-2">
              <RadioGroupItem value="Recurring" id="visit-recurring" />
              <Label htmlFor="visit-recurring" className="font-normal">Recurring</Label>
            </div>
          </RadioGroup>

          <div className="flex items-center gap-2">
            <Checkbox
              id="visit-contractor"
              checked={form.data.pass_category === 'CONTRACTOR'}
              onCheckedChange={(checked) => form.setData('pass_category', checked === true ? 'CONTRACTOR' : 'VISITOR')}
              aria-describedby="visit-contractor-help"
            />
            <Label htmlFor="visit-contractor" className="font-normal">Contractor or tradesperson</Label>
          </div>
        </div>
        {form.data.pass_category === 'CONTRACTOR' && (
          <p id="visit-contractor-help" className="text-xs text-muted-foreground">
            Contractors need security approval before their pass is sent, and are admitted during working hours only.
          </p>
        )}

        <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
          <div className="space-y-1.5">
            <Label htmlFor="visit-date">{isRecurring ? 'Dates' : 'Date'}</Label>
            <Popover>
              <PopoverTrigger asChild>
                <Button
                  id="visit-date"
                  type="button"
                  variant="outline"
                  className={cn(
                    'w-full justify-start text-left font-normal',
                    !(isRecurring ? dateRange?.from : expectedDate) && 'text-muted-foreground',
                  )}
                >
                  <CalendarIcon className="mr-2 h-4 w-4 shrink-0" />
                  <span className="truncate">
                    {isRecurring
                      ? dateRange?.from
                        ? dateRange.to
                          ? `${format(dateRange.from, 'LLL d, y')} – ${format(dateRange.to, 'LLL d, y')}`
                          : format(dateRange.from, 'LLL d, y')
                        : 'Pick a date range'
                      : expectedDate
                        ? format(expectedDate, 'PPP')
                        : 'Pick a date'}
                  </span>
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-auto p-0" align="start">
                {isRecurring ? (
                  <Calendar
                    initialFocus
                    mode="range"
                    defaultMonth={dateRange?.from}
                    selected={dateRange}
                    onSelect={setDateRange}
                    numberOfMonths={2}
                    disabled={(date) => isUnavailable(date, userStay)}
                  />
                ) : (
                  <Calendar
                    initialFocus
                    mode="single"
                    selected={expectedDate}
                    onSelect={setExpectedDate}
                    disabled={(date) => isUnavailable(date, userStay)}
                  />
                )}
              </PopoverContent>
            </Popover>
          </div>

          <div className="space-y-1.5">
            <Label id="visit-time-label">{isRecurring ? 'Starts at' : 'Arrives at'}</Label>
            <div className="flex gap-2" role="group" aria-labelledby="visit-time-label">
              <Select value={hour} onValueChange={setHour}>
                <SelectTrigger className="w-[70px]" aria-label="Hour"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {Array.from({ length: 12 }, (_, i) => `${i + 1}`).map((h) => (
                    <SelectItem key={h} value={h}>{h}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={minute} onValueChange={setMinute}>
                <SelectTrigger className="w-[70px]" aria-label="Minute"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {['00', '15', '30', '45'].map((m) => (
                    <SelectItem key={m} value={m}>{m}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={meridiem} onValueChange={setMeridiem}>
                <SelectTrigger className="w-[70px]" aria-label="AM or PM"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="AM">AM</SelectItem>
                  <SelectItem value="PM">PM</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </div>
        <FieldError message={errors.expected_at} />

        <div className="space-y-1.5">
          <Label htmlFor="visit-window">Arrival window</Label>
          <Select value={windowPreset} onValueChange={setWindowPreset}>
            <SelectTrigger id="visit-window">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {ARRIVAL_WINDOWS.map((w) => (
                <SelectItem key={w.value} value={w.value}>{w.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {windowPreset === 'custom' && (
            <div className="grid grid-cols-2 gap-3 pt-1">
              <div className="space-y-1">
                <Label htmlFor="visit-window-start" className="text-xs text-muted-foreground">From</Label>
                <Input
                  id="visit-window-start"
                  type="time"
                  value={customWindowStart}
                  onChange={(e) => setCustomWindowStart(e.target.value)}
                />
              </div>
              <div className="space-y-1">
                <Label htmlFor="visit-window-end" className="text-xs text-muted-foreground">Until</Label>
                <Input
                  id="visit-window-end"
                  type="time"
                  value={customWindowEnd}
                  onChange={(e) => setCustomWindowEnd(e.target.value)}
                />
              </div>
            </div>
          )}
          <FieldError message={errors.arrival_window_start ?? errors.arrival_window_end} />
        </div>
      </Section>

      <Section title="On the pass">
        <div className="space-y-1.5">
          <Label htmlFor="visit-parking">Parking instructions</Label>
          <Select
            value={parkingPreset}
            onValueChange={(value) => {
              setParkingPreset(value);
              const preset = PARKING_PRESETS.find((p) => p.value === value);
              if (preset) {
                form.setData('parking_instructions', preset.text);
              }
            }}
          >
            <SelectTrigger id="visit-parking">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {PARKING_PRESETS.map((p) => (
                <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
              ))}
              <SelectItem value="custom">Custom instructions</SelectItem>
            </SelectContent>
          </Select>
          <Textarea
            rows={2}
            className="min-h-0"
            aria-label="Parking instructions text"
            value={form.data.parking_instructions}
            onChange={(e) => {
              form.setData('parking_instructions', e.target.value);
              setParkingPreset('custom');
            }}
            placeholder="Blank: the estate's visitor parking rules go on the pass"
          />
          <FieldError message={errors.parking_instructions} />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="visit-notes">
            Notes for the gate <span className="font-normal text-muted-foreground">(optional)</span>
          </Label>
          <Input
            id="visit-notes"
            placeholder="e.g. Bringing catering supplies, elderly guest needing escort"
            value={form.data.notes}
            onChange={(e) => form.setData('notes', e.target.value)}
            aria-invalid={!!errors.notes}
          />
          <FieldError message={errors.notes} />
        </div>

        <div className="space-y-2">
          <Label id="visit-send-label">Send the pass by</Label>
          <div className="flex flex-wrap gap-x-5 gap-y-2" role="group" aria-labelledby="visit-send-label" aria-describedby="visit-send-help">
            <div className="flex items-center gap-2">
              <Checkbox
                id="notify-email"
                checked={form.data.notify_email}
                onCheckedChange={(checked) => form.setData('notify_email', checked === true)}
              />
              <Label htmlFor="notify-email" className="font-normal">Email</Label>
            </div>
            <div className="flex items-center gap-2">
              <Checkbox
                id="notify-sms"
                checked={messaging.sms && form.data.notify_sms}
                disabled={!messaging.sms}
                onCheckedChange={(checked) => form.setData('notify_sms', checked === true)}
              />
              <Label htmlFor="notify-sms" className={cn('font-normal', !messaging.sms && 'text-muted-foreground')}>
                SMS{!messaging.sms && ' (not set up)'}
              </Label>
            </div>
            <div className="flex items-center gap-2">
              <Checkbox
                id="notify-whatsapp"
                checked={messaging.whatsapp && form.data.notify_whatsapp}
                disabled={!messaging.whatsapp}
                onCheckedChange={(checked) => form.setData('notify_whatsapp', checked === true)}
              />
              <Label htmlFor="notify-whatsapp" className={cn('font-normal', !messaging.whatsapp && 'text-muted-foreground')}>
                WhatsApp{!messaging.whatsapp && ' (not set up)'}
              </Label>
            </div>
          </div>
          <p id="visit-send-help" className="text-xs text-muted-foreground">
            Email needs an email address as the contact; SMS and WhatsApp need a phone number.
          </p>
        </div>
      </Section>

      <DialogFooter className="gap-2 sm:gap-0">
        <Button type="button" variant="outline" onClick={onDone} disabled={form.processing}>
          Cancel
        </Button>
        <Button type="submit" disabled={form.processing}>
          {form.processing ? 'Saving…' : 'Register visitor'}
        </Button>
      </DialogFooter>
    </form>
  );
}
