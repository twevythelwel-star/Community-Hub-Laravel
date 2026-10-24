import React from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Building2,
  CheckCircle2,
  Shield,
  ShieldCheck,
  Scale,
  Lock,
  ShieldAlert,
  EyeOff,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  CompanyInfo,
  PropertyItem,
  SummaryKpis,
  UserAuth,
  BeneficialOwnershipProfile,
} from './company-types';

interface CompanyHeaderBannerProps {
  company: CompanyInfo;
  available_companies: { id: number; legal_name: string; trade_name: string | null; status: string }[];
  properties: PropertyItem[];
  summary: SummaryKpis;
  current_user_authorization: UserAuth;
  beneficial_ownership?: BeneficialOwnershipProfile;
  can_view_beneficial_ownership?: boolean;
  onSwitchCompany: (id: number) => void;
  onOpenVerificationModal: () => void;
}

export function CompanyHeaderBanner({
  company,
  available_companies,
  properties,
  summary,
  current_user_authorization,
  beneficial_ownership,
  can_view_beneficial_ownership = false,
  onSwitchCompany,
  onOpenVerificationModal,
}: CompanyHeaderBannerProps) {
  return (
    <>
      {/* ── TOP BANNER: COMPANY IDENTITY & AUTHORIZATION NOTICE ── */}
      <div className="rounded-2xl border border-border/60 bg-gradient-to-br from-card via-card/95 to-muted/30 p-6 shadow-sm backdrop-blur transition-all">
        <div className="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
          <div className="flex items-start gap-4">
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/20">
              <Building2 className="h-8 w-8" />
            </div>
            <div className="space-y-1">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                  {company.legal_name}
                </h1>
                <Badge variant="outline" className="border-primary/30 bg-primary/5 text-xs font-semibold text-primary">
                  Company-Owned Portfolio
                </Badge>
                <Badge variant="outline" className="border-emerald-500/30 bg-emerald-500/10 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                  <CheckCircle2 className="mr-1 h-3 w-3 inline" /> Verified Entity
                </Badge>
              </div>
              <p className="text-sm text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 pt-0.5">
                <span>Reg: <strong className="text-foreground font-medium">{company.registration_number || 'CORP-ABC-2024'}</strong></span>
                <span>Jurisdiction: <strong className="text-foreground font-medium">{company.jurisdiction || 'Jamaica'}</strong></span>
                <span>Properties Authorized: <strong className="text-foreground font-medium">{properties.length} Units</strong></span>
              </p>
            </div>
          </div>

          {/* Authorization badge & Company switcher */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3">
            {available_companies.length > 1 && (
              <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-muted-foreground">Entity:</span>
                <select
                  className="h-9 rounded-lg border border-border bg-background px-3 text-xs font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
                  value={company.id}
                  onChange={(e) => onSwitchCompany(Number(e.target.value))}
                >
                  {available_companies.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.legal_name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            <div className="rounded-xl border border-primary/20 bg-primary/5 px-3.5 py-2 text-xs">
              <div className="flex items-center gap-1.5 font-semibold text-primary">
                <Shield className="h-3.5 w-3.5" />
                <span>Authorized Representative</span>
              </div>
              <div className="text-[11px] text-muted-foreground mt-0.5">
                {current_user_authorization.role_label}
                {current_user_authorization.is_signatory && ' • Legal Signatory'}
              </div>
            </div>
          </div>
        </div>

        {/* Quick Portfolio Stats Ticker */}
        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6 border-t border-border/50 pt-5">
          <div className="rounded-xl bg-background/60 p-3 ring-1 ring-border/50">
            <div className="text-xs text-muted-foreground">Portfolio Units</div>
            <div className="mt-1 text-xl font-bold tracking-tight text-foreground">{properties.length}</div>
          </div>
          <div className="rounded-xl bg-background/60 p-3 ring-1 ring-border/50">
            <div className="text-xs text-muted-foreground flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full bg-emerald-500 inline-block" /> Occupied
            </div>
            <div className="mt-1 text-xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
              {summary.occupied_count}
            </div>
          </div>
          <div className="rounded-xl bg-background/60 p-3 ring-1 ring-border/50">
            <div className="text-xs text-muted-foreground flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full bg-amber-500 inline-block" /> Vacant
            </div>
            <div className="mt-1 text-xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
              {summary.vacant_count}
            </div>
          </div>
          <div className="rounded-xl bg-background/60 p-3 ring-1 ring-border/50">
            <div className="text-xs text-muted-foreground flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full bg-blue-500 inline-block" /> Rented
            </div>
            <div className="mt-1 text-xl font-bold tracking-tight text-blue-600 dark:text-blue-400">
              {summary.rented_count}
            </div>
          </div>
          <div className="rounded-xl bg-background/60 p-3 ring-1 ring-border/50">
            <div className="text-xs text-muted-foreground">People & Access</div>
            <div className="mt-1 text-xl font-bold tracking-tight text-foreground">
              {summary.total_residents + summary.total_renters + summary.total_visitors + summary.total_staff}
            </div>
          </div>
          <div className="rounded-xl bg-background/60 p-3 ring-1 ring-border/50">
            <div className="text-xs text-muted-foreground">Registered Vehicles</div>
            <div className="mt-1 text-xl font-bold tracking-tight text-foreground">
              {summary.total_vehicles}
            </div>
          </div>
        </div>
      </div>

      {/* ── OWNERSHIP VERIFICATION & STATUTORY COMPLIANCE SECTION ── */}
      <div className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm space-y-4">
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-border/50 pb-4">
          <div className="flex items-start gap-3">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-1 ring-emerald-500/20">
              <ShieldCheck className="h-6 w-6" />
            </div>
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-bold text-foreground">
                  Ownership Verification & Regulatory Compliance
                </h3>
                <Badge variant="outline" className="border-emerald-500/40 bg-emerald-500/10 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                  <CheckCircle2 className="mr-1 h-3.5 w-3.5 inline" /> {company.verification_source || 'Companies Office of Jamaica (COJ)'} Verified
                </Badge>
                <Badge variant="secondary" className="text-[11px] font-medium bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                  Good Standing
                </Badge>
              </div>
              <p className="text-xs text-muted-foreground mt-0.5">
                Verified corporate identity, certified directors & shareholders under statutory framework.
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={onOpenVerificationModal}
              className="h-8 gap-1.5 text-xs font-semibold hover:border-primary/50"
            >
              <Scale className="h-3.5 w-3.5 text-primary" />
              <span>Statutory Verification Standards</span>
            </Button>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* Card 1: Certified Corporate Profile */}
          <div className="rounded-xl border border-border/60 bg-muted/20 p-4 space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                <Building2 className="h-4 w-4 text-primary" />
                <span>Corporate Registry Profile</span>
              </div>
              <Badge variant="secondary" className="text-[10px] font-medium">
                Public Record
              </Badge>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs">
              <div className="rounded-lg bg-background/80 p-2.5 ring-1 ring-border/50">
                <span className="text-muted-foreground block text-[11px]">COJ Company Number</span>
                <span className="font-semibold text-foreground text-xs mt-0.5 block">{company.registration_number || 'CORP-ABC-2024'}</span>
              </div>
              <div className="rounded-lg bg-background/80 p-2.5 ring-1 ring-border/50">
                <span className="text-muted-foreground block text-[11px]">Entity Structure</span>
                <span className="font-semibold text-foreground text-xs mt-0.5 block">Private Company Limited by Shares</span>
              </div>
              <div className="rounded-lg bg-background/80 p-2.5 ring-1 ring-border/50">
                <span className="text-muted-foreground block text-[11px]">Identified Directors</span>
                <span className="font-semibold text-foreground text-xs mt-0.5 block">Jane Sterling, Marcus Sterling</span>
              </div>
              <div className="rounded-lg bg-background/80 p-2.5 ring-1 ring-border/50">
                <span className="text-muted-foreground block text-[11px]">Identified Shareholders</span>
                <span className="font-semibold text-foreground text-xs mt-0.5 block">Sterling Family Holdings (65%), Marcus (35%)</span>
              </div>
            </div>

            <div className="flex items-center justify-between text-[11px] text-muted-foreground pt-1 border-t border-border/40">
              <span>Statutory Authority: Companies Office of Jamaica (COJ)</span>
              <span className="text-emerald-600 dark:text-emerald-400 font-medium">Articles & Cert of Inc. On File</span>
            </div>
          </div>

          {/* Card 2: Beneficial Ownership Protection (Privacy Shield) */}
          <div className={cn(
            "rounded-xl border p-4 space-y-3 transition-all",
            can_view_beneficial_ownership
              ? "border-primary/30 bg-primary/5"
              : "border-amber-500/30 bg-amber-500/5"
          )}>
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                <Lock className={cn("h-4 w-4", can_view_beneficial_ownership ? "text-primary" : "text-amber-600 dark:text-amber-400")} />
                <span>Beneficial Ownership (Form 38)</span>
              </div>
              <Badge
                variant="outline"
                className={cn(
                  "text-[10px] font-semibold",
                  can_view_beneficial_ownership
                    ? "border-primary/40 bg-primary/10 text-primary"
                    : "border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300"
                )}
              >
                {can_view_beneficial_ownership ? "Authorized Director Access" : "Statutory Privacy Protected"}
              </Badge>
            </div>

            {can_view_beneficial_ownership && beneficial_ownership && !beneficial_ownership.is_restricted ? (
              <div className="space-y-2.5">
                <div className="rounded-lg bg-background/80 p-3 ring-1 ring-primary/20 space-y-2">
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-semibold text-foreground">Filing Ref: {beneficial_ownership.declaration_reference || 'COJ-BO-2024-88190'}</span>
                    <span className="text-[11px] text-muted-foreground">COJ Form 38 (Filed {beneficial_ownership.filing_date || 'May 18, 2024'})</span>
                  </div>

                  <div className="space-y-1.5 pt-1">
                    {beneficial_ownership.beneficial_owners.map((bo, idx) => (
                      <div key={idx} className="flex items-center justify-between text-xs rounded bg-muted/40 px-2.5 py-1.5">
                        <div>
                          <span className="font-semibold text-foreground">{bo.full_name}</span>
                          <span className="text-[11px] text-muted-foreground ml-2">({bo.control_nature})</span>
                        </div>
                        <Badge variant="secondary" className="text-[10px] font-bold">
                          {bo.percentage_interest}% Interest
                        </Badge>
                      </div>
                    ))}
                  </div>
                </div>

                <p className="text-[11px] text-muted-foreground flex items-center gap-1.5">
                  <ShieldAlert className="h-3.5 w-3.5 text-primary shrink-0" />
                  <span>Confidential: Limited to corporate directors & compliance auditors under Companies Act.</span>
                </p>
              </div>
            ) : (
              <div className="space-y-2.5">
                <div className="rounded-lg bg-background/90 p-3.5 ring-1 ring-amber-500/20 space-y-2">
                  <div className="flex items-start gap-2.5">
                    <div className="p-1.5 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 shrink-0">
                      <Lock className="h-4 w-4" />
                    </div>
                    <div className="space-y-1">
                      <span className="text-xs font-bold text-foreground block">
                        Beneficial Ownership Details Restricted
                      </span>
                      <p className="text-[11px] text-muted-foreground leading-relaxed">
                        The Companies Office of Jamaica specifically treats beneficial ownership information as private/confidential and limits access. Do not expose beneficial ownership information to ordinary residents or security personnel.
                      </p>
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                  <EyeOff className="h-3.5 w-3.5 text-amber-600 shrink-0" />
                  <span>Protected under Companies (Amendment) Act & COJ AML/CFT Regulations.</span>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
