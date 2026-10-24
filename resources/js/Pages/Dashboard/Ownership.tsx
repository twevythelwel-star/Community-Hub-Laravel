import React, { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Building2,
  HeartHandshake,
  Landmark,
  FileText,
  CheckCircle2,
  Layers,
  Users,
  Home,
  FileBadge,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import {
  HierarchyPillarItem,
  OwnershipProps,
} from '@/components/dashboard/ownership/ownership-types';
import { OwnershipModelTab } from '@/components/dashboard/ownership/ownership-model-tab';
import { OwnershipOverviewTab } from '@/components/dashboard/ownership/ownership-overview-tab';
import { OwnershipPeopleTab } from '@/components/dashboard/ownership/ownership-people-tab';
import { OwnershipPropertiesTab } from '@/components/dashboard/ownership/ownership-properties-tab';
import { OwnershipDocumentsTab } from '@/components/dashboard/ownership/ownership-documents-tab';
import {
  AddRepresentativeDialog,
  AddHouseholdMemberDialog,
  QrTokenDialog,
  InvariantInspectorDialog,
} from '@/components/dashboard/ownership/ownership-dialogs';

export default function Ownership({
  ownershipType,
  moduleTitle,
  moduleSubtitle,
  moduleBadge,
  activeEntity,
  hierarchy,
  verification,
  beneficialOwnership,
  canViewBeneficialOwnership,
  representatives,
  household,
  properties,
  availableEntities,
  availableRoles,
  canManage,
}: OwnershipProps) {
  const [activeTab, setActiveTab] = useState<string>('overview');
  const [isAddRepOpen, setIsAddRepOpen] = useState(false);
  const [isAddMemberOpen, setIsAddMemberOpen] = useState(false);
  const [selectedToken, setSelectedToken] = useState<string | null>(null);

  // A+ Data Model selected pillar and inspected item for 6-question breakdown
  const [selectedPillar, setSelectedPillar] = useState<
    'all' | 'entity_people' | 'occupants' | 'access_relationships' | 'credentials'
  >('all');
  const [inspectedItem, setInspectedItem] = useState<HierarchyPillarItem | null>(null);

  // Form states
  const [repForm, setRepForm] = useState({
    full_name: '',
    role: availableRoles[0]?.value || 'authorized_representative',
    email: '',
    phone: '',
    id_document_type: 'National ID / Passport',
    id_document_number: '',
    is_primary_contact: false,
    is_signatory: false,
  });

  const [memberForm, setMemberForm] = useState({
    name: '',
    email: '',
    phone: '',
    role_in_household: 'spouse',
    relationship_label: 'Spouse',
    permissions: ['invite_guests', 'view_cameras'],
  });

  const handleSwitchType = (type: string) => {
    router.get('/dashboard/ownership', { type }, { preserveState: false });
  };

  const handleSwitchEntity = (entityId: string) => {
    router.get('/dashboard/ownership', { entity_id: entityId }, { preserveState: false });
  };

  const submitRepresentative = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeEntity) return;

    router.post(
      '/dashboard/ownership/representatives',
      {
        ownership_entity_id: activeEntity.id,
        ...repForm,
      },
      {
        onSuccess: () => {
          setIsAddRepOpen(false);
          setRepForm({
            full_name: '',
            role: availableRoles[0]?.value || 'authorized_representative',
            email: '',
            phone: '',
            id_document_type: 'National ID / Passport',
            id_document_number: '',
            is_primary_contact: false,
            is_signatory: false,
          });
        },
      }
    );
  };

  const submitHouseholdMember = (e: React.FormEvent) => {
    e.preventDefault();
    router.post('/dashboard/household/members', memberForm, {
      onSuccess: () => {
        setIsAddMemberOpen(false);
        setMemberForm({
          name: '',
          email: '',
          phone: '',
          role_in_household: 'spouse',
          relationship_label: 'Spouse',
          permissions: ['invite_guests'],
        });
      },
    });
  };

  const revokeRep = (personId: number) => {
    if (confirm('Are you sure you want to revoke authorization for this person?')) {
      router.post(`/dashboard/ownership/representatives/${personId}/revoke`);
    }
  };

  const removeMember = (memberId: number) => {
    if (confirm('Remove this member from the household?')) {
      router.delete(`/dashboard/household/members/${memberId}`);
    }
  };

  const getEntityIcon = (type: string) => {
    switch (type) {
      case 'company':
        return <Building2 className="h-6 w-6 text-primary" />;
      case 'individual':
        return <HeartHandshake className="h-6 w-6 text-emerald-600 dark:text-emerald-400" />;
      case 'trust':
        return <Landmark className="h-6 w-6 text-amber-600 dark:text-amber-400" />;
      case 'estate':
        return <FileText className="h-6 w-6 text-purple-600 dark:text-purple-400" />;
      default:
        return <Building2 className="h-6 w-6 text-primary" />;
    }
  };

  const allHierarchyItems: HierarchyPillarItem[] = useMemo(() => {
    if (!hierarchy?.pillars) return [];
    const { entity_people, occupants, access_relationships, credentials } = hierarchy.pillars;
    return [...entity_people, ...occupants, ...access_relationships, ...credentials];
  }, [hierarchy]);

  const filteredHierarchyItems = useMemo(() => {
    if (selectedPillar === 'all') return allHierarchyItems;
    return allHierarchyItems.filter((item) => item.pillar === selectedPillar);
  }, [allHierarchyItems, selectedPillar]);

  return (
    <DashboardLayout>
      <Head title={`${moduleTitle} — Community Hub`} />

      <div className="space-y-6 pb-12">
        {/* Top Header & Structure Switcher */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between border-b pb-5">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <Badge
                variant="outline"
                className="px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider bg-primary/5 text-primary border-primary/20"
              >
                {moduleBadge}
              </Badge>
              {verification?.is_verified && (
                <Badge className="bg-emerald-600 hover:bg-emerald-600 text-white flex items-center gap-1 text-xs">
                  <CheckCircle2 className="h-3 w-3" />
                  Statutory Verified
                </Badge>
              )}
            </div>
            <div className="flex items-center gap-3 pt-1">
              <div className="p-2.5 rounded-xl bg-primary/10 border border-primary/15 shadow-sm">
                {getEntityIcon(ownershipType)}
              </div>
              <div>
                <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                  {moduleTitle}
                </h1>
                <p className="text-sm text-muted-foreground max-w-2xl">{moduleSubtitle}</p>
              </div>
            </div>
          </div>

          {/* Structure Switcher Pills */}
          <div className="flex flex-col sm:flex-row items-start sm:items-center gap-2.5">
            <div className="flex items-center p-1 bg-muted/60 rounded-xl border text-xs font-medium shadow-inner">
              <button
                type="button"
                onClick={() => handleSwitchType('company')}
                className={`px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-all ${
                  ownershipType === 'company'
                    ? 'bg-background text-foreground shadow-sm font-semibold'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                <Building2 className="h-3.5 w-3.5" />
                <span>Company</span>
              </button>
              <button
                type="button"
                onClick={() => handleSwitchType('individual')}
                className={`px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-all ${
                  ownershipType === 'individual'
                    ? 'bg-background text-foreground shadow-sm font-semibold'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                <HeartHandshake className="h-3.5 w-3.5" />
                <span>Individual</span>
              </button>
              <button
                type="button"
                onClick={() => handleSwitchType('trust')}
                className={`px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-all ${
                  ownershipType === 'trust'
                    ? 'bg-background text-foreground shadow-sm font-semibold'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                <Landmark className="h-3.5 w-3.5" />
                <span>Trust</span>
              </button>
              <button
                type="button"
                onClick={() => handleSwitchType('estate')}
                className={`px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-all ${
                  ownershipType === 'estate'
                    ? 'bg-background text-foreground shadow-sm font-semibold'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                <FileText className="h-3.5 w-3.5" />
                <span>Estate</span>
              </button>
            </div>

            {/* Entity Switcher dropdown */}
            {availableEntities.length > 1 && (
              <Select
                value={activeEntity?.id.toString()}
                onValueChange={handleSwitchEntity}
              >
                <SelectTrigger className="w-[200px] h-8 text-xs">
                  <SelectValue placeholder="Switch Legal Entity" />
                </SelectTrigger>
                <SelectContent>
                  {availableEntities.map((ent) => (
                    <SelectItem key={ent.id} value={ent.id.toString()} className="text-xs">
                      {ent.legal_name} ({ent.property_count} lots)
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>
        </div>

        {/* 5 Distinct Architectural Tabs */}
        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
          <TabsList className="bg-muted/60 p-1 border h-auto flex flex-wrap gap-1">
            <TabsTrigger value="overview" className="gap-2 text-xs font-medium">
              {getEntityIcon(ownershipType)}
              <span>Legal Entity Profile</span>
            </TabsTrigger>
            <TabsTrigger value="model" className="gap-2 text-xs font-medium">
              <Layers className="h-4 w-4" />
              <span>A+ Data Model (Hierarchy)</span>
            </TabsTrigger>
            {ownershipType === 'company' && (
              <TabsTrigger value="people" className="gap-2 text-xs font-medium">
                <Users className="h-4 w-4" />
                <span>Authorized Representatives</span>
              </TabsTrigger>
            )}
            {ownershipType === 'individual' && (
              <TabsTrigger value="people" className="gap-2 text-xs font-medium">
                <Users className="h-4 w-4" />
                <span>Household Members</span>
              </TabsTrigger>
            )}
            {ownershipType === 'trust' && (
              <TabsTrigger value="people" className="gap-2 text-xs font-medium">
                <Users className="h-4 w-4" />
                <span>Trustees & Fiduciary</span>
              </TabsTrigger>
            )}
            {ownershipType === 'estate' && (
              <TabsTrigger value="people" className="gap-2 text-xs font-medium">
                <Users className="h-4 w-4" />
                <span>Executors & Court Appointees</span>
              </TabsTrigger>
            )}
            <TabsTrigger value="properties" className="gap-2 text-xs font-medium">
              <Home className="h-4 w-4" />
              <span>Properties ({properties.length})</span>
            </TabsTrigger>
            <TabsTrigger value="documents" className="gap-2 text-xs font-medium">
              <FileBadge className="h-4 w-4" />
              <span>Statutory Documents</span>
            </TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="space-y-6">
            <OwnershipOverviewTab
              ownershipType={ownershipType}
              activeEntity={activeEntity}
              verification={verification}
              beneficialOwnership={beneficialOwnership}
              canViewBeneficialOwnership={canViewBeneficialOwnership}
              getEntityIcon={getEntityIcon}
            />
          </TabsContent>

          <TabsContent value="model" className="space-y-6">
            <OwnershipModelTab
              propertiesCount={properties.length}
              activeEntity={activeEntity}
              hierarchy={hierarchy}
              selectedPillar={selectedPillar}
              onSelectPillar={setSelectedPillar}
              filteredHierarchyItems={filteredHierarchyItems}
              allHierarchyItemsCount={allHierarchyItems.length}
              onInspectItem={setInspectedItem}
            />
          </TabsContent>

          <TabsContent value="people" className="space-y-6">
            <OwnershipPeopleTab
              ownershipType={ownershipType}
              activeEntity={activeEntity}
              representatives={representatives}
              household={household}
              canManage={canManage}
              onOpenAddRep={() => setIsAddRepOpen(true)}
              onOpenAddMember={() => setIsAddMemberOpen(true)}
              onRevokeRep={revokeRep}
              onRemoveMember={removeMember}
              onSelectToken={setSelectedToken}
            />
          </TabsContent>

          <TabsContent value="properties" className="space-y-4">
            <OwnershipPropertiesTab
              activeEntity={activeEntity}
              properties={properties}
            />
          </TabsContent>

          <TabsContent value="documents" className="space-y-4">
            <OwnershipDocumentsTab ownershipType={ownershipType} />
          </TabsContent>
        </Tabs>
      </div>

      {/* Dialogs */}
      <AddRepresentativeDialog
        open={isAddRepOpen}
        onOpenChange={setIsAddRepOpen}
        activeEntity={activeEntity}
        availableRoles={availableRoles}
        repForm={repForm}
        onRepFormChange={setRepForm}
        onSubmit={submitRepresentative}
      />

      <AddHouseholdMemberDialog
        open={isAddMemberOpen}
        onOpenChange={setIsAddMemberOpen}
        memberForm={memberForm}
        onMemberFormChange={setMemberForm}
        onSubmit={submitHouseholdMember}
      />

      <QrTokenDialog
        token={selectedToken}
        onClose={() => setSelectedToken(null)}
      />

      <InvariantInspectorDialog
        item={inspectedItem}
        onClose={() => setInspectedItem(null)}
      />
    </DashboardLayout>
  );
}
