import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  UserPlus,
  Trash2,
  QrCode,
} from 'lucide-react';
import { OwnershipProps } from './ownership-types';

export interface OwnershipPeopleTabProps {
  ownershipType: OwnershipProps['ownershipType'];
  activeEntity: OwnershipProps['activeEntity'];
  representatives: OwnershipProps['representatives'];
  household: OwnershipProps['household'];
  canManage: boolean;
  onOpenAddRep: () => void;
  onOpenAddMember: () => void;
  onRevokeRep: (personId: number) => void;
  onRemoveMember: (memberId: number) => void;
  onSelectToken: (token: string | null) => void;
}

export function OwnershipPeopleTab({
  ownershipType,
  activeEntity,
  representatives,
  household,
  canManage,
  onOpenAddRep,
  onOpenAddMember,
  onRevokeRep,
  onRemoveMember,
  onSelectToken,
}: OwnershipPeopleTabProps) {
  return (
    <div className="space-y-6">
      {/* If Company: Corporate Representatives */}
      {ownershipType === 'company' && (
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-foreground">
                Authorized Corporate Representatives
              </h2>
              <p className="text-xs text-muted-foreground">
                Directors, Officers, and designated managers authorized to act on behalf of {activeEntity?.legal_name}.
              </p>
            </div>
            {canManage && (
              <Button size="sm" onClick={onOpenAddRep} className="gap-1.5 text-xs">
                <UserPlus className="h-3.5 w-3.5" />
                Appoint Representative
              </Button>
            )}
          </div>

          {/* Representatives List */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {representatives.map((rep) => (
              <Card key={rep.id} className="border shadow-sm flex flex-col justify-between">
                <CardHeader className="p-4 pb-2">
                  <div className="flex items-start justify-between">
                    <div>
                      <CardTitle className="text-sm font-semibold text-foreground">
                        {rep.name}
                      </CardTitle>
                      <CardDescription className="text-xs font-medium text-primary flex items-center gap-1 mt-0.5">
                        {rep.role_label}
                        {rep.is_signatory && (
                          <Badge variant="outline" className="text-[10px] px-1 py-0 h-4 border-primary/30 text-primary">
                            Signatory
                          </Badge>
                        )}
                      </CardDescription>
                    </div>
                    <Badge variant={rep.is_active ? 'default' : 'secondary'} className="text-[10px]">
                      {rep.status}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="p-4 pt-1 space-y-3 text-xs">
                  <div className="space-y-1 text-muted-foreground">
                    {rep.email && <div>Email: <span className="text-foreground">{rep.email}</span></div>}
                    {rep.phone && <div>Phone: <span className="text-foreground">{rep.phone}</span></div>}
                    {rep.id_document_number && <div>ID: <span className="font-mono text-foreground">{rep.id_document_number}</span></div>}
                  </div>

                  {/* Granular Property Scope */}
                  <div className="pt-2 border-t text-[11px]">
                    <span className="text-muted-foreground block mb-1">Assigned Property Scope:</span>
                    {rep.authorized_properties.length > 0 ? (
                      <div className="flex flex-wrap gap-1">
                        {rep.authorized_properties.map((p) => (
                          <Badge key={p.id} variant="secondary" className="text-[10px] font-mono">
                            {p.lot_number}
                          </Badge>
                        ))}
                      </div>
                    ) : (
                      <span className="text-xs text-foreground font-medium">All Company Properties</span>
                    )}
                  </div>

                  {canManage && (
                    <div className="pt-2 flex justify-end">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onRevokeRep(rep.id)}
                        className="text-xs text-destructive hover:text-destructive hover:bg-destructive/10 h-7 px-2"
                      >
                        <Trash2 className="h-3 w-3 mr-1" />
                        Revoke
                      </Button>
                    </div>
                  )}
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      )}

      {/* If Individual: Household & Family Members */}
      {ownershipType === 'individual' && household && (
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-foreground">
                Household & Family Members
              </h2>
              <p className="text-xs text-muted-foreground">
                Registered occupants living at {household.property_number} ({household.address}).
              </p>
            </div>

            <Button size="sm" onClick={onOpenAddMember} className="gap-1.5 text-xs">
              <UserPlus className="h-3.5 w-3.5" />
              Add Household Member
            </Button>
          </div>

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {household.members.map((member) => (
              <Card key={member.id} className="border shadow-sm flex flex-col justify-between">
                <CardHeader className="p-4 pb-2">
                  <div className="flex items-start justify-between">
                    <div>
                      <CardTitle className="text-sm font-semibold text-foreground">
                        {member.name}
                      </CardTitle>
                      <CardDescription className="text-xs font-medium text-emerald-600 dark:text-emerald-400 capitalize">
                        {member.relationship_label || member.role_in_household}
                      </CardDescription>
                    </div>
                    <Badge variant="outline" className="text-[10px]">
                      {member.pass_category}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent className="p-4 pt-1 space-y-3 text-xs">
                  <div className="space-y-1 text-muted-foreground">
                    {member.email && <div>Email: <span className="text-foreground">{member.email}</span></div>}
                    {member.phone && <div>Phone: <span className="text-foreground">{member.phone}</span></div>}
                    <div>Credential: <span className="text-foreground font-medium">{member.credential_label}</span></div>
                  </div>

                  {member.gate_pass && (
                    <div className="pt-2 border-t flex items-center justify-between">
                      <span className="text-[11px] font-mono text-muted-foreground">
                        Pass: {member.gate_pass.pass_id}
                      </span>
                      {member.gate_pass.token && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => onSelectToken(member.gate_pass?.token || null)}
                          className="h-6 text-[10px] gap-1 px-2"
                        >
                          <QrCode className="h-3 w-3" />
                          View QR
                        </Button>
                      )}
                    </div>
                  )}

                  <div className="pt-2 flex justify-end">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => onRemoveMember(member.id)}
                      className="text-xs text-destructive hover:text-destructive hover:bg-destructive/10 h-7 px-2"
                    >
                      <Trash2 className="h-3 w-3 mr-1" />
                      Remove
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      )}

      {/* If Trust or Estate: Appointees List */}
      {(ownershipType === 'trust' || ownershipType === 'estate') && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-lg font-semibold text-foreground">
                {ownershipType === 'trust' ? 'Appointed Trustees & Fiduciary Counsel' : 'Court-Appointed Executors & Administrators'}
              </h2>
              <p className="text-xs text-muted-foreground">
                Authorized legal appointees and representatives for {activeEntity?.legal_name}.
              </p>
            </div>
          </div>

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {representatives.map((rep) => (
              <Card key={rep.id} className="border shadow-sm p-4 space-y-3 text-xs">
                <div className="flex items-start justify-between">
                  <div>
                    <div className="font-semibold text-sm text-foreground">{rep.name}</div>
                    <div className="text-xs text-primary font-medium">{rep.role_label}</div>
                  </div>
                  <Badge variant="outline" className="text-[10px]">
                    {rep.status}
                  </Badge>
                </div>
                <div className="space-y-1 text-muted-foreground">
                  {rep.email && <div>Email: <span className="text-foreground">{rep.email}</span></div>}
                  {rep.phone && <div>Phone: <span className="text-foreground">{rep.phone}</span></div>}
                  <div>Signatory: <span className="text-foreground">{rep.is_signatory ? 'Yes (Sole & Joint)' : 'No'}</span></div>
                </div>
              </Card>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
