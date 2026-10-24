import React, { useState, useMemo } from 'react';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import {
  Home,
  KeyRound,
  UserCheck,
  User,
  HardHat,
  HeartHandshake,
  Shield,
  Check,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { CompanyInfo, CompanyDashboardProps } from './company-types';

interface CompanyPeopleTabProps {
  people_and_access: CompanyDashboardProps['people_and_access'];
  company: CompanyInfo;
  searchQuery: string;
}

export function CompanyPeopleTab({
  people_and_access,
  company,
  searchQuery,
}: CompanyPeopleTabProps) {
  const [peopleSubTab, setPeopleSubTab] = useState<
    'residents' | 'renters' | 'visitors' | 'staff' | 'contractors' | 'legacy_contacts' | 'representatives'
  >('residents');

  const q = searchQuery.toLowerCase().trim();

  const filteredResidents = useMemo(() => {
    if (!q) return people_and_access.residents;
    return people_and_access.residents.filter(
      (r) => r.name.toLowerCase().includes(q) || r.unit.toLowerCase().includes(q) || r.role.toLowerCase().includes(q)
    );
  }, [people_and_access.residents, q]);

  const filteredRenters = useMemo(() => {
    if (!q) return people_and_access.renters;
    return people_and_access.renters.filter(
      (r) => r.name.toLowerCase().includes(q) || r.unit.toLowerCase().includes(q) || r.stay_type.toLowerCase().includes(q)
    );
  }, [people_and_access.renters, q]);

  const filteredVisitors = useMemo(() => {
    if (!q) return people_and_access.visitors;
    return people_and_access.visitors.filter(
      (v) => v.name.toLowerCase().includes(q) || v.unit.toLowerCase().includes(q) || v.type.toLowerCase().includes(q)
    );
  }, [people_and_access.visitors, q]);

  const filteredStaff = useMemo(() => {
    if (!q) return people_and_access.staff;
    return people_and_access.staff.filter(
      (s) => s.name.toLowerCase().includes(q) || s.unit.toLowerCase().includes(q) || s.role.toLowerCase().includes(q)
    );
  }, [people_and_access.staff, q]);

  const filteredContractors = useMemo(() => {
    if (!q) return people_and_access.contractors;
    return people_and_access.contractors.filter(
      (c) => c.name.toLowerCase().includes(q) || c.unit.toLowerCase().includes(q) || c.trade.toLowerCase().includes(q)
    );
  }, [people_and_access.contractors, q]);

  const filteredLegacyContacts = useMemo(() => {
    if (!q) return people_and_access.legacy_contacts;
    return people_and_access.legacy_contacts.filter(
      (l) => l.name.toLowerCase().includes(q) || l.unit.toLowerCase().includes(q) || l.relationship.toLowerCase().includes(q)
    );
  }, [people_and_access.legacy_contacts, q]);

  const filteredRepresentatives = useMemo(() => {
    if (!q) return people_and_access.representatives;
    return people_and_access.representatives.filter(
      (r) => r.name.toLowerCase().includes(q) || r.role_label.toLowerCase().includes(q) || r.corporate_title.toLowerCase().includes(q)
    );
  }, [people_and_access.representatives, q]);

  return (
    <div className="space-y-4">
      {/* Secondary Subtabs: 7 Pillars */}
      <div className="flex flex-wrap items-center gap-1.5 border-b border-border/60 pb-3">
        {[
          { key: 'residents', label: 'Residents', count: people_and_access.residents.length, icon: Home },
          { key: 'renters', label: 'Renters', count: people_and_access.renters.length, icon: KeyRound },
          { key: 'visitors', label: 'Visitors', count: people_and_access.visitors.length, icon: UserCheck },
          { key: 'staff', label: 'Staff', count: people_and_access.staff.length, icon: User },
          { key: 'contractors', label: 'Contractors', count: people_and_access.contractors.length, icon: HardHat },
          { key: 'legacy_contacts', label: 'Legacy Contacts', count: people_and_access.legacy_contacts.length, icon: HeartHandshake },
          { key: 'representatives', label: 'Representatives', count: people_and_access.representatives.length, icon: Shield },
        ].map(({ key, label, count, icon: TabIcon }) => (
          <button
            key={key}
            type="button"
            onClick={() => setPeopleSubTab(key as any)}
            className={cn(
              "flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors",
              peopleSubTab === key
                ? "bg-primary text-primary-foreground shadow-sm"
                : "text-muted-foreground hover:bg-muted hover:text-foreground"
            )}
          >
            <TabIcon className="h-3.5 w-3.5" />
            <span>{label}</span>
            <span className={cn(
              "rounded-full px-1.5 py-0.2 text-[10px]",
              peopleSubTab === key ? "bg-primary-foreground/20 text-primary-foreground" : "bg-muted text-muted-foreground"
            )}>
              {count}
            </span>
          </button>
        ))}
      </div>

      {/* Subtab Content: Residents */}
      {peopleSubTab === 'residents' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Verified Primary Residents & Household Occupants</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredResidents.length} Residents</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Residents residing inside authorized company-owned units.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Full Name</TableHead>
                  <TableHead className="text-xs">Role</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Contact Information</TableHead>
                  <TableHead className="text-xs">Occupancy Since</TableHead>
                  <TableHead className="text-xs text-right">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredResidents.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No residents found for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredResidents.map((r) => (
                    <TableRow key={r.id}>
                      <TableCell className="font-semibold text-xs text-foreground">
                        {r.name}
                      </TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className="text-[10px] font-normal">
                          {r.role}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-xs font-medium text-foreground">
                        {r.unit}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        <div>{r.phone}</div>
                        <div className="text-[11px] text-muted-foreground/80">{r.email}</div>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        {r.residing_since}
                      </TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          Active Resident
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Renters */}
      {peopleSubTab === 'renters' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Tenants & Rental Occupants</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredRenters.length} Renters</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Long-term tenants and verified short-term guest leases across company properties.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Tenant / Guest</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Stay Type</TableHead>
                  <TableHead className="text-xs">Lease Period</TableHead>
                  <TableHead className="text-xs">Contact</TableHead>
                  <TableHead className="text-xs text-right">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredRenters.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No renters found for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredRenters.map((r) => (
                    <TableRow key={r.id}>
                      <TableCell className="font-semibold text-xs text-foreground">
                        {r.name}
                        {r.notes && (
                          <div className="text-[11px] text-muted-foreground font-normal mt-0.5">
                            {r.notes}
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{r.unit}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className={cn(
                          "text-[10px]",
                          r.stay_type === 'Long-term' ? "border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400" : "border-purple-500/30 bg-purple-500/10 text-purple-600"
                        )}>
                          {r.stay_type}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        {r.lease_start} → {r.lease_end}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{r.contact}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          {r.status}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Visitors */}
      {peopleSubTab === 'visitors' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Scheduled & Checked-In Visitors</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredVisitors.length} Visitors</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Visitor passes issued for company-owned residences.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Visitor Name</TableHead>
                  <TableHead className="text-xs">Destination Unit</TableHead>
                  <TableHead className="text-xs">Pass Type</TableHead>
                  <TableHead className="text-xs">Expected Time</TableHead>
                  <TableHead className="text-xs">Host Account</TableHead>
                  <TableHead className="text-xs text-right">Gate Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredVisitors.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No visitors found for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredVisitors.map((v) => (
                    <TableRow key={v.id}>
                      <TableCell className="font-semibold text-xs text-foreground">{v.name}</TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{v.unit}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className="text-[10px]">{v.type}</Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{v.expected_at}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{v.host_name || 'Unit Host'}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className={cn(
                          "text-[10px]",
                          v.status_label === 'Checked in'
                            ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30"
                            : "bg-blue-500/10 text-blue-600 border-blue-500/30"
                        )}>
                          {v.status_label}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Staff */}
      {peopleSubTab === 'staff' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Household Staff & Caretakers</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredStaff.length} Staff Members</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Domestic staff, housekeepers, and groundskeepers authorized for company units.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Staff Member</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Role / Title</TableHead>
                  <TableHead className="text-xs">Authorized Schedule</TableHead>
                  <TableHead className="text-xs">Contact</TableHead>
                  <TableHead className="text-xs text-right">Gate Access</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredStaff.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No staff members registered for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredStaff.map((s) => (
                    <TableRow key={s.id}>
                      <TableCell className="font-semibold text-xs text-foreground">{s.name}</TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{s.unit}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className="text-[10px]">{s.role}</Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        <div>{Array.isArray(s.schedule) ? s.schedule.join(', ') : 'Recurring Shifts'}</div>
                        <div className="text-[11px] text-muted-foreground/80">{s.time_window}</div>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{s.phone}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          Active Pass
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Contractors */}
      {peopleSubTab === 'contractors' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Service Contractors & Vendors</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredContractors.length} Contractors</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Approved maintenance, plumbing, electrical, and turnover service personnel.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Company / Contractor</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Trade / Scope</TableHead>
                  <TableHead className="text-xs">Permit Validity</TableHead>
                  <TableHead className="text-xs">Work Order Notes</TableHead>
                  <TableHead className="text-xs text-right">Clearance</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredContractors.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No contractors active for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredContractors.map((c) => (
                    <TableRow key={c.id}>
                      <TableCell className="font-semibold text-xs text-foreground">{c.name}</TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{c.unit}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className="text-[10px]">{c.trade}</Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        {c.starts_at} → {c.expires_at}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground max-w-xs truncate">{c.notes}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          Authorized
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Legacy Contacts */}
      {peopleSubTab === 'legacy_contacts' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Legacy Contacts & Emergency Delegates</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredLegacyContacts.length} Contacts</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Continuity delegates and emergency succession contacts for the properties.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Contact Name</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Relationship</TableHead>
                  <TableHead className="text-xs">Emergency Phone</TableHead>
                  <TableHead className="text-xs">Continuity Notes</TableHead>
                  <TableHead className="text-xs text-right">Delegation</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredLegacyContacts.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No legacy contacts assigned for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredLegacyContacts.map((l) => (
                    <TableRow key={l.id}>
                      <TableCell className="font-semibold text-xs text-foreground">{l.name}</TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{l.unit}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className="text-[10px]">{l.relationship}</Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{l.phone}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{l.notes}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          Active Delegate
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Representatives */}
      {peopleSubTab === 'representatives' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Authorized Company Representatives</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredRepresentatives.length} Representatives</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Directors, property managers, and authorized corporate signatories for {company.legal_name}.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Representative</TableHead>
                  <TableHead className="text-xs">Corporate Role</TableHead>
                  <TableHead className="text-xs">Assigned Scope</TableHead>
                  <TableHead className="text-xs">Signatory Rights</TableHead>
                  <TableHead className="text-xs">Contact</TableHead>
                  <TableHead className="text-xs text-right">Verification</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredRepresentatives.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell className="text-xs">
                      <div className="font-semibold text-foreground flex items-center gap-1.5">
                        <span>{r.name}</span>
                        {r.is_primary_contact && (
                          <Badge className="h-4 px-1 text-[9px] bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30">
                            Primary Contact
                          </Badge>
                        )}
                      </div>
                      <div className="text-[11px] text-muted-foreground">{r.corporate_title}</div>
                    </TableCell>
                    <TableCell className="text-xs">
                      <Badge variant="outline" className="text-[10px] font-medium">
                        {r.role_label}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-xs font-medium text-foreground">
                      {r.managed_properties_summary}
                    </TableCell>
                    <TableCell className="text-xs">
                      {r.is_signatory ? (
                        <span className="text-emerald-600 dark:text-emerald-400 font-semibold flex items-center gap-1 text-[11px]">
                          <Check className="h-3 w-3" /> Legal Signatory
                        </span>
                      ) : (
                        <span className="text-muted-foreground text-[11px]">Non-signatory</span>
                      )}
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                      <div>{r.email}</div>
                      <div className="text-[11px]">{r.phone}</div>
                    </TableCell>
                    <TableCell className="text-right text-xs">
                      <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                        Verified
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
