import React from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import { Siren } from 'lucide-react';
import { cn } from '@/lib/utils';
import { MusterRollCallItem } from './occupancy-types';

export interface StartMusterDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (e: React.FormEvent) => void;
  incidentType: 'fire' | 'hurricane' | 'earthquake' | 'flood' | 'security_incident' | 'drill' | 'other';
  onIncidentTypeChange: (type: 'fire' | 'hurricane' | 'earthquake' | 'flood' | 'security_incident' | 'drill' | 'other') => void;
  title: string;
  onTitleChange: (title: string) => void;
  assemblyPoint: string;
  onAssemblyPointChange: (point: string) => void;
  notes: string;
  onNotesChange: (notes: string) => void;
  submitting: boolean;
  totalOccupants: number;
}

export function StartMusterDialog({
  open,
  onOpenChange,
  onSubmit,
  incidentType,
  onIncidentTypeChange,
  title,
  onTitleChange,
  assemblyPoint,
  onAssemblyPointChange,
  notes,
  onNotesChange,
  submitting,
  totalOccupants,
}: StartMusterDialogProps) {
  const incidentTypes = ['fire', 'hurricane', 'earthquake', 'flood', 'security_incident', 'drill', 'other'] as const;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[480px]">
        <form onSubmit={onSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-rose-600">
              <Siren className="w-5 h-5 animate-pulse" />
              Initiate Emergency Muster
            </DialogTitle>
            <DialogDescription className="text-xs">
              Create an active muster session and snapshot all {totalOccupants} verified occupants on-site for emergency roll-call.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold">Incident Type</Label>
              <div className="grid grid-cols-2 gap-2 text-xs">
                {incidentTypes.map((t) => (
                  <button
                    key={t}
                    type="button"
                    onClick={() => {
                      onIncidentTypeChange(t);
                      if (!title) {
                        onTitleChange(`${t.replace('_', ' ').toUpperCase()} Evacuation`);
                      }
                    }}
                    className={cn(
                      'p-2 rounded-lg border text-left font-bold capitalize transition-all',
                      incidentType === t ? 'border-rose-500 bg-rose-500/10 text-rose-600' : 'border-border bg-card'
                    )}
                  >
                    {t.replace('_', ' ')}
                  </button>
                ))}
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="muster-title" className="text-xs font-semibold">Incident Title</Label>
              <Input
                id="muster-title"
                value={title}
                onChange={(e) => onTitleChange(e.target.value)}
                placeholder="e.g. Unit 14 Structural Fire, Hurricane Evacuation"
                className="h-9 text-xs"
                required
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="muster-assembly" className="text-xs font-semibold">Designated Assembly Point</Label>
              <Input
                id="muster-assembly"
                value={assemblyPoint}
                onChange={(e) => onAssemblyPointChange(e.target.value)}
                className="h-9 text-xs"
                required
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="muster-notes" className="text-xs font-semibold">Initial Notes &amp; Dispatch Instructions</Label>
              <Textarea
                id="muster-notes"
                value={notes}
                onChange={(e) => onNotesChange(e.target.value)}
                placeholder="Special instructions for emergency responders..."
                rows={2}
                className="text-xs resize-none"
              />
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0 pt-2 border-t">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button
              type="submit"
              size="sm"
              disabled={submitting}
              className="gap-2 bg-rose-600 hover:bg-rose-700 text-white font-bold"
            >
              <Siren className="w-4 h-4" />
              <span>{submitting ? 'Activating...' : 'Begin Emergency Muster'}</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export interface MusterNoteDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedRollCall: MusterRollCallItem | null;
  targetStatus: string;
  occupantNote: string;
  onOccupantNoteChange: (note: string) => void;
  onSaveStatus: (occupantId: string, status: string, notes?: string) => void;
}

export function MusterNoteDialog({
  open,
  onOpenChange,
  selectedRollCall,
  targetStatus,
  occupantNote,
  onOccupantNoteChange,
  onSaveStatus,
}: MusterNoteDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[420px]">
        <DialogHeader>
          <DialogTitle className="text-base font-bold">
            Mark {selectedRollCall?.name} as {targetStatus.replace('_', ' ').toUpperCase()}
          </DialogTitle>
          <DialogDescription className="text-xs">
            Record safety or evacuation details for first responders.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-2">
          <Textarea
            placeholder="e.g. Wheelchair assistance required, with family at Clubhouse..."
            value={occupantNote}
            onChange={(e) => onOccupantNoteChange(e.target.value)}
            rows={3}
            className="text-xs resize-none"
          />
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            type="button"
            size="sm"
            onClick={() => {
              if (selectedRollCall) {
                onSaveStatus(selectedRollCall.occupant_id, targetStatus, occupantNote);
              }
              onOpenChange(false);
            }}
            className="bg-primary text-primary-foreground font-semibold"
          >
            Save Status
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
