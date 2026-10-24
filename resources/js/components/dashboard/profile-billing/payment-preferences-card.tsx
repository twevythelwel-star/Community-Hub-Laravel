import React from 'react';
import { 
  CreditCard, 
  Landmark, 
  CheckCircle2, 
  ShieldCheck, 
  Info, 
  Check, 
  RefreshCw, 
  Settings2, 
  Bell 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

interface NotificationPreferences {
  payment_confirmation: boolean;
  receipt: boolean;
  failed_payment: boolean;
  refund: boolean;
}

interface PaymentPreferencesCardProps {
  preferredPayment: string;
  defaultPaymentMethod: string;
  notifications: NotificationPreferences;
  isSavingPrefs: boolean;
  onPreferredPaymentChange: (value: string) => void;
  onNotificationsChange: React.Dispatch<React.SetStateAction<NotificationPreferences>>;
  onSavePreferences: () => void;
}

export function PaymentPreferencesCard({
  preferredPayment,
  defaultPaymentMethod,
  notifications,
  isSavingPrefs,
  onPreferredPaymentChange,
  onNotificationsChange,
  onSavePreferences,
}: PaymentPreferencesCardProps) {
  return (
    <Card className="border-border/80 shadow-md overflow-hidden">
      <CardHeader className="border-b border-border/40 pb-4 bg-muted/15">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <div>
            <CardTitle className="text-xl font-bold font-headline flex items-center gap-2">
              <Settings2 className="w-5 h-5 text-primary" />
              PAYMENT PREFERENCES
            </CardTitle>
            <CardDescription className="text-xs">
              Configure your preferred checkout rails, default payment method, and automated transaction notifications.
            </CardDescription>
          </div>
          <Badge variant="outline" className="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 border-emerald-500/30 w-fit flex items-center gap-1">
            <ShieldCheck className="w-3.5 h-3.5" />
            Non-Custodial Architecture
          </Badge>
        </div>
      </CardHeader>

      <CardContent className="space-y-6 pt-6">
        {/* Preferred payment selection */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <Label className="text-xs font-bold tracking-wider uppercase text-muted-foreground">
              Preferred payment
            </Label>
            <span className="text-[11px] text-muted-foreground flex items-center gap-1">
              <Info className="w-3.5 h-3.5" /> Select your primary checkout preference
            </span>
          </div>

          <RadioGroup
            value={preferredPayment}
            onValueChange={onPreferredPaymentChange}
            className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3"
          >
            {/* Apple Pay */}
            <label
              htmlFor="pref-apple-pay"
              className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                preferredPayment === 'apple_pay'
                  ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                  : 'border-border/70 bg-card hover:bg-muted/40 hover:border-border'
              }`}
            >
              <RadioGroupItem value="apple_pay" id="pref-apple-pay" className="mt-1" />
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="w-5 h-5 rounded bg-black text-white flex items-center justify-center text-xs font-bold">
                    
                  </span>
                  <span className="font-bold text-sm text-foreground">Apple Pay</span>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  Biometric 1-tap checkout via Apple Wallet
                </p>
              </div>
            </label>

            {/* Google Pay */}
            <label
              htmlFor="pref-google-pay"
              className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                preferredPayment === 'google_pay'
                  ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                  : 'border-border/70 bg-card hover:bg-muted/40 hover:border-border'
              }`}
            >
              <RadioGroupItem value="google_pay" id="pref-google-pay" className="mt-1" />
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="w-5 h-5 rounded border border-border bg-white dark:bg-zinc-900 flex items-center justify-center text-xs font-bold text-blue-500">
                    G
                  </span>
                  <span className="font-bold text-sm text-foreground">Google Pay</span>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  Cards saved in your Google Account
                </p>
              </div>
            </label>

            {/* Samsung Pay */}
            <label
              htmlFor="pref-samsung-pay"
              className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                preferredPayment === 'samsung_pay'
                  ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                  : 'border-border/70 bg-card hover:bg-muted/40 hover:border-border'
              }`}
            >
              <RadioGroupItem value="samsung_pay" id="pref-samsung-pay" className="mt-1" />
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="w-5 h-5 rounded bg-blue-600 text-white flex items-center justify-center text-[10px] font-bold">
                    S
                  </span>
                  <span className="font-bold text-sm text-foreground">Samsung Pay</span>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  Samsung Galaxy Knox biometric wallet
                </p>
              </div>
            </label>

            {/* Card */}
            <label
              htmlFor="pref-card"
              className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                preferredPayment === 'card'
                  ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                  : 'border-border/70 bg-card hover:bg-muted/40 hover:border-border'
              }`}
            >
              <RadioGroupItem value="card" id="pref-card" className="mt-1" />
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <CreditCard className="w-4 h-4 text-primary" />
                  <span className="font-bold text-sm text-foreground">Card</span>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  Visa, Mastercard, or Keycard credit/debit
                </p>
              </div>
            </label>

            {/* Bank Transfer */}
            <label
              htmlFor="pref-bank-transfer"
              className={`flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all ${
                preferredPayment === 'bank_transfer'
                  ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                  : 'border-border/70 bg-card hover:bg-muted/40 hover:border-border'
              }`}
            >
              <RadioGroupItem value="bank_transfer" id="pref-bank-transfer" className="mt-1" />
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <Landmark className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
                  <span className="font-bold text-sm text-foreground">Bank Transfer</span>
                </div>
                <p className="text-[11px] text-muted-foreground">
                  Direct transfer via NCB, Scotiabank, JN Bank
                </p>
              </div>
            </label>
          </RadioGroup>
        </div>

        {/* Default payment method readout */}
        <div className="p-4 rounded-xl border border-border/70 bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
          <div className="space-y-1">
            <span className="font-bold text-foreground text-sm flex items-center gap-1.5">
              <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400" />
              Default payment method
            </span>
            <p className="text-muted-foreground text-[11px]">
              Pre-selected automatically during quick checkout and scheduled HOA assessments.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Badge variant="secondary" className="font-bold text-xs py-1 px-3">
              {defaultPaymentMethod}
            </Badge>
            <Badge variant="outline" className="text-[10px] text-emerald-600 dark:text-emerald-400 border-emerald-500/30">
              Active Default
            </Badge>
          </div>
        </div>

        <div className="border-t border-border/60" />

        {/* Notifications */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <Label className="text-xs font-bold tracking-wider uppercase text-muted-foreground flex items-center gap-1.5">
              <Bell className="w-3.5 h-3.5 text-primary" />
              Notifications
            </Label>
            <span className="text-[11px] text-muted-foreground">
              Automatic multi-channel alerts
            </span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {/* Payment confirmation */}
            <label
              htmlFor="notif-payment-confirmation"
              className="flex items-start gap-3 p-3 rounded-lg border border-border/60 bg-card hover:bg-muted/30 cursor-pointer transition-colors"
            >
              <Checkbox
                id="notif-payment-confirmation"
                checked={notifications.payment_confirmation}
                onCheckedChange={(checked) =>
                  onNotificationsChange((prev) => ({ ...prev, payment_confirmation: Boolean(checked) }))
                }
                className="mt-0.5"
              />
              <div className="space-y-0.5">
                <span className="text-xs font-bold text-foreground">Payment confirmation</span>
                <p className="text-[11px] text-muted-foreground">
                  Instant notification as soon as payment is authorized.
                </p>
              </div>
            </label>

            {/* Receipt */}
            <label
              htmlFor="notif-receipt"
              className="flex items-start gap-3 p-3 rounded-lg border border-border/60 bg-card hover:bg-muted/30 cursor-pointer transition-colors"
            >
              <Checkbox
                id="notif-receipt"
                checked={notifications.receipt}
                onCheckedChange={(checked) =>
                  onNotificationsChange((prev) => ({ ...prev, receipt: Boolean(checked) }))
                }
                className="mt-0.5"
              />
              <div className="space-y-0.5">
                <span className="text-xs font-bold text-foreground">Receipt</span>
                <p className="text-[11px] text-muted-foreground">
                  Formal tax receipt and statement sent to your email.
                </p>
              </div>
            </label>

            {/* Failed payment */}
            <label
              htmlFor="notif-failed-payment"
              className="flex items-start gap-3 p-3 rounded-lg border border-border/60 bg-card hover:bg-muted/30 cursor-pointer transition-colors"
            >
              <Checkbox
                id="notif-failed-payment"
                checked={notifications.failed_payment}
                onCheckedChange={(checked) =>
                  onNotificationsChange((prev) => ({ ...prev, failed_payment: Boolean(checked) }))
                }
                className="mt-0.5"
              />
              <div className="space-y-0.5">
                <span className="text-xs font-bold text-foreground">Failed payment</span>
                <p className="text-[11px] text-muted-foreground">
                  Immediate SMS & email alert if a transaction is declined.
                </p>
              </div>
            </label>

            {/* Refund */}
            <label
              htmlFor="notif-refund"
              className="flex items-start gap-3 p-3 rounded-lg border border-border/60 bg-card hover:bg-muted/30 cursor-pointer transition-colors"
            >
              <Checkbox
                id="notif-refund"
                checked={notifications.refund}
                onCheckedChange={(checked) =>
                  onNotificationsChange((prev) => ({ ...prev, refund: Boolean(checked) }))
                }
                className="mt-0.5"
              />
              <div className="space-y-0.5">
                <span className="text-xs font-bold text-foreground">Refund</span>
                <p className="text-[11px] text-muted-foreground">
                  Alert when a reversal or refund is credited to your ledger.
                </p>
              </div>
            </label>
          </div>
        </div>

        {/* Action Row */}
        <div className="flex items-center justify-between pt-2">
          <p className="text-[11px] text-muted-foreground">
            Preferences are synchronized with your homeowner profile.
          </p>
          <Button
            onClick={onSavePreferences}
            disabled={isSavingPrefs}
            className="font-bold text-xs gap-1.5 shadow-sm"
          >
            {isSavingPrefs ? (
              <>
                <RefreshCw className="w-3.5 h-3.5 animate-spin" /> Saving...
              </>
            ) : (
              <>
                <Check className="w-3.5 h-3.5" /> Save Preferences
              </>
            )}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
