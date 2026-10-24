import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  CheckCircle2,
  Users,
  Briefcase,
  Landmark,
  FileText,
  Lock,
  ShieldAlert,
} from 'lucide-react';
import { OwnershipProps } from './ownership-types';

export interface OwnershipOverviewTabProps {
  ownershipType: OwnershipProps['ownershipType'];
  activeEntity: OwnershipProps['activeEntity'];
  verification: OwnershipProps['verification'];
  beneficialOwnership: OwnershipProps['beneficialOwnership'];
  canViewBeneficialOwnership: boolean;
  getEntityIcon: (type: string) => React.ReactNode;
}

export function OwnershipOverviewTab({
  ownershipType,
  activeEntity,
  verification,
  beneficialOwnership,
  canViewBeneficialOwnership,
  getEntityIcon,
}: OwnershipOverviewTabProps) {
  return (
    <div className="grid gap-6 md:grid-cols-3">
      {/* Card 1: Legal Entity Profile */}
      <Card className="md:col-span-2 shadow-sm border-border/80">
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <CardTitle className="text-base font-semibold flex items-center gap-2">
              {getEntityIcon(ownershipType)}
              {activeEntity?.legal_name || 'Individual Property Owner'}
            </CardTitle>
            <Badge variant={verification?.is_verified ? 'default' : 'outline'} className="capitalize text-xs">
              {verification?.verification_status || 'Unverified'}
            </Badge>
          </div>
          <CardDescription className="text-xs">
            {ownershipType === 'company' && 'Companies Office of Jamaica (COJ) corporate registry record.'}
            {ownershipType === 'individual' && 'National Land Agency (NLA) registered title & primary occupancy.'}
            {ownershipType === 'trust' && 'Deed of Trust fiduciary settlement record.'}
            {ownershipType === 'estate' && 'Supreme Court probate court administration.'}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 pt-1">
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3.5 bg-muted/30 rounded-xl border border-border/50 text-xs">
            <div>
              <div className="text-muted-foreground font-medium">Structure</div>
              <div className="font-semibold text-foreground pt-0.5 capitalize">
                {activeEntity?.entity_type_label || ownershipType}
              </div>
            </div>
            <div>
              <div className="text-muted-foreground font-medium">
                {ownershipType === 'company' ? 'COJ Registration' : ownershipType === 'estate' ? 'Probate Suit #' : 'Reference / Tax ID'}
              </div>
              <div className="font-mono font-medium text-foreground pt-0.5">
                {activeEntity?.registration_number || activeEntity?.tax_id || 'NLA-VOL-1402/FOL-88'}
              </div>
            </div>
            <div>
              <div className="text-muted-foreground font-medium">Jurisdiction</div>
              <div className="font-medium text-foreground pt-0.5">
                {activeEntity?.jurisdiction || 'Jamaica'}
              </div>
            </div>
            <div>
              <div className="text-muted-foreground font-medium">Status</div>
              <div className="font-medium text-emerald-600 dark:text-emerald-400 pt-0.5 flex items-center gap-1">
                <CheckCircle2 className="h-3 w-3" />
                Good Standing
              </div>
            </div>
          </div>

          {/* Company specifics: Directors & Corporate Shareholders */}
          {ownershipType === 'company' && verification?.corporate_profile && (
            <div className="space-y-3 pt-2">
              <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Public Corporate Registry Filings (COJ)
              </div>
              <div className="grid sm:grid-cols-2 gap-3 text-xs">
                <div className="p-3 rounded-lg border bg-background/50 space-y-1.5">
                  <div className="font-semibold text-foreground flex items-center gap-1.5">
                    <Users className="h-3.5 w-3.5 text-primary" />
                    Registered Directors ({verification.corporate_profile.directors?.length || 0})
                  </div>
                  <ul className="space-y-1 text-muted-foreground">
                    {verification.corporate_profile.directors?.map((dir, idx) => (
                      <li key={idx} className="flex items-center justify-between">
                        <span>{dir.name}</span>
                        <span className="text-[10px] bg-muted px-1.5 py-0.5 rounded font-mono">{dir.position || 'Director'}</span>
                      </li>
                    ))}
                  </ul>
                </div>
                <div className="p-3 rounded-lg border bg-background/50 space-y-1.5">
                  <div className="font-semibold text-foreground flex items-center gap-1.5">
                    <Briefcase className="h-3.5 w-3.5 text-primary" />
                    Registered Corporate Shareholders
                  </div>
                  <ul className="space-y-1 text-muted-foreground">
                    {verification.corporate_profile.shareholders?.map((sh, idx) => (
                      <li key={idx} className="flex items-center justify-between">
                        <span>{sh.name}</span>
                        <span className="font-semibold text-foreground">{sh.percentage}%</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          )}

          {/* Trust specifics: Trustees & Deed */}
          {ownershipType === 'trust' && verification?.trust_profile && (
            <div className="p-3.5 rounded-xl border bg-background/50 space-y-2 text-xs">
              <div className="font-semibold text-foreground flex items-center gap-1.5">
                <Landmark className="h-3.5 w-3.5 text-amber-500" />
                Deed of Trust Settlement Information
              </div>
              <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground">
                <div>Deed Reg: <span className="font-mono text-foreground">{verification.trust_profile.trust_deed_reference || '#TR-2021-994'}</span></div>
                <div>Date of Execution: <span className="text-foreground">{verification.trust_profile.settlement_date || 'May 14, 2021'}</span></div>
              </div>
            </div>
          )}

          {/* Estate specifics: Probate Court Record */}
          {ownershipType === 'estate' && verification?.estate_profile && (
            <div className="p-3.5 rounded-xl border bg-background/50 space-y-2 text-xs">
              <div className="font-semibold text-foreground flex items-center gap-1.5">
                <FileText className="h-3.5 w-3.5 text-purple-500" />
                Supreme Court of Judicature of Jamaica — Probate Division
              </div>
              <div className="grid sm:grid-cols-2 gap-2 text-muted-foreground">
                <div>Suit Number: <span className="font-mono text-foreground">{verification.estate_profile.court_suit_number || '#PROB-2023-0182'}</span></div>
                <div>Grant: <span className="text-foreground">{verification.estate_profile.grant_type || 'Grant of Probate'}</span></div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Card 2: Beneficial Ownership Safeguard Card */}
      <Card className="shadow-sm border-border/80 flex flex-col justify-between">
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <CardTitle className="text-sm font-semibold flex items-center gap-1.5">
              <Lock className="h-4 w-4 text-amber-500" />
              Beneficial Ownership
            </CardTitle>
            <Badge variant="outline" className="text-[10px] bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200">
              Private & Confidential
            </Badge>
          </div>
          <CardDescription className="text-xs">
            Statutory confidentiality rules under the Companies Office of Jamaica.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3 pt-0">
          {canViewBeneficialOwnership && beneficialOwnership?.data ? (
            <div className="space-y-2.5">
              <div className="p-2.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 text-xs">
                <div className="font-semibold text-emerald-800 dark:text-emerald-300 flex items-center gap-1">
                  <CheckCircle2 className="h-3.5 w-3.5" />
                  Form 38 Declared (Director Access)
                </div>
                <div className="text-[11px] text-emerald-700/90 dark:text-emerald-400 mt-0.5">
                  Ref: {beneficialOwnership.data.declaration_reference || 'COJ-BO-2024'}
                </div>
              </div>
              <div className="space-y-1.5 text-xs">
                {beneficialOwnership.data.beneficial_owners?.map((owner, idx) => (
                  <div key={idx} className="p-2 rounded border bg-background flex items-center justify-between">
                    <div>
                      <div className="font-medium text-foreground">{owner.full_name}</div>
                      <div className="text-[10px] text-muted-foreground">{owner.nature_of_control || 'Direct Shareholder'}</div>
                    </div>
                    <span className="font-semibold text-xs text-primary">{owner.ownership_percentage}%</span>
                  </div>
                ))}
              </div>
            </div>
          ) : (
            /* Statutory Confidentiality Privacy Shield */
            <div className="p-3.5 rounded-xl bg-muted/60 border border-dashed text-xs space-y-2">
              <div className="flex items-start gap-2 text-muted-foreground">
                <ShieldAlert className="h-4 w-4 text-muted-foreground shrink-0 mt-0.5" />
                <div className="space-y-1">
                  <span className="font-semibold text-foreground block">
                    Statutory Privacy Shield
                  </span>
                  <p className="text-[11px] leading-relaxed">
                    {beneficialOwnership?.confidentiality_notice ||
                      'Beneficial ownership records are restricted under the Companies Act of Jamaica. They are never disclosed to ordinary residents or security guards.'}
                  </p>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
