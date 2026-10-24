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
  Car,
  SquareParking,
  BadgeCheck,
  ShieldAlert,
  CreditCard,
  FileText,
  FileCheck2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { CompanyDashboardProps } from './company-types';

interface CompanyOperationsTabProps {
  operations: CompanyDashboardProps['operations'];
  searchQuery: string;
}

export function CompanyOperationsTab({
  operations,
  searchQuery,
}: CompanyOperationsTabProps) {
  const [opsSubTab, setOpsSubTab] = useState<
    'vehicles' | 'parking' | 'credentials' | 'incidents' | 'payments' | 'documents'
  >('vehicles');

  const q = searchQuery.toLowerCase().trim();

  const filteredVehicles = useMemo(() => {
    if (!q) return operations.vehicles;
    return operations.vehicles.filter(
      (v) => v.license_plate.toLowerCase().includes(q) || v.description.toLowerCase().includes(q) || v.unit.toLowerCase().includes(q)
    );
  }, [operations.vehicles, q]);

  const filteredParking = useMemo(() => {
    if (!q) return operations.parking;
    return operations.parking.filter(
      (p) => p.pass_id.toLowerCase().includes(q) || p.license_plate.toLowerCase().includes(q) || p.assigned_bay.toLowerCase().includes(q)
    );
  }, [operations.parking, q]);

  const filteredCredentials = useMemo(() => {
    if (!q) return operations.credentials;
    return operations.credentials.filter(
      (c) => c.pass_id.toLowerCase().includes(q) || c.holder_name.toLowerCase().includes(q) || c.unit.toLowerCase().includes(q)
    );
  }, [operations.credentials, q]);

  const filteredIncidents = useMemo(() => {
    if (!q) return operations.incidents;
    return operations.incidents.filter(
      (i) => i.title.toLowerCase().includes(q) || i.description.toLowerCase().includes(q) || i.gate.toLowerCase().includes(q)
    );
  }, [operations.incidents, q]);

  const filteredPayments = useMemo(() => {
    if (!q) return operations.payments;
    return operations.payments.filter(
      (p) => p.reference.toLowerCase().includes(q) || p.unit.toLowerCase().includes(q) || p.status.toLowerCase().includes(q)
    );
  }, [operations.payments, q]);

  const filteredDocuments = useMemo(() => {
    if (!q) return operations.documents;
    return operations.documents.filter(
      (d) => d.title.toLowerCase().includes(q) || d.document_type_label.toLowerCase().includes(q)
    );
  }, [operations.documents, q]);

  return (
    <div className="space-y-4">
      {/* Secondary Subtabs: 6 Pillars */}
      <div className="flex flex-wrap items-center gap-1.5 border-b border-border/60 pb-3">
        {[
          { key: 'vehicles', label: 'Vehicles', count: operations.vehicles.length, icon: Car },
          { key: 'parking', label: 'Parking', count: operations.parking.length, icon: SquareParking },
          { key: 'credentials', label: 'Credentials', count: operations.credentials.length, icon: BadgeCheck },
          { key: 'incidents', label: 'Incidents', count: operations.incidents.length, icon: ShieldAlert },
          { key: 'payments', label: 'Payments', count: operations.payments.length, icon: CreditCard },
          { key: 'documents', label: 'Documents', count: operations.documents.length, icon: FileText },
        ].map(({ key, label, count, icon: TabIcon }) => (
          <button
            key={key}
            type="button"
            onClick={() => setOpsSubTab(key as any)}
            className={cn(
              "flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors",
              opsSubTab === key
                ? "bg-primary text-primary-foreground shadow-sm"
                : "text-muted-foreground hover:bg-muted hover:text-foreground"
            )}
          >
            <TabIcon className="h-3.5 w-3.5" />
            <span>{label}</span>
            <span className={cn(
              "rounded-full px-1.5 py-0.2 text-[10px]",
              opsSubTab === key ? "bg-primary-foreground/20 text-primary-foreground" : "bg-muted text-muted-foreground"
            )}>
              {count}
            </span>
          </button>
        ))}
      </div>

      {/* Subtab Content: Vehicles */}
      {opsSubTab === 'vehicles' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Authorized Property Vehicles & ANPR</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredVehicles.length} Vehicles</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Vehicles registered to company-owned properties with automated gate barrier recognition.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">License Plate</TableHead>
                  <TableHead className="text-xs">Vehicle Details</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Parking Bay</TableHead>
                  <TableHead className="text-xs">Features</TableHead>
                  <TableHead className="text-xs text-right">ANPR Gate Access</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredVehicles.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No vehicles registered for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredVehicles.map((v) => (
                    <TableRow key={v.id}>
                      <TableCell className="text-xs">
                        <span className="font-mono font-bold text-sm bg-muted px-2 py-0.5 rounded border border-border/80">
                          {v.license_plate}
                        </span>
                      </TableCell>
                      <TableCell className="text-xs text-foreground font-medium">
                        {v.year} {v.make} {v.model}
                        <span className="text-muted-foreground font-normal ml-1">({v.color})</span>
                      </TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{v.unit}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{v.parking_location}</TableCell>
                      <TableCell className="text-xs">
                        {v.is_ev && (
                          <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                            Electric (EV)
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right text-xs">
                        {v.anpr_enabled ? (
                          <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                            ANPR Authorized
                          </Badge>
                        ) : (
                          <Badge variant="outline" className="text-[10px]">Manual Check</Badge>
                        )}
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Subtab Content: Parking */}
      {opsSubTab === 'parking' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Assigned Parking Bays & Passes</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredParking.length} Bays Allocated</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Designated vehicle bay allocations and active permits.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Pass ID</TableHead>
                  <TableHead className="text-xs">Assigned Bay</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Permit Category</TableHead>
                  <TableHead className="text-xs">Plate / Holder</TableHead>
                  <TableHead className="text-xs text-right">Permit Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredParking.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No parking permits issued for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredParking.map((p) => (
                    <TableRow key={p.id}>
                      <TableCell className="text-xs font-mono font-medium">{p.pass_id}</TableCell>
                      <TableCell className="text-xs font-bold text-foreground">{p.assigned_bay}</TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{p.unit}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className="text-[10px]">{p.category_label}</Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">
                        <span className="font-semibold text-foreground">{p.license_plate}</span> ({p.holder_name})
                      </TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          Active Permit
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

      {/* Subtab Content: Credentials */}
      {opsSubTab === 'credentials' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Digital Credentials & Gate Passes</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredCredentials.length} Credentials</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Cryptographically signed gate passes and NFC credentials associated with authorized units.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Pass ID</TableHead>
                  <TableHead className="text-xs">Holder</TableHead>
                  <TableHead className="text-xs">Unit</TableHead>
                  <TableHead className="text-xs">Access Zone</TableHead>
                  <TableHead className="text-xs">Designated Gate</TableHead>
                  <TableHead className="text-xs text-right">Pass Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredCredentials.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No active credentials found for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredCredentials.map((c) => (
                    <TableRow key={c.id}>
                      <TableCell className="text-xs font-mono font-medium">{c.pass_id}</TableCell>
                      <TableCell className="text-xs font-semibold text-foreground">{c.holder_name}</TableCell>
                      <TableCell className="text-xs font-medium text-foreground">{c.unit}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{c.access_zone}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{c.designated_gate}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          Active Digital Pass
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

      {/* Subtab Content: Incidents */}
      {opsSubTab === 'incidents' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Gate & Safety Exceptions</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredIncidents.length} Records</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Gate clearance events, security check logs, and audit trails.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Incident Description</TableHead>
                  <TableHead className="text-xs">Gate / Location</TableHead>
                  <TableHead className="text-xs">Risk Level</TableHead>
                  <TableHead className="text-xs">Occurred At</TableHead>
                  <TableHead className="text-xs text-right">Resolution</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredIncidents.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center py-8 text-xs text-muted-foreground">
                      No security incidents or gate flags reported for the selected properties.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredIncidents.map((i) => (
                    <TableRow key={i.id}>
                      <TableCell className="text-xs">
                        <div className="font-semibold text-foreground">{i.title}</div>
                        <div className="text-[11px] text-muted-foreground mt-0.5">{i.description}</div>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{i.gate}</TableCell>
                      <TableCell className="text-xs">
                        <Badge variant="outline" className={cn(
                          "text-[10px]",
                          i.risk_level === 'LOW' ? "border-emerald-500/30 text-emerald-600" : "border-amber-500/30 text-amber-600"
                        )}>
                          {i.risk_level} Risk
                        </Badge>
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground">{i.occurred_at}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                          {i.status}
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

      {/* Subtab Content: Payments */}
      {opsSubTab === 'payments' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>HOA Assessments & Property Maintenance Invoices</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredPayments.length} Invoices</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Corporate assessment dues, reserves, and maintenance invoices billed per property.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Invoice Ref</TableHead>
                  <TableHead className="text-xs">Property</TableHead>
                  <TableHead className="text-xs">Billing Period</TableHead>
                  <TableHead className="text-xs">Amount</TableHead>
                  <TableHead className="text-xs">Due Date</TableHead>
                  <TableHead className="text-xs text-right">Payment Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredPayments.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-xs text-muted-foreground">
                      No invoices found for the selected property scope.
                    </TableCell>
                  </TableRow>
                ) : (
                  filteredPayments.map((p) => (
                    <TableRow key={p.id}>
                      <TableCell className="text-xs font-mono font-medium">{p.reference}</TableCell>
                      <TableCell className="text-xs font-semibold text-foreground">{p.unit}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{p.period}</TableCell>
                      <TableCell className="text-xs font-bold text-foreground">{p.amount_formatted}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{p.due_on}</TableCell>
                      <TableCell className="text-right text-xs">
                        <Badge className={cn(
                          "text-[10px]",
                          p.status === 'paid'
                            ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30"
                            : "bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30"
                        )}>
                          {p.status_label}
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

      {/* Subtab Content: Documents */}
      {opsSubTab === 'documents' && (
        <Card>
          <CardHeader className="py-4">
            <CardTitle className="text-base font-bold flex items-center justify-between">
              <span>Legal Documentation & Property Deeds</span>
              <span className="text-xs font-normal text-muted-foreground">{filteredDocuments.length} Documents</span>
            </CardTitle>
            <CardDescription className="text-xs">
              Corporate articles, registered title deeds, board resolutions, and active leases.
            </CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-xs">Document Title</TableHead>
                  <TableHead className="text-xs">Type</TableHead>
                  <TableHead className="text-xs">Registered Holder</TableHead>
                  <TableHead className="text-xs">File Size</TableHead>
                  <TableHead className="text-xs">Registration Date</TableHead>
                  <TableHead className="text-xs text-right">Verification</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredDocuments.map((doc) => (
                  <TableRow key={doc.id}>
                    <TableCell className="text-xs">
                      <div className="font-semibold text-foreground flex items-center gap-1.5">
                        <FileCheck2 className="h-4 w-4 text-primary shrink-0" />
                        <span>{doc.title}</span>
                      </div>
                      <div className="text-[11px] text-muted-foreground font-mono mt-0.5">{doc.file_name}</div>
                    </TableCell>
                    <TableCell className="text-xs">
                      <Badge variant="outline" className="text-[10px]">{doc.document_type_label}</Badge>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">{doc.holder_name}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">{doc.file_size_formatted}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">{doc.issued_at}</TableCell>
                    <TableCell className="text-right text-xs">
                      <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30 text-[10px]">
                        Verified Legal Document
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
