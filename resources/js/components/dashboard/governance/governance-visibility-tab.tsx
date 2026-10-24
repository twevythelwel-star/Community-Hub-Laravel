import React from 'react';
import {
  Eye,
  CheckCircle2,
  Sparkles,
  Lock,
} from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsContent } from '@/components/ui/tabs';
import { VisibilityRule } from './governance-types';

interface GovernanceVisibilityTabProps {
  visibilityMatrix: VisibilityRule[];
  simulatorRole: string;
  setSimulatorRole: (role: string) => void;
}

export function GovernanceVisibilityTab({
  visibilityMatrix,
  simulatorRole,
  setSimulatorRole,
}: GovernanceVisibilityTabProps) {
  const currentRule =
    visibilityMatrix.find((r) => r.role === simulatorRole) || visibilityMatrix[0];

  return (
    <TabsContent value="visibility" className="space-y-4">
      <Card className="border border-cyan-500/20 bg-linear-to-br from-cyan-500/5 to-transparent">
        <CardHeader className="p-4 pb-2">
          <CardTitle className="text-sm font-bold flex items-center justify-between flex-wrap gap-2">
            <div className="flex items-center gap-2">
              <Eye className="w-4 h-4 text-cyan-500" />
              Property-Scoped Visibility & Authorization Model
            </div>
            <Badge variant="outline" className="text-xs text-cyan-600 border-cyan-300">
              Default Scope: Property / Address
            </Badge>
          </CardTitle>
          <CardDescription className="text-xs space-y-1">
            <p>
              <strong>Core Rule:</strong> A person can only see people and access information that their role is authorized to see, and the default scope is the property/address they are associated with.
            </p>
            <p className="text-cyan-700 dark:text-cyan-300 font-medium">
              ⚡ <strong>Crucial Separation of Duties:</strong> &quot;Can See&quot; (visibility/inspection) and &quot;Can Act&quot; (creation/mutation/approval) are strictly separate permissions across all 10 roles.
            </p>
          </CardDescription>
        </CardHeader>

        <CardContent className="p-4 pt-2 space-y-6">
          {/* Governance Role Matrix Table */}
          <div className="rounded-xl border border-border/60 overflow-hidden bg-card/60 shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="bg-muted/70 border-b border-border/60 text-muted-foreground font-semibold">
                    <th className="py-2.5 px-3">Role</th>
                    <th className="py-2.5 px-3">Own Property</th>
                    <th className="py-2.5 px-3 text-center">Other Properties</th>
                    <th className="py-2.5 px-3 text-center">Entire Community</th>
                    <th className="py-2.5 px-3">Can See vs Can Act Summary</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/40">
                  {visibilityMatrix.map((rule) => {
                    const isSelected = simulatorRole === rule.role;
                    return (
                      <tr
                        key={rule.role}
                        onClick={() => setSimulatorRole(rule.role)}
                        className={`cursor-pointer transition-colors ${
                          isSelected ? 'bg-cyan-500/10 font-medium' : 'hover:bg-muted/40'
                        }`}
                      >
                        <td className="py-2.5 px-3">
                          <div className="flex items-center gap-1.5">
                            <Badge
                              variant={isSelected ? 'default' : 'outline'}
                              className="text-[11px] font-semibold"
                            >
                              {rule.role}
                            </Badge>
                          </div>
                        </td>

                        <td className="py-2.5 px-3">
                          {rule.own_property === true ? (
                            <Badge className="bg-emerald-600 text-white text-[10px]">✅ Full Scope</Badge>
                          ) : (
                            <span className="text-[11px] font-medium text-foreground">
                              {String(rule.own_property)}
                            </span>
                          )}
                        </td>

                        <td className="py-2.5 px-3 text-center">
                          {rule.other_properties ? (
                            <Badge className="bg-emerald-600 text-white text-[10px]">✅</Badge>
                          ) : (
                            <Badge variant="outline" className="text-red-600 border-red-300 text-[10px]">❌</Badge>
                          )}
                        </td>

                        <td className="py-2.5 px-3 text-center">
                          {rule.entire_community === 'All' || rule.entire_community === true ? (
                            <Badge className="bg-purple-600 text-white text-[10px]">All ✅</Badge>
                          ) : (
                            <Badge variant="outline" className="text-red-600 border-red-300 text-[10px]">❌</Badge>
                          )}
                        </td>

                        <td className="py-2.5 px-3">
                          <div className="text-[11px] space-y-0.5">
                            <span className="text-muted-foreground block truncate max-w-xs">
                              <strong className="text-emerald-600">See:</strong> {rule.can_see[0]}
                            </span>
                            <span className="text-muted-foreground block truncate max-w-xs">
                              <strong className="text-blue-600">Act:</strong> {rule.can_act[0]}
                            </span>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>

          {/* Interactive Role Scope Inspector & Permission Simulator */}
          {currentRule && (
            <div className="p-4 rounded-xl border border-border/80 bg-muted/20 space-y-4">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-border/60 pb-3">
                <div>
                  <h4 className="text-xs font-bold text-foreground flex items-center gap-2">
                    <span>Deep Dive Scope Inspector:</span>
                    <Badge className="bg-cyan-600 text-white text-xs">{currentRule.role}</Badge>
                  </h4>
                  <p className="text-[11px] text-muted-foreground mt-0.5">
                    Demonstrating exact architectural distinction between viewing rights and mutation capabilities.
                  </p>
                </div>
                <div className="flex items-center gap-1.5 flex-wrap">
                  <span className="text-[11px] text-muted-foreground mr-1">Switch Persona:</span>
                  {visibilityMatrix.slice(0, 5).map((r) => (
                    <Button
                      key={r.role}
                      variant={simulatorRole === r.role ? 'default' : 'outline'}
                      size="sm"
                      className="h-6 text-[10px] px-2"
                      onClick={() => setSimulatorRole(r.role)}
                    >
                      {r.role}
                    </Button>
                  ))}
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                {/* What Role Can See */}
                <div className="p-3.5 rounded-lg border border-emerald-500/30 bg-emerald-500/5 space-y-2">
                  <div className="flex items-center gap-2 text-emerald-700 dark:text-emerald-400 font-bold text-xs">
                    <CheckCircle2 className="w-4 h-4 shrink-0" />
                    <span>Authorized Visibility (&quot;Can See&quot;)</span>
                  </div>
                  <ul className="space-y-1.5 text-[11px] text-muted-foreground">
                    {currentRule.can_see.map((item, idx) => (
                      <li key={idx} className="flex items-start gap-1.5">
                        <span className="text-emerald-500 mt-0.5 font-bold">✓</span>
                        <span>{item}</span>
                      </li>
                    ))}
                  </ul>
                </div>

                {/* What Role Can Act On */}
                <div className="p-3.5 rounded-lg border border-blue-500/30 bg-blue-500/5 space-y-2">
                  <div className="flex items-center gap-2 text-blue-700 dark:text-blue-400 font-bold text-xs">
                    <Sparkles className="w-4 h-4 shrink-0" />
                    <span>Action Capabilities (&quot;Can Act&quot;)</span>
                  </div>
                  <ul className="space-y-1.5 text-[11px] text-muted-foreground">
                    {currentRule.can_act.map((item, idx) => (
                      <li key={idx} className="flex items-start gap-1.5">
                        <span className="text-blue-500 mt-0.5 font-bold">⚡</span>
                        <span>{item}</span>
                      </li>
                    ))}
                  </ul>
                </div>

                {/* Guardrails: Why Can See != Can Act */}
                <div className="p-3.5 rounded-lg border border-amber-500/30 bg-amber-500/5 space-y-2">
                  <div className="flex items-center gap-2 text-amber-700 dark:text-amber-400 font-bold text-xs">
                    <Lock className="w-4 h-4 shrink-0" />
                    <span>Strict Security Guardrails</span>
                  </div>
                  <ul className="space-y-1.5 text-[11px] text-muted-foreground">
                    {currentRule.cannot_act.map((item, idx) => (
                      <li key={idx} className="flex items-start gap-1.5">
                        <span className="text-amber-600 mt-0.5 font-bold">🚫</span>
                        <span>{item}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </TabsContent>
  );
}
