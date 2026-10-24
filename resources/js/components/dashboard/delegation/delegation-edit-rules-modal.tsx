import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Calendar,
  Check,
  Clock,
  Lock,
  SlidersHorizontal,
} from 'lucide-react';
import {
  AccessPermissions,
  AuthorizedPerson,
  DURATION_PRESETS,
} from './delegation-types';

interface EditRulesModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  activePerson: AuthorizedPerson | null;
  editRulesData: any;
  setEditRulesData: React.Dispatch<React.SetStateAction<any>>;
  onSubmit: (e: React.FormEvent) => void;
  isRenter: boolean;
  permissions?: AccessPermissions;
  handleSelectEditRulesDurationPreset: (presetKey: string) => void;
  setEndOfYearExpiration: (target: 'form' | 'edit') => void;
  applyQuickSchedule: (shiftKey: string, target: 'form' | 'edit') => void;
}

export function DelegationEditRulesModal({
  isOpen,
  onOpenChange,
  activePerson,
  editRulesData,
  setEditRulesData,
  onSubmit,
  isRenter,
  permissions,
  handleSelectEditRulesDurationPreset,
  setEndOfYearExpiration,
  applyQuickSchedule,
}: EditRulesModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg bg-card/95 border-border/60">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-base">
            <SlidersHorizontal className="w-5 h-5 text-primary" />
            <span>Edit Access Rules: {activePerson?.name}</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Update allowed entry days, operational hours, and security parameters.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} className="space-y-4 pt-2">
          {/* Duration Presets Selector */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label className="text-xs font-semibold flex items-center gap-1.5">
                <Clock className="w-3.5 h-3.5 text-primary" />
                <span>Duration Preset</span>
              </Label>
              <span className="text-[10px] text-muted-foreground">
                Modifies gate validity period
              </span>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
              {DURATION_PRESETS.filter((preset) => !isRenter || preset.key !== 'manual_revocation').map((preset) => {
                const isSelected = editRulesData.duration_type === preset.key;
                const Icon = preset.icon;
                return (
                  <button
                    key={preset.key}
                    type="button"
                    onClick={() => handleSelectEditRulesDurationPreset(preset.key)}
                    className={`flex items-center justify-between p-2 rounded-lg border transition-all text-xs ${
                      isSelected
                        ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary font-semibold'
                        : 'border-border/40 hover:border-border text-muted-foreground bg-muted/20'
                    }`}
                  >
                    <span className="flex items-center gap-1.5 truncate">
                      <Icon className={`w-3.5 h-3.5 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`} />
                      <span className="truncate">{preset.shortLabel}</span>
                    </span>
                    {isSelected && <Check className="w-3 h-3 text-primary shrink-0" />}
                  </button>
                );
              })}
            </div>

            {/* Duration Expiration Inputs */}
            <div className="grid grid-cols-2 gap-3 pt-1">
              <div className="space-y-1">
                <Label className="text-[11px] text-muted-foreground">Start Date</Label>
                <Input
                  type="date"
                  value={editRulesData.starts_at}
                  onChange={(e) => setEditRulesData({ ...editRulesData, starts_at: e.target.value })}
                  className="h-8 text-xs"
                />
              </div>
              <div className="space-y-1">
                <div className="flex items-center justify-between">
                  <Label className="text-[11px] text-muted-foreground">Expiration Date</Label>
                  {editRulesData.duration_type !== 'manual_revocation' && (
                    <button
                      type="button"
                      onClick={() => setEndOfYearExpiration('edit')}
                      className="text-[10px] text-emerald-400 hover:text-emerald-300 font-medium flex items-center gap-0.5 hover:underline"
                      title="Set expiration to December 31"
                    >
                      <Calendar className="w-2.5 h-2.5" />
                      Set Dec 31
                    </button>
                  )}
                </div>
                {editRulesData.duration_type === 'manual_revocation' ? (
                  <div className="h-8 flex items-center px-2.5 rounded-md border border-border/40 bg-muted/40 text-[11px] text-muted-foreground">
                    <Lock className="w-3 h-3 mr-1 text-teal-400" />
                    Until Revoked
                  </div>
                ) : editRulesData.duration_type === '2_hours' ? (
                  <div className="h-8 flex items-center px-2.5 rounded-md border border-border/40 bg-muted/40 text-[11px] text-muted-foreground">
                    <Clock className="w-3 h-3 mr-1 text-amber-500" />
                    +2 Hours
                  </div>
                ) : (
                  <Input
                    type="date"
                    value={editRulesData.expires_at}
                    max={isRenter && permissions?.leaseEndDate ? permissions.leaseEndDate : undefined}
                    onChange={(e) => setEditRulesData({ ...editRulesData, expires_at: e.target.value })}
                    className="h-8 text-xs"
                  />
                )}
              </div>
            </div>
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label className="text-xs font-semibold">Allowed Entry Days</Label>
              <div className="flex flex-wrap gap-1">
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('mon_fri', 'edit')}
                  className="text-[9px] px-1.5 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Mon–Fri
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('mon_sat', 'edit')}
                  className="text-[9px] px-1.5 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Mon–Sat
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('tue_thu', 'edit')}
                  className="text-[9px] px-1.5 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Tue/Thu
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('weekends', 'edit')}
                  className="text-[9px] px-1.5 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Weekends
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('everyday', 'edit')}
                  className="text-[9px] px-1.5 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  All Days
                </button>
              </div>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day) => {
                const isChecked = editRulesData.access_rules.allowed_days.includes(day);
                return (
                  <label
                    key={day}
                    className={`flex items-center gap-1 px-2.5 py-1 rounded text-xs border cursor-pointer ${
                      isChecked
                        ? 'bg-primary/10 border-primary text-foreground font-medium'
                        : 'bg-background border-border text-muted-foreground'
                    }`}
                  >
                    <input
                      type="checkbox"
                      className="hidden"
                      checked={isChecked}
                      onChange={(e) => {
                        const days = e.target.checked
                          ? [...editRulesData.access_rules.allowed_days, day]
                          : editRulesData.access_rules.allowed_days.filter((d: string) => d !== day);
                        setEditRulesData({
                          ...editRulesData,
                          access_rules: { ...editRulesData.access_rules, allowed_days: days },
                        });
                      }}
                    />
                    <span>{day}</span>
                  </label>
                );
              })}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label className="text-xs">Start Time</Label>
              <Input
                type="time"
                value={editRulesData.access_rules.entry_start_time}
                onChange={(e) =>
                  setEditRulesData({
                    ...editRulesData,
                    access_rules: { ...editRulesData.access_rules, entry_start_time: e.target.value },
                  })
                }
                className="h-8 text-xs"
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">End Time</Label>
              <Input
                type="time"
                value={editRulesData.access_rules.entry_end_time}
                onChange={(e) =>
                  setEditRulesData({
                    ...editRulesData,
                    access_rules: { ...editRulesData.access_rules, entry_end_time: e.target.value },
                  })
                }
                className="h-8 text-xs"
              />
            </div>
          </div>

          <div className="space-y-2 pt-2">
            <Label className="text-xs font-semibold">Security & Amenity Permissions</Label>
            <div className="space-y-2">
              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <Checkbox
                  checked={editRulesData.access_rules.gate_access}
                  onCheckedChange={(checked) =>
                    setEditRulesData({
                      ...editRulesData,
                      access_rules: { ...editRulesData.access_rules, gate_access: !!checked },
                    })
                  }
                />
                <span>Digital Gate Scanner Access</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <Checkbox
                  checked={editRulesData.access_rules.requires_id_verification}
                  onCheckedChange={(checked) =>
                    setEditRulesData({
                      ...editRulesData,
                      access_rules: { ...editRulesData.access_rules, requires_id_verification: !!checked },
                    })
                  }
                />
                <span>Mandatory Physical Photo ID Check on scan</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <Checkbox
                  checked={editRulesData.access_rules.pool_access}
                  onCheckedChange={(checked) =>
                    setEditRulesData({
                      ...editRulesData,
                      access_rules: { ...editRulesData.access_rules, pool_access: !!checked },
                    })
                  }
                />
                <span>Pool & Wet Areas Access</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <Checkbox
                  checked={editRulesData.access_rules.gym_access}
                  onCheckedChange={(checked) =>
                    setEditRulesData({
                      ...editRulesData,
                      access_rules: { ...editRulesData.access_rules, gym_access: !!checked },
                    })
                  }
                />
                <span>Fitness Center Access</span>
              </label>
              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <Checkbox
                  checked={editRulesData.access_rules.parking_allocated}
                  onCheckedChange={(checked) =>
                    setEditRulesData({
                      ...editRulesData,
                      access_rules: { ...editRulesData.access_rules, parking_allocated: !!checked },
                    })
                  }
                />
                <span>Resident / Visitor Allocated Parking</span>
              </label>
            </div>
          </div>

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm">
              Save Changes
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
