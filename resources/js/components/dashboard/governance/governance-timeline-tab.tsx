import React from 'react';
import {
  History,
  Search,
  Clock,
  Building,
  User,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { TabsContent } from '@/components/ui/tabs';
import { AuditTimelineEvent } from './governance-types';

interface GovernanceTimelineTabProps {
  filteredTimelineEvents: AuditTimelineEvent[];
  searchTimelinePassId: string;
  onSearch: (term: string) => void;
}

export function GovernanceTimelineTab({
  filteredTimelineEvents,
  searchTimelinePassId,
  onSearch,
}: GovernanceTimelineTabProps) {
  return (
    <TabsContent value="timeline" className="space-y-4">
      <Card className="border border-purple-500/20 bg-muted/20">
        <CardContent className="p-4">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3">
            <div>
              <h3 className="text-sm font-bold flex items-center gap-2">
                <History className="w-4 h-4 text-purple-500" />
                Millisecond Chronological Audit Trail
              </h3>
              <p className="text-xs text-muted-foreground">
                Forensic trace of credential presentation, verification checks, security guard approval, hardware gate actuation, and exits.
              </p>
            </div>

            <div className="w-full sm:w-72 relative">
              <Search className="w-4 h-4 absolute left-3 top-2.5 text-muted-foreground" />
              <Input
                placeholder="Search pass ID, holder or gate..."
                value={searchTimelinePassId}
                onChange={(e) => onSearch(e.target.value)}
                className="pl-9 h-9 text-xs"
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {filteredTimelineEvents.length === 0 && (
        <Card className="border-dashed text-center py-8">
          <CardContent className="space-y-1">
            <History className="w-8 h-8 text-muted-foreground mx-auto" />
            <h4 className="text-sm font-semibold">
              {searchTimelinePassId.trim() ? 'No matching events' : 'No access events yet'}
            </h4>
            <p className="text-xs text-muted-foreground">
              Gate scans, approvals and revocations are recorded here as they happen.
            </p>
          </CardContent>
        </Card>
      )}

      {/* Timeline Stream */}
      <div className="relative pl-6 space-y-4 before:absolute before:left-2.5 before:top-3 before:bottom-3 before:w-0.5 before:bg-border/60">
        {filteredTimelineEvents.map((evt, idx) => {
          const isMarySmith = evt.pass_id.includes('MARY');
          return (
            <div key={evt.id || idx} className="relative flex items-start gap-4 group">
              {/* Timeline Node Icon */}
              <div
                className={`absolute -left-6 mt-1 w-5 h-5 rounded-full border-2 bg-background flex items-center justify-center transition-transform group-hover:scale-110 ${
                  evt.severity === 'CRITICAL'
                    ? 'border-red-500 text-red-500'
                    : evt.severity === 'WARNING'
                    ? 'border-amber-500 text-amber-500'
                    : isMarySmith
                    ? 'border-purple-500 text-purple-500'
                    : 'border-primary text-primary'
                }`}
              >
                <div className="w-1.5 h-1.5 rounded-full bg-current" />
              </div>

              {/* Timeline Card */}
              <Card
                className={`flex-1 border transition-all ${
                  isMarySmith
                    ? 'border-purple-500/40 bg-purple-500/5 shadow-xs'
                    : 'border-border/60 hover:border-border'
                }`}
              >
                <CardContent className="p-3.5 sm:p-4">
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <Badge variant="outline" className="font-mono text-[10px]">
                        {evt.pass_id}
                      </Badge>

                      <Badge
                        className={`text-[9px] uppercase font-bold ${
                          evt.event_type === 'GATE_OPENED'
                            ? 'bg-emerald-600 text-white'
                            : evt.event_type === 'IDENTITY_VERIFIED'
                            ? 'bg-blue-600 text-white'
                            : evt.event_type === 'SECURITY_APPROVED'
                            ? 'bg-purple-600 text-white'
                            : evt.event_type === 'CREDENTIAL_DENIED'
                            ? 'bg-red-600 text-white'
                            : 'bg-zinc-700 text-white'
                        }`}
                      >
                        {evt.event_type.replace('_', ' ')}
                      </Badge>

                      <span className="text-xs font-bold text-foreground">
                        {evt.holder_name}
                      </span>
                    </div>

                    <div className="text-xs text-muted-foreground flex items-center gap-1 font-mono">
                      <Clock className="w-3 h-3" />
                      {new Date(evt.occurred_at).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                      })}
                      <span className="text-[10px] text-muted-foreground">
                        ({new Date(evt.occurred_at).toLocaleDateString()})
                      </span>
                    </div>
                  </div>

                  <h4 className="text-sm font-bold text-foreground mt-1">
                    {evt.headline}
                  </h4>

                  {evt.description && (
                    <p className="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                      {evt.description}
                    </p>
                  )}

                  <div className="mt-2 flex items-center gap-3 text-[11px] text-muted-foreground flex-wrap">
                    <span className="flex items-center gap-1">
                      <Building className="w-3 h-3" /> Gate:{' '}
                      <span className="font-semibold text-foreground">{evt.gate}</span>
                    </span>
                    <span className="flex items-center gap-1">
                      <User className="w-3 h-3" /> Actor:{' '}
                      <span className="font-semibold text-foreground">{evt.actor_type}</span>
                    </span>
                    {evt.telemetry && (
                      <span className="font-mono text-[10px] bg-muted/60 px-1.5 py-0.5 rounded">
                        telemetry: {Object.keys(evt.telemetry).length} params
                      </span>
                    )}
                  </div>
                </CardContent>
              </Card>
            </div>
          );
        })}
      </div>
    </TabsContent>
  );
}
