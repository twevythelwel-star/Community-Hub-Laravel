import React, { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { router, usePage } from '@inertiajs/react';
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
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { useToast } from '@/hooks/use-toast';
import { Switch } from '../ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';
import type { FundraiserRow } from './fundraiser-progress-card';
import { PaymentChannelPicker } from './payment-channel-picker';
import {
  channelLabel,
  channelSurcharge,
  totalWithSurcharge,
  type PaymentChannel,
} from '@/lib/payment-channels';
import {
  HeartHandshake,
  CheckCircle2,
  Download,
  Share2,
  Repeat,
  ShieldCheck,
  Clock,
} from 'lucide-react';
import { ShareCampaignDialog } from './share-campaign-dialog';

const formSchema = z
  .object({
    amount: z.coerce.number().min(1, 'Donation must be at least 1.'),
    donorName: z.string().optional(),
    isAnonymous: z.boolean().default(false),
    isRecurring: z.boolean().default(false),
    frequency: z.enum(['monthly', 'quarterly', 'annual']).default('monthly'),
  })
  .refine((data) => (data.isAnonymous ? true : !!data.donorName && data.donorName.length > 0), {
    message: 'Name is required for non-anonymous donations.',
    path: ['donorName'],
  });

type DonateFormValues = z.infer<typeof formSchema>;

type DonateFormProps = {
  children?: React.ReactNode;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  fundraiser: FundraiserRow;
  channels?: PaymentChannel[];
  initialAmount?: number;
  initialRecurring?: boolean;
};

export function DonateForm({
  children,
  open,
  onOpenChange,
  fundraiser,
  channels = [],
  initialAmount,
  initialRecurring,
}: DonateFormProps) {
  const { toast } = useToast();
  const [submitting, setSubmitting] = useState(false);
  const [channelKey, setChannelKey] = useState<string | null>(channels[0]?.key ?? null);
  const [completedDonation, setCompletedDonation] = useState<{
    amount: number;
    receiptUrl?: string;
    /** Office channels: the gift counts once the office confirms the money arrived. */
    awaitingConfirmation: boolean;
  } | null>(null);
  const [shareOpen, setShareOpen] = useState(false);

  const selectedChannel = channels.find((c) => c.key === channelKey) ?? null;

  // The server treats a missing channel as card, so this does too.
  const isCardGift = (channelKey ?? 'card') === 'card';
  // Shared by HandleInertiaRequests from ProviderRegistry: whether the estate
  // has a card processor configured, and which (Stripe, WiPay, ...).
  const paymentsProps = (usePage().props as { payments?: { cardCheckout?: boolean; cardProvider?: string | null } | null }).payments;
  const cardCheckout = Boolean(paymentsProps?.cardCheckout);
  const cardProvider = paymentsProps?.cardProvider ?? 'the card processor';
  const suggestedPills = fundraiser.suggestedAmounts ?? [1000, 2500, 5000, 10000];

  const form = useForm<DonateFormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      amount: initialAmount ?? suggestedPills[0] ?? 2500,
      donorName: '',
      isAnonymous: false,
      isRecurring: initialRecurring ?? false,
      frequency: 'monthly',
    },
  });

  const isAnonymous = form.watch('isAnonymous');
  const isRecurring = form.watch('isRecurring');
  const currentAmount = form.watch('amount');

  function onSubmit(values: DonateFormValues) {
    setSubmitting(true);

    router.post(
      `/dashboard/fundraising/${fundraiser.id}/donate`,
      {
        amount: values.amount,
        donor_name: values.isAnonymous ? null : values.donorName,
        is_anonymous: values.isAnonymous,
        is_recurring: values.isRecurring,
        frequency: values.isRecurring ? values.frequency : null,
        channel: channelKey,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          const awaitingConfirmation = Boolean(selectedChannel?.requires_confirmation);

          setCompletedDonation({
            amount: values.amount,
            receiptUrl: `/dashboard/fundraising`, // Receipt will be available on the card and profile
            awaitingConfirmation,
          });
          toast({
            title: awaitingConfirmation ? 'Gift submitted' : 'Contribution Recorded',
            description: awaitingConfirmation
              ? `Thank you! ${values.amount} ${fundraiser.currency} will count towards "${fundraiser.title}" once the office confirms it.`
              : `Thank you! ${values.amount} ${fundraiser.currency} recorded against "${fundraiser.title}".`,
          });
        },
        onError: (errors) => {
          toast({
            variant: 'destructive',
            title: 'Could not record your contribution',
            description: Object.values(errors)[0] ?? 'Please check the amount and try again.',
          });
          setSubmitting(false);
        },
        onFinish: () => setSubmitting(false),
      }
    );
  }

  const handleClose = (isOpen: boolean) => {
    onOpenChange(isOpen);
    if (!isOpen) {
      setTimeout(() => {
        setCompletedDonation(null);
        form.reset();
      }, 300);
    }
  };

  return (
    <>
      <Dialog open={open} onOpenChange={handleClose}>
        {children && <DialogTrigger asChild>{children}</DialogTrigger>}
        <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
          {completedDonation ? (
            /* Post-donation receipt and share state */
            <div className="py-6 text-center space-y-4">
              <div className="mx-auto w-12 h-12 rounded-full bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                <CheckCircle2 className="h-7 w-7" />
              </div>

              <div>
                <h3 className="text-xl font-bold tracking-tight">Thank You for Your Generosity!</h3>
                <p className="text-sm text-muted-foreground mt-1">
                  Your pledge of{' '}
                  <strong className="text-foreground">
                    {fundraiser.currency} {completedDonation.amount.toLocaleString()}
                  </strong>{' '}
                  to &ldquo;{fundraiser.title}&rdquo;{' '}
                  {completedDonation.awaitingConfirmation ? 'has been submitted.' : 'has been officially registered.'}
                </p>
              </div>

              {completedDonation.awaitingConfirmation ? (
                <div className="bg-amber-50 dark:bg-amber-950/30 p-4 rounded-lg border border-amber-200 dark:border-amber-800 text-left text-xs space-y-2">
                  <div className="flex items-center gap-2 text-amber-800 dark:text-amber-300 font-semibold">
                    <Clock className="h-4 w-4" />
                    <span>Awaiting office confirmation</span>
                  </div>
                  <p className="text-muted-foreground leading-relaxed">
                    Once the community office sees your payment arrive, your gift counts towards the campaign and
                    your receipt appears under &ldquo;My Contributions&rdquo;.
                  </p>
                </div>
              ) : (
                <div className="bg-slate-50 dark:bg-slate-900/50 p-4 rounded-lg border text-left text-xs space-y-2">
                  <div className="flex items-center gap-2 text-emerald-700 dark:text-emerald-400 font-semibold">
                    <ShieldCheck className="h-4 w-4" />
                    <span>Audited Community Record</span>
                  </div>
                  <p className="text-muted-foreground leading-relaxed">
                    Your contribution has been recorded in the Master Ledger. An official PDF receipt is available to download below or anytime under &ldquo;My Contributions&rdquo;.
                  </p>
                </div>
              )}

              <div className="flex flex-col sm:flex-row gap-2 pt-2">
                <Button
                  type="button"
                  variant="outline"
                  className="w-full gap-2"
                  onClick={() => {
                    handleClose(false);
                    setShareOpen(true);
                  }}
                >
                  <Share2 className="h-4 w-4" />
                  Share Campaign
                </Button>

                <Button
                  type="button"
                  className="w-full"
                  onClick={() => handleClose(false)}
                >
                  Done
                </Button>
              </div>
            </div>
          ) : (
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                <DialogHeader>
                  <div className="flex items-center gap-2">
                    <div className="p-2 rounded-full bg-primary/10 text-primary">
                      <HeartHandshake className="h-5 w-5" />
                    </div>
                    <div>
                      <DialogTitle>Support {fundraiser.title}</DialogTitle>
                      <DialogDescription>
                        Target: {fundraiser.currency} {fundraiser.goal.toLocaleString()} &bull;{' '}
                        {fundraiser.progress}% funded
                      </DialogDescription>
                    </div>
                  </div>
                </DialogHeader>

                {/* Quick Amount Select Pills */}
                <div className="space-y-2">
                  <FormLabel className="text-xs font-semibold text-muted-foreground">
                    Select Contribution Amount ({fundraiser.currency})
                  </FormLabel>
                  <div className="grid grid-cols-4 gap-2">
                    {suggestedPills.map((pill) => (
                      <Button
                        key={pill}
                        type="button"
                        variant={currentAmount === pill ? 'default' : 'outline'}
                        size="sm"
                        className="h-9 font-semibold text-xs"
                        onClick={() => form.setValue('amount', pill, { shouldValidate: true })}
                      >
                        ${pill.toLocaleString()}
                      </Button>
                    ))}
                  </div>
                </div>

                {/* Custom Amount Input */}
                <FormField
                  control={form.control}
                  name="amount"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Custom Amount ({fundraiser.currency})</FormLabel>
                      <FormControl>
                        <Input
                          type="number"
                          min="1"
                          step="0.01"
                          placeholder="Or enter custom amount..."
                          {...field}
                        />
                      </FormControl>
                      <FormDescription className="text-[11px]">
                        Processed in {fundraiser.currency}, the designated campaign currency.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                {/* Recurring Donation Switch & Options */}
                {fundraiser.allowRecurring && (
                  <div className="rounded-lg border p-3 bg-slate-50/50 dark:bg-slate-900/20 space-y-3">
                    <FormField
                      control={form.control}
                      name="isRecurring"
                      render={({ field }) => (
                        <FormItem className="flex flex-row items-center justify-between">
                          <div className="space-y-0.5">
                            <div className="flex items-center gap-1.5 font-medium text-sm">
                              <Repeat className="h-4 w-4 text-primary" />
                              <span>Repeat this donation</span>
                            </div>
                            <FormDescription className="text-xs">
                              Make this a recurring monthly or scheduled contribution.
                            </FormDescription>
                          </div>
                          <FormControl>
                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                          </FormControl>
                        </FormItem>
                      )}
                    />

                    {isRecurring && (
                      <FormField
                        control={form.control}
                        name="frequency"
                        render={({ field }) => (
                          <FormItem className="pt-1">
                            <FormLabel className="text-xs font-semibold">Billing Frequency</FormLabel>
                            <Select onValueChange={field.onChange} defaultValue={field.value}>
                              <FormControl>
                                <SelectTrigger className="h-8 text-xs bg-background">
                                  <SelectValue placeholder="Select frequency" />
                                </SelectTrigger>
                              </FormControl>
                              <SelectContent>
                                <SelectItem value="monthly">Monthly Repeat</SelectItem>
                                <SelectItem value="quarterly">Quarterly Repeat</SelectItem>
                                <SelectItem value="annual">Annual Repeat</SelectItem>
                              </SelectContent>
                            </Select>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    )}
                  </div>
                )}

                {/* Payment Channel Picker */}
                {channels.length > 0 && (
                  <div className="space-y-2">
                    <PaymentChannelPicker
                      channels={channels}
                      value={channelKey}
                      onChange={setChannelKey}
                      legend="Payment / Settlement Method"
                      disabled={submitting}
                    />

                    {selectedChannel && channelSurcharge(selectedChannel) > 0 && (
                      <p className="rounded-md bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        {channelLabel(selectedChannel)} adds {channelSurcharge(selectedChannel)}%.
                        A {form.watch('amount') || 0} {fundraiser.currency} gift is recorded as{' '}
                        {totalWithSurcharge(Number(form.watch('amount')) || 0, selectedChannel)}{' '}
                        {fundraiser.currency}.
                      </p>
                    )}
                  </div>
                )}

                {isCardGift && (
                  <p className="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
                    {cardCheckout
                      ? `You'll finish on ${cardProvider}'s secure payment page. Your gift is recorded, with a receipt, once ${cardProvider} confirms it.`
                      : 'Card donations are not available yet. Please choose another method.'}
                  </p>
                )}

                {/* Anonymous Donation Switch */}
                {fundraiser.allowAnonymous && (
                  <FormField
                    control={form.control}
                    name="isAnonymous"
                    render={({ field }) => (
                      <FormItem className="flex flex-row items-center justify-between rounded-lg border p-3">
                        <div className="space-y-0.5">
                          <FormLabel className="text-sm font-medium">Donate Anonymously</FormLabel>
                          <FormDescription className="text-xs">
                            Your name is hidden from fellow residents on the public donor feed.
                          </FormDescription>
                        </div>
                        <FormControl>
                          <Switch checked={field.value} onCheckedChange={field.onChange} />
                        </FormControl>
                      </FormItem>
                    )}
                  />
                )}

                {!isAnonymous && (
                  <FormField
                    control={form.control}
                    name="donorName"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Public Display Name</FormLabel>
                        <FormControl>
                          <Input placeholder="e.g. The Morrison Family" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                )}

                <DialogFooter className="pt-2">
                  <Button type="button" variant="outline" onClick={() => handleClose(false)}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={submitting || (isCardGift && !cardCheckout)} className="gap-2">
                    <HeartHandshake className="h-4 w-4" />
                    {submitting
                      ? isCardGift ? 'Opening checkout...' : 'Recording...'
                      : `${isCardGift ? 'Continue to checkout' : 'Contribute'} ${fundraiser.currency} ${Number(currentAmount || 0).toLocaleString()}`}
                  </Button>
                </DialogFooter>
              </form>
            </Form>
          )}
        </DialogContent>
      </Dialog>

      {/* Share campaign dialog fallback */}
      <ShareCampaignDialog
        fundraiser={fundraiser}
        open={shareOpen}
        onOpenChange={setShareOpen}
      />
    </>
  );
}
