import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Input } from '@/components/ui/input';
import { Users, Layers, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CompanyDashboardProps } from '@/components/dashboard/company/company-types';
import { CompanyHeaderBanner } from '@/components/dashboard/company/company-header-banner';
import { CompanyVerificationModal } from '@/components/dashboard/company/company-verification-modal';
import { CompanyPropertiesSection } from '@/components/dashboard/company/company-properties-section';
import { CompanyPeopleTab } from '@/components/dashboard/company/company-people-tab';
import { CompanyOperationsTab } from '@/components/dashboard/company/company-operations-tab';

export default function CompanyDashboard({
  company,
  available_companies,
  properties,
  selected_property_id,
  selected_property,
  summary,
  people_and_access,
  operations,
  current_user_authorization,
  verification,
  beneficial_ownership,
  can_view_beneficial_ownership = false,
}: CompanyDashboardProps) {
  const [activeMainTab, setActiveMainTab] = useState<'people' | 'operations'>('people');
  const [searchQuery, setSearchQuery] = useState('');
  const [verificationModalOpen, setVerificationModalOpen] = useState(false);

  const handleSelectProperty = (propId: number | 'all') => {
    router.get(
      route('dashboard.company'),
      {
        company_id: company.id,
        property_id: propId === 'all' ? undefined : propId,
      },
      { preserveState: true, preserveScroll: true }
    );
  };

  const handleSwitchCompany = (companyId: number) => {
    router.get(
      route('dashboard.company'),
      { company_id: companyId },
      { preserveState: false }
    );
  };

  return (
    <DashboardLayout>
      <Head title={`${company.legal_name} — Company Property Dashboard`} />

      <div className="space-y-6 pb-12">
        <CompanyHeaderBanner
          company={company}
          available_companies={available_companies}
          properties={properties}
          summary={summary}
          current_user_authorization={current_user_authorization}
          beneficial_ownership={beneficial_ownership}
          can_view_beneficial_ownership={can_view_beneficial_ownership}
          onSwitchCompany={handleSwitchCompany}
          onOpenVerificationModal={() => setVerificationModalOpen(true)}
        />

        <CompanyVerificationModal
          open={verificationModalOpen}
          onOpenChange={setVerificationModalOpen}
          company={company}
          current_user_authorization={current_user_authorization}
          beneficial_ownership={beneficial_ownership}
          can_view_beneficial_ownership={can_view_beneficial_ownership}
        />

        <CompanyPropertiesSection
          properties={properties}
          selected_property_id={selected_property_id}
          selected_property={selected_property}
          onSelectProperty={handleSelectProperty}
        />

        <div className="border-t border-border/60 my-6" />

        {/* ── SECTION 2 & 3: PEOPLE & ACCESS / OPERATIONS TABS ── */}
        <div className="space-y-5">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            {/* Primary Domain Switcher: PEOPLE & ACCESS vs OPERATIONS */}
            <div className="inline-flex rounded-xl bg-muted p-1 border border-border">
              <button
                type="button"
                onClick={() => setActiveMainTab('people')}
                className={cn(
                  "flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-bold transition-all",
                  activeMainTab === 'people'
                    ? "bg-card text-foreground shadow-sm ring-1 ring-border"
                    : "text-muted-foreground hover:text-foreground"
                )}
              >
                <Users className="h-4 w-4 text-primary" />
                <span>PEOPLE & ACCESS</span>
              </button>
              <button
                type="button"
                onClick={() => setActiveMainTab('operations')}
                className={cn(
                  "flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-bold transition-all",
                  activeMainTab === 'operations'
                    ? "bg-card text-foreground shadow-sm ring-1 ring-border"
                    : "text-muted-foreground hover:text-foreground"
                )}
              >
                <Layers className="h-4 w-4 text-primary" />
                <span>OPERATIONS</span>
              </button>
            </div>

            {/* Global quick search input */}
            <div className="relative w-full sm:w-72">
              <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search people, passes, vehicles..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9 h-9 text-xs"
              />
            </div>
          </div>

          {activeMainTab === 'people' && (
            <CompanyPeopleTab
              people_and_access={people_and_access}
              company={company}
              searchQuery={searchQuery}
            />
          )}

          {activeMainTab === 'operations' && (
            <CompanyOperationsTab
              operations={operations}
              searchQuery={searchQuery}
            />
          )}
        </div>
      </div>
    </DashboardLayout>
  );
}
