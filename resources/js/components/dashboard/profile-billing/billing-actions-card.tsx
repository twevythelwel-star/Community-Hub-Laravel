import React from 'react';
import { 
  CreditCard, 
  Landmark, 
  Link as LinkIcon, 
  Radio, 
  Wallet, 
  Info 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import type { WalletCapability } from '@/lib/payment-capabilities';
import type { BillingData, SavedMethod } from './types';

interface BillingActionsCardProps {
  balance: number;
  currencySymbol: string;
  billing?: BillingData;
  capabilities: {
    apple_pay: WalletCapability;
    google_pay: WalletCapability;
    samsung_pay: WalletCapability;
    tap_nfc: WalletCapability;
  };
  preferredWalletBadge: string;
  savedMethods: SavedMethod[];
  onOpenCardModal: () => void;
  onPayWithWallet: () => void;
  onQuickPay: (cap: WalletCapability) => void;
  onOpenSelectorWithMethod: (channel: string) => void;
  renderBadge: (cap: WalletCapability) => React.ReactNode;
}

export function BillingActionsCard({
  balance,
  currencySymbol,
  billing,
  capabilities,
  preferredWalletBadge,
  savedMethods,
  onOpenCardModal,
  onPayWithWallet,
  onQuickPay,
  onOpenSelectorWithMethod,
  renderBadge,
}: BillingActionsCardProps) {
  return (
    <Card className="border-border/80 shadow-md overflow-hidden">
      <CardHeader className="border-b border-border/40 pb-4 bg-muted/15">
        <div className="flex items-center justify-between">
          <div>
            <CardTitle className="text-2xl font-bold font-headline flex items-center gap-2">
              <Wallet className="w-6 h-6 text-primary" />
              Payments & Wallets
            </CardTitle>
            <CardDescription className="text-xs">
              Manage your resident balance, instant device wallets, and authorized community payment channels.
            </CardDescription>
          </div>
          <Badge variant="outline" className="font-mono text-xs">
            Account Active
          </Badge>
        </div>
      </CardHeader>

      <CardContent className="space-y-6 pt-6">
        {/* Outstanding Balance Banner */}
        <div className="rounded-xl border border-primary/20 bg-gradient-to-br from-primary/10 via-background to-muted/30 p-6 text-center space-y-4 shadow-sm">
          <div className="space-y-1">
            <p className="text-xs font-semibold tracking-wider uppercase text-muted-foreground">
              Outstanding Balance
            </p>
            <p className="font-headline text-4xl sm:text-5xl font-black text-foreground tracking-tight">
              {currencySymbol}
              {balance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </p>
            {billing?.latestInvoice && (
              <p className="text-xs text-muted-foreground">
                Statement <span className="font-mono font-medium text-foreground">{billing.latestInvoice.invoiceNumber}</span>
                {billing.latestInvoice.dueDate && (
                  <> • Due on {billing.latestInvoice.dueDate}</>
                )}
              </p>
            )}
          </div>

          <div className="flex flex-col sm:flex-row items-center justify-center gap-3 w-full sm:max-w-xl mx-auto pt-2">
            <Button
              onClick={onOpenCardModal}
              size="lg"
              className="w-full sm:flex-1 h-12 text-sm sm:text-base font-bold bg-primary hover:bg-primary/90 text-primary-foreground shadow-lg hover:shadow-primary/25 transition-all uppercase tracking-wider"
            >
              PAY {currencySymbol}
              {balance.toLocaleString('en-US', { minimumFractionDigits: 0 })}
            </Button>

            <Button
              onClick={onPayWithWallet}
              size="lg"
              variant="outline"
              className="w-full sm:flex-1 h-12 text-sm sm:text-base font-bold border-2 border-primary/35 hover:border-primary hover:bg-primary/10 transition-all flex items-center justify-center gap-2 shadow-sm text-foreground"
            >
              <Wallet className="w-5 h-5 text-primary" />
              <span>Pay with Wallet</span>
              <Badge variant="secondary" className="text-[10px] uppercase font-bold py-0.5 px-2 ml-1">
                {preferredWalletBadge}
              </Badge>
            </Button>
          </div>
        </div>

        {/* ── QUICK PAYMENT SECTION ─────────────────────────────────── */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold tracking-wider uppercase text-muted-foreground">
              QUICK PAYMENT
            </h3>
            <span className="text-[11px] text-muted-foreground flex items-center gap-1">
              <Info className="w-3 h-3" /> Hardware capability-aware
            </span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {/* Apple Pay Button */}
            <button
              type="button"
              onClick={() => onQuickPay(capabilities.apple_pay)}
              className="group relative flex items-center justify-between p-4 rounded-xl border border-border/70 bg-card hover:bg-muted/40 hover:border-primary/50 transition-all text-left shadow-sm active:scale-[0.99]"
            >
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-black text-white flex items-center justify-center font-bold text-lg shadow-sm">
                  
                </div>
                <div>
                  <div className="font-bold text-sm text-foreground flex items-center gap-1.5">
                    Apple Pay
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    Face ID / Touch ID 1-tap checkout
                  </p>
                </div>
              </div>
              <div>{renderBadge(capabilities.apple_pay)}</div>
            </button>

            {/* Google Pay Button */}
            <button
              type="button"
              onClick={() => onQuickPay(capabilities.google_pay)}
              className="group relative flex items-center justify-between p-4 rounded-xl border border-border/70 bg-card hover:bg-muted/40 hover:border-primary/50 transition-all text-left shadow-sm active:scale-[0.99]"
            >
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-white dark:bg-zinc-900 border border-border flex items-center justify-center font-bold text-sm shadow-sm text-foreground">
                  <span className="text-blue-500 font-extrabold">G</span>
                  <span className="text-zinc-600 dark:text-zinc-400 font-semibold text-xs ml-0.5">Pay</span>
                </div>
                <div>
                  <div className="font-bold text-sm text-foreground flex items-center gap-1.5">
                    Google Pay
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    Cards stored with Google Account
                  </p>
                </div>
              </div>
              <div>{renderBadge(capabilities.google_pay)}</div>
            </button>

            {/* Samsung Pay Button */}
            <button
              type="button"
              onClick={() => onQuickPay(capabilities.samsung_pay)}
              className="group relative flex items-center justify-between p-4 rounded-xl border border-border/70 bg-card hover:bg-muted/40 hover:border-primary/50 transition-all text-left shadow-sm active:scale-[0.99]"
            >
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold text-xs shadow-sm">
                  S<span className="font-normal text-[10px]">Pay</span>
                </div>
                <div>
                  <div className="font-bold text-sm text-foreground flex items-center gap-1.5">
                    Samsung Pay
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    Knox biometric wallet for Galaxy
                  </p>
                </div>
              </div>
              <div>{renderBadge(capabilities.samsung_pay)}</div>
            </button>

            {/* Tap to Pay / Contactless Button */}
            <button
              type="button"
              onClick={() => onQuickPay(capabilities.tap_nfc)}
              className="group relative flex items-center justify-between p-4 rounded-xl border border-border/70 bg-card hover:bg-muted/40 hover:border-primary/50 transition-all text-left shadow-sm active:scale-[0.99]"
            >
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 flex items-center justify-center font-bold text-sm shadow-sm">
                  <Radio className="w-5 h-5" />
                </div>
                <div>
                  <div className="font-bold text-sm text-foreground flex items-center gap-1.5">
                    Tap to Pay / Contactless
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    Physical card tap on card-present terminal
                  </p>
                </div>
              </div>
              <div>{renderBadge(capabilities.tap_nfc)}</div>
            </button>
          </div>
        </div>

        <div className="border-t border-border/60 my-6" />

        {/* ── OTHER PAYMENT METHODS ─────────────────────────────────── */}
        <div className="space-y-3">
          <h3 className="text-xs font-bold tracking-wider uppercase text-muted-foreground">
            Other payment methods
          </h3>

          <div className="grid gap-2 sm:grid-cols-3">
            <button
              type="button"
              onClick={onOpenCardModal}
              className="p-3.5 rounded-lg border border-border/60 bg-muted/20 hover:bg-muted/60 transition-all text-left flex items-center gap-3 group"
            >
              <div className="p-2 rounded-md bg-primary/10 text-primary group-hover:scale-105 transition-transform">
                <CreditCard className="w-4 h-4" />
              </div>
              <div>
                <p className="text-xs font-bold text-foreground">💳 Debit / Credit Card</p>
                <p className="text-[11px] text-muted-foreground">Visa, Mastercard, Keycard</p>
              </div>
            </button>

            <button
              type="button"
              onClick={() => onOpenSelectorWithMethod('bank_transfer')}
              className="p-3.5 rounded-lg border border-border/60 bg-muted/20 hover:bg-muted/60 transition-all text-left flex items-center gap-3 group"
            >
              <div className="p-2 rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 group-hover:scale-105 transition-transform">
                <Landmark className="w-4 h-4" />
              </div>
              <div>
                <p className="text-xs font-bold text-foreground">🏦 Bank Transfer</p>
                <p className="text-[11px] text-muted-foreground">NCB, Scotiabank, JN Bank</p>
              </div>
            </button>

            <button
              type="button"
              onClick={() => onOpenSelectorWithMethod('payment_link')}
              className="p-3.5 rounded-lg border border-border/60 bg-muted/20 hover:bg-muted/60 transition-all text-left flex items-center gap-3 group"
            >
              <div className="p-2 rounded-md bg-sky-500/10 text-sky-600 dark:text-sky-400 group-hover:scale-105 transition-transform">
                <LinkIcon className="w-4 h-4" />
              </div>
              <div>
                <p className="text-xs font-bold text-foreground">🔗 Payment Link</p>
                <p className="text-[11px] text-muted-foreground">Tokenized SMS / WhatsApp</p>
              </div>
            </button>
          </div>
        </div>

        <div className="border-t border-border/60 my-6" />

        {/* ── SAVED PAYMENT METHODS ─────────────────────────────────── */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold tracking-wider uppercase text-muted-foreground">
              Saved Payment Methods
            </h3>
            <span className="text-[11px] text-muted-foreground">
              Tokenized • PCI DSS Level 1 Compliant
            </span>
          </div>

          <div className="space-y-2">
            {savedMethods.map((method) => (
              <div
                key={method.id}
                className="flex items-center justify-between p-3.5 rounded-lg border border-border/60 bg-card hover:bg-muted/30 transition-colors"
              >
                <div className="flex items-center gap-3">
                  <div className="w-9 h-6 rounded bg-slate-900 text-white flex items-center justify-center font-mono font-bold text-[10px] tracking-wider shadow-sm">
                    {method.brand?.toUpperCase() || 'CARD'}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-sm font-bold text-foreground tracking-wider">
                        •••• {method.lastFour || '4242'}
                      </span>
                      {method.isDefault && (
                        <Badge variant="outline" className="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 border-emerald-500/30">
                          Default
                        </Badge>
                      )}
                    </div>
                    <p className="text-[11px] text-muted-foreground">
                      {method.displayName || 'Homeowner Primary Card'} • Expires 12/28
                    </p>
                  </div>
                </div>
                <Button
                  onClick={onOpenCardModal}
                  variant="outline"
                  size="sm"
                  className="text-xs font-medium"
                >
                  Pay with Card
                </Button>
              </div>
            ))}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
