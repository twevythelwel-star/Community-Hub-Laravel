import React, { useState } from 'react';
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
import { Progress } from '@/components/ui/progress';
import { Badge } from '@/components/ui/badge';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import {
  HeartHandshake,
  Sparkles,
  Download,
  Building,
  Layers,
  FileCheck2,
  Share2,
  Newspaper,
  Image as ImageIcon,
  Repeat,
} from 'lucide-react';
import { DonateForm } from './donate-form';
import { ShareCampaignDialog } from './share-campaign-dialog';
import { AddFundraiserUpdateDialog } from './add-fundraiser-update-dialog';
import type { PaymentChannel } from '@/lib/payment-channels';
import { useToast } from '@/hooks/use-toast';

export type FundraiserRow = {
  id: number;
  title: string;
  description: string;
  goal: number;
  raised: number;
  progress: number;
  donorCount: number;
  currency: string;
  startDate: string;
  endDate: string;
  status: 'Active' | 'Completed' | 'Upcoming' | 'Canceled';
  isOpen?: boolean;
  beneficiary?: string | null;
  coverImageUrl?: string | null;
  images?: string[];
  refunded?: number;
  allowAnonymous?: boolean;
  allowRecurring?: boolean;
  suggestedAmounts?: number[];
  matchingSponsor?: string | null;
  fundAllocation?: Record<string, number> | null;
  updates?: {
    id: number;
    title: string;
    content: string;
    imageUrl?: string | null;
    date: string;
  }[];
  recentDonations?: {
    id?: number;
    donorName: string;
    amount: number;
    currency: string;
    timestamp: string;
    status?: string;
    isRecurring?: boolean;
    receiptNumber?: string;
    receiptUrl?: string;
  }[];
};

type FundraiserProgressCardProps = {
  fundraiser: FundraiserRow;
  canManage?: boolean;
  /** The estate's enabled payment methods, passed through to the donate form. */
  channels?: PaymentChannel[];
};

const JMD_EQUIVALENTS = {
  USD: 0.0064,
  GBP: 0.0051,
  EUR: 0.006,
  CAD: 0.0088,
} as const;

const LOCALES: Record<string, string> = {
  USD: 'en-US',
  GBP: 'en-GB',
  EUR: 'de-DE',
  CAD: 'en-CA',
  JMD: 'en-JM',
};

function money(amount: number, currency: string): string {
  return amount.toLocaleString(LOCALES[currency] ?? undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  });
}

function statusVariant(status: FundraiserRow['status']) {
  switch (status) {
    case 'Active':
      return 'default' as const;
    case 'Completed':
      return 'secondary' as const;
    case 'Upcoming':
      return 'outline' as const;
    default:
      return 'destructive' as const;
  }
}

export function FundraiserProgressCard({
  fundraiser,
  canManage,
  channels = [],
}: FundraiserProgressCardProps) {
  const { toast } = useToast();
  const [isDonateOpen, setDonateOpen] = useState(false);
  const [isShareOpen, setShareOpen] = useState(false);
  const [isAddUpdateOpen, setAddUpdateOpen] = useState(false);
  const [showUpdates, setShowUpdates] = useState(false);
  const [enabling, setEnabling] = useState(false);

  const reachedGoal = fundraiser.progress >= 100;

  const enable = () => {
    setEnabling(true);

    router.patch(
      `/dashboard/fundraising/${fundraiser.id}`,
      { status: 'Active' },
      {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            title: 'Fundraiser opened',
            description: `"${fundraiser.title}" is now accepting donations.`,
          }),
        onFinish: () => setEnabling(false),
      }
    );
  };

  return (
    <>
      <Card
        id={`campaign-${fundraiser.id}`}
        className="flex flex-col h-full overflow-hidden border-slate-200 dark:border-slate-800 shadow-sm hover:shadow-md transition-shadow"
      >
        {/* Cover / Hero Image if provided */}
        {fundraiser.coverImageUrl && (
          <div className="relative h-44 w-full overflow-hidden bg-slate-100 dark:bg-slate-900 border-b">
            <img
              src={fundraiser.coverImageUrl}
              alt={fundraiser.title}
              className="h-full w-full object-cover transition-transform duration-300 hover:scale-105"
              onError={(e) => {
                // Hide if broken
                (e.target as HTMLElement).style.display = 'none';
              }}
            />
            {fundraiser.images && fundraiser.images.length > 0 && (
              <span className="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded bg-black/60 px-2 py-0.5 text-[10px] font-medium text-white backdrop-blur-sm">
                <ImageIcon className="h-3 w-3" />
                {fundraiser.images.length + 1} photos
              </span>
            )}
          </div>
        )}

        <CardHeader>
          <div className="flex justify-between items-start gap-2">
            <div>
              <CardTitle className="text-xl font-bold tracking-tight">
                {fundraiser.title}
              </CardTitle>
              {fundraiser.beneficiary && (
                <p className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                  <Building className="h-3 w-3" /> Beneficiary: {fundraiser.beneficiary}
                </p>
              )}
            </div>
            <Badge variant={statusVariant(fundraiser.status)}>
              {reachedGoal && fundraiser.status !== 'Upcoming' ? 'Goal Reached!' : fundraiser.status}
            </Badge>
          </div>

          {/* Corporate Matching Sponsor Banner */}
          {fundraiser.matchingSponsor && (
            <div className="mt-2 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-[11px] font-semibold text-emerald-800 dark:text-emerald-300">
              <Sparkles className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
              <span>1:1 Matching Gift by {fundraiser.matchingSponsor}</span>
            </div>
          )}

          <CardDescription className="mt-2 text-sm text-slate-600 dark:text-slate-400 leading-relaxed line-clamp-3">
            {fundraiser.description}
          </CardDescription>
        </CardHeader>

        <CardContent className="flex-grow space-y-4">
          <div>
            <div className="flex items-baseline justify-between mb-2">
              <span className="text-2xl font-bold tracking-tight text-primary">
                {money(fundraiser.raised, fundraiser.currency)}
              </span>
              <span className="text-sm font-medium text-muted-foreground">
                Goal: {money(fundraiser.goal, fundraiser.currency)}
              </span>
            </div>

            <Progress value={fundraiser.progress} className="h-2.5 bg-slate-100 dark:bg-slate-800" />

            <div className="flex justify-between items-center mt-2 gap-2">
              {fundraiser.currency === 'JMD' ? (
                <p className="text-xs text-muted-foreground">
                  Goal is roughly{' '}
                  {Object.entries(JMD_EQUIVALENTS)
                    .map(([code, rate]) => money(fundraiser.goal * rate, code))
                    .join(' / ')}
                </p>
              ) : (
                <span />
              )}
              <p className="text-xs text-muted-foreground font-semibold whitespace-nowrap">
                {fundraiser.progress}% &bull; {fundraiser.donorCount}{' '}
                {fundraiser.donorCount === 1 ? 'supporter' : 'supporters'}
              </p>
            </div>
          </div>

          {/* Fund Allocation Pill Breakdown */}
          {fundraiser.fundAllocation && Object.keys(fundraiser.fundAllocation).length > 0 && (
            <div className="border-t border-slate-100 dark:border-slate-800/80 pt-3 space-y-1.5">
              <div className="flex items-center gap-1 text-xs font-semibold text-muted-foreground">
                <Layers className="h-3 w-3" /> Budget Allocation
              </div>
              <div className="flex flex-wrap gap-1.5">
                {Object.entries(fundraiser.fundAllocation).map(([cat, pct]) => (
                  <span
                    key={cat}
                    className="inline-flex items-center text-[10px] bg-slate-100 dark:bg-slate-800/80 px-2 py-0.5 rounded text-foreground font-medium"
                  >
                    {cat}: <strong className="ml-1 text-primary">{pct}%</strong>
                  </span>
                ))}
              </div>
            </div>
          )}

          {/* Recent Supporters */}
          {fundraiser.recentDonations && fundraiser.recentDonations.length > 0 && (
            <div className="space-y-2 border-t border-slate-100 dark:border-slate-800/80 pt-3">
              <div className="flex items-center justify-between">
                <p className="text-xs font-semibold text-muted-foreground">Recent Supporters</p>
                <span className="text-[10px] text-emerald-600 dark:text-emerald-400 flex items-center gap-0.5">
                  <FileCheck2 className="h-3 w-3" /> Audited receipts
                </span>
              </div>

              <div className="space-y-1.5">
                {fundraiser.recentDonations.slice(0, 4).map((donation, index) => (
                  <div
                    key={index}
                    className="flex items-center justify-between text-xs p-1.5 rounded bg-slate-50/60 dark:bg-slate-900/30"
                  >
                    <div className="flex items-center gap-1.5 truncate">
                      <span className="truncate font-medium text-foreground">{donation.donorName}</span>
                      {donation.isRecurring && (
                        <span className="inline-flex items-center gap-0.5 text-[9px] font-bold uppercase text-primary bg-primary/10 px-1 py-0.2 rounded">
                          <Repeat className="h-2.5 w-2.5" /> Repeat
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <span className="tabular-nums font-bold text-foreground">
                        {money(donation.amount, donation.currency)}
                      </span>
                      {donation.receiptUrl && (
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-6 w-6 text-primary hover:text-primary/80"
                          title="Download donation receipt (PDF)"
                          onClick={() => window.open(donation.receiptUrl, '_blank')}
                        >
                          <Download className="h-3 w-3" />
                        </Button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Campaign Updates Feed */}
          {fundraiser.updates && fundraiser.updates.length > 0 && (
            <div className="border-t border-slate-100 dark:border-slate-800/80 pt-3">
              <div className="flex items-center justify-between">
                <p className="text-xs font-semibold text-muted-foreground flex items-center gap-1">
                  <Newspaper className="h-3.5 w-3.5" /> Project Updates ({fundraiser.updates.length})
                </p>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-6 text-[11px] text-primary"
                  onClick={() => setShowUpdates(!showUpdates)}
                >
                  {showUpdates ? 'Hide' : 'View Updates'}
                </Button>
              </div>

              {showUpdates && (
                <div className="mt-2 space-y-2 max-h-48 overflow-y-auto pr-1">
                  {fundraiser.updates.map((update) => (
                    <div
                      key={update.id}
                      className="p-2.5 rounded-lg border bg-slate-50/50 dark:bg-slate-900/40 text-xs space-y-1"
                    >
                      <div className="flex justify-between items-start gap-2">
                        <strong className="font-semibold text-foreground">{update.title}</strong>
                        <span className="text-[10px] text-muted-foreground whitespace-nowrap">
                          {update.date}
                        </span>
                      </div>
                      <p className="text-slate-600 dark:text-slate-400 leading-relaxed text-[11px]">
                        {update.content}
                      </p>
                      {update.imageUrl && (
                        <img
                          src={update.imageUrl}
                          alt={update.title}
                          className="mt-1 h-24 w-full object-cover rounded border"
                          onError={(e) => {
                            (e.target as HTMLElement).style.display = 'none';
                          }}
                        />
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </CardContent>

        <CardFooter className="flex flex-wrap justify-between items-center gap-2 bg-muted/40 py-3 px-6 mt-auto border-t border-slate-100 dark:border-slate-800">
          <div className="text-xs text-muted-foreground">
            {fundraiser.status === 'Upcoming' && (
              <span>
                Starts: <ClientFormattedDate date={fundraiser.startDate} formatString="MMM d, yyyy" />
              </span>
            )}
            {fundraiser.status !== 'Upcoming' && (
              <span>
                {fundraiser.status === 'Active' ? 'Ends' : 'Ended'}:{' '}
                <ClientFormattedDate date={fundraiser.endDate} formatString="MMM d, yyyy" />
              </span>
            )}
          </div>

          <div className="flex items-center gap-2">
            {/* Share Campaign Button */}
            <Button
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={() => setShareOpen(true)}
              title="Share campaign with residents"
            >
              <Share2 className="h-3.5 w-3.5" />
              Share
            </Button>

            {/* Admin Add Update Button */}
            {canManage && (
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => setAddUpdateOpen(true)}
                title="Post milestone update"
              >
                <Newspaper className="h-3.5 w-3.5" />
                Update
              </Button>
            )}

            {fundraiser.isOpen && (
              <Button
                className="shadow-sm gap-2"
                onClick={() => setDonateOpen(true)}
              >
                <HeartHandshake className="h-4 w-4" />
                Donate Now
              </Button>
            )}

            {fundraiser.status === 'Upcoming' && canManage && (
              <Button variant="outline" size="sm" disabled={enabling} onClick={enable}>
                {enabling ? 'Opening…' : 'Enable Now'}
              </Button>
            )}
          </div>
        </CardFooter>
      </Card>

      {/* Donate Modal */}
      {fundraiser.isOpen && (
        <DonateForm
          open={isDonateOpen}
          onOpenChange={setDonateOpen}
          fundraiser={fundraiser}
          channels={channels}
        />
      )}

      {/* Share Modal */}
      <ShareCampaignDialog
        fundraiser={fundraiser}
        open={isShareOpen}
        onOpenChange={setShareOpen}
      />

      {/* Add Update Modal */}
      {canManage && (
        <AddFundraiserUpdateDialog
          fundraiser={fundraiser}
          open={isAddUpdateOpen}
          onOpenChange={setAddUpdateOpen}
        />
      )}
    </>
  );
}
