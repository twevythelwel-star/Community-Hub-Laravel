import React from 'react';
import { FileText, PlusCircle } from 'lucide-react';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { TabsContent } from '@/components/ui/tabs';
import { ComplianceDocument } from './governance-types';

interface GovernanceDocumentsTabProps {
  documents: ComplianceDocument[];
  onOpenAttachDoc: () => void;
}

export function GovernanceDocumentsTab({
  documents,
  onOpenAttachDoc,
}: GovernanceDocumentsTabProps) {
  return (
    <TabsContent value="documents" className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h3 className="text-sm font-bold tracking-tight">Compliance Document Vault</h3>
          <p className="text-xs text-muted-foreground">
            Attach and verify digital leases, authorization letters, photo IDs, insurance policies, and contractor certifications.
          </p>
        </div>

        <Button
          size="sm"
          onClick={onOpenAttachDoc}
          className="gap-1.5 text-xs font-semibold"
        >
          <PlusCircle className="w-3.5 h-3.5" /> Upload Document
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-3.5">
        {documents.length === 0 && (
          <Card className="md:col-span-3 border-dashed text-center py-8">
            <CardContent className="space-y-1">
              <FileText className="w-8 h-8 text-muted-foreground mx-auto" />
              <h4 className="text-sm font-semibold">No compliance documents</h4>
              <p className="text-xs text-muted-foreground">
                Upload leases, insurance and contractor certificates to track their expiry.
              </p>
            </CardContent>
          </Card>
        )}

        {documents.map((doc) => {
          const daysRemaining = Math.ceil(
            (new Date(doc.expires_at).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24)
          );

          return (
            <Card key={doc.id} className="border border-border/60 hover:shadow-xs transition-all">
              <CardHeader className="p-4 pb-2">
                <div className="flex items-center justify-between gap-2">
                  <Badge variant="outline" className="text-[10px] uppercase font-bold">
                    {doc.document_type.replace('_', ' ')}
                  </Badge>
                  <Badge
                    className={`text-[10px] uppercase font-bold ${
                      doc.status === 'valid'
                        ? 'bg-emerald-600 text-white'
                        : doc.status === 'expiring_soon'
                        ? 'bg-amber-600 text-white animate-pulse'
                        : 'bg-red-600 text-white'
                    }`}
                  >
                    {doc.status.replace('_', ' ')}
                  </Badge>
                </div>
                <CardTitle className="text-sm font-bold mt-2 truncate" title={doc.title}>
                  {doc.title}
                </CardTitle>
                <CardDescription className="text-xs truncate">
                  Holder: <span className="font-semibold text-foreground">{doc.holder_name}</span>
                </CardDescription>
              </CardHeader>
              <CardContent className="p-4 pt-1 space-y-2 text-xs">
                <div className="flex items-center justify-between text-muted-foreground">
                  <span>Expiration:</span>
                  <span className="font-medium text-foreground">
                    {new Date(doc.expires_at).toLocaleDateString()}
                  </span>
                </div>

                <div className="flex items-center justify-between text-muted-foreground">
                  <span>Validity Status:</span>
                  <span
                    className={`font-semibold ${
                      daysRemaining <= 0
                        ? 'text-red-500'
                        : daysRemaining <= 14
                        ? 'text-amber-500'
                        : 'text-emerald-500'
                    }`}
                  >
                    {daysRemaining <= 0 ? 'Expired' : `${daysRemaining} days remaining`}
                  </span>
                </div>

                {doc.gate_pass && (
                  <div className="mt-2 pt-2 border-t text-[11px] text-muted-foreground">
                    <span>Linked Pass: </span>
                    <span className="font-mono font-bold text-foreground">
                      {doc.gate_pass.pass_id}
                    </span>
                    <span className="ml-1 text-[10px] uppercase">({doc.gate_pass.status})</span>
                  </div>
                )}
              </CardContent>
              <CardFooter className="p-4 pt-0 flex justify-between items-center text-xs">
                <span className="text-[11px] text-muted-foreground font-mono">
                  {doc.file_name}
                </span>
                <Button variant="ghost" size="sm" className="h-7 text-xs gap-1">
                  <FileText className="w-3 h-3" /> View
                </Button>
              </CardFooter>
            </Card>
          );
        })}
      </div>
    </TabsContent>
  );
}
