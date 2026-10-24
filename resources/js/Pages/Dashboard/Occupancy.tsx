import React, { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { useToast } from '@/hooks/use-toast';
import {
  HardHat,
  HeartHandshake,
  Home,
  Shield,
  Sparkles,
  User,
  UserCheck,
} from 'lucide-react';
import {
  ActiveMusterData,
  CommunityHierarchyData,
  CommunityPropertyNode,
  EnclosureZone,
  MusterRollCallItem,
  Occupant,
  OccupancySummary,
  UnitSummaryItem,
} from '@/components/dashboard/occupancy/occupancy-types';
import { OccupancyHeader } from '@/components/dashboard/occupancy/occupancy-header';
import { OccupancyScorecardSection } from '@/components/dashboard/occupancy/occupancy-scorecard-section';
import { OccupancyMapTreeView } from '@/components/dashboard/occupancy/occupancy-map-tree-view';
import { OccupancyRosterTable } from '@/components/dashboard/occupancy/occupancy-roster-table';
import { OccupancyDrilldownModal } from '@/components/dashboard/occupancy/occupancy-drilldown-modal';
import { OccupancyPersonModal } from '@/components/dashboard/occupancy/occupancy-person-modal';
import {
  MusterNoteDialog,
  StartMusterDialog,
} from '@/components/dashboard/occupancy/occupancy-muster-dialogs';

type Props = {
  summary: OccupancySummary;
  expectedSummary?: OccupancySummary | null;
  unitSummary?: OccupancySummary | null;
  unitExpectedSummary?: OccupancySummary | null;
  units: UnitSummaryItem[];
  selectedUnit?: string | null;
  selectedCategory?: string | null;
  search?: string | null;
  activeTab?: string;
  communityHierarchy?: CommunityHierarchyData | null;
  occupants: {
    data: Occupant[];
    total: number;
    current_page: number;
    per_page: number;
    last_page: number;
  };
  canManage: boolean;
  userUnit?: string | null;
  activeMuster?: ActiveMusterData | null;
  enclosureZones?: EnclosureZone[];
  lastUpdated: string;
};

export default function OccupancyPage({
  summary,
  expectedSummary,
  unitSummary,
  unitExpectedSummary,
  units,
  selectedUnit: initialUnit,
  selectedCategory: initialCategory = 'all',
  search: initialSearch = '',
  activeTab: initialTab = 'inside',
  communityHierarchy,
  occupants,
  canManage,
  userUnit,
  activeMuster,
  enclosureZones,
  lastUpdated,
}: Props) {
  const { toast } = useToast();
  const [unitFilter, setUnitFilter] = useState(initialUnit || '');
  const [activeCategory, setActiveCategory] = useState(initialCategory || 'all');
  const [searchQuery, setSearchQuery] = useState(initialSearch || '');
  const [activeViewMode, setActiveViewMode] = useState<'inside' | 'expected' | 'map'>(
    initialTab === 'expected' ? 'expected' : initialTab === 'map' ? 'map' : 'inside'
  );
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [copiedLedger, setCopiedLedger] = useState(false);

  // Muster Mode & Dialogs
  const [startMusterOpen, setStartMusterOpen] = useState(false);
  const [musterIncidentType, setMusterIncidentType] = useState<
    'fire' | 'hurricane' | 'earthquake' | 'flood' | 'security_incident' | 'drill' | 'other'
  >('fire');
  const [musterTitle, setMusterTitle] = useState('');
  const [musterAssemblyPoint, setMusterAssemblyPoint] = useState('Central Park East Muster Field (Gate 01 Ingress)');
  const [musterNotes, setMusterNotes] = useState('');
  const [submittingMuster, setSubmittingMuster] = useState(false);

  // Status Note Dialog
  const [noteDialogOpen, setNoteDialogOpen] = useState(false);
  const [selectedRollCall, setSelectedRollCall] = useState<MusterRollCallItem | null>(null);
  const [targetStatus, setTargetStatus] = useState<string>('');
  const [occupantNote, setOccupantNote] = useState('');

  // Unit Drill-Down Modal
  const [drillDownModalOpen, setDrillDownModalOpen] = useState(false);
  const [drillDownUnit, setDrillDownUnit] = useState<string>('');
  const [drillDownLoading, setDrillDownLoading] = useState(false);
  const [drillDownData, setDrillDownData] = useState<any | null>(null);
  const [drillDownError, setDrillDownError] = useState<string | null>(null);

  // Deep Access Relationship Inspector Modal
  const [selectedPerson, setSelectedPerson] = useState<Occupant | null>(null);

  // Map Selected Property & Topology Tree
  const [selectedTreeUnit, setSelectedTreeUnit] = useState<string>('');
  const [compareTreeUnit, setCompareTreeUnit] = useState<string>('');
  const [copiedTree, setCopiedTree] = useState(false);

  const propertyList: any[] = communityHierarchy?.properties ?? [];
  const asciiTree = useMemo(() => {
    const findUnit = (unit: string) => propertyList.find((p: any) => p.unit?.toLowerCase() === unit.toLowerCase());
    const p1 = (selectedTreeUnit && findUnit(selectedTreeUnit)) || propertyList[0];
    const p2 = (compareTreeUnit && findUnit(compareTreeUnit)) || propertyList.find((p: any) => p !== p1);

    if (!p1) {
      return 'Community Map\n\nNo occupancy data is available right now.';
    }

    const name = (p: any) => (p ? String(p.unit).toUpperCase() : '—');
    const inside = (p: any) => (p ? `${p.insideCount ?? 0} inside` : '');
    const branches = (p1.breakdown ?? []).filter((b: any) => b.inside > 0);
    const branchLines = branches.length > 0
      ? branches.map((item: any, idx: number) => {
          const symbol = idx === branches.length - 1 ? '└─ ' : '├─ ';
          return `       ${symbol}${item.branchLabel || `${item.inside} ${item.shortLabel || item.label}`}`;
        })
      : ['       └─ No one recorded inside'];

    return [
      `Community Map`,
      ``,
      `             COMMUNITY`,
      `                 │`,
      `       ┌─────────┴─────────┐`,
      `       │                   │`,
      `    ${name(p1).padEnd(7)}              ${name(p2)}`,
      `       │                   │`,
      `   ${inside(p1).padEnd(8)}              ${inside(p2)}`,
      `       │`,
      ...branchLines,
    ].join('\n');
  }, [propertyList, selectedTreeUnit, compareTreeUnit]);

  const handleFilterApply = (newUnit?: string | null, newCat?: string, newSearch?: string, newTab?: string) => {
    const unitParam = newUnit !== undefined ? (newUnit || undefined) : (unitFilter || undefined);
    const catParam = newCat !== undefined ? (newCat === 'all' ? undefined : newCat) : (activeCategory === 'all' ? undefined : activeCategory);
    const searchParam = newSearch !== undefined ? (newSearch || undefined) : (searchQuery || undefined);
    const tabParam = newTab !== undefined ? newTab : activeViewMode;

    router.get(
      '/dashboard/occupancy',
      {
        unit: unitParam,
        category: catParam,
        search: searchParam,
        tab: tabParam,
      },
      {
        preserveScroll: true,
        preserveState: true,
      }
    );
  };

  const handleSelectUnit = (unit: string | null) => {
    setUnitFilter(unit || '');
    handleFilterApply(unit, activeCategory, searchQuery, activeViewMode);
  };

  const handleOpenUnitModal = async (unitName: string) => {
    setDrillDownUnit(unitName);
    setDrillDownModalOpen(true);
    setDrillDownLoading(true);
    setDrillDownError(null);
    try {
      const res = await fetch(`/dashboard/occupancy/unit/${encodeURIComponent(unitName)}`);
      if (res.ok) {
        const data = await res.json();
        setDrillDownData(data);
      } else {
        setDrillDownData(null);
        setDrillDownError(
          res.status === 403
            ? 'You can only see who is at your own unit.'
            : 'Could not load this unit right now. Try again, or check the gate log directly.'
        );
      }
    } catch (err) {
      console.error('Error fetching unit drilldown:', err);
      setDrillDownData(null);
      setDrillDownError('Could not load this unit right now. Try again, or check the gate log directly.');
    } finally {
      setDrillDownLoading(false);
    }
  };

  const handleCategoryChange = (cat: string) => {
    setActiveCategory(cat);
    handleFilterApply(unitFilter, cat, searchQuery, activeViewMode);
  };

  const handleViewModeChange = (mode: 'inside' | 'expected' | 'map') => {
    setActiveViewMode(mode);
    handleFilterApply(unitFilter, activeCategory, searchQuery, mode);
  };

  const handleRefresh = () => {
    setIsRefreshing(true);
    router.reload({
      onFinish: () => setIsRefreshing(false),
    });
  };

  // 7 Primary Community Access Categories Configuration
  const categoryConfig: Record<string, { label: string; icon: any; color: string; badgeClass: string; desc: string }> = {
    residents: {
      label: 'Residents',
      icon: Home,
      color: 'text-blue-500 bg-blue-500/10 border-blue-500/20',
      badgeClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
      desc: 'Verified Property Owners & Household',
    },
    long_term_guests: {
      label: 'Long-Term Guests / Renters',
      icon: UserCheck,
      color: 'text-indigo-500 bg-indigo-500/10 border-indigo-500/20',
      badgeClass: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20',
      desc: 'Leaseholders & Long-Term Occupants',
    },
    short_term_guests: {
      label: 'Short-Term Rental Guests',
      icon: Sparkles,
      color: 'text-pink-500 bg-pink-500/10 border-pink-500/20',
      badgeClass: 'bg-pink-500/10 text-pink-600 dark:text-pink-400 border-pink-500/20',
      desc: 'Active Airbnb / Vacation Rental Guests',
    },
    visitors: {
      label: 'Visitors',
      icon: User,
      color: 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
      badgeClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
      desc: 'Scheduled & One-Time Guest Passes',
    },
    staff: {
      label: 'Staff',
      icon: Shield,
      color: 'text-amber-500 bg-amber-500/10 border-amber-500/20',
      badgeClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
      desc: 'Estate Security, Facilities & Caregivers',
    },
    contractors: {
      label: 'Contractors',
      icon: HardHat,
      color: 'text-orange-500 bg-orange-500/10 border-orange-500/20',
      badgeClass: 'bg-orange-500/10 text-orange-600 dark:text-orange-400 border-orange-500/20',
      desc: 'Permitted Trades, Technicians & Utility',
    },
    legacy_contacts: {
      label: 'Legacy Contacts',
      icon: HeartHandshake,
      color: 'text-purple-500 bg-purple-500/10 border-purple-500/20',
      badgeClass: 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
      desc: 'Emergency Contacts & Successor Delegates',
    },
  };

  const categoriesList = [
    { key: 'residents', label: 'Residents' },
    { key: 'long_term_guests', label: 'Long-Term Guests / Renters' },
    { key: 'short_term_guests', label: 'Short-Term Rental Guests' },
    { key: 'visitors', label: 'Visitors' },
    { key: 'staff', label: 'Staff' },
    { key: 'contractors', label: 'Contractors' },
    { key: 'legacy_contacts', label: 'Legacy Contacts' },
  ];

  const currentSummary = unitFilter && unitSummary ? unitSummary : summary;
  const currentExpectedSummary = unitFilter && unitExpectedSummary ? unitExpectedSummary : (expectedSummary || {
    residents: 0,
    long_term_guests: 0,
    short_term_guests: 0,
    visitors: 0,
    staff: 0,
    contractors: 0,
    legacy_contacts: 0,
    total: 0,
  });

  const activeDisplaySummary = activeViewMode === 'expected' ? currentExpectedSummary : currentSummary;

  // Copy Security Log Tally to Clipboard
  const handleCopyLedger = () => {
    const isExp = activeViewMode === 'expected';
    const s = activeDisplaySummary;
    const text = [
      isExp ? 'Expected Today Community Tally' : 'Currently Inside Community',
      'Category                    Count',
      '─────────────────────────────────',
      `Residents                   ${String(s.residents).padStart(5, ' ')}`,
      `Long-Term Guests / Renters  ${String(s.long_term_guests).padStart(5, ' ')}`,
      `Short-Term Rental Guests    ${String(s.short_term_guests || 0).padStart(5, ' ')}`,
      `Visitors                    ${String(s.visitors).padStart(5, ' ')}`,
      `Staff                       ${String(s.staff).padStart(5, ' ')}`,
      `Contractors                 ${String(s.contractors).padStart(5, ' ')}`,
      `Legacy Contacts             ${String(s.legacy_contacts).padStart(5, ' ')}`,
      '─────────────────────────────────',
      `TOTAL                       ${String(s.total).padStart(5, ' ')}`,
      '',
      `Timestamp: ${new Date(lastUpdated).toLocaleString()}`,
    ].join('\n');

    navigator.clipboard.writeText(text);
    setCopiedLedger(true);
    toast({
      title: 'Ledger Tally Copied',
      description: 'The complete 7-category census summary has been copied to your clipboard.',
    });
    setTimeout(() => setCopiedLedger(false), 2500);
  };

  // Start Emergency Muster
  const handleStartMuster = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmittingMuster(true);

    router.post(
      '/dashboard/occupancy/muster/start',
      {
        incident_type: musterIncidentType,
        title: musterTitle || `${musterIncidentType.toUpperCase()} Emergency Evacuation`,
        assembly_point: musterAssemblyPoint,
        notes: musterNotes,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setSubmittingMuster(false);
          setStartMusterOpen(false);
          toast({
            title: '🚨 Emergency Muster Activated',
            description: 'Live evacuation roll-call has been initiated for all occupants inside.',
          });
        },
        onError: (errs) => {
          setSubmittingMuster(false);
          toast({
            variant: 'destructive',
            title: 'Unable to Start Muster',
            description: (Object.values(errs)[0] as string) || 'Server error occurred.',
          });
        },
      }
    );
  };

  // Update Individual Muster Status
  const handleUpdateStatus = (occupantId: string, status: string, notes?: string) => {
    if (!activeMuster) return;

    router.post(
      '/dashboard/occupancy/muster/status',
      {
        session_id: activeMuster.id,
        occupant_id: occupantId,
        status,
        notes,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          toast({
            title: 'Status Updated',
            description: `Occupant marked as ${status.replace('_', ' ').toUpperCase()}.`,
          });
        },
      }
    );
  };

  return (
    <DashboardLayout>
      <Head title="Community Access Command Center — Live Occupancy & Gate Telemetry" />

      <div className="flex flex-col gap-6">
        {/* Header & View Mode Navigation */}
        <OccupancyHeader
          activeMuster={activeMuster}
          canManage={canManage}
          isRefreshing={isRefreshing}
          onRefresh={handleRefresh}
          onOpenStartMuster={() => setStartMusterOpen(true)}
          activeViewMode={activeViewMode}
          onViewModeChange={handleViewModeChange}
          currentSummary={currentSummary}
          currentExpectedSummary={currentExpectedSummary}
          unitFilter={unitFilter}
          userUnit={userUnit}
          onOpenUnitModal={handleOpenUnitModal}
        />

        {/* 7-Category Scorecard & Ledger */}
        {activeViewMode !== 'map' && (
          <OccupancyScorecardSection
            unitFilter={unitFilter}
            onClearUnitFilter={() => handleSelectUnit(null)}
            currentSummary={currentSummary}
            currentExpectedSummary={currentExpectedSummary}
            activeDisplaySummary={activeDisplaySummary}
            activeCategory={activeCategory}
            onCategoryChange={handleCategoryChange}
            activeViewMode={activeViewMode}
            categoriesList={categoriesList}
            categoryConfig={categoryConfig}
            copiedLedger={copiedLedger}
            onCopyLedger={handleCopyLedger}
          />
        )}

        {/* Community Map & Property Hierarchy Tree */}
        {activeViewMode === 'map' && (
          <OccupancyMapTreeView
            propertyList={propertyList}
            selectedTreeUnit={selectedTreeUnit}
            onSelectTreeUnit={setSelectedTreeUnit}
            asciiTree={asciiTree}
            copiedTree={copiedTree}
            onCopyTree={() => {
              navigator.clipboard?.writeText(asciiTree);
              setCopiedTree(true);
              setTimeout(() => setCopiedTree(false), 2000);
            }}
            unitFilter={unitFilter}
            onOpenUnitModal={handleOpenUnitModal}
            onFilterCensus={(unit) => {
              handleSelectUnit(unit);
              setActiveViewMode('inside');
            }}
          />
        )}

        {/* Current Occupants Registry Table */}
        {activeViewMode !== 'map' && (
          <OccupancyRosterTable
            activeViewMode={activeViewMode}
            occupants={occupants}
            activeDisplaySummary={activeDisplaySummary}
            activeCategory={activeCategory}
            onCategoryChange={handleCategoryChange}
            searchQuery={searchQuery}
            onSearchQueryChange={setSearchQuery}
            onSearchSubmit={(q) => handleFilterApply(unitFilter, activeCategory, q)}
            categoriesList={categoriesList}
            categoryConfig={categoryConfig}
            onSelectPerson={setSelectedPerson}
            onOpenUnitModal={handleOpenUnitModal}
          />
        )}

        {/* Dedicated Unit Drill-Down Modal: "Who's inside Unit 14?" */}
        <OccupancyDrilldownModal
          open={drillDownModalOpen}
          onOpenChange={setDrillDownModalOpen}
          drillDownData={drillDownData}
          drillDownUnit={drillDownUnit}
          drillDownLoading={drillDownLoading}
          drillDownError={drillDownError}
          onSelectPerson={setSelectedPerson}
          onFilterMainRegistry={handleSelectUnit}
        />

        {/* Deep Access Relationship Inspector Dialog */}
        <OccupancyPersonModal
          person={selectedPerson}
          onClose={() => setSelectedPerson(null)}
        />

        {/* Emergency Muster Start Dialog */}
        <StartMusterDialog
          open={startMusterOpen}
          onOpenChange={setStartMusterOpen}
          onSubmit={handleStartMuster}
          incidentType={musterIncidentType}
          onIncidentTypeChange={setMusterIncidentType}
          title={musterTitle}
          onTitleChange={setMusterTitle}
          assemblyPoint={musterAssemblyPoint}
          onAssemblyPointChange={setMusterAssemblyPoint}
          notes={musterNotes}
          onNotesChange={setMusterNotes}
          submitting={submittingMuster}
          totalOccupants={summary.total}
        />

        {/* Muster Status Note Dialog */}
        <MusterNoteDialog
          open={noteDialogOpen}
          onOpenChange={setNoteDialogOpen}
          selectedRollCall={selectedRollCall}
          targetStatus={targetStatus}
          occupantNote={occupantNote}
          onOccupantNoteChange={setOccupantNote}
          onSaveStatus={handleUpdateStatus}
        />
      </div>
    </DashboardLayout>
  );
}
