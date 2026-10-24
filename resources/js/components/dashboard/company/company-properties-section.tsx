import React from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PropertyItem } from './company-types';

interface CompanyPropertiesSectionProps {
  properties: PropertyItem[];
  selected_property_id: number | 'all';
  selected_property: {
    id: number;
    lot_number: string;
    property_code: string;
    street_address: string;
    status: string;
    occupant_name: string;
  } | null;
  onSelectProperty: (propId: number | 'all') => void;
}

export function CompanyPropertiesSection({
  properties,
  selected_property_id,
  selected_property,
  onSelectProperty,
}: CompanyPropertiesSectionProps) {
  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <div className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
            PROPERTIES
          </div>
          <h2 className="text-xl font-bold text-foreground">
            Authorized Company Properties
          </h2>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant={selected_property_id === 'all' ? 'default' : 'outline'}
            size="sm"
            onClick={() => onSelectProperty('all')}
            className="h-8 text-xs font-semibold"
          >
            All Properties ({properties.length})
          </Button>
        </div>
      </div>

      {/* Properties Grid with exact requested status dots */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        {properties.map((prop) => {
          const isSelected = selected_property_id === prop.id;

          return (
            <div
              key={prop.id}
              onClick={() => onSelectProperty(prop.id)}
              className={cn(
                "group relative cursor-pointer rounded-2xl border p-5 transition-all shadow-sm hover:shadow-md",
                isSelected
                  ? "border-primary bg-primary/5 ring-2 ring-primary/30"
                  : "border-border/70 bg-card hover:border-primary/40 hover:bg-muted/20"
              )}
            >
              <div className="flex items-start justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-lg font-bold text-foreground tracking-tight group-hover:text-primary transition-colors">
                      {prop.lot_number}
                    </span>
                    {isSelected && (
                      <Badge className="h-5 px-1.5 text-[10px] bg-primary text-primary-foreground font-semibold">
                        Viewing
                      </Badge>
                    )}
                  </div>
                  <div className="text-xs text-muted-foreground mt-0.5">
                    {prop.street_address}
                  </div>
                </div>

                {/* Exact prompt status: ● Occupied, ● Vacant, ● Rented */}
                <div className="flex items-center gap-1.5">
                  <span
                    className={cn(
                      "h-2.5 w-2.5 rounded-full inline-block animate-pulse",
                      prop.status_color === 'emerald' && "bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]",
                      prop.status_color === 'amber' && "bg-amber-500 shadow-[0_0_8px_rgba(245,158,11,0.5)]",
                      prop.status_color === 'blue' && "bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.5)]"
                    )}
                  />
                  <span
                    className={cn(
                      "text-xs font-bold",
                      prop.status_color === 'emerald' && "text-emerald-600 dark:text-emerald-400",
                      prop.status_color === 'amber' && "text-amber-600 dark:text-amber-400",
                      prop.status_color === 'blue' && "text-blue-600 dark:text-blue-400"
                    )}
                  >
                    {prop.status_label}
                  </span>
                </div>
              </div>

              {/* Occupant / Tenant summary line */}
              <div className="mt-4 border-t border-border/50 pt-3 flex items-center justify-between text-xs">
                <span className="text-muted-foreground">Current Occupancy:</span>
                <span className="font-medium text-foreground truncate max-w-[150px]">
                  {prop.occupant_name}
                </span>
              </div>

              {/* Unit mini stats */}
              <div className="mt-2.5 flex items-center justify-between text-[11px] text-muted-foreground/80 bg-muted/40 rounded-lg px-2.5 py-1.5">
                <span>{prop.stats.residents_count + prop.stats.renters_count} Occupants</span>
                <span>•</span>
                <span>{prop.stats.vehicles_count} Vehicles</span>
                <span>•</span>
                <span>{prop.stats.parking_count} Bays</span>
              </div>
            </div>
          );
        })}
      </div>

      {selected_property && (
        <div className="flex items-center justify-between rounded-xl bg-primary/10 border border-primary/20 px-4 py-2.5 text-xs text-foreground">
          <div className="flex items-center gap-2">
            <Info className="h-4 w-4 text-primary shrink-0" />
            <span>
              Currently filtering dashboard to: <strong>{selected_property.lot_number} ({selected_property.street_address})</strong>
            </span>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => onSelectProperty('all')}
            className="h-7 text-xs font-semibold text-primary hover:bg-primary/20"
          >
            Clear Filter (View All)
          </Button>
        </div>
      )}
    </div>
  );
}
