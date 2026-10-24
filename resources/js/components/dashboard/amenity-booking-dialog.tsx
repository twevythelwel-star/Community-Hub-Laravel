import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Calendar } from '@/components/ui/calendar';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import { useAuth } from '@/context/auth-context';
import { format } from 'date-fns';
import {
  Calendar as CalendarIcon,
  Clock,
  Users,
  CheckCircle2,
  Building2,
  Sparkles,
  MapPin,
  Share2,
  FileText
} from 'lucide-react';
import { cn } from '@/lib/utils';

export interface AmenityItem {
  id: string | number;
  name: string;
  category?: string;
  maxGuests?: number;
  openHours?: string;
}

const DEFAULT_AMENITIES: AmenityItem[] = [
  { id: 'clubhouse', name: 'Community Center & Ballroom', category: 'Community Center', maxGuests: 60, openHours: '08:00 - 22:00' },
  { id: 'tennis', name: 'West Tennis & Pickleball Courts', category: 'Park', maxGuests: 8, openHours: '06:00 - 21:00' },
  { id: 'pool', name: 'Central Pool Pavilion & Deck', category: 'Community Center', maxGuests: 30, openHours: '07:00 - 20:00' },
  { id: 'bbq', name: 'Evergreen BBQ Grills & Picnic Gazebo', category: 'Park', maxGuests: 25, openHours: '10:00 - 21:00' },
  { id: 'hall', name: 'Executive Meeting Room & Lounge', category: 'Community Center', maxGuests: 16, openHours: '08:00 - 20:00' },
];

const TIME_SLOTS = [
  { id: 'morning', label: 'Morning Slot (08:00 AM – 12:00 PM)', duration: '4 hrs' },
  { id: 'afternoon', label: 'Afternoon Slot (12:00 PM – 04:00 PM)', duration: '4 hrs' },
  { id: 'evening', label: 'Evening Prime Slot (04:00 PM – 08:00 PM)', duration: '4 hrs' },
  { id: 'night', label: 'Twilight Session (08:00 PM – 10:00 PM)', duration: '2 hrs' },
];

interface AmenityBookingDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  initialAmenityName?: string;
  availableAmenities?: AmenityItem[];
}

export function AmenityBookingDialog({
  open,
  onOpenChange,
  initialAmenityName,
  availableAmenities = DEFAULT_AMENITIES,
}: AmenityBookingDialogProps) {
  const { user } = useAuth();
  const { toast } = useToast();

  const [selectedAmenity, setSelectedAmenity] = useState<string>(
    initialAmenityName || availableAmenities[0]?.name || 'Community Center & Ballroom'
  );
  const [selectedDate, setSelectedDate] = useState<Date | undefined>(new Date());
  const [selectedSlot, setSelectedSlot] = useState<string>(TIME_SLOTS[1].id);
  const [guestCount, setGuestCount] = useState<number>(4);
  const [notes, setNotes] = useState<string>('');
  const [isConfirmed, setIsConfirmed] = useState<boolean>(false);
  const [confirmationRef, setConfirmationRef] = useState<string>('');

  // Update selectedAmenity if initialAmenityName changes when opened
  React.useEffect(() => {
    if (initialAmenityName) {
      setSelectedAmenity(initialAmenityName);
    }
  }, [initialAmenityName]);

  const activeAmenityData = availableAmenities.find(
    (a) => a.name.toLowerCase() === selectedAmenity.toLowerCase()
  ) || {
    name: selectedAmenity,
    category: 'Estate Facility',
    maxGuests: 40,
    openHours: '08:00 - 22:00',
  };

  const handleBook = (e: React.FormEvent) => {
    e.preventDefault();

    const ref = `BK-${new Date().getFullYear()}-${Math.floor(1000 + Math.random() * 9000)}`;
    setConfirmationRef(ref);
    setIsConfirmed(true);

    toast({
      title: 'Amenity Reserved Successfully',
      description: `${selectedAmenity} reserved for ${selectedDate ? format(selectedDate, 'PPP') : 'selected date'}. Booking ref: ${ref}`,
    });
  };

  const handleReset = () => {
    setIsConfirmed(false);
    setNotes('');
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg max-h-[92vh] overflow-y-auto">
        {!isConfirmed ? (
          <form onSubmit={handleBook} className="space-y-4">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2 text-lg font-bold">
                <Building2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                Reserve Community Amenity
              </DialogTitle>
              <DialogDescription>
                Schedule facility usage for family gatherings, tennis matches, or community events.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4 pt-1">
              {/* Facility Selection */}
              <div className="space-y-1.5">
                <Label htmlFor="amenity-select" className="text-xs font-semibold">
                  Select Facility / Amenity
                </Label>
                <Select value={selectedAmenity} onValueChange={setSelectedAmenity}>
                  <SelectTrigger id="amenity-select" className="h-10">
                    <SelectValue placeholder="Choose an amenity" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableAmenities.map((amenity) => (
                      <SelectItem key={amenity.id} value={amenity.name}>
                        <div className="flex items-center justify-between w-full gap-2">
                          <span>{amenity.name}</span>
                          {amenity.category && (
                            <span className="text-[10px] text-muted-foreground ml-2">
                              ({amenity.category})
                            </span>
                          )}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Amenity Specs Badge Banner */}
              <div className="flex items-center justify-between rounded-lg border bg-muted/30 px-3 py-2 text-xs">
                <span className="flex items-center gap-1.5 text-muted-foreground">
                  <Clock className="w-3.5 h-3.5 text-emerald-600" />
                  Hours: <strong className="text-foreground">{activeAmenityData.openHours}</strong>
                </span>
                <span className="flex items-center gap-1.5 text-muted-foreground">
                  <Users className="w-3.5 h-3.5 text-blue-600" />
                  Capacity: <strong className="text-foreground">Up to {activeAmenityData.maxGuests || 30} guests</strong>
                </span>
              </div>

              {/* Date & Time Slot Row */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {/* Date Picker */}
                <div className="space-y-1.5">
                  <Label className="text-xs font-semibold">Reservation Date</Label>
                  <Popover>
                    <PopoverTrigger asChild>
                      <Button
                        variant="outline"
                        type="button"
                        className={cn(
                          'w-full justify-start text-left font-normal h-10',
                          !selectedDate && 'text-muted-foreground'
                        )}
                      >
                        <CalendarIcon className="mr-2 h-4 w-4" />
                        {selectedDate ? format(selectedDate, 'PPP') : <span>Pick date</span>}
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                      <Calendar
                        mode="single"
                        selected={selectedDate}
                        onSelect={setSelectedDate}
                        disabled={(date) => date < new Date(new Date().setHours(0, 0, 0, 0))}
                        initialFocus
                      />
                    </PopoverContent>
                  </Popover>
                </div>

                {/* Guest Count */}
                <div className="space-y-1.5">
                  <Label htmlFor="guest-count" className="text-xs font-semibold">
                    Expected Attendance
                  </Label>
                  <Input
                    id="guest-count"
                    type="number"
                    min={1}
                    max={activeAmenityData.maxGuests || 50}
                    value={guestCount}
                    onChange={(e) => setGuestCount(parseInt(e.target.value) || 1)}
                    className="h-10"
                  />
                </div>
              </div>

              {/* Time Slot Selection */}
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Select Time Window</Label>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  {TIME_SLOTS.map((slot) => {
                    const isSelected = selectedSlot === slot.id;
                    return (
                      <button
                        key={slot.id}
                        type="button"
                        onClick={() => setSelectedSlot(slot.id)}
                        className={cn(
                          'p-2.5 text-left rounded-xl border text-xs font-medium transition-all flex flex-col justify-between gap-1',
                          isSelected
                            ? 'border-emerald-500 bg-emerald-500/10 text-emerald-900 dark:text-emerald-200 ring-1 ring-emerald-500'
                            : 'border-border/80 bg-card hover:bg-muted/40 text-muted-foreground hover:text-foreground'
                        )}
                      >
                        <span className="font-semibold text-foreground text-[11px] leading-tight">
                          {slot.label.split(' (')[0]}
                        </span>
                        <span className="text-[10px] opacity-80">
                          {slot.label.includes('(') ? `(${slot.label.split('(')[1]}` : slot.duration}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Resident Host Information */}
              <div className="rounded-xl border border-border/80 bg-muted/20 p-3 text-xs space-y-1">
                <div className="flex justify-between items-center text-muted-foreground">
                  <span>Host Resident</span>
                  <span className="font-semibold text-foreground">{user?.displayName || user?.name || 'Resident'}</span>
                </div>
                <div className="flex justify-between items-center text-muted-foreground">
                  <span>Assigned Property</span>
                  <span className="font-mono text-foreground">{user?.lot ? `Lot ${user.lot}` : 'Main Residence'}</span>
                </div>
              </div>

              {/* Optional Notes */}
              <div className="space-y-1.5">
                <Label htmlFor="booking-notes" className="text-xs font-semibold">
                  Special Setup / Equipment Notes (Optional)
                </Label>
                <Input
                  id="booking-notes"
                  placeholder="e.g. Need grill gas canister, extra banquet tables..."
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  className="text-xs h-9"
                />
              </div>
            </div>

            <DialogFooter className="gap-2 sm:gap-0 pt-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button
                type="submit"
                className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold gap-1.5 shadow-sm"
              >
                <Sparkles className="w-4 h-4" />
                Confirm Reservation
              </Button>
            </DialogFooter>
          </form>
        ) : (
          /* Confirmation Pass Screen */
          <div className="space-y-5 py-2 text-center">
            <div className="mx-auto w-14 h-14 rounded-full bg-emerald-500/15 flex items-center justify-center text-emerald-600 dark:text-emerald-400 animate-bounce">
              <CheckCircle2 className="w-8 h-8" />
            </div>

            <div>
              <Badge variant="outline" className="text-emerald-600 border-emerald-500/40 text-xs px-3 py-1 font-mono">
                {confirmationRef}
              </Badge>
              <h3 className="text-xl font-bold text-foreground mt-2">Reservation Confirmed</h3>
              <p className="text-xs text-muted-foreground mt-1">
                Your reservation is registered with estate operations and security.
              </p>
            </div>

            <div className="rounded-xl border border-border bg-card p-4 text-left space-y-2.5 text-xs shadow-sm">
              <div className="flex justify-between items-center border-b pb-2">
                <span className="text-muted-foreground">Amenity</span>
                <strong className="text-foreground">{selectedAmenity}</strong>
              </div>
              <div className="flex justify-between items-center border-b pb-2">
                <span className="text-muted-foreground">Date</span>
                <span className="font-semibold text-foreground">
                  {selectedDate ? format(selectedDate, 'EEEE, MMMM d, yyyy') : 'Scheduled'}
                </span>
              </div>
              <div className="flex justify-between items-center border-b pb-2">
                <span className="text-muted-foreground">Time Slot</span>
                <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                  {TIME_SLOTS.find((s) => s.id === selectedSlot)?.label}
                </span>
              </div>
              <div className="flex justify-between items-center border-b pb-2">
                <span className="text-muted-foreground">Registered Guests</span>
                <span className="font-medium text-foreground">{guestCount} people</span>
              </div>
              <div className="flex justify-between items-center">
                <span className="text-muted-foreground">Host Lot</span>
                <span className="font-mono text-foreground">{user?.lot ? `Lot ${user.lot}` : 'Main Residence'}</span>
              </div>
            </div>

            <DialogFooter className="flex-col sm:flex-row gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                className="w-full gap-1.5"
                onClick={() => {
                  navigator.clipboard.writeText(
                    `Amenity Booking: ${selectedAmenity} on ${selectedDate ? format(selectedDate, 'PPP') : ''} (${confirmationRef})`
                  );
                  toast({ title: 'Reservation Details Copied' });
                }}
              >
                <Share2 className="w-3.5 h-3.5" />
                Copy Details
              </Button>
              <Button
                type="button"
                className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-semibold"
                onClick={handleReset}
              >
                Done
              </Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
