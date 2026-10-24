import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { History, RotateCcw } from 'lucide-react';
import type { BoundaryAuditLogEntry } from '@/lib/boundary-manager/types';

export interface BoundaryAuditTabProps {
  auditHistory: BoundaryAuditLogEntry[];
  previousVersions: Array<{
    id: number;
    version: number;
    status: string;
    pointsCount: number;
    publishedAt: string | null;
    publishedBy: string | null;
  }>;
  canEditBoundary: boolean;
  onReplaceWithVersion: (versionId: number, versionNum: number) => void;
}

export function BoundaryAuditTab({
  auditHistory,
  previousVersions,
  canEditBoundary,
  onReplaceWithVersion,
}: BoundaryAuditTabProps) {
  return (
    <div className="space-y-6">
      {/* ── Audit History View ── */}
      <Card className="border shadow-md">
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-xl font-bold flex items-center gap-2">
                <History className="h-5 w-5 text-primary" />
                <span>Boundary Modification Audit Ledger</span>
              </CardTitle>
              <CardDescription className="text-xs">
                Cryptographically structured audit trail documenting all published versions and boundary revisions.
              </CardDescription>
            </div>
            <Badge variant="outline" className="font-mono text-xs">
              {auditHistory.length} Log Entries
            </Badge>
          </div>
        </CardHeader>
        <CardContent>
          <div className="rounded-xl border overflow-hidden">
            <table className="w-full text-left text-xs">
              <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                <tr>
                  <th className="p-3">Community</th>
                  <th className="p-3">Action</th>
                  <th className="p-3">Changed By</th>
                  <th className="p-3">Revision</th>
                  <th className="p-3">Vertices</th>
                  <th className="p-3">Area Enclosed</th>
                  <th className="p-3">Date / Timestamp</th>
                  <th className="p-3 text-right">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {auditHistory.map((item) => (
                  <tr key={item.id} className="hover:bg-muted/30 transition-colors">
                    <td className="p-3 font-medium text-foreground">{item.community}</td>
                    <td className="p-3">
                      <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                        {item.action}
                      </span>
                    </td>
                    <td className="p-3">
                      <span className="text-foreground font-medium">{item.changedBy}</span>
                      <span className="text-muted-foreground block text-[10px]">({item.role})</span>
                    </td>
                    <td className="p-3 font-mono font-bold">
                      v{item.previousVersion} → v{item.newVersion}
                    </td>
                    <td className="p-3 font-mono">{item.pointsCount} Points</td>
                    <td className="p-3 font-mono">{item.areaAcres} Acres</td>
                    <td className="p-3 text-muted-foreground font-mono">{item.timestamp}</td>
                    <td className="p-3 text-right">
                      <Badge variant="outline" className="font-mono text-[10px] border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                        PUBLISHED
                      </Badge>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* ── Historical Versions & Rollback / Replace Section ── */}
      <Card className="border shadow-md">
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-xl font-bold flex items-center gap-2">
                <RotateCcw className="h-5 w-5 text-indigo-600" />
                <span>Boundary Version History &amp; Snapshot Rollback</span>
              </CardTitle>
              <CardDescription className="text-xs">
                Restore or replace the live community boundary with any previously published cryptographic snapshot.
              </CardDescription>
            </div>
            <Badge variant="outline" className="font-mono text-xs">
              {previousVersions.length} Archived Versions
            </Badge>
          </div>
        </CardHeader>
        <CardContent>
          {previousVersions.length === 0 ? (
            <div className="p-8 text-center rounded-xl border border-dashed text-muted-foreground">
              <RotateCcw className="h-8 w-8 mx-auto mb-2 opacity-40" />
              <p className="font-medium text-sm">No historical version snapshots archived yet.</p>
              <p className="text-xs text-muted-foreground mt-1">
                When you publish revisions to the boundary, immutable snapshots will appear here for 1-click restore.
              </p>
            </div>
          ) : (
            <div className="rounded-xl border overflow-hidden">
              <table className="w-full text-left text-xs">
                <thead className="bg-muted/60 text-muted-foreground font-semibold border-b">
                  <tr>
                    <th className="p-3">Version</th>
                    <th className="p-3">Status</th>
                    <th className="p-3">Vertices</th>
                    <th className="p-3">Published At</th>
                    <th className="p-3">Published By</th>
                    <th className="p-3 text-right">Rollback / Replace</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {previousVersions.map((ver) => (
                    <tr key={ver.id} className="hover:bg-muted/30 transition-colors">
                      <td className="p-3 font-mono font-bold text-foreground">
                        Version {ver.version}
                      </td>
                      <td className="p-3">
                        <Badge 
                          variant="outline" 
                          className={`font-mono text-[10px] ${
                            ver.status === 'PUBLISHED' 
                              ? 'border-emerald-500/40 text-emerald-600 bg-emerald-500/10' 
                              : 'border-amber-500/40 text-amber-600 bg-amber-500/10'
                          }`}
                        >
                          {ver.status}
                        </Badge>
                      </td>
                      <td className="p-3 font-mono">{ver.pointsCount} Points</td>
                      <td className="p-3 text-muted-foreground font-mono">{ver.publishedAt || 'Draft'}</td>
                      <td className="p-3 font-medium text-foreground">{ver.publishedBy || 'System Admin'}</td>
                      <td className="p-3 text-right">
                        {canEditBoundary && (
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onReplaceWithVersion(ver.id, ver.version)}
                            className="h-7 text-xs gap-1.5 font-semibold text-indigo-600 dark:text-indigo-400 border-indigo-500/30 hover:bg-indigo-50 dark:hover:bg-indigo-950/40"
                            title={`Restore active boundary to Version ${ver.version}`}
                          >
                            <RotateCcw className="h-3 w-3" />
                            <span>Replace with v{ver.version}</span>
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
