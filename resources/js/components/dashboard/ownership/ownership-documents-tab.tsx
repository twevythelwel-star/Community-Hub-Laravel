import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { FileBadge } from 'lucide-react';
import { OwnershipProps } from './ownership-types';

export interface OwnershipDocumentsTabProps {
  ownershipType: OwnershipProps['ownershipType'];
}

export function OwnershipDocumentsTab({ ownershipType }: OwnershipDocumentsTabProps) {
  return (
    <Card className="border shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="text-base font-semibold flex items-center gap-2">
          <FileBadge className="h-4 w-4 text-primary" />
          Statutory & Compliance Records
        </CardTitle>
        <CardDescription className="text-xs">
          Legal documents filed with Jamaican regulatory authorities.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-2 text-xs">
        {ownershipType === 'company' && (
          <>
            <div className="p-3 rounded-lg border bg-background flex items-center justify-between">
              <div>
                <div className="font-semibold text-foreground">Certificate of Incorporation</div>
                <div className="text-[11px] text-muted-foreground">Companies Office of Jamaica • Certified True Copy</div>
              </div>
              <Badge className="bg-emerald-600 text-white text-[10px]">Verified Valid</Badge>
            </div>
            <div className="p-3 rounded-lg border bg-background flex items-center justify-between">
              <div>
                <div className="font-semibold text-foreground">Articles of Incorporation</div>
                <div className="text-[11px] text-muted-foreground">Certified Memorandum & Articles of Association</div>
              </div>
              <Badge className="bg-emerald-600 text-white text-[10px]">Verified Valid</Badge>
            </div>
            <div className="p-3 rounded-lg border bg-background flex items-center justify-between">
              <div>
                <div className="font-semibold text-foreground">Form 9 Annual Return</div>
                <div className="text-[11px] text-muted-foreground">Current Good Standing Annual Return Record</div>
              </div>
              <Badge className="bg-emerald-600 text-white text-[10px]">Active Filing</Badge>
            </div>
          </>
        )}

        {ownershipType === 'individual' && (
          <div className="p-3 rounded-lg border bg-background flex items-center justify-between">
            <div>
              <div className="font-semibold text-foreground">Duplicate Certificate of Title</div>
              <div className="text-[11px] text-muted-foreground">National Land Agency (NLA) • Volume 1402, Folio 88</div>
            </div>
            <Badge className="bg-emerald-600 text-white text-[10px]">Verified Title</Badge>
          </div>
        )}

        {ownershipType === 'trust' && (
          <div className="p-3 rounded-lg border bg-background flex items-center justify-between">
            <div>
              <div className="font-semibold text-foreground">Stamp Commissioner Certified Trust Deed</div>
              <div className="text-[11px] text-muted-foreground">Stamped Deed of Settlement & Trustee Resolution</div>
            </div>
            <Badge className="bg-emerald-600 text-white text-[10px]">Verified Deed</Badge>
          </div>
        )}

        {ownershipType === 'estate' && (
          <div className="p-3 rounded-lg border bg-background flex items-center justify-between">
            <div>
              <div className="font-semibold text-foreground">Supreme Court Grant of Probate</div>
              <div className="text-[11px] text-muted-foreground">Suit #PROB-2023-0182 with Certified Death Certificate</div>
            </div>
            <Badge className="bg-emerald-600 text-white text-[10px]">Court Order Valid</Badge>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
