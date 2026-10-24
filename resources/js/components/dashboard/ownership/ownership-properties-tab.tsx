import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Home } from 'lucide-react';
import { OwnershipProps } from './ownership-types';

export interface OwnershipPropertiesTabProps {
  activeEntity: OwnershipProps['activeEntity'];
  properties: OwnershipProps['properties'];
}

export function OwnershipPropertiesTab({
  activeEntity,
  properties,
}: OwnershipPropertiesTabProps) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold text-foreground">
            Linked Property Portfolio
          </h2>
          <p className="text-xs text-muted-foreground">
            Real estate units and lots registered under {activeEntity?.legal_name || 'your profile'}.
          </p>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {properties.map((prop) => (
          <Card key={prop.id} className="border shadow-sm">
            <CardHeader className="p-4 pb-2">
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-semibold flex items-center gap-1.5">
                  <Home className="h-4 w-4 text-primary" />
                  {prop.lot_number}
                </CardTitle>
                <Badge variant="outline" className="capitalize text-xs">
                  {prop.status}
                </Badge>
              </div>
              <CardDescription className="text-xs">
                {prop.street_address}
              </CardDescription>
            </CardHeader>
            <CardContent className="p-4 pt-1 space-y-2 text-xs">
              <div className="flex items-center justify-between text-muted-foreground">
                <span>Type:</span>
                <span className="text-foreground font-medium capitalize">{prop.property_type}</span>
              </div>
              <div className="flex items-center justify-between text-muted-foreground">
                <span>Occupant:</span>
                <span className="text-foreground font-medium">{prop.primary_occupant?.name || 'Vacant / Corporate'}</span>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
