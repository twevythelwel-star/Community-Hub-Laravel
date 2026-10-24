import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useToast } from '@/hooks/use-toast';
import { Check, Copy, Share2, MessageCircle, Mail, Twitter } from 'lucide-react';
import type { FundraiserRow } from './fundraiser-progress-card';
import { Progress } from '@/components/ui/progress';

type ShareCampaignDialogProps = {
  fundraiser: FundraiserRow;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function ShareCampaignDialog({
  fundraiser,
  open,
  onOpenChange,
}: ShareCampaignDialogProps) {
  const { toast } = useToast();
  const [copied, setCopied] = useState(false);

  // Construct URL to this fundraiser
  const campaignUrl = typeof window !== 'undefined'
    ? `${window.location.origin}/dashboard/fundraising#campaign-${fundraiser.id}`
    : `https://communityhub.estate/dashboard/fundraising#campaign-${fundraiser.id}`;

  const shareText = `Support "${fundraiser.title}" on our Community Hub! Goal: ${fundraiser.currency} ${fundraiser.goal.toLocaleString()} (${fundraiser.progress}% reached so far).`;

  const copyToClipboard = async () => {
    try {
      await navigator.clipboard.writeText(campaignUrl);
      setCopied(true);
      toast({
        title: 'Link copied to clipboard!',
        description: 'You can now paste and share this campaign link anywhere.',
      });
      setTimeout(() => setCopied(false), 3000);
    } catch {
      toast({
        variant: 'destructive',
        title: 'Could not copy link',
        description: 'Please copy the link directly from the box below.',
      });
    }
  };

  const handleNativeShare = async () => {
    if (navigator.share) {
      try {
        await navigator.share({
          title: fundraiser.title,
          text: shareText,
          url: campaignUrl,
        });
      } catch (err) {
        if ((err as Error).name !== 'AbortError') {
          copyToClipboard();
        }
      }
    } else {
      copyToClipboard();
    }
  };

  const whatsappUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(`${shareText}\n${campaignUrl}`)}`;
  const emailUrl = `mailto:?subject=${encodeURIComponent(`Support: ${fundraiser.title}`)}&body=${encodeURIComponent(`${shareText}\n\nJoin us and donate at:\n${campaignUrl}`)}`;
  const twitterUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText)}&url=${encodeURIComponent(campaignUrl)}`;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-full bg-primary/10 text-primary">
              <Share2 className="h-5 w-5" />
            </div>
            <div>
              <DialogTitle>Share Campaign</DialogTitle>
              <DialogDescription>
                Spread the word to fellow residents and neighbours.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {/* Campaign Preview Card */}
        <div className="rounded-lg border bg-slate-50/70 dark:bg-slate-900/40 p-4 space-y-3">
          <div className="flex items-start justify-between gap-2">
            <div>
              <h4 className="font-semibold text-sm text-foreground">{fundraiser.title}</h4>
              {fundraiser.beneficiary && (
                <p className="text-xs text-muted-foreground">Beneficiary: {fundraiser.beneficiary}</p>
              )}
            </div>
            <span className="text-xs font-bold px-2 py-0.5 rounded bg-primary/10 text-primary">
              {fundraiser.progress}%
            </span>
          </div>

          <Progress value={fundraiser.progress} className="h-2" />

          <div className="flex justify-between items-center text-xs text-muted-foreground font-medium">
            <span>Raised: {fundraiser.currency} {fundraiser.raised.toLocaleString()}</span>
            <span>Goal: {fundraiser.currency} {fundraiser.goal.toLocaleString()}</span>
          </div>
        </div>

        {/* Share Link Input */}
        <div className="space-y-2">
          <label className="text-xs font-semibold text-muted-foreground">Campaign Link</label>
          <div className="flex items-center gap-2">
            <Input
              value={campaignUrl}
              readOnly
              className="font-mono text-xs select-all bg-muted/30"
              onClick={(e) => (e.target as HTMLInputElement).select()}
            />
            <Button
              type="button"
              variant={copied ? 'default' : 'outline'}
              size="sm"
              className="gap-1 shrink-0"
              onClick={copyToClipboard}
            >
              {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
              {copied ? 'Copied' : 'Copy'}
            </Button>
          </div>
        </div>

        {/* Quick Social Share Buttons */}
        <div className="space-y-2 pt-1">
          <label className="text-xs font-semibold text-muted-foreground">Share Directly</label>
          <div className="grid grid-cols-3 gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="w-full gap-2 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/50"
              onClick={() => window.open(whatsappUrl, '_blank')}
            >
              <MessageCircle className="h-4 w-4" />
              WhatsApp
            </Button>

            <Button
              type="button"
              variant="outline"
              size="sm"
              className="w-full gap-2 border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/50"
              onClick={() => window.open(emailUrl, '_blank')}
            >
              <Mail className="h-4 w-4" />
              Email
            </Button>

            <Button
              type="button"
              variant="outline"
              size="sm"
              className="w-full gap-2 border-sky-200 dark:border-sky-800 text-sky-700 dark:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-950/50"
              onClick={() => window.open(twitterUrl, '_blank')}
            >
              <Twitter className="h-4 w-4" />
              X / Post
            </Button>
          </div>

          {typeof navigator !== 'undefined' && 'share' in navigator && (
            <Button
              type="button"
              variant="default"
              className="w-full mt-2 gap-2"
              onClick={handleNativeShare}
            >
              <Share2 className="h-4 w-4" />
              Share via System Dialog
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
