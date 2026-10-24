import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import {
  Building2,
  CheckCircle2,
  ShieldCheck,
  Scale,
  Lock,
  FileCheck,
  User,
} from 'lucide-react';
import {
  CompanyInfo,
  UserAuth,
  BeneficialOwnershipProfile,
} from './company-types';

interface CompanyVerificationModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  company: CompanyInfo;
  current_user_authorization: UserAuth;
  beneficial_ownership?: BeneficialOwnershipProfile;
  can_view_beneficial_ownership?: boolean;
}

export function CompanyVerificationModal({
  open,
  onOpenChange,
  company,
  current_user_authorization,
  beneficial_ownership,
  can_view_beneficial_ownership = false,
}: CompanyVerificationModalProps) {
  const [verificationTab, setVerificationTab] = useState<'coj' | 'ubo' | 'multi_entity'>('coj');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <ShieldCheck className="h-5 w-5 text-primary" />
            <DialogTitle className="text-lg font-bold">
              Ownership Verification & Regulatory Framework
            </DialogTitle>
          </div>
          <DialogDescription className="text-xs text-muted-foreground">
            Statutory audit standards and verification requirements for Company, Trust, Estate, and Individual ownership structures.
          </DialogDescription>
        </DialogHeader>

        <Tabs value={verificationTab} onValueChange={(v: any) => setVerificationTab(v)} className="w-full mt-2">
          <TabsList className="grid grid-cols-3 h-9 text-xs">
            <TabsTrigger value="coj">Corporate (Company)</TabsTrigger>
            <TabsTrigger value="ubo">Beneficial Ownership</TabsTrigger>
            <TabsTrigger value="multi_entity">Trust • Estate • Individual</TabsTrigger>
          </TabsList>

          {/* Tab 1: Corporate / Company */}
          <TabsContent value="coj" className="space-y-3.5 pt-3">
            <div className="rounded-xl border border-border/70 bg-muted/20 p-4 space-y-3">
              <div className="flex items-center justify-between">
                <h4 className="text-sm font-bold text-foreground flex items-center gap-2">
                  <Building2 className="h-4 w-4 text-primary" />
                  Companies Office of Jamaica (COJ) Verification
                </h4>
                <Badge variant="outline" className="text-xs font-semibold border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                  Fully Verified
                </Badge>
              </div>
              <p className="text-xs text-muted-foreground leading-relaxed">
                The Companies Office of Jamaica provides company registration and basic company information, and identifies directors, shareholders and other corporate information; beneficial ownership information has separate confidentiality/access rules.
              </p>

              <div className="grid grid-cols-2 gap-2 text-xs pt-1">
                <div className="p-2.5 rounded-lg bg-background ring-1 ring-border/50">
                  <span className="text-muted-foreground block text-[11px]">COJ Registration #</span>
                  <strong className="text-foreground">{company.registration_number || 'CORP-ABC-2024'}</strong>
                </div>
                <div className="p-2.5 rounded-lg bg-background ring-1 ring-border/50">
                  <span className="text-muted-foreground block text-[11px]">Jurisdiction</span>
                  <strong className="text-foreground">{company.jurisdiction || 'Jamaica'}</strong>
                </div>
                <div className="p-2.5 rounded-lg bg-background ring-1 ring-border/50">
                  <span className="text-muted-foreground block text-[11px]">Articles of Incorporation</span>
                  <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1 mt-0.5">
                    <CheckCircle2 className="h-3 w-3" /> Validated on File
                  </span>
                </div>
                <div className="p-2.5 rounded-lg bg-background ring-1 ring-border/50">
                  <span className="text-muted-foreground block text-[11px]">Certificate of Incorporation</span>
                  <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1 mt-0.5">
                    <CheckCircle2 className="h-3 w-3" /> Validated on File
                  </span>
                </div>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
              <div className="rounded-xl border border-border/70 p-3.5 space-y-2">
                <span className="font-bold text-foreground block">Identified Certified Directors</span>
                <ul className="space-y-1.5 text-muted-foreground">
                  <li className="flex items-center justify-between p-2 rounded bg-muted/30">
                    <span className="font-medium text-foreground">Jane Sterling</span>
                    <Badge variant="outline" className="text-[10px]">Managing Director</Badge>
                  </li>
                  <li className="flex items-center justify-between p-2 rounded bg-muted/30">
                    <span className="font-medium text-foreground">Marcus Sterling</span>
                    <Badge variant="outline" className="text-[10px]">Executive Director</Badge>
                  </li>
                </ul>
              </div>

              <div className="rounded-xl border border-border/70 p-3.5 space-y-2">
                <span className="font-bold text-foreground block">Identified Shareholders</span>
                <ul className="space-y-1.5 text-muted-foreground">
                  <li className="flex items-center justify-between p-2 rounded bg-muted/30">
                    <span className="font-medium text-foreground">Sterling Family Holdings Ltd.</span>
                    <Badge variant="secondary" className="text-[10px] font-bold">65% Equity</Badge>
                  </li>
                  <li className="flex items-center justify-between p-2 rounded bg-muted/30">
                    <span className="font-medium text-foreground">Marcus Sterling</span>
                    <Badge variant="secondary" className="text-[10px] font-bold">35% Equity</Badge>
                  </li>
                </ul>
              </div>
            </div>
          </TabsContent>

          {/* Tab 2: Beneficial Ownership */}
          <TabsContent value="ubo" className="space-y-3.5 pt-3">
            <div className="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 space-y-2.5">
              <div className="flex items-center gap-2">
                <Lock className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                <h4 className="text-sm font-bold text-foreground">
                  Statutory Confidentiality & Non-Disclosure Invariant
                </h4>
              </div>
              <p className="text-xs text-muted-foreground leading-relaxed">
                <strong>Rule:</strong> Do not expose beneficial ownership information to ordinary residents or security personnel. The Companies Office specifically treats beneficial ownership information as private/confidential and limits access.
              </p>
              <p className="text-[11px] text-muted-foreground">
                In Jamaica, beneficial ownership filings (Form 38 under the Companies (Amendment) Act) are strictly restricted from the public and gate guards, and can only be inspected by authorized corporate directors and statutory regulatory authorities.
              </p>
            </div>

            {can_view_beneficial_ownership && beneficial_ownership && !beneficial_ownership.is_restricted ? (
              <div className="rounded-xl border border-primary/20 bg-card p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-bold text-foreground">
                    Beneficial Ownership Register (Authorized View)
                  </span>
                  <Badge className="bg-primary text-primary-foreground text-[10px]">
                    Director Clearance
                  </Badge>
                </div>

                <div className="space-y-2">
                  {beneficial_ownership.beneficial_owners.map((owner, i) => (
                    <div key={i} className="rounded-lg border border-border/50 p-3 text-xs space-y-1">
                      <div className="flex items-center justify-between">
                        <span className="font-bold text-foreground">{owner.full_name}</span>
                        <Badge variant="secondary" className="text-[10px] font-bold">{owner.percentage_interest}% Interest</Badge>
                      </div>
                      <div className="grid grid-cols-3 gap-2 text-[11px] text-muted-foreground pt-1">
                        <div>Nationality: <strong className="text-foreground">{owner.nationality}</strong></div>
                        <div>TRN: <strong className="text-foreground">{owner.id_trn}</strong></div>
                        <div>Control: <strong className="text-foreground">{owner.control_nature}</strong></div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ) : (
              <div className="rounded-xl border border-border/70 p-6 text-center space-y-2 bg-muted/10">
                <Lock className="h-8 w-8 text-muted-foreground mx-auto" />
                <h5 className="text-xs font-bold text-foreground">Information Masked by Statutory Privacy Rule</h5>
                <p className="text-xs text-muted-foreground max-w-md mx-auto">
                  Current user session ({current_user_authorization.role_label}) does not hold corporate director or statutory compliance privileges for this entity.
                </p>
              </div>
            )}
          </TabsContent>

          {/* Tab 3: Trust, Estate & Individual */}
          <TabsContent value="multi_entity" className="space-y-3 pt-3 text-xs">
            {/* Trust */}
            <div className="rounded-xl border border-border/70 p-3.5 space-y-1.5">
              <div className="flex items-center justify-between">
                <strong className="text-foreground flex items-center gap-1.5">
                  <Scale className="h-4 w-4 text-primary" />
                  Trust Ownership Verification Standard
                </strong>
                <Badge variant="outline" className="text-[10px]">Rule</Badge>
              </div>
              <p className="text-muted-foreground">
                <strong>Require appropriate trust documentation and trustee authorization:</strong>
              </p>
              <ul className="list-disc pl-5 space-y-1 text-muted-foreground text-[11px]">
                <li>Inspection of stamped Trust Deed / Deed of Settlement establishing the trust and its powers.</li>
                <li>Deed of Appointment / Removal of Trustees verifying current legal title holders.</li>
                <li>Formal trustee authorization resolution empowering actions with respect to community properties.</li>
                <li>Verification of identified trustees and primary beneficiaries.</li>
              </ul>
            </div>

            {/* Estate */}
            <div className="rounded-xl border border-border/70 p-3.5 space-y-1.5">
              <div className="flex items-center justify-between">
                <strong className="text-foreground flex items-center gap-1.5">
                  <FileCheck className="h-4 w-4 text-primary" />
                  Estate Ownership Verification Standard
                </strong>
                <Badge variant="outline" className="text-[10px]">Rule</Badge>
              </div>
              <p className="text-muted-foreground">
                <strong>Require appropriate estate/executor documentation:</strong>
              </p>
              <ul className="list-disc pl-5 space-y-1 text-muted-foreground text-[11px]">
                <li>Official Supreme Court Grant of Probate (where testate with Will) or Letters of Administration (intestate).</li>
                <li>Certified Death Certificate issued by the Registrar General's Department (RGD).</li>
                <li>Verification of court suit / filing reference number and judicial seal.</li>
                <li>Identification and authorization of court-confirmed executors or estate administrators.</li>
              </ul>
            </div>

            {/* Individual */}
            <div className="rounded-xl border border-border/70 p-3.5 space-y-1.5">
              <div className="flex items-center justify-between">
                <strong className="text-foreground flex items-center gap-1.5">
                  <User className="h-4 w-4 text-primary" />
                  Individual Ownership Verification Standard
                </strong>
                <Badge variant="outline" className="text-[10px]">Rule</Badge>
              </div>
              <p className="text-muted-foreground">
                <strong>Verify identity and property ownership/authorization:</strong>
              </p>
              <ul className="list-disc pl-5 space-y-1 text-muted-foreground text-[11px]">
                <li>Government-issued photo identification: National ID (Electoral ID), Passport, or Driver's Licence + TRN.</li>
                <li>Registered Duplicate Certificate of Title verified with National Land Agency (NLA) Volume & Folio numbers.</li>
                <li>Or registered Power of Attorney / Deed of Conveyance recorded at Island Record Office (IRO).</li>
                <li>Documented authorization connecting individual identity to legal title.</li>
              </ul>
            </div>
          </TabsContent>
        </Tabs>
      </DialogContent>
    </Dialog>
  );
}
