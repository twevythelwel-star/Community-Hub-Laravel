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
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Calendar,
  Check,
  Clock,
  Info,
  Lock,
  RefreshCw,
  ShieldCheck,
  Sparkles,
  UserCheck,
} from 'lucide-react';
import {
  AccessPermissions,
  DURATION_PRESETS,
  RECURRING_WORKER_TEMPLATES,
} from './delegation-types';

interface AuthorizePersonModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  formData: any;
  setFormData: React.Dispatch<React.SetStateAction<any>>;
  onSubmit: (e: React.FormEvent) => void;
  properties: Array<{ id: number; lot_number: string; street_address: string }>;
  isShortTerm: boolean;
  isRenter: boolean;
  permissions?: AccessPermissions;
  applyCategoryDefaults: (catKey: string) => void;
  handleSelectDurationPreset: (presetKey: string) => void;
  handleApplyRecurringWorkerTemplate: (template: (typeof RECURRING_WORKER_TEMPLATES)[0]) => void;
  setEndOfYearExpiration: (target: 'form' | 'edit') => void;
  applyQuickSchedule: (shiftKey: string, target: 'form' | 'edit') => void;
}

export function DelegationAuthorizeModal({
  isOpen,
  onOpenChange,
  formData,
  setFormData,
  onSubmit,
  properties,
  isShortTerm,
  isRenter,
  permissions,
  applyCategoryDefaults,
  handleSelectDurationPreset,
  handleApplyRecurringWorkerTemplate,
  setEndOfYearExpiration,
  applyQuickSchedule,
}: AuthorizePersonModalProps) {
  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto bg-card/95 border-border/60">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-lg">
            <UserCheck className="w-5 h-5 text-primary" />
            <span>Authorize Person around Property</span>
          </DialogTitle>
          <DialogDescription className="text-xs">
            Grant dedicated credentials, amenity access, and security rules for individuals around your residence.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={onSubmit} className="space-y-4 pt-2">
          {/* Category Selector Tabs */}
          <div className="space-y-1.5">
            <Label className="text-xs font-semibold">Select Access Category</Label>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
              {(isShortTerm ? [
                { key: 'visitors', label: 'Pre-Cleared Visitor', code: 'GP-VIS' },
              ] : isRenter ? [
                { key: 'long_term_occupants', label: 'Co-Occupant / Family', code: 'GP-LTO' },
                { key: 'visitors', label: 'Pre-Cleared Visitor', code: 'GP-VIS' },
                { key: 'domestic_staff', label: 'Staff Member', code: 'GP-HST' },
                { key: 'contractors', label: 'Contractor', code: 'GP-CON' },
                { key: 'legacy_contacts', label: 'Emergency Contact', code: 'GP-DEL' },
              ] : [
                { key: 'long_term_occupants', label: 'Long-term occupant', code: 'GP-LTO' },
                { key: 'legacy_contacts', label: 'Legacy contact', code: 'GP-DEL' },
                { key: 'family_members', label: 'Family member', code: 'GP-DEL' },
                { key: 'caregivers', label: 'Caregiver', code: 'GP-DEL' },
                { key: 'property_managers', label: 'Property manager', code: 'GP-DEL' },
                { key: 'domestic_staff', label: 'Domestic staff', code: 'GP-HST' },
                { key: 'contractors', label: 'Contractor', code: 'GP-CON' },
              ]).map((cat) => (
                <button
                  key={cat.key}
                  type="button"
                  onClick={() => applyCategoryDefaults(cat.key)}
                  className={`p-2.5 rounded-lg border text-left transition-all ${
                    formData.categoryKey === cat.key
                      ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary'
                      : 'border-border/40 hover:border-border text-muted-foreground bg-muted/20'
                  }`}
                >
                  <div className="text-[10px] font-mono font-bold text-primary">{cat.code}</div>
                  <div className="text-xs font-semibold mt-0.5">{cat.label}</div>
                </button>
              ))}
            </div>
          </div>

          {/* Recurring Provider Quick Presets */}
          {!isShortTerm && (
            <div className="space-y-1.5 p-3 rounded-xl border border-primary/20 bg-gradient-to-r from-primary/5 via-background to-muted/20">
              <div className="flex items-center justify-between">
                <Label className="text-xs font-semibold flex items-center gap-1.5 text-foreground">
                  <RefreshCw className="w-3.5 h-3.5 text-primary" />
                  <span>Recurring Provider Presets</span>
                </Label>
                <span className="text-[10px] text-muted-foreground">
                  Mon–Fri (8am–5pm) • Valid until Dec 31 • Reusable QR
                </span>
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-1">
                {RECURRING_WORKER_TEMPLATES.map((tmpl) => {
                  const Icon = tmpl.icon;
                  const isCurrent = formData.relationship === tmpl.relationship;
                  return (
                    <button
                      key={tmpl.name}
                      type="button"
                      onClick={() => handleApplyRecurringWorkerTemplate(tmpl)}
                      className={`flex items-start gap-2 p-2 rounded-lg border text-left transition-all text-xs ${
                        isCurrent
                          ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary shadow-sm'
                          : 'border-border/40 hover:border-border text-muted-foreground bg-card/60'
                      }`}
                    >
                      <div className="p-1 rounded bg-muted text-primary mt-0.5 shrink-0">
                        <Icon className="w-3.5 h-3.5" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="font-semibold text-foreground truncate text-[11px]">{tmpl.name}</div>
                        <div className="text-[9px] text-muted-foreground font-mono">{tmpl.badge}</div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* Identity Information */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
            <div className="space-y-1">
              <Label htmlFor="name" className="text-xs font-medium">Full Name *</Label>
              <Input
                id="name"
                required
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g. Michael Brown"
                className="h-9 text-xs"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="email" className="text-xs font-medium">Email Address *</Label>
              <Input
                id="email"
                type="email"
                required
                value={formData.email}
                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                placeholder="e.g. michael@example.com"
                className="h-9 text-xs"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="phone" className="text-xs font-medium">Phone Number</Label>
              <Input
                id="phone"
                value={formData.phone}
                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                placeholder="+1 (876) 555-0144"
                className="h-9 text-xs"
              />
            </div>

            <div className="space-y-1">
              <Label htmlFor="relationship" className="text-xs font-medium">Relationship *</Label>
              <Input
                id="relationship"
                required
                value={formData.relationship}
                onChange={(e) => setFormData({ ...formData, relationship: e.target.value })}
                placeholder="e.g. Adult Son, Nurse, Gardener"
                className="h-9 text-xs"
              />
            </div>
          </div>

          {/* Access Duration Presets */}
          <div className="space-y-2 pt-1">
            <div className="flex items-center justify-between">
              <Label className="text-xs font-semibold flex items-center gap-1.5">
                <Clock className="w-3.5 h-3.5 text-primary" />
                <span>Access Duration Preset</span>
              </Label>
              <span className="text-[10px] text-muted-foreground">
                Enforces automatic gate pass expiration
              </span>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
              {DURATION_PRESETS.filter((preset) => {
                if (isShortTerm) {
                  return !['manual_revocation', 'recurring'].includes(preset.key);
                }
                if (isRenter) {
                  return preset.key !== 'manual_revocation';
                }
                return true;
              }).map((preset) => {
                const isSelected = formData.duration_type === preset.key;
                const Icon = preset.icon;
                return (
                  <button
                    key={preset.key}
                    type="button"
                    onClick={() => handleSelectDurationPreset(preset.key)}
                    className={`flex flex-col text-left p-2.5 rounded-lg border transition-all text-xs ${
                      isSelected
                        ? 'border-primary bg-primary/10 text-foreground ring-1 ring-primary shadow-sm'
                        : 'border-border/40 hover:border-border text-muted-foreground bg-muted/20'
                    }`}
                  >
                    <div className="flex items-center justify-between w-full mb-1">
                      <span className="font-semibold text-foreground flex items-center gap-1.5">
                        <Icon className={`w-3.5 h-3.5 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`} />
                        {preset.label}
                      </span>
                      {isSelected && <Check className="w-3.5 h-3.5 text-primary" />}
                    </div>
                    <span className="text-[10px] text-muted-foreground leading-snug">
                      {preset.description}
                    </span>
                  </button>
                );
              })}
            </div>

            {/* Duration Guidance Banner */}
            <div className="p-2.5 rounded-lg border border-primary/20 bg-primary/5 text-[11px] flex items-start gap-2 text-muted-foreground">
              <Info className="w-3.5 h-3.5 text-primary shrink-0 mt-0.5" />
              <span>
                {formData.duration_type === '2_hours' && (
                  <span><strong className="text-foreground">2-Hour Rapid Pass:</strong> Pass valid for 2 hours from issue time. Ideal for cleaners, deliveries, and quick repairs.</span>
                )}
                {formData.duration_type === '1_day' && (
                  <span><strong className="text-foreground">1-Day Day Pass:</strong> Pass valid for today until 23:59:59. Ideal for day cleaners, babysitters, and party guests.</span>
                )}
                {formData.duration_type === '1_week' && (
                  <span><strong className="text-foreground">1-Week Short Term:</strong> Pass valid for 7 consecutive days through midnight. Ideal for visiting relatives, painting crews, or contractors.</span>
                )}
                {formData.duration_type === 'recurring' && (
                  <span><strong className="text-foreground">Recurring Service Provider Shift:</strong> Instead of repeatedly creating QR codes, the person receives a single persistent credential valid until <strong>December 31</strong>. Gate entry is strictly enforced on permitted days within shift hours.</span>
                )}
                {formData.duration_type === 'custom' && (
                  <span><strong className="text-foreground">Custom Date Range:</strong> Access active between the selected start and expiration dates.</span>
                )}
                {formData.duration_type === 'manual_revocation' && (
                  <span><strong className="text-foreground">Ongoing Until Revoked:</strong> Credential remains valid indefinitely without auto-expiration until you manually click Revoke.</span>
                )}
              </span>
            </div>

            {formData.duration_type === 'recurring' && (
              <div className="p-3 rounded-lg border border-emerald-500/30 bg-emerald-500/5 space-y-2.5">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-1.5 text-xs font-semibold text-emerald-400">
                    <Sparkles className="w-3.5 h-3.5" />
                    <span>Recurring Service Provider Quick Presets</span>
                  </div>
                  <span className="text-[10px] text-muted-foreground">Persistent QR valid until Dec 31</span>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                  {RECURRING_WORKER_TEMPLATES.map((tmpl) => {
                    const Icon = tmpl.icon;
                    const isMatched = formData.relationship === tmpl.relationship;
                    return (
                      <button
                        key={tmpl.name}
                        type="button"
                        onClick={() => handleApplyRecurringWorkerTemplate(tmpl)}
                        className={`p-2 rounded-lg border text-left transition-all flex flex-col justify-between ${
                          isMatched
                            ? 'border-emerald-500 bg-emerald-500/15 text-foreground ring-1 ring-emerald-500'
                            : 'border-border/40 hover:border-emerald-500/40 bg-card/60 text-muted-foreground'
                        }`}
                      >
                        <div>
                          <div className="flex items-center justify-between mb-1">
                            <span className="text-xs font-semibold text-foreground flex items-center gap-1.5 truncate">
                              <Icon className="w-3.5 h-3.5 text-emerald-400 shrink-0" />
                              <span className="truncate">{tmpl.name}</span>
                            </span>
                            {isMatched && <Check className="w-3 h-3 text-emerald-400 shrink-0" />}
                          </div>
                          <p className="text-[10px] text-muted-foreground line-clamp-1">{tmpl.desc}</p>
                        </div>
                        <div className="mt-1.5 inline-flex items-center text-[9px] font-medium text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20 w-fit">
                          {tmpl.badge}
                        </div>
                      </button>
                    );
                  })}
                </div>
              </div>
            )}
          </div>

          {/* Timeframe & Property */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
            <div className="space-y-1">
              <Label className="text-xs font-medium">Property</Label>
              <Select
                value={formData.property_id}
                onValueChange={(val) => setFormData({ ...formData, property_id: val })}
              >
                <SelectTrigger className="h-9 text-xs">
                  <SelectValue placeholder="Select Property" />
                </SelectTrigger>
                <SelectContent>
                  {properties.map((p) => (
                    <SelectItem key={p.id} value={String(p.id)}>
                      Lot {p.lot_number} ({p.street_address})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label htmlFor="starts_at" className="text-xs font-medium">Effective Start Date</Label>
              <Input
                id="starts_at"
                type="date"
                value={formData.starts_at}
                onChange={(e) => setFormData({ ...formData, starts_at: e.target.value })}
                className="h-9 text-xs"
              />
            </div>

            <div className="space-y-1">
              <div className="flex items-center justify-between">
                <Label htmlFor="expires_at" className="text-xs font-medium">Expiration Date</Label>
                {formData.duration_type !== 'manual_revocation' && (
                  <button
                    type="button"
                    onClick={() => setEndOfYearExpiration('form')}
                    className="text-[10px] text-emerald-400 hover:text-emerald-300 font-medium flex items-center gap-0.5 hover:underline"
                    title="Set expiration to December 31"
                  >
                    <Calendar className="w-2.5 h-2.5" />
                    Set Dec 31
                  </button>
                )}
              </div>
              {formData.duration_type === 'manual_revocation' ? (
                <div className="h-9 flex items-center px-3 rounded-md border border-border/40 bg-muted/40 text-xs text-muted-foreground">
                  <Lock className="w-3 h-3 mr-1.5 text-teal-400" />
                  Until Manually Revoked
                </div>
              ) : formData.duration_type === '2_hours' ? (
                <div className="h-9 flex items-center px-3 rounded-md border border-border/40 bg-muted/40 text-xs text-muted-foreground">
                  <Clock className="w-3 h-3 mr-1.5 text-amber-500" />
                  +2 Hours from issuance
                </div>
              ) : (
                <Input
                  id="expires_at"
                  type="date"
                  value={formData.expires_at}
                  max={isShortTerm && permissions?.checkoutDate ? permissions.checkoutDate : (isRenter && permissions?.leaseEndDate ? permissions.leaseEndDate : undefined)}
                  onChange={(e) => setFormData({ ...formData, expires_at: e.target.value })}
                  className="h-9 text-xs"
                />
              )}
            </div>
          </div>

          {/* Operational Schedule & Access Hours */}
          <div className="p-3 rounded-lg border border-border/40 bg-muted/20 space-y-2.5">
            <div className="flex items-center justify-between">
              <Label className="text-xs font-semibold">Operational Schedule Rules</Label>
              <span className="text-[10px] text-muted-foreground font-normal">Enforced at Gate Scanner</span>
            </div>

            <div className="flex items-center justify-between gap-1 flex-wrap pb-1.5 border-b border-border/20">
              <span className="text-[11px] text-muted-foreground font-medium">Quick Shifts:</span>
              <div className="flex flex-wrap gap-1">
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('mon_fri', 'form')}
                  className="text-[10px] px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Mon–Fri (8am–5pm)
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('mon_sat', 'form')}
                  className="text-[10px] px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Mon–Sat (6am–8pm)
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('tue_thu', 'form')}
                  className="text-[10px] px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Tue & Thu
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('weekends', 'form')}
                  className="text-[10px] px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  Weekends
                </button>
                <button
                  type="button"
                  onClick={() => applyQuickSchedule('everyday', 'form')}
                  className="text-[10px] px-2 py-0.5 rounded bg-muted hover:bg-muted/80 text-foreground border border-border/50 transition-colors"
                >
                  24/7 Everyday
                </button>
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day) => {
                const isChecked = formData.access_rules.allowed_days.includes(day);
                return (
                  <label
                    key={day}
                    className={`flex items-center gap-1.5 px-2.5 py-1 rounded text-xs border cursor-pointer ${
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
                          ? [...formData.access_rules.allowed_days, day]
                          : formData.access_rules.allowed_days.filter((d: string) => d !== day);
                        setFormData({
                          ...formData,
                          access_rules: { ...formData.access_rules, allowed_days: days },
                        });
                      }}
                    />
                    <span>{day}</span>
                  </label>
                );
              })}
            </div>

            <div className="grid grid-cols-2 gap-3 pt-1">
              <div className="space-y-1">
                <Label className="text-[11px] text-muted-foreground">Entry Start Time</Label>
                <Input
                  type="time"
                  value={formData.access_rules.entry_start_time}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, entry_start_time: e.target.value },
                    })
                  }
                  className="h-8 text-xs"
                />
              </div>
              <div className="space-y-1">
                <Label className="text-[11px] text-muted-foreground">Entry End Time</Label>
                <Input
                  type="time"
                  value={formData.access_rules.entry_end_time}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, entry_end_time: e.target.value },
                    })
                  }
                  className="h-8 text-xs"
                />
              </div>
            </div>

            <div className="pt-2">
              <label className="flex items-center gap-2 cursor-pointer">
                <Checkbox
                  checked={formData.access_rules.requires_id_verification}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      access_rules: {
                        ...formData.access_rules,
                        requires_id_verification: !!checked,
                      },
                    })
                  }
                />
                <span className="text-xs text-foreground font-medium">
                  Require security guard to inspect physical photo ID on scanner check-in
                </span>
              </label>
            </div>
          </div>

          {/* Amenity & Property Privileges */}
          <div className="space-y-1.5 pt-1">
            <Label className="text-xs font-semibold">Amenity & Property Privileges</Label>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
              <label className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 text-xs cursor-pointer">
                <Checkbox
                  checked={formData.access_rules.pool_access}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, pool_access: !!checked },
                    })
                  }
                />
                <span>Swimming Pool</span>
              </label>
              <label className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 text-xs cursor-pointer">
                <Checkbox
                  checked={formData.access_rules.gym_access}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, gym_access: !!checked },
                    })
                  }
                />
                <span>Fitness Gym</span>
              </label>
              <label className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 text-xs cursor-pointer">
                <Checkbox
                  checked={formData.access_rules.clubhouse_access}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, clubhouse_access: !!checked },
                    })
                  }
                />
                <span>Clubhouse</span>
              </label>
              <label className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 text-xs cursor-pointer">
                <Checkbox
                  checked={formData.access_rules.parking_allocated}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, parking_allocated: !!checked },
                    })
                  }
                />
                <span>Allocated Parking</span>
              </label>
              <label className="flex items-center gap-2 p-2 rounded border border-border/30 bg-muted/10 text-xs cursor-pointer">
                <Checkbox
                  checked={formData.access_rules.can_invite_visitors}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      access_rules: { ...formData.access_rules, can_invite_visitors: !!checked },
                    })
                  }
                />
                <span>Can Pre-Clear Guests</span>
              </label>
            </div>
          </div>

          {/* Security Notes */}
          <div className="space-y-1 pt-1">
            <Label htmlFor="notes" className="text-xs font-medium">Security & Gate Instructions</Label>
            <Textarea
              id="notes"
              rows={2}
              value={formData.security_notes}
              onChange={(e) => setFormData({ ...formData, security_notes: e.target.value })}
              placeholder="Optional guidance for estate guard and security post..."
              className="text-xs"
            />
          </div>

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm" className="gap-2">
              <ShieldCheck className="w-4 h-4" />
              <span>Issue Authorization & QR</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
