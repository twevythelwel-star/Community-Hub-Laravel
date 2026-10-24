import React from 'react';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  AlertTriangle,
  Ban,
  Car,
  ChevronRight,
  Clock,
  Dumbbell,
  History,
  Lock,
  Mail,
  MoreVertical,
  Phone,
  QrCode,
  RefreshCw,
  Shield,
  ShieldAlert,
  ShieldCheck,
  SlidersHorizontal,
  Waves,
} from 'lucide-react';
import { AuthorizedPerson } from './delegation-types';

interface DelegationPersonCardProps {
  person: AuthorizedPerson;
  onViewPass: (person: AuthorizedPerson) => void;
  onEditRules: (person: AuthorizedPerson) => void;
  onReissuePass: (person: AuthorizedPerson) => void;
  onViewAudit: (person: AuthorizedPerson) => void;
  onTriggerEmergency: (person: AuthorizedPerson) => void;
  onDeactivateEmergency: (person: AuthorizedPerson) => void;
  onRevoke: (person: AuthorizedPerson) => void;
}

export function renderCredentialShapeIcon(shape?: string) {
  switch (shape) {
    case 'Hexagon':
      return <div className="w-5 h-5 rounded-sm rotate-45 border-2 border-emerald-500 bg-emerald-500/20 flex items-center justify-center text-[10px] font-bold text-emerald-400">H</div>;
    case 'RoundedSquare':
      return <div className="w-5 h-5 rounded-md border-2 border-indigo-500 bg-indigo-500/20 flex items-center justify-center text-[10px] font-bold text-indigo-400">L</div>;
    case 'HouseHex':
      return <div className="w-5 h-5 rounded-t-lg rounded-b-sm border-2 border-teal-500 bg-teal-500/20 flex items-center justify-center text-[10px] font-bold text-teal-400">S</div>;
    case 'Pentagon':
      return <div className="w-5 h-5 clip-polygon border-2 border-orange-500 bg-orange-500/20 flex items-center justify-center text-[10px] font-bold text-orange-400">C</div>;
    case 'Circle':
      return <div className="w-5 h-5 rounded-full border-2 border-cyan-500 bg-cyan-500/20 flex items-center justify-center text-[10px] font-bold text-cyan-400">V</div>;
    case 'Octagon':
    default:
      return <div className="w-5 h-5 rounded-sm border-2 border-amber-500 bg-amber-500/20 flex items-center justify-center text-[10px] font-bold text-amber-400">D</div>;
  }
}

export function DelegationPersonCard({
  person,
  onViewPass,
  onEditRules,
  onReissuePass,
  onViewAudit,
  onTriggerEmergency,
  onDeactivateEmergency,
  onRevoke,
}: DelegationPersonCardProps) {
  const isActive = person.status === 'active' && person.credential?.isActive;
  const isFormer = person.categoryKey === 'former_occupants' || ['revoked', 'expired'].includes(person.status);

  return (
    <Card
      key={person.id}
      className={`relative flex flex-col justify-between overflow-hidden border transition-all hover:shadow-md ${
        person.isEmergencyActive
          ? 'border-red-500/50 bg-red-950/10'
          : isFormer
          ? 'border-border/30 opacity-75 bg-muted/10'
          : 'border-border/60 hover:border-primary/40 bg-card/80'
      }`}
    >
      <CardHeader className="p-4 pb-2 space-y-2">
        <div className="flex items-start justify-between gap-2">
          <div className="flex items-center gap-2">
            {renderCredentialShapeIcon(person.credential?.shape)}
            <div>
              <Badge
                variant="outline"
                className={`text-[10px] font-semibold tracking-wide uppercase px-2 py-0.5 ${person.categoryBadgeColor}`}
              >
                {person.credential?.categoryCode || 'GP'} • {person.categoryLabel}
              </Badge>
            </div>
          </div>

          <div className="flex items-center gap-1">
            {person.durationLabel && (
              <span
                className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border ${
                  person.durationType === '2_hours'
                    ? 'bg-amber-500/10 text-amber-400 border-amber-500/30'
                    : person.durationType === '1_day'
                    ? 'bg-blue-500/10 text-blue-400 border-blue-500/30'
                    : person.durationType === '1_week'
                    ? 'bg-purple-500/10 text-purple-400 border-purple-500/30'
                    : person.durationType === 'recurring'
                    ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                    : person.durationType === 'manual_revocation'
                    ? 'bg-teal-500/10 text-teal-400 border-teal-500/30'
                    : 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30'
                }`}
              >
                <Clock className="w-2.5 h-2.5" />
                {person.durationLabel}
              </span>
            )}
            {person.isEmergencyActive && (
              <Badge className="bg-red-500 text-white text-[10px] font-bold animate-pulse">
                EMERGENCY
              </Badge>
            )}
            <span
              className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium border ${
                isActive
                  ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                  : isFormer
                  ? 'bg-zinc-500/10 text-zinc-400 border-zinc-500/30'
                  : 'bg-amber-500/10 text-amber-400 border-amber-500/30'
              }`}
            >
              <span className={`w-1.5 h-1.5 rounded-full ${isActive ? 'bg-emerald-400 animate-ping' : isFormer ? 'bg-zinc-400' : 'bg-amber-400'}`} />
              {person.status.toUpperCase()}
            </span>
          </div>
        </div>

        <div>
          <h3 className="text-base font-semibold text-foreground tracking-tight flex items-center justify-between">
            <span>{person.name}</span>
          </h3>
          <p className="text-xs text-muted-foreground font-medium">
            {person.relationship} • {person.propertyLabel}
          </p>
        </div>
      </CardHeader>

      <CardContent className="p-4 pt-2 space-y-3 flex-1 flex flex-col justify-between">
        <div className="space-y-1 text-xs text-muted-foreground pt-1 border-t border-border/30">
          {person.email && (
            <div className="flex items-center gap-1.5 truncate">
              <Mail className="w-3.5 h-3.5 text-muted-foreground/70" />
              <span className="truncate">{person.email}</span>
            </div>
          )}
          {person.phone && (
            <div className="flex items-center gap-1.5">
              <Phone className="w-3.5 h-3.5 text-muted-foreground/70" />
              <span>{person.phone}</span>
            </div>
          )}
          <div className="flex items-center gap-1.5">
            <Clock className="w-3.5 h-3.5 text-muted-foreground/70" />
            <span>
              {person.accessRules?.allowed_days ? `${person.accessRules.allowed_days.join(', ')} • ` : ''}
              {person.accessRules?.entry_start_time || '00:00'} - {person.accessRules?.entry_end_time || '23:59'}
            </span>
          </div>
        </div>

        <div className="flex flex-wrap gap-1 pt-1">
          {person.accessRules?.gate_access !== false && (
            <span className="inline-flex items-center gap-1 text-[10px] bg-muted/60 text-foreground px-2 py-0.5 rounded border border-border/40">
              <Lock className="w-3 h-3 text-primary" /> Gate Pass
            </span>
          )}
          {person.accessRules?.pool_access && (
            <span className="inline-flex items-center gap-1 text-[10px] bg-muted/60 text-foreground px-2 py-0.5 rounded border border-border/40">
              <Waves className="w-3 h-3 text-cyan-400" /> Pool
            </span>
          )}
          {person.accessRules?.gym_access && (
            <span className="inline-flex items-center gap-1 text-[10px] bg-muted/60 text-foreground px-2 py-0.5 rounded border border-border/40">
              <Dumbbell className="w-3 h-3 text-purple-400" /> Fitness
            </span>
          )}
          {person.accessRules?.parking_allocated && (
            <span className="inline-flex items-center gap-1 text-[10px] bg-muted/60 text-foreground px-2 py-0.5 rounded border border-border/40">
              <Car className="w-3 h-3 text-emerald-400" /> Parking
            </span>
          )}
          {person.accessRules?.requires_id_verification && (
            <span className="inline-flex items-center gap-1 text-[10px] bg-amber-500/10 text-amber-400 px-2 py-0.5 rounded border border-amber-500/30 font-medium">
              <ShieldAlert className="w-3 h-3" /> ID Check
            </span>
          )}
        </div>

        <div className="pt-2 border-t border-border/30 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <div className="p-1.5 rounded-lg bg-primary/10 text-primary border border-primary/20">
              <QrCode className="w-4 h-4" />
            </div>
            <div>
              <p className="text-[10px] text-muted-foreground uppercase font-mono">
                {person.credential?.passId || 'PASS-PENDING'}
              </p>
              <p className="text-[11px] font-semibold">Digital Credential</p>
            </div>
          </div>

          <Button
            size="sm"
            variant="secondary"
            onClick={() => onViewPass(person)}
            className="h-7 text-xs px-2.5 gap-1.5"
          >
            <span>View Pass</span>
            <ChevronRight className="w-3 h-3" />
          </Button>
        </div>

        <div className="pt-2 border-t border-border/30 flex items-center justify-between gap-1">
          {person.sourceType === 'delegation' ? (
            person.canManage === false ? (
              <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground/80 py-1 font-medium">
                <ShieldCheck className="w-3.5 h-3.5 text-indigo-400" />
                <span>Authorized for Property</span>
              </div>
            ) : (
              <>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onEditRules(person)}
                  className="h-7 px-2 text-[11px] text-muted-foreground hover:text-foreground gap-1"
                >
                  <SlidersHorizontal className="w-3 h-3" />
                  <span>Edit Rules</span>
                </Button>

                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="sm" className="h-7 w-7 p-0">
                      <MoreVertical className="w-3.5 h-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="w-48 text-xs">
                    <DropdownMenuLabel>Credential Actions</DropdownMenuLabel>
                    <DropdownMenuItem onClick={() => onReissuePass(person)}>
                      <RefreshCw className="w-3.5 h-3.5 mr-2" /> Reissue / Rotate QR
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={() => onViewAudit(person)}>
                      <History className="w-3.5 h-3.5 mr-2" /> Audit Trail & Events
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    {person.isEmergencyActive ? (
                      <DropdownMenuItem onClick={() => onDeactivateEmergency(person)}>
                        <Shield className="w-3.5 h-3.5 mr-2 text-emerald-400" /> End Emergency Mode
                      </DropdownMenuItem>
                    ) : (
                      <DropdownMenuItem onClick={() => onTriggerEmergency(person)}>
                        <AlertTriangle className="w-3.5 h-3.5 mr-2 text-amber-400" /> Trigger Emergency
                      </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      onClick={() => onRevoke(person)}
                      className="text-red-400 focus:text-red-400"
                    >
                      <Ban className="w-3.5 h-3.5 mr-2" /> Revoke Authorization
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </>
            )
          ) : (
            <div className="text-[10px] text-muted-foreground/80 py-1">
              {person.sourceType === 'user'
                ? 'Managed via Primary Ownership'
                : person.sourceType === 'renter'
                ? 'Managed via Lease Agreement'
                : person.sourceType === 'household_member'
                ? 'Managed via Household Registry'
                : 'Managed via Estate Gate Pass'}
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
