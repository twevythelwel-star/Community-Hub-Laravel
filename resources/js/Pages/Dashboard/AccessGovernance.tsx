import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  ShieldAlert,
  AlertTriangle,
  Clock,
  FileCheck,
  RefreshCw,
  Layers,
  History,
  Eye,
  FileText,
  PlusCircle,
  FileClock,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  AccessGovernanceProps,
  RiskIncident,
  AuditTimelineEvent,
  GovernanceRiskTab,
  GovernanceApprovalsTab,
  GovernanceExpirationTab,
  GovernanceVisibilityTab,
  GovernanceDocumentsTab,
  GovernanceTimelineTab,
  GovernanceDialogs,
} from '@/components/dashboard/governance';

export default function AccessGovernance({
  incidents = [],
  approvalRequests = [],
  documents = [],
  recentTimeline = [],
  visibilityMatrix = [],
  userScope = null,
  metrics,
}: AccessGovernanceProps) {
  const [activeTab, setActiveTab] = useState('risk');
  const [simulatorRole, setSimulatorRole] = useState('Homeowner');
  const [searchTimelinePassId, setSearchTimelinePassId] = useState('');
  const [filteredTimelineEvents, setFilteredTimelineEvents] = useState<AuditTimelineEvent[]>(recentTimeline);
  const [selectedIncident, setSelectedIncident] = useState<RiskIncident | null>(null);
  const [isLockdownDialogOpen, setIsLockdownDialogOpen] = useState(false);
  const [lockdownReason, setLockdownReason] = useState('');
  const [isAttachingDoc, setIsAttachingDoc] = useState(false);

  // New Document Form State
  const [newDocTitle, setNewDocTitle] = useState('');
  const [newDocType, setNewDocType] = useState('lease');
  const [newDocHolder, setNewDocHolder] = useState('');
  const [newDocExpires, setNewDocExpires] = useState('');

  // Handle Forensic Search
  const handleTimelineSearch = (term: string) => {
    setSearchTimelinePassId(term);
    if (!term.trim()) {
      setFilteredTimelineEvents(recentTimeline);
      return;
    }
    const cleanTerm = term.toLowerCase().trim();
    setFilteredTimelineEvents(
      recentTimeline.filter(
        (e) =>
          e.pass_id.toLowerCase().includes(cleanTerm) ||
          e.holder_name.toLowerCase().includes(cleanTerm) ||
          e.headline.toLowerCase().includes(cleanTerm) ||
          e.gate.toLowerCase().includes(cleanTerm)
      )
    );
  };

  const handleRunSweep = () => {
    router.post('/dashboard/access-governance/passes/expire-sweep', {}, {
      preserveScroll: true,
    });
  };

  const handleAuditDocuments = () => {
    router.post('/dashboard/access-governance/documents/audit', {}, {
      preserveScroll: true,
    });
  };

  const handleLockdown = () => {
    if (!selectedIncident) return;
    router.post(
      `/dashboard/access-governance/incidents/${selectedIncident.id}/lockdown`,
      {
        reason: lockdownReason || 'Immediate security quarantine from Access Risk Engine',
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsLockdownDialogOpen(false);
          setLockdownReason('');
          setSelectedIncident(null);
        },
      }
    );
  };

  const handleApproveRequest = (id: number) => {
    router.post(
      `/dashboard/access-governance/approvals/${id}/approve`,
      { notes: 'Approved via Governance Dashboard' },
      { preserveScroll: true }
    );
  };

  const handleRejectRequest = (id: number) => {
    const reason = prompt('Please specify rejection reason:');
    if (!reason) return;
    router.post(
      `/dashboard/access-governance/approvals/${id}/reject`,
      { reason },
      { preserveScroll: true }
    );
  };

  const handleCreateDocument = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/dashboard/access-governance/documents/attach',
      {
        title: newDocTitle,
        document_type: newDocType,
        holder_name: newDocHolder,
        expires_at: newDocExpires,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsAttachingDoc(false);
          setNewDocTitle('');
          setNewDocHolder('');
          setNewDocExpires('');
        },
      }
    );
  };

  return (
    <DashboardLayout>
      <Head title="Access Governance & Risk Engine" />

      <div className="space-y-6 pb-12">
        {/* Header Section */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-border/40 pb-5">
          <div>
            <div className="flex items-center gap-2 mb-1">
              <span className="p-1.5 rounded-lg bg-red-500/10 text-red-600 dark:text-red-400">
                <ShieldAlert className="w-5 h-5" />
              </span>
              <span className="text-xs uppercase tracking-wider font-bold text-muted-foreground">
                Zero-Trust Perimeter & Continuous Policy Enforcement
              </span>
            </div>
            <h1 className="text-3xl font-extrabold tracking-tight">Access Risk & Governance Engine</h1>
            <p className="text-sm text-muted-foreground mt-1">
              Proactive anomaly detection, multi-tier approvals, mandatory expiration compliance, digital lease vault, and granular forensic timelines.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={handleRunSweep}
              className="gap-1.5 text-xs font-semibold shadow-xs"
            >
              <RefreshCw className="w-3.5 h-3.5" /> Run Expiration Sweep
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleAuditDocuments}
              className="gap-1.5 text-xs font-semibold shadow-xs"
            >
              <FileClock className="w-3.5 h-3.5" /> Audit Leases & Docs
            </Button>
            <Button
              size="sm"
              onClick={() => setIsAttachingDoc(true)}
              className="gap-1.5 text-xs font-semibold shadow-xs bg-primary text-primary-foreground hover:bg-primary/90"
            >
              <PlusCircle className="w-3.5 h-3.5" /> Attach Compliance Doc
            </Button>
          </div>
        </div>

        {/* 5-Pillar Scorecard Summary Banner */}
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3.5">
          <Card className="border border-red-500/20 bg-linear-to-br from-red-500/5 to-transparent shadow-xs">
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">Suspicious Alerts</p>
                <AlertTriangle className="w-4 h-4 text-red-500" />
              </div>
              <div className="mt-2 flex items-baseline gap-2">
                <span className="text-2xl font-black text-red-600 dark:text-red-400">
                  {metrics.activeIncidents}
                </span>
                {metrics.criticalIncidents > 0 && (
                  <Badge variant="destructive" className="text-[10px] uppercase font-bold animate-pulse px-1.5 py-0">
                    {metrics.criticalIncidents} Critical
                  </Badge>
                )}
              </div>
              <p className="text-[11px] text-muted-foreground mt-1">Active risk anomalies</p>
            </CardContent>
          </Card>

          <Card className="border border-amber-500/20 bg-linear-to-br from-amber-500/5 to-transparent shadow-xs">
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">Pending Approvals</p>
                <Layers className="w-4 h-4 text-amber-500" />
              </div>
              <div className="mt-2 flex items-baseline gap-2">
                <span className="text-2xl font-black text-amber-600 dark:text-amber-400">
                  {metrics.pendingApprovals}
                </span>
                <span className="text-xs text-muted-foreground">in queue</span>
              </div>
              <p className="text-[11px] text-muted-foreground mt-1">Tiered governance routing</p>
            </CardContent>
          </Card>

          <Card className="border border-blue-500/20 bg-linear-to-br from-blue-500/5 to-transparent shadow-xs">
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">Mandatory Expirations</p>
                <Clock className="w-4 h-4 text-blue-500" />
              </div>
              <div className="mt-2 flex items-baseline gap-2">
                <span className="text-2xl font-black text-blue-600 dark:text-blue-400">100%</span>
                <span className="text-xs text-muted-foreground">enforced</span>
              </div>
              <p className="text-[11px] text-muted-foreground mt-1">Zero forgotten permissions</p>
            </CardContent>
          </Card>

          <Card className="border border-emerald-500/20 bg-linear-to-br from-emerald-500/5 to-transparent shadow-xs">
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">Doc Compliance</p>
                <FileCheck className="w-4 h-4 text-emerald-500" />
              </div>
              <div className="mt-2 flex items-baseline gap-2">
                <span className="text-2xl font-black text-foreground">
                  {documents.length}
                </span>
                {metrics.expiringDocuments > 0 && (
                  <Badge variant="outline" className="text-[10px] text-amber-600 border-amber-300">
                    {metrics.expiringDocuments} Expirations
                  </Badge>
                )}
              </div>
              <p className="text-[11px] text-muted-foreground mt-1">Leases, certs, IDs verified</p>
            </CardContent>
          </Card>

          <Card className="border border-purple-500/20 bg-linear-to-br from-purple-500/5 to-transparent shadow-xs col-span-2 md:col-span-1">
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-muted-foreground">Forensic Timeline</p>
                <History className="w-4 h-4 text-purple-500" />
              </div>
              <div className="mt-2 flex items-baseline gap-2">
                <span className="text-2xl font-black text-purple-600 dark:text-purple-400">
                  {recentTimeline.length}
                </span>
                <span className="text-xs text-muted-foreground">events</span>
              </div>
              <p className="text-[11px] text-muted-foreground mt-1">Full chronological audit</p>
            </CardContent>
          </Card>
        </div>

        {/* Tab Navigation */}
        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-4">
          <TabsList className="grid grid-cols-2 md:grid-cols-6 p-1 bg-muted/60 rounded-xl h-auto">
            <TabsTrigger value="risk" className="flex items-center gap-2 py-2.5 text-xs font-semibold">
              <AlertTriangle className="w-4 h-4 text-red-500" />
              <span>Suspicious Risk Engine</span>
              {metrics.activeIncidents > 0 && (
                <span className="ml-1 px-1.5 py-0.2 bg-red-600 text-white rounded-full text-[10px] font-bold">
                  {metrics.activeIncidents}
                </span>
              )}
            </TabsTrigger>

            <TabsTrigger value="approvals" className="flex items-center gap-2 py-2.5 text-xs font-semibold">
              <Layers className="w-4 h-4 text-amber-500" />
              <span>Approval Workflows</span>
              {metrics.pendingApprovals > 0 && (
                <span className="ml-1 px-1.5 py-0.2 bg-amber-600 text-white rounded-full text-[10px] font-bold">
                  {metrics.pendingApprovals}
                </span>
              )}
            </TabsTrigger>

            <TabsTrigger value="expiration" className="flex items-center gap-2 py-2.5 text-xs font-semibold">
              <Clock className="w-4 h-4 text-blue-500" />
              <span>Mandatory Expiration</span>
            </TabsTrigger>

            <TabsTrigger value="visibility" className="flex items-center gap-2 py-2.5 text-xs font-semibold">
              <Eye className="w-4 h-4 text-cyan-500" />
              <span>Visibility Model</span>
            </TabsTrigger>

            <TabsTrigger value="documents" className="flex items-center gap-2 py-2.5 text-xs font-semibold">
              <FileText className="w-4 h-4 text-emerald-500" />
              <span>Digital Lease & Docs</span>
            </TabsTrigger>

            <TabsTrigger value="timeline" className="flex items-center gap-2 py-2.5 text-xs font-semibold">
              <History className="w-4 h-4 text-purple-500" />
              <span>Complete Audit Timeline</span>
            </TabsTrigger>
          </TabsList>

          <GovernanceRiskTab
            incidents={incidents}
            onLockdown={(incident) => {
              setSelectedIncident(incident);
              setIsLockdownDialogOpen(true);
            }}
            onTracePass={(passId) => {
              setActiveTab('timeline');
              handleTimelineSearch(passId);
            }}
          />

          <GovernanceApprovalsTab
            approvalRequests={approvalRequests}
            onApprove={handleApproveRequest}
            onReject={handleRejectRequest}
          />

          <GovernanceExpirationTab onRunSweep={handleRunSweep} />

          <GovernanceVisibilityTab
            visibilityMatrix={visibilityMatrix}
            simulatorRole={simulatorRole}
            setSimulatorRole={setSimulatorRole}
          />

          <GovernanceDocumentsTab
            documents={documents}
            onOpenAttachDoc={() => setIsAttachingDoc(true)}
          />

          <GovernanceTimelineTab
            filteredTimelineEvents={filteredTimelineEvents}
            searchTimelinePassId={searchTimelinePassId}
            onSearch={handleTimelineSearch}
          />
        </Tabs>

        <GovernanceDialogs
          isLockdownDialogOpen={isLockdownDialogOpen}
          setIsLockdownDialogOpen={setIsLockdownDialogOpen}
          selectedIncident={selectedIncident}
          lockdownReason={lockdownReason}
          setLockdownReason={setLockdownReason}
          onLockdown={handleLockdown}
          isAttachingDoc={isAttachingDoc}
          setIsAttachingDoc={setIsAttachingDoc}
          newDocTitle={newDocTitle}
          setNewDocTitle={setNewDocTitle}
          newDocType={newDocType}
          setNewDocType={setNewDocType}
          newDocHolder={newDocHolder}
          setNewDocHolder={setNewDocHolder}
          newDocExpires={newDocExpires}
          setNewDocExpires={setNewDocExpires}
          onCreateDocument={handleCreateDocument}
        />
      </div>
    </DashboardLayout>
  );
}
