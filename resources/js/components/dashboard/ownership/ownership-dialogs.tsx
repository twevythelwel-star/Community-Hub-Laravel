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
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { QrCode, UserSquare2 } from 'lucide-react';
import { HierarchyPillarItem, OwnershipProps } from './ownership-types';

export interface AddRepresentativeDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  activeEntity: OwnershipProps['activeEntity'];
  availableRoles: OwnershipProps['availableRoles'];
  repForm: {
    full_name: string;
    role: string;
    email: string;
    phone: string;
    id_document_type: string;
    id_document_number: string;
    is_primary_contact: boolean;
    is_signatory: boolean;
  };
  onRepFormChange: (form: any) => void;
  onSubmit: (e: React.FormEvent) => void;
}

export function AddRepresentativeDialog({
  open,
  onOpenChange,
  activeEntity,
  availableRoles,
  repForm,
  onRepFormChange,
  onSubmit,
}: AddRepresentativeDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[480px]">
        <form onSubmit={onSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="text-base font-semibold">
              Appoint Corporate Representative
            </DialogTitle>
            <DialogDescription className="text-xs">
              Designate an authorized person with specific operational powers for {activeEntity?.legal_name}.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2 text-xs">
            <div className="space-y-1">
              <Label className="text-xs font-medium">Full Legal Name</Label>
              <Input
                required
                value={repForm.full_name}
                onChange={(e) => onRepFormChange({ ...repForm, full_name: e.target.value })}
                placeholder="e.g. Marcus Sterling"
                className="h-8 text-xs"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label className="text-xs font-medium">Corporate Role</Label>
                <Select
                  value={repForm.role}
                  onValueChange={(val) => onRepFormChange({ ...repForm, role: val })}
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder="Select Role" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableRoles.map((r) => (
                      <SelectItem key={r.value} value={r.value} className="text-xs">
                        {r.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <Label className="text-xs font-medium">ID Document Type</Label>
                <Input
                  value={repForm.id_document_type}
                  onChange={(e) => onRepFormChange({ ...repForm, id_document_type: e.target.value })}
                  placeholder="Passport / Driver's Licence"
                  className="h-8 text-xs"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label className="text-xs font-medium">Email Address</Label>
                <Input
                  type="email"
                  value={repForm.email}
                  onChange={(e) => onRepFormChange({ ...repForm, email: e.target.value })}
                  placeholder="representative@company.com"
                  className="h-8 text-xs"
                />
              </div>

              <div className="space-y-1">
                <Label className="text-xs font-medium">Phone Number</Label>
                <Input
                  value={repForm.phone}
                  onChange={(e) => onRepFormChange({ ...repForm, phone: e.target.value })}
                  placeholder="+1 (876) 555-0199"
                  className="h-8 text-xs"
                />
              </div>
            </div>

            <div className="pt-2 flex items-center gap-4">
              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <input
                  type="checkbox"
                  checked={repForm.is_signatory}
                  onChange={(e) => onRepFormChange({ ...repForm, is_signatory: e.target.checked })}
                  className="rounded border-input text-primary"
                />
                <span>Authorized Signatory</span>
              </label>

              <label className="flex items-center gap-2 cursor-pointer text-xs">
                <input
                  type="checkbox"
                  checked={repForm.is_primary_contact}
                  onChange={(e) => onRepFormChange({ ...repForm, is_primary_contact: e.target.checked })}
                  className="rounded border-input text-primary"
                />
                <span>Primary Point of Contact</span>
              </label>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm">
              Confirm Appointment
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export interface AddHouseholdMemberDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  memberForm: {
    name: string;
    email: string;
    phone: string;
    role_in_household: string;
    relationship_label: string;
    permissions: string[];
  };
  onMemberFormChange: (form: any) => void;
  onSubmit: (e: React.FormEvent) => void;
}

export function AddHouseholdMemberDialog({
  open,
  onOpenChange,
  memberForm,
  onMemberFormChange,
  onSubmit,
}: AddHouseholdMemberDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[480px]">
        <form onSubmit={onSubmit} className="space-y-4">
          <DialogHeader>
            <DialogTitle className="text-base font-semibold">
              Register Household Member
            </DialogTitle>
            <DialogDescription className="text-xs">
              Add a family member, dependent, or long-term resident to this household.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3 py-2 text-xs">
            <div className="space-y-1">
              <Label className="text-xs font-medium">Full Name</Label>
              <Input
                required
                value={memberForm.name}
                onChange={(e) => onMemberFormChange({ ...memberForm, name: e.target.value })}
                placeholder="e.g. Sarah Sterling"
                className="h-8 text-xs"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label className="text-xs font-medium">Household Role</Label>
                <Select
                  value={memberForm.role_in_household}
                  onValueChange={(val) =>
                    onMemberFormChange({
                      ...memberForm,
                      role_in_household: val,
                      relationship_label: val,
                    })
                  }
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder="Role" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="spouse" className="text-xs">Spouse / Partner</SelectItem>
                    <SelectItem value="child" className="text-xs">Child / Dependent</SelectItem>
                    <SelectItem value="long_term_occupant" className="text-xs">Long-Term Occupant</SelectItem>
                    <SelectItem value="caregiver" className="text-xs">Caregiver</SelectItem>
                    <SelectItem value="other" className="text-xs">Other Resident</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <Label className="text-xs font-medium">Relationship Label</Label>
                <Input
                  value={memberForm.relationship_label}
                  onChange={(e) => onMemberFormChange({ ...memberForm, relationship_label: e.target.value })}
                  placeholder="e.g. Daughter"
                  className="h-8 text-xs"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label className="text-xs font-medium">Email (Optional)</Label>
                <Input
                  type="email"
                  value={memberForm.email}
                  onChange={(e) => onMemberFormChange({ ...memberForm, email: e.target.value })}
                  placeholder="sarah@example.com"
                  className="h-8 text-xs"
                />
              </div>

              <div className="space-y-1">
                <Label className="text-xs font-medium">Phone (Optional)</Label>
                <Input
                  value={memberForm.phone}
                  onChange={(e) => onMemberFormChange({ ...memberForm, phone: e.target.value })}
                  placeholder="+1 (876) 555-0123"
                  className="h-8 text-xs"
                />
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" size="sm">
              Add to Household
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export interface QrTokenDialogProps {
  token: string | null;
  onClose: () => void;
}

export function QrTokenDialog({ token, onClose }: QrTokenDialogProps) {
  if (!token) return null;

  return (
    <Dialog open={!!token} onOpenChange={() => onClose()}>
      <DialogContent className="sm:max-w-xs text-center space-y-3">
        <DialogHeader>
          <DialogTitle className="text-base font-semibold">
            Digital Credential Token
          </DialogTitle>
          <DialogDescription className="text-xs">
            Scan at the gate scanner to verify entry.
          </DialogDescription>
        </DialogHeader>
        <div className="p-6 bg-white rounded-xl flex items-center justify-center border shadow-inner">
          <QrCode className="h-32 w-32 text-black" />
        </div>
        <div className="font-mono text-xs bg-muted p-2 rounded break-all">
          {token}
        </div>
      </DialogContent>
    </Dialog>
  );
}

export interface InvariantInspectorDialogProps {
  item: HierarchyPillarItem | null;
  onClose: () => void;
}

export function InvariantInspectorDialog({ item, onClose }: InvariantInspectorDialogProps) {
  if (!item) return null;

  return (
    <Dialog open={!!item} onOpenChange={() => onClose()}>
      <DialogContent className="sm:max-w-xl space-y-4">
        <DialogHeader className="pb-2 border-b">
          <div className="flex items-center gap-2">
            <Badge variant="outline" className="text-[10px] font-mono capitalize">
              {item.pillar.replace('_', ' ')}
            </Badge>
            <Badge className="bg-primary/10 text-primary text-[10px]">
              {item.answers.relationship.label}
            </Badge>
          </div>
          <DialogTitle className="text-lg font-bold flex items-center gap-2 pt-1">
            <UserSquare2 className="h-5 w-5 text-primary" />
            Authorization Invariant Inspector
          </DialogTitle>
          <DialogDescription className="text-xs">
            Complete enterprise audit trail resolving the 6 mandatory authorization questions.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 text-xs">
          {/* Question 1 */}
          <div className="p-3 rounded-lg bg-muted/40 border space-y-1">
            <div className="font-semibold text-foreground flex items-center gap-1.5 text-primary">
              <span>1.</span> Who is this person?
            </div>
            <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground pt-1 pl-4">
              <div>Name: <span className="font-medium text-foreground">{item.answers.who.name}</span></div>
              <div>ID / Credential: <span className="font-mono text-foreground">{item.answers.who.id_document}</span></div>
              {item.answers.who.email && <div>Email: <span className="text-foreground">{item.answers.who.email}</span></div>}
              {item.answers.who.phone && <div>Phone: <span className="text-foreground">{item.answers.who.phone}</span></div>}
            </div>
          </div>

          {/* Question 2 */}
          <div className="p-3 rounded-lg bg-muted/40 border space-y-1">
            <div className="font-semibold text-foreground flex items-center gap-1.5 text-primary">
              <span>2.</span> What legal/functional relationship do they have?
            </div>
            <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground pt-1 pl-4">
              <div>Relationship: <span className="font-medium text-foreground">{item.answers.relationship.label}</span></div>
              <div>Category: <span className="font-mono text-foreground capitalize">{item.functional_type}</span></div>
              <div>Signatory Authority: <span className="text-foreground">{item.answers.relationship.is_signatory ? 'Yes (Legal Signatory)' : 'No'}</span></div>
              <div>Primary Contact: <span className="text-foreground">{item.answers.relationship.is_primary_contact ? 'Yes' : 'No'}</span></div>
            </div>
          </div>

          {/* Question 3 */}
          <div className="p-3 rounded-lg bg-muted/40 border space-y-1">
            <div className="font-semibold text-foreground flex items-center gap-1.5 text-primary">
              <span>3.</span> Which entity/property does that relationship apply to?
            </div>
            <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground pt-1 pl-4">
              <div>Owning Entity: <span className="font-medium text-foreground">{item.answers.scope.entity_name}</span></div>
              <div>Entity Type: <span className="text-foreground">{item.answers.scope.entity_type}</span></div>
              <div className="sm:col-span-2">
                Scope: <span className="font-medium text-foreground">{item.answers.scope.properties.join(', ')}</span>
              </div>
            </div>
          </div>

          {/* Question 4 */}
          <div className="p-3 rounded-lg bg-muted/40 border space-y-1">
            <div className="font-semibold text-foreground flex items-center gap-1.5 text-primary">
              <span>4.</span> What are they allowed to do?
            </div>
            <div className="pt-1 pl-4 space-y-1.5">
              <div className="text-muted-foreground text-[11px]">{item.answers.permissions.summary}</div>
              <div className="flex flex-wrap gap-1">
                {item.answers.permissions.granted.map((perm, idx) => (
                  <Badge key={idx} variant="secondary" className="text-[10px] font-mono">
                    {perm}
                  </Badge>
                ))}
              </div>
            </div>
          </div>

          {/* Question 5 */}
          <div className="p-3 rounded-lg bg-muted/40 border space-y-1">
            <div className="font-semibold text-foreground flex items-center gap-1.5 text-primary">
              <span>5.</span> When does that authorization expire?
            </div>
            <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground pt-1 pl-4">
              <div>Valid From: <span className="text-foreground">{item.answers.validity.valid_from}</span></div>
              <div>Expires At: <span className="font-semibold text-foreground">{item.answers.validity.valid_until}</span></div>
              <div>Status: <span className="font-medium text-emerald-600 dark:text-emerald-400 capitalize">{item.answers.validity.status}</span></div>
            </div>
          </div>

          {/* Question 6 */}
          <div className="p-3 rounded-lg bg-muted/40 border space-y-1">
            <div className="font-semibold text-foreground flex items-center gap-1.5 text-primary">
              <span>6.</span> Who authorized them?
            </div>
            <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground pt-1 pl-4">
              <div>Authorizing Principal: <span className="font-medium text-foreground">{item.answers.authorizer.name}</span></div>
              <div>Authority: <span className="text-foreground">{item.answers.authorizer.authority}</span></div>
              <div className="sm:col-span-2">Source Document: <span className="font-mono text-foreground">{item.answers.authorizer.source}</span></div>
            </div>
          </div>
        </div>

        <DialogFooter className="pt-2 border-t">
          <Button type="button" size="sm" onClick={() => onClose()}>
            Close Inspector
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
