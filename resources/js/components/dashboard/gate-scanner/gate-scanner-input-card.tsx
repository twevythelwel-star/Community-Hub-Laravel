import React from 'react';
import { 
  Radio, 
  KeyRound, 
  Nfc, 
  LogIn, 
  LogOut, 
  MapPin, 
  Volume2, 
  VolumeX 
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import NfcTagReadButton from '@/components/dashboard/nfc-tag-read-button';
import { cn } from '@/lib/utils';
import type { GateOption } from './types';

interface GateScannerHeaderControlsProps {
  direction: 'in' | 'out';
  onDirectionChange: (dir: 'in' | 'out') => void;
  selectedGate: string;
  onGateChange: (gate: string) => void;
  gates: GateOption[];
  soundEnabled: boolean;
  onToggleSound: () => void;
}

export function GateScannerHeaderControls({
  direction,
  onDirectionChange,
  selectedGate,
  onGateChange,
  gates,
  soundEnabled,
  onToggleSound,
}: GateScannerHeaderControlsProps) {
  return (
    <div className="flex flex-wrap items-center gap-3">
      {/* Lane Direction Toggle */}
      <div className="flex items-center p-1 bg-muted/80 rounded-xl border border-border/60 shadow-xs">
        <button
          type="button"
          onClick={() => onDirectionChange('in')}
          className={cn(
            'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all',
            direction === 'in'
              ? 'bg-emerald-600 text-white shadow-xs'
              : 'text-muted-foreground hover:text-foreground'
          )}
        >
          <LogIn className="w-3.5 h-3.5" />
          <span>Inbound (Entry)</span>
        </button>
        <button
          type="button"
          onClick={() => onDirectionChange('out')}
          className={cn(
            'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all',
            direction === 'out'
              ? 'bg-sky-600 text-white shadow-xs'
              : 'text-muted-foreground hover:text-foreground'
          )}
        >
          <LogOut className="w-3.5 h-3.5" />
          <span>Outbound (Exit)</span>
        </button>
      </div>

      {/* Gate Selector */}
      <div className="flex items-center gap-2 bg-muted/60 px-3 py-1.5 rounded-xl border border-border/60">
        <MapPin className="h-4 w-4 text-muted-foreground" />
        <label htmlFor="gate-selector" className="text-xs font-semibold text-muted-foreground uppercase">
          Gate:
        </label>
        <select
          id="gate-selector"
          value={selectedGate}
          onChange={(e) => onGateChange(e.target.value)}
          className="bg-transparent text-sm font-bold text-foreground focus:outline-hidden cursor-pointer"
        >
          {gates.map((g) => (
            <option key={g.id} value={g.id} className="bg-popover text-foreground">
              {g.name} ({g.id})
            </option>
          ))}
        </select>
      </div>

      {/* Sound Toggle */}
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onToggleSound}
        title={soundEnabled ? 'Mute Audio Signals' : 'Enable Audio Signals'}
        className="gap-1.5 rounded-xl"
      >
        {soundEnabled ? (
          <Volume2 className="h-4 w-4 text-primary" />
        ) : (
          <VolumeX className="h-4 w-4 text-muted-foreground" />
        )}
        <span className="hidden sm:inline text-xs">{soundEnabled ? 'Sound On' : 'Muted'}</span>
      </Button>
    </div>
  );
}

interface GateScannerInputCardProps {
  direction: 'in' | 'out';
  tokenInput: string;
  onTokenInputChange: (val: string) => void;
  onScanSubmit: (e?: React.FormEvent) => void;
  isScanning: boolean;
  inputRef?: React.RefObject<HTMLInputElement>;
}

export function GateScannerInputCard({
  direction,
  tokenInput,
  onTokenInputChange,
  onScanSubmit,
  isScanning,
  inputRef,
}: GateScannerInputCardProps) {
  return (
    <Card className="border-border shadow-xs">
      <CardHeader className="pb-3">
        <CardTitle className="text-base flex items-center justify-between">
          <span className="flex items-center gap-2">
            <span>Gate Authenticator</span>
            <Badge
              variant="outline"
              className="border-emerald-500/30 text-emerald-400 bg-emerald-500/10 font-mono text-[11px] flex items-center gap-1"
            >
              <Radio className="w-3 h-3 animate-pulse" />
              QR + NFC + Mobile Wallet
            </Badge>
          </span>
          <div className="flex items-center gap-2">
            <Badge
              variant="outline"
              className={cn(
                'text-xs font-semibold px-2.5 py-0.5',
                direction === 'in'
                  ? 'border-emerald-500/40 text-emerald-400 bg-emerald-500/10'
                  : 'border-sky-500/40 text-sky-400 bg-sky-500/10'
              )}
            >
              {direction === 'in' ? '🟢 Inbound (Entry)' : '🔵 Outbound (Exit)'}
            </Badge>
            <Badge variant="outline" className="font-mono text-xs">
              Scanner Ready
            </Badge>
          </div>
        </CardTitle>
        <CardDescription>
          Scan QR code with camera, tap contactless NFC card/phone, or enter 6-digit offline PIN.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <form onSubmit={onScanSubmit} className="flex gap-2">
          <div className="relative flex-1">
            <Input
              ref={inputRef}
              type="text"
              value={tokenInput}
              onChange={(e) => onTokenInputChange(e.target.value)}
              placeholder="Scan QR or wallet pass, tap NFC, or enter PIN..."
              className="font-mono text-base tracking-wide h-12 pr-10"
              disabled={isScanning}
              autoFocus
            />
            <KeyRound className="absolute right-3 top-3.5 h-5 w-5 text-muted-foreground/60" />
          </div>
          <Button
            type="submit"
            disabled={isScanning || !tokenInput.trim()}
            className={cn(
              'h-12 px-6 font-semibold',
              direction === 'out' && 'bg-sky-600 hover:bg-sky-700 text-white'
            )}
          >
            {isScanning
              ? 'Verifying...'
              : direction === 'in'
                ? 'Verify Entry'
                : 'Verify Exit'}
          </Button>
        </form>

        <NfcTagReadButton
          disabled={isScanning}
          onRead={(code) => {
            onTokenInputChange(code);
            inputRef?.current?.focus();
          }}
        />

        {/* Contactless NFC Terminal Status */}
        <div className="flex items-center justify-between p-2.5 rounded-lg bg-slate-900/50 border border-slate-800 text-xs">
          <div className="flex items-center gap-2 text-slate-300">
            <div className="w-6 h-6 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center">
              <Nfc className="w-3.5 h-3.5" />
            </div>
            <span>
              NFC Antenna Field: <strong className="text-emerald-400">Listening (13.56 MHz)</strong>
            </span>
          </div>
          <span className="text-[11px] text-muted-foreground font-mono">
            ISO 14443 Type A / Apple VAS / Google Smart Tap
          </span>
        </div>
      </CardContent>
    </Card>
  );
}
