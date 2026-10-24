import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Link as LinkIcon, Check, Copy, Calendar as CalendarIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';
import { toIsoDateTime } from '@/components/dashboard/register-visitor-dialog';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

interface VisitorRsvpDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function VisitorRsvpDialog({ open, onOpenChange }: VisitorRsvpDialogProps) {
  const { toast } = useToast();
  const [rsvpTitle, setRsvpTitle] = useState('');
  const [rsvpDate, setRsvpDate] = useState<Date | undefined>(new Date());
  const [rsvpMaxGuests, setRsvpMaxGuests] = useState('50');
  const [rsvpNotes, setRsvpNotes] = useState('');
  const [rsvpGeneratedUrl, setRsvpGeneratedUrl] = useState<string | null>(null);
  const [rsvpCopied, setRsvpCopied] = useState(false);
  const [rsvpSubmitting, setRsvpSubmitting] = useState(false);

  const onSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!rsvpTitle.trim()) {
      toast({ variant: 'destructive', title: 'Title required', description: 'Please enter an event or gathering title.' });
      return;
    }

    // The dialog has no time picker; arrival is 12:00 PM on the chosen day.
    const expectedAt = toIsoDateTime(rsvpDate, '12', '00', 'PM');
    setRsvpSubmitting(true);
    router.post('/dashboard/visitors/invites', {
      title: rsvpTitle,
      expected_at: expectedAt,
      max_guests: parseInt(rsvpMaxGuests, 10) || 50,
      notes: rsvpNotes || null,
    }, {
      preserveScroll: true,
      onSuccess: (page) => {
        setRsvpSubmitting(false);
        const url = (page.props as any).flash?.rsvp_url || null;
        if (url) {
          setRsvpGeneratedUrl(url);
        } else {
          onOpenChange(false);
        }
        toast({ title: 'RSVP Invite Created', description: 'Share the link with your attendees.' });
      },
      onError: (errs) => {
        setRsvpSubmitting(false);
        toast({ variant: 'destructive', title: 'Creation failed', description: (Object.values(errs)[0] as string) || 'Error generating RSVP link' });
      },
    });
  };

  const onCopy = () => {
    if (!rsvpGeneratedUrl) return;
    navigator.clipboard.writeText(rsvpGeneratedUrl);
    setRsvpCopied(true);
    setTimeout(() => setRsvpCopied(false), 2000);
    toast({ title: 'Copied!', description: 'RSVP link copied to clipboard.' });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[500px]">
        {rsvpGeneratedUrl ? (
          <div className="space-y-4 py-4 text-center">
            <div className="mx-auto h-12 w-12 rounded-full bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
              <Check className="h-6 w-6" />
            </div>
            <DialogTitle className="text-xl font-bold">Event RSVP Link Ready!</DialogTitle>
            <DialogDescription className="text-xs">
              Share this link with your event guests. They can pre-clear themselves and receive instant digital QR gate passes via SMS before arriving.
            </DialogDescription>
            <div className="flex items-center gap-2 p-2.5 rounded-xl bg-muted/60 border font-mono text-xs text-foreground">
              <span className="truncate flex-1 text-left">{rsvpGeneratedUrl}</span>
              <Button
                type="button"
                size="sm"
                variant="secondary"
                onClick={onCopy}
                className="gap-1 h-8"
              >
                {rsvpCopied ? (
                  <Check className="h-3.5 w-3.5 text-emerald-600" />
                ) : (
                  <Copy className="h-3.5 w-3.5" />
                )}
                <span>{rsvpCopied ? 'Copied' : 'Copy'}</span>
              </Button>
            </div>
            <DialogFooter className="pt-2">
              <Button
                type="button"
                className="w-full"
                onClick={() => {
                  onOpenChange(false);
                  setRsvpGeneratedUrl(null);
                }}
              >
                Done
              </Button>
            </DialogFooter>
          </div>
        ) : (
          <form onSubmit={onSubmit}>
            <DialogHeader>
              <div className="flex items-center gap-2 mb-1">
                <span className="p-1.5 rounded-lg bg-primary/10 text-primary">
                  <LinkIcon className="h-5 w-5" />
                </span>
                <DialogTitle className="text-lg font-bold">Create Event RSVP Link</DialogTitle>
              </div>
              <DialogDescription className="text-xs">
                Generate a self-service registration link for guests attending your private party, dinner, or family gathering.
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4 py-3">
              <div className="space-y-1.5">
                <Label htmlFor="rsvp-title" className="text-xs font-semibold">
                  Event Name / Occasion
                </Label>
                <Input
                  id="rsvp-title"
                  placeholder="e.g. Birthday Party, Dinner Gathering"
                  value={rsvpTitle}
                  onChange={(e) => setRsvpTitle(e.target.value)}
                  required
                  className="h-9 text-xs"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label className="text-xs font-semibold">Arrival Date</Label>
                  <Popover>
                    <PopoverTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        className={cn(
                          'w-full justify-start text-left font-normal h-9 text-xs',
                          !rsvpDate && 'text-muted-foreground'
                        )}
                      >
                        <CalendarIcon className="mr-2 h-3.5 w-3.5" />
                        {rsvpDate ? format(rsvpDate, 'MMM d, yyyy') : <span>Pick date</span>}
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                      <Calendar
                        mode="single"
                        selected={rsvpDate}
                        onSelect={setRsvpDate}
                        initialFocus
                      />
                    </PopoverContent>
                  </Popover>
                </div>

                <div className="space-y-1.5">
                  <Label className="text-xs font-semibold">Max Guests</Label>
                  <Input
                    type="number"
                    min="1"
                    max="200"
                    value={rsvpMaxGuests}
                    onChange={(e) => setRsvpMaxGuests(e.target.value)}
                    className="h-9 text-xs"
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="rsvp-notes" className="text-xs font-semibold">
                  Notes for Attendees (Optional)
                </Label>
                <Input
                  id="rsvp-notes"
                  placeholder="e.g. Park in visitor bay on North driveway"
                  value={rsvpNotes}
                  onChange={(e) => setRsvpNotes(e.target.value)}
                  className="h-9 text-xs"
                />
              </div>
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
              <Button type="submit" size="sm" disabled={rsvpSubmitting} className="gap-1.5">
                <LinkIcon className="h-4 w-4" />
                <span>{rsvpSubmitting ? 'Creating Link...' : 'Generate RSVP Link'}</span>
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
