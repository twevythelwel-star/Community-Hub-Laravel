import { useState } from 'react';
import { router } from '@inertiajs/react';
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ThumbsUp, ThumbsDown, UserCircle, Trash2 } from 'lucide-react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { ClientFormattedDistanceToNow } from '../client-formatted-date';
import { useToast } from '@/hooks/use-toast';

/**
 * The alert feed.
 *
 * `handleVote` used to mutate local React state: the tally went up on screen,
 * nothing was sent anywhere, and a reload restored the mock numbers. Votes now
 * post to `dashboard.warnings.respond`, which keeps one row per user per
 * warning behind a unique index.
 */

export type WarningRow = {
  id: number;
  title: string;
  description: string;
  author: string;
  timestamp: string;
  confirms: number;
  denies: number;
  userStatus: 'confirmed' | 'denied' | null;
  isMine: boolean;
};

type WarningListProps = {
  warnings: WarningRow[];
  canRemove: boolean;
};

export function WarningList({ warnings, canRemove }: WarningListProps) {
  const { toast } = useToast();
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [toRemove, setToRemove] = useState<WarningRow | null>(null);

  const vote = (warning: WarningRow, response: 'confirmed' | 'denied') => {
    /*
     * Clicking the vote you already hold clears nothing — the server has no
     * "retract" — so it is a no-op rather than a wasted round trip.
     */
    if (warning.userStatus === response) {
      return;
    }

    setPendingId(warning.id);

    router.post(
      `/dashboard/warnings/${warning.id}/respond`,
      { response },
      {
        preserveScroll: true,
        onFinish: () => setPendingId(null),
      },
    );
  };

  const remove = (warning: WarningRow) => {
    router.delete(`/dashboard/warnings/${warning.id}`, {
      preserveScroll: true,
      onSuccess: () => {
        setToRemove(null);
        toast({
          title: 'Alert removed',
          description: `"${warning.title}" is no longer visible to the community.`,
        });
      },
    });
  };

  if (warnings.length === 0) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-muted-foreground">
          <p className="text-sm">No active safety alerts. That is good news.</p>
        </CardContent>
      </Card>
    );
  }

  return (
    <>
      <div className="space-y-4">
        {warnings.map((warning) => {
          const busy = pendingId === warning.id;

          return (
            <Card key={warning.id} className="shadow-sm hover:shadow-md transition-shadow">
              <CardHeader>
                <div className="flex items-start justify-between gap-2">
                  <CardTitle>{warning.title}</CardTitle>
                  {warning.isMine && <Badge variant="outline">Your alert</Badge>}
                </div>
                <CardDescription className="flex items-center gap-2 pt-1 text-xs">
                  <UserCircle className="h-4 w-4" />
                  <span>{warning.author}</span> &middot;
                  <ClientFormattedDistanceToNow date={warning.timestamp} />
                </CardDescription>
              </CardHeader>

              <CardContent>
                <p className="text-sm whitespace-pre-line">{warning.description}</p>
              </CardContent>

              <CardFooter className="flex flex-wrap gap-4 justify-between items-center bg-muted/50 py-3 px-6">
                <div className="flex items-center gap-4 text-sm text-muted-foreground">
                  <div className="flex items-center gap-1.5">
                    <ThumbsUp className="h-4 w-4 text-green-600" />
                    <span className="font-medium text-foreground">{warning.confirms}</span>
                    <span className="hidden sm:inline">Confirmed</span>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <ThumbsDown className="h-4 w-4 text-red-600" />
                    <span className="font-medium text-foreground">{warning.denies}</span>
                    <span className="hidden sm:inline">Denied</span>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {/*
                    The original disabled both buttons permanently after one
                    vote, which contradicted the server: `respond` uses
                    updateOrCreate precisely so a vote can be changed. Only the
                    author is locked out, and only from their own alert.
                  */}
                  {warning.isMine ? (
                    <span className="text-xs text-muted-foreground">
                      You raised this alert
                    </span>
                  ) : (
                    <>
                      <Button
                        variant={warning.userStatus === 'confirmed' ? 'secondary' : 'outline'}
                        size="sm"
                        onClick={() => vote(warning, 'confirmed')}
                        disabled={busy}
                        aria-pressed={warning.userStatus === 'confirmed'}
                        className="bg-green-50 hover:bg-green-100 text-green-800 border-green-200"
                      >
                        <ThumbsUp className="mr-2 h-4 w-4" />
                        Confirm
                      </Button>
                      <Button
                        variant={warning.userStatus === 'denied' ? 'secondary' : 'outline'}
                        size="sm"
                        onClick={() => vote(warning, 'denied')}
                        disabled={busy}
                        aria-pressed={warning.userStatus === 'denied'}
                        className="bg-red-50 hover:bg-red-100 text-red-800 border-red-200"
                      >
                        <ThumbsDown className="mr-2 h-4 w-4" />
                        Deny
                      </Button>
                    </>
                  )}

                  {canRemove && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setToRemove(warning)}
                      aria-label={`Remove alert: ${warning.title}`}
                    >
                      <Trash2 className="h-4 w-4 text-muted-foreground" />
                    </Button>
                  )}
                </div>
              </CardFooter>
            </Card>
          );
        })}
      </div>

      <AlertDialog open={toRemove !== null} onOpenChange={(open) => !open && setToRemove(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove this alert?</AlertDialogTitle>
            <AlertDialogDescription>
              &ldquo;{toRemove?.title}&rdquo; will stop being visible to the community, along with
              its confirmations. Use this for false alarms and duplicates.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => toRemove && remove(toRemove)}>
              Remove alert
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
