import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { Banknote, CheckCircle2, Cpu, CreditCard, DollarSign, Landmark, QrCode, ShieldCheck, Smartphone, XCircle } from 'lucide-react';
import type { ChannelReport } from './types';

const getChannelIcon = (key: string): ReactNode => {
  switch (key) {
    case 'card':
      return <CreditCard className="h-5 w-5 text-indigo-500" />;
    case 'apple_pay':
    case 'google_pay':
    case 'samsung_wallet':
      return <Smartphone className="h-5 w-5 text-blue-500" />;
    case 'nfc_pos':
      return <Cpu className="h-5 w-5 text-amber-500" />;
    case 'bank_wire':
      return <Landmark className="h-5 w-5 text-emerald-600" />;
    case 'cash_office':
      return <Banknote className="h-5 w-5 text-emerald-500" />;
    case 'cash_app':
    case 'zelle':
      return <DollarSign className="h-5 w-5 text-green-500" />;
    case 'qr_code':
      return <QrCode className="h-5 w-5 text-purple-500" />;
    default:
      return <CreditCard className="h-5 w-5 text-slate-500" />;
  }
};

/** The payment channels, their pre-flight checks, and (for administrators) validate / enable controls. */
export function PaymentChannelsTab({ channels, isAdmin }: { channels: ChannelReport[]; isAdmin: boolean }) {
  const { toast } = useToast();
  const [validating, setValidating] = useState<string | null>(null);

  const handleValidateChannel = (channelKey: string) => {
    setValidating(channelKey);
    router.post(
      route('dashboard.billing.channels.validate', channelKey),
      {},
      {
        preserveScroll: true,
        onFinish: () => setValidating(null),
        onSuccess: () => {
          toast({
            title: 'Technical Integration Verified',
            description: `Channel [${channelKey}] has been audited against security and provider readiness standards.`,
          });
        },
      }
    );
  };

  const handleToggleChannel = (channelKey: string) => {
    router.post(
      route('dashboard.billing.channels.toggle', channelKey),
      {},
      {
        preserveScroll: true,
        onSuccess: () => {
          toast({
            title: 'Channel Status Updated',
            description: `Production availability for [${channelKey}] has been updated.`,
          });
        },
        onError: (errors) => {
          toast({
            variant: 'destructive',
            title: 'Channel Toggle Blocked',
            description: errors?.channel || 'Cannot enable channel without verified integration.',
          });
        },
      }
    );
  };

  return (
    <Card className="border shadow-md bg-gradient-to-br from-card via-card to-primary/5">
      <CardHeader>
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-indigo-500/10 text-indigo-600 border border-indigo-500/20">
              <Cpu className="h-6 w-6" />
            </div>
            <div>
              <CardTitle className="text-xl font-bold flex items-center gap-2">
                <span>Payment Orchestration &amp; Technical Integration Matrix</span>
                <Badge variant="outline" className="font-mono text-xs border-indigo-500/40 text-indigo-600 bg-indigo-500/10">
                  10 CHANNELS
                </Badge>
              </CardTitle>
              <CardDescription className="text-xs">
                Provider availability and technical integration are validated individually before production enablement.
              </CardDescription>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Badge variant="secondary" className="font-mono text-xs">
              {channels.filter((c) => c.enabled).length} of 10 Enabled in Production
            </Badge>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {channels.map((channel) => (
            <div
              key={channel.channel_key}
              className={`p-4 rounded-xl border transition-all ${
                channel.enabled
                  ? 'border-emerald-500/30 bg-emerald-500/[0.02] shadow-sm'
                  : 'border-border bg-card/60 opacity-80'
              }`}
            >
              <div className="flex items-start justify-between gap-3 mb-3">
                <div className="flex items-center gap-2.5">
                  <div className="p-2 rounded-lg bg-muted/60 border shrink-0">
                    {getChannelIcon(channel.channel_key)}
                  </div>
                  <div>
                    <h4 className="font-bold text-sm text-foreground flex items-center gap-1.5">
                      {channel.label}
                      {channel.is_ready ? (
                        <Badge variant="outline" className="text-[9px] font-mono border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                          ✓ Verified
                        </Badge>
                      ) : (
                        <Badge variant="outline" className="text-[9px] font-mono border-amber-500/40 text-amber-600 bg-amber-500/10">
                          Needs Config
                        </Badge>
                      )}
                    </h4>
                    <div className="flex items-center gap-1.5 mt-0.5">
                      <Badge variant="outline" className={`text-[9px] font-mono uppercase ${
                        channel.integration_mode === 'API'
                          ? 'border-cyan-500/40 text-cyan-600 bg-cyan-500/10'
                          : channel.integration_mode === 'WEBHOOK'
                          ? 'border-purple-500/40 text-purple-600 bg-purple-500/10'
                          : channel.integration_mode === 'HOSTED_CHECKOUT'
                          ? 'border-indigo-500/40 text-indigo-600 bg-indigo-500/10'
                          : channel.integration_mode === 'BANK_RECONCILIATION'
                          ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10'
                          : 'border-amber-500/40 text-amber-600 bg-amber-500/10'
                      }`}>
                        {channel.integration_mode || 'MANUAL_VERIFICATION'}
                      </Badge>
                      <span className="font-mono text-[10px] text-muted-foreground">
                        &bull; Driver: {channel.channel_key}
                      </span>
                    </div>
                  </div>
                </div>

                <Badge
                  variant={channel.enabled ? 'default' : 'secondary'}
                  className={`font-mono text-[10px] ${
                    channel.enabled ? 'bg-emerald-600 text-white' : ''
                  }`}
                >
                  {channel.enabled ? 'PRODUCTION ACTIVE' : 'DISABLED'}
                </Badge>
              </div>

              {/* Technical Checklist */}
              <div className="space-y-1.5 mb-3 p-2.5 rounded-lg bg-muted/30 border text-xs">
                <span className="text-[10px] font-semibold text-muted-foreground uppercase block">
                  Pre-Flight Technical Checks
                </span>
                {channel.checks.map((chk, idx) => (
                  <div key={idx} className="flex items-center justify-between text-[11px]">
                    <span className="flex items-center gap-1 text-foreground">
                      {chk.passed ? (
                        <CheckCircle2 className="h-3 w-3 text-emerald-600 shrink-0" />
                      ) : (
                        <XCircle className="h-3 w-3 text-rose-500 shrink-0" />
                      )}
                      <span>{chk.name}</span>
                    </span>
                    <span className="text-[10px] font-mono text-muted-foreground">{chk.message}</span>
                  </div>
                ))}
              </div>

              {/* Action buttons */}
              {isAdmin && (
                <div className="flex items-center justify-between pt-2 border-t gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={validating === channel.channel_key}
                    onClick={() => handleValidateChannel(channel.channel_key)}
                    className="h-7 text-xs font-semibold gap-1 text-primary"
                  >
                    <ShieldCheck className="h-3 w-3" />
                    <span>{validating === channel.channel_key ? 'Validating...' : 'Validate Integration'}</span>
                  </Button>

                  <Button
                    type="button"
                    size="sm"
                    variant={channel.enabled ? 'destructive' : 'default'}
                    onClick={() => handleToggleChannel(channel.channel_key)}
                    className={`h-7 text-xs font-semibold gap-1 ${
                      !channel.enabled ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : ''
                    }`}
                  >
                    <span>{channel.enabled ? 'Disable Channel' : 'Enable for Production'}</span>
                  </Button>
                </div>
              )}
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
