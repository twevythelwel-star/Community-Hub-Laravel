import React from 'react';
import { format, parseISO } from 'date-fns';
import { Share2, FileDown, Copy, MessageSquare } from 'lucide-react';
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
import { VisitorRow } from './visitor-types';

interface VisitorSharePassDialogProps {
  visitor: VisitorRow | null;
  onClose: () => void;
  onCopyLink: (visitor: VisitorRow) => void;
  onShareWhatsApp: (visitor: VisitorRow) => void;
  onShareSms: (visitor: VisitorRow) => void;
}

export function VisitorSharePassDialog({
  visitor,
  onClose,
  onCopyLink,
  onShareWhatsApp,
  onShareSms,
}: VisitorSharePassDialogProps) {
  if (!visitor) return null;

  return (
    <Dialog open={!!visitor} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-lg font-bold">
            <Share2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
            Share Digital Guest Pass
          </DialogTitle>
          <DialogDescription>
            Send secure gate entry credentials to <strong>{visitor.name}</strong>.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="rounded-xl border border-border/80 bg-muted/40 p-4 space-y-2.5">
            <div className="flex items-center justify-between text-xs">
              <span className="text-muted-foreground">Visitor</span>
              <span className="font-semibold text-foreground">{visitor.name}</span>
            </div>
            <div className="flex items-center justify-between text-xs">
              <span className="text-muted-foreground">Property Destination</span>
              <span className="font-mono font-medium text-foreground">
                {visitor.hostLot || visitor.homeowner || '—'}
              </span>
            </div>
            {visitor.vehicle && (
              <div className="flex items-center justify-between text-xs">
                <span className="text-muted-foreground">Vehicle Plate</span>
                <span className="font-mono font-medium text-foreground">{visitor.vehicle}</span>
              </div>
            )}
            <div className="flex items-center justify-between text-xs">
              <span className="text-muted-foreground">Arrival Window</span>
              <span className="font-mono font-medium text-primary">
                {visitor.arrivalWindow ||
                  visitor.timeDisplay ||
                  (visitor.expectedAt ? format(parseISO(visitor.expectedAt), 'PPp') : 'Pending')}
              </span>
            </div>
            {visitor.parkingInstructions && (
              <div className="pt-1 border-t border-border/50 text-xs">
                <span className="text-muted-foreground block text-[11px] font-semibold mb-0.5">
                  Parking Instructions:
                </span>
                <p className="text-foreground text-[11px] leading-relaxed bg-background/60 p-2 rounded-lg border border-border/50 font-mono">
                  {visitor.parkingInstructions}
                </p>
              </div>
            )}
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label className="text-xs text-muted-foreground">Digital Guest Pass URL</Label>
              <a
                href={
                  visitor.guestPassUrl ||
                  (visitor.shareToken ? `/guest/pass/${visitor.shareToken}` : '#')
                }
                target="_blank"
                rel="noopener noreferrer"
                className="text-[11px] text-primary hover:underline font-medium inline-flex items-center gap-1"
              >
                <span>Preview Pass</span>
                <span>↗</span>
              </a>
            </div>
            <div className="flex items-center gap-2">
              <Input
                readOnly
                value={
                  visitor.guestPassUrl ||
                  `${window.location.origin}/guest/pass/${visitor.shareToken}`
                }
                className="font-mono text-xs select-all bg-background"
              />
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="shrink-0 gap-1.5"
                onClick={() => onCopyLink(visitor)}
              >
                <Copy className="h-3.5 w-3.5" />
                Copy
              </Button>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 pt-1">
            <Button
              type="button"
              className="w-full bg-[#25D366] hover:bg-[#20bd5a] text-white font-semibold gap-2 shadow-xs"
              onClick={() => onShareWhatsApp(visitor)}
            >
              <Share2 className="h-4 w-4" />
              WhatsApp
            </Button>
            <Button
              type="button"
              variant="outline"
              className="w-full font-semibold gap-2 border-blue-500/40 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30"
              onClick={() => onShareSms(visitor)}
            >
              <MessageSquare className="h-4 w-4 text-blue-600 dark:text-blue-400" />
              SMS Text
            </Button>
          </div>

          {visitor.shareToken && (
            <div className="pt-1 text-center">
              <a
                href={`/guest/pass/${visitor.shareToken}/pdf`}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors underline-offset-4 hover:underline"
              >
                <FileDown className="h-3.5 w-3.5" />
                Download Printable PDF Guest Pass
              </a>
            </div>
          )}
        </div>

        <DialogFooter className="sm:justify-end">
          <Button variant="ghost" onClick={onClose}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
