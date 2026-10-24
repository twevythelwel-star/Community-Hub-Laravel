import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { CalendarIcon, Edit, UserCheck } from 'lucide-react';
import { Calendar } from '@/components/ui/calendar';
import { cn } from '@/lib/utils';
import { format, parseISO } from 'date-fns';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Renter } from '@/types';

export type HomeownerOption = { id: number; name: string; property: string };

type StayType = 'Long-term (Renter)' | 'Short-term (Airbnb)';

type RenterFormProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Given: edit this stay. Absent: register a new one. */
  renter?: Renter | null;
  /** Registering only: whose property the stay is for; the address comes from them. */
  homeowners?: HomeownerOption[];
  /** Registering only: the button that opens the dialog. */
  children?: React.ReactNode;
};

const toDate = (value: Date | string | undefined | null) =>
  value ? (typeof value === 'string' ? parseISO(value) : value) : undefined;

/** Registers a renter or short-term guest, or edits one when `renter` is given. */
export function RenterForm({ open, onOpenChange, renter = null, homeowners = [], children }: RenterFormProps) {
  const editing = renter !== null;
  const { toast } = useToast();
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const [homeownerId, setHomeownerId] = useState('');
  const [name, setName] = useState('');
  const [stayType, setStayType] = useState<StayType>('Long-term (Renter)');
  const [contact, setContact] = useState('');
  const [leaseStart, setLeaseStart] = useState<Date | undefined>(new Date());
  const [leaseEnd, setLeaseEnd] = useState<Date | undefined>(undefined);
  const [notes, setNotes] = useState('');
  const [status, setStatus] = useState<'Active' | 'Inactive'>('Active');

  const resetForm = () => {
    setHomeownerId('');
    setName('');
    setStayType('Long-term (Renter)');
    setContact('');
    setLeaseStart(new Date());
    setLeaseEnd(undefined);
    setNotes('');
    setStatus('Active');
    setErrors({});
  };

  useEffect(() => {
    if (renter) {
      setName(renter.name);
      setStayType(renter.stayType ?? 'Long-term (Renter)');
      setContact(renter.contact || '');
      setNotes(renter.notes || '');
      setStatus(renter.status === 'Active' ? 'Active' : 'Inactive');
      setLeaseStart(toDate(renter.leaseStart));
      setLeaseEnd(toDate(renter.leaseEnd));
      setErrors({});
    }
  }, [renter]);

  const validate = (): Record<string, string> | null => {
    if (!editing && !homeownerId) return { homeowner_id: 'Choose the homeowner whose property this is.' };
    if (!name.trim()) return { name: 'Full name is required.' };
    if (!leaseStart) return { lease_start: 'Start date is required.' };
    if (!leaseEnd) return { lease_end: 'End date is required.' };
    if (leaseEnd < leaseStart) return { lease_end: 'End date cannot be earlier than start date.' };
    return null;
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const invalid = validate();
    if (invalid) {
      setErrors(invalid);
      return;
    }

    setSubmitting(true);
    setErrors({});

    const fields = {
      name,
      stay_type: stayType,
      contact,
      notes,
      lease_start: format(leaseStart as Date, 'yyyy-MM-dd'),
      lease_end: format(leaseEnd as Date, 'yyyy-MM-dd'),
    };

    const options = {
      preserveScroll: true,
      onSuccess: () => {
        if (editing) {
          toast({
            title: 'Temporary Homeowner Updated',
            description: `${name}'s stay details and access timeframe have been updated.`,
          });
        } else {
          const property = homeowners.find((h) => String(h.id) === homeownerId)?.property;
          toast({
            title: 'Temporary Homeowner Registered',
            description: `${name} has been added as a ${stayType}${property ? ` at ${property}` : ''}.`,
          });
          resetForm();
        }
        onOpenChange(false);
        setSubmitting(false);
      },
      onError: (errs: Record<string, string>) => {
        setErrors(errs);
        setSubmitting(false);
      },
    };

    if (editing) {
      router.put(`/dashboard/renters/${renter.id}`, { ...fields, status }, options);
    } else {
      router.post('/dashboard/renters', { ...fields, homeowner_id: Number(homeownerId) }, options);
    }
  };

  // The edit dialog with no renter chosen yet: nothing to show.
  if (!editing && !children && !open) {
    return null;
  }

  const idPrefix = editing ? 'edit-renter' : 'renter';

  const stayOption = (value: StayType, label: string, hint: string) => (
    <div
      className={cn(
        'flex flex-col items-start gap-1 p-3 rounded-lg border cursor-pointer transition-all',
        stayType === value
          ? 'border-primary bg-primary/5 text-primary font-medium'
          : 'border-muted hover:border-muted-foreground/30'
      )}
      onClick={() => setStayType(value)}
    >
      <div className="flex items-center space-x-2">
        <RadioGroupItem value={value} id={`${idPrefix}-stay-${value.startsWith('Long') ? 'long' : 'short'}`} />
        <Label htmlFor={`${idPrefix}-stay-${value.startsWith('Long') ? 'long' : 'short'}`} className="cursor-pointer font-semibold">
          {label}
        </Label>
      </div>
      <span className="text-xs text-muted-foreground pl-6">{hint}</span>
    </div>
  );

  const datePicker = (
    label: string,
    placeholder: string,
    value: Date | undefined,
    onSelect: (date: Date | undefined) => void,
    error: string | undefined,
    disabled?: (date: Date) => boolean
  ) => (
    <div className="space-y-1.5">
      <Label>{label}</Label>
      <Popover>
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            className={cn('w-full justify-start text-left font-normal', !value && 'text-muted-foreground')}
          >
            <CalendarIcon className="mr-2 h-4 w-4 opacity-70" />
            {value ? format(value, 'PPP') : <span>{placeholder}</span>}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="start">
          <Calendar mode="single" selected={value} onSelect={onSelect} disabled={disabled} initialFocus />
        </PopoverContent>
      </Popover>
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => {
        onOpenChange(isOpen);
        if (!isOpen && !editing) {
          resetForm();
        }
      }}
    >
      {children && <DialogTrigger asChild>{children}</DialogTrigger>}
      <DialogContent className="sm:max-w-[500px]">
        <form onSubmit={handleSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {editing ? <Edit className="h-5 w-5 text-primary" /> : <UserCheck className="h-5 w-5 text-primary" />}
              {editing ? 'Edit Temporary Homeowner' : 'Register Temporary Homeowner'}
            </DialogTitle>
            <DialogDescription>
              {editing
                ? 'Adjust stay timeframe, stay category, contact details, or active status.'
                : "Grant temporary access to a renter or short-term guest at a homeowner's property. Access automatically expires once the end date is reached."}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            {!editing && (
              <div className="space-y-1.5">
                <Label htmlFor="renter-homeowner">Homeowner &amp; property</Label>
                <Select value={homeownerId} onValueChange={setHomeownerId}>
                  <SelectTrigger id="renter-homeowner" aria-invalid={!!errors.homeowner_id}>
                    <SelectValue placeholder={homeowners.length ? 'Choose a homeowner' : 'No homeowner accounts yet'} />
                  </SelectTrigger>
                  <SelectContent>
                    {homeowners.map((h) => (
                      <SelectItem key={h.id} value={String(h.id)}>
                        {h.name} — {h.property}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.homeowner_id && <p className="text-xs text-destructive">{errors.homeowner_id}</p>}
              </div>
            )}

            <div className="space-y-2">
              <Label className="font-medium">Type of Stay</Label>
              <RadioGroup
                value={stayType}
                onValueChange={(val) => setStayType(val as StayType)}
                className="grid grid-cols-2 gap-3"
              >
                {stayOption('Long-term (Renter)', 'Long-term', 'Multi-month lease tenants')}
                {stayOption('Short-term (Airbnb)', 'Short-term (Airbnb)', 'Days to weeks vacation stays')}
              </RadioGroup>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor={`${idPrefix}-name`}>Occupant Name</Label>
              <Input
                id={`${idPrefix}-name`}
                placeholder="e.g., Sophia Taylor or John Doe"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
              />
              {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor={`${idPrefix}-contact`}>Contact Phone or Email</Label>
              <Input
                id={`${idPrefix}-contact`}
                placeholder="e.g., +1 (555) 234-5678 or guest@example.com"
                value={contact}
                onChange={(e) => setContact(e.target.value)}
              />
              {errors.contact && <p className="text-xs text-destructive">{errors.contact}</p>}
            </div>

            <div className="grid grid-cols-2 gap-3">
              {datePicker('Start Date', 'Start Date', leaseStart, setLeaseStart, errors.lease_start)}
              {datePicker('Expiration Date', 'End Date', leaseEnd, setLeaseEnd, errors.lease_end, (date) =>
                leaseStart ? date < leaseStart : false
              )}
            </div>

            {editing && (
              <div className="space-y-1.5">
                <Label>Access Status</Label>
                <div className="flex gap-4">
                  <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                      type="radio"
                      name="edit-renter-status"
                      checked={status === 'Active'}
                      onChange={() => setStatus('Active')}
                      className="accent-primary"
                    />
                    <span>Active Access</span>
                  </label>
                  <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input
                      type="radio"
                      name="edit-renter-status"
                      checked={status === 'Inactive'}
                      onChange={() => setStatus('Inactive')}
                      className="accent-primary"
                    />
                    <span>Inactive / Suspended</span>
                  </label>
                </div>
              </div>
            )}

            <div className="space-y-1.5">
              <Label htmlFor={`${idPrefix}-notes`}>
                {editing ? 'Notes / Special Instructions' : 'Notes / Special Instructions (Optional)'}
              </Label>
              <Textarea
                id={`${idPrefix}-notes`}
                placeholder="Keycode instructions, vehicle license plates, or party size..."
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                rows={2}
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button type="submit" disabled={submitting}>
              {editing ? (submitting ? 'Saving...' : 'Save Changes') : submitting ? 'Registering...' : 'Register Occupant'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
