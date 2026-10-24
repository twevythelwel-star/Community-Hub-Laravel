import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Home, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/hooks/use-toast';
import type { DirectoryUser } from '@/components/dashboard/create-user-form';

type Props = {
  person: DirectoryUser | null;
  onOpenChange: (open: boolean) => void;
};

/**
 * The properties a homeowner owns. HOA dues are charged per property, so each
 * one listed here is billed its own monthly assessment.
 */
export function ManagePropertiesDialog({ person, onOpenChange }: Props) {
  const { toast } = useToast();
  const [lotNumber, setLotNumber] = useState('');
  const [streetAddress, setStreetAddress] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);

  const properties = person?.properties ?? [];

  const close = (open: boolean) => {
    if (!open) {
      setLotNumber('');
      setStreetAddress('');
      setErrors({});
      setConfirmingId(null);
    }
    onOpenChange(open);
  };

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!person) return;

    setSubmitting(true);
    router.post(
      `/dashboard/directory/users/${person.id}/properties`,
      { lot_number: lotNumber, street_address: streetAddress || null },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast({ title: 'Property added', description: `${lotNumber} is now billed to ${person.name}.` });
          setLotNumber('');
          setStreetAddress('');
          setErrors({});
        },
        onError: (errs) => setErrors(errs),
        onFinish: () => setSubmitting(false),
      },
    );
  };

  const remove = (id: number, label: string) => {
    if (confirmingId !== id) {
      setConfirmingId(id);
      return;
    }

    router.delete(`/dashboard/directory/properties/${id}`, {
      preserveScroll: true,
      onSuccess: () => {
        setConfirmingId(null);
        toast({ title: 'Property removed', description: `${label} is no longer billed to ${person?.name}.` });
      },
    });
  };

  return (
    <Dialog open={!!person} onOpenChange={close}>
      <DialogContent className="sm:max-w-[480px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Home className="h-5 w-5 text-primary" />
            Properties owned by {person?.name}
          </DialogTitle>
          <DialogDescription>
            Each property is billed its own monthly HOA assessment. Removing one stops future
            billing; invoices already issued are kept.
          </DialogDescription>
        </DialogHeader>

        <ul className="space-y-2" aria-label="Owned properties">
          {properties.length === 0 && (
            <li className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
              No property on record, so nothing is billed. Add the lot they own below.
            </li>
          )}
          {properties.map((p) => (
            <li key={p.id} className="flex items-center justify-between gap-3 rounded-md border p-3">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{p.label || 'Unnamed lot'}</p>
                <p className="font-mono text-xs text-muted-foreground">{p.code}</p>
              </div>
              <Button
                type="button"
                size="sm"
                variant={confirmingId === p.id ? 'destructive' : 'ghost'}
                className="shrink-0 gap-1.5"
                onClick={() => remove(p.id, p.label)}
                aria-label={confirmingId === p.id ? `Confirm removing ${p.label}` : `Remove ${p.label}`}
              >
                <Trash2 className="h-3.5 w-3.5" />
                {confirmingId === p.id ? 'Confirm' : 'Remove'}
              </Button>
            </li>
          ))}
        </ul>

        <form onSubmit={add} className="space-y-3 border-t pt-4">
          <p className="text-sm font-medium">Add a property</p>
          <div className="grid gap-3 sm:grid-cols-[1fr_1.5fr]">
            <div className="space-y-1.5">
              <Label htmlFor="property-lot">Lot or unit</Label>
              <Input
                id="property-lot"
                placeholder="Lot 22"
                value={lotNumber}
                onChange={(e) => setLotNumber(e.target.value)}
                aria-invalid={!!errors.lot_number}
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="property-street">
                Street <span className="font-normal text-muted-foreground">(optional)</span>
              </Label>
              <Input
                id="property-street"
                placeholder="Hibiscus Way"
                value={streetAddress}
                onChange={(e) => setStreetAddress(e.target.value)}
                aria-invalid={!!errors.street_address}
              />
            </div>
          </div>
          {(errors.lot_number || errors.street_address) && (
            <p className="text-xs text-destructive">{errors.lot_number ?? errors.street_address}</p>
          )}
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={() => close(false)}>
              Done
            </Button>
            <Button type="submit" disabled={submitting || !lotNumber.trim()} className="gap-1.5">
              <Plus className="h-4 w-4" />
              {submitting ? 'Adding…' : 'Add property'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
