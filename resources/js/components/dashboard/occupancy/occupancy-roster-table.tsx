import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Building, ChevronRight, Clock, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Occupant, OccupancySummary } from './occupancy-types';

export interface OccupancyRosterTableProps {
  activeViewMode: 'inside' | 'expected' | 'map';
  occupants: {
    data: Occupant[];
    total: number;
    current_page: number;
    per_page: number;
    last_page: number;
  };
  activeDisplaySummary: OccupancySummary;
  activeCategory: string;
  onCategoryChange: (cat: string) => void;
  searchQuery: string;
  onSearchQueryChange: (q: string) => void;
  onSearchSubmit: (q: string) => void;
  categoriesList: Array<{ key: string; label: string }>;
  categoryConfig: Record<string, { label: string; icon: any; color: string; badgeClass: string; desc: string }>;
  onSelectPerson: (person: Occupant) => void;
  onOpenUnitModal: (unit: string) => void;
}

export function OccupancyRosterTable({
  activeViewMode,
  occupants,
  activeDisplaySummary,
  activeCategory,
  onCategoryChange,
  searchQuery,
  onSearchQueryChange,
  onSearchSubmit,
  categoriesList,
  categoryConfig,
  onSelectPerson,
  onOpenUnitModal,
}: OccupancyRosterTableProps) {
  return (
    <Card className="border rounded-2xl shadow-xs">
      <CardHeader className="pb-3 border-b">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
          <div>
            <CardTitle className="text-lg font-bold">
              {activeViewMode === 'expected' ? 'Expected Today Registry' : 'Occupant Census Roster'}
            </CardTitle>
            <CardDescription className="text-xs">
              {occupants.total > 0
                ? `Displaying ${occupants.data.length} of ${occupants.total} individuals. Every record retains its property association.`
                : 'No individuals recorded matching this selection.'}
            </CardDescription>
          </div>

          {/* Search Within Roster */}
          <div className="flex items-center gap-2">
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input
                placeholder="Search name, unit, plate, pass ID..."
                value={searchQuery}
                onChange={(e) => onSearchQueryChange(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    onSearchSubmit(searchQuery);
                  }
                }}
                className="h-8 pl-8 text-xs"
              />
            </div>
            {searchQuery && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  onSearchQueryChange('');
                  onSearchSubmit('');
                }}
                className="h-8 px-2 text-xs"
              >
                Clear
              </Button>
            )}
          </div>
        </div>

        {/* Category Filter Tabs */}
        <div className="flex items-center gap-1.5 overflow-x-auto pt-2 text-xs">
          <Button
            size="sm"
            variant={activeCategory === 'all' ? 'default' : 'ghost'}
            onClick={() => onCategoryChange('all')}
            className="h-7 px-2.5 text-xs rounded-lg"
          >
            All ({activeDisplaySummary.total})
          </Button>
          {categoriesList.map((cat) => (
            <Button
              key={cat.key}
              size="sm"
              variant={activeCategory === cat.key ? 'default' : 'ghost'}
              onClick={() => onCategoryChange(cat.key)}
              className="h-7 px-2.5 text-xs rounded-lg whitespace-nowrap"
            >
              {cat.label} ({(activeDisplaySummary as any)[cat.key] || 0})
            </Button>
          ))}
        </div>
      </CardHeader>

      <CardContent className="p-0">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead className="w-[240px]">Person &amp; Role</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Authorized Property</TableHead>
              <TableHead>Authorized By / Host</TableHead>
              <TableHead>Check-in &amp; Gate</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right pr-6">Access Details</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {occupants.data.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="py-12 text-center text-sm text-muted-foreground">
                  No individuals recorded for this selection.
                </TableCell>
              </TableRow>
            ) : (
              occupants.data.map((item) => {
                const cfg = categoryConfig[item.categoryKey] || categoryConfig.visitors;
                const IconComponent = cfg.icon;
                const isExpected = item.checkInStatus === 'EXPECTED' || activeViewMode === 'expected';

                return (
                  <TableRow key={item.id} className="hover:bg-muted/30">
                    {/* 1. Name & Role */}
                    <TableCell className="font-semibold text-foreground">
                      <div className="flex items-center gap-2.5">
                        <div className={cn('p-1.5 rounded-lg shrink-0', cfg.color)}>
                          <IconComponent className="h-4 w-4" />
                        </div>
                        <div>
                          <button
                            type="button"
                            onClick={() => onSelectPerson(item)}
                            className="font-bold text-foreground text-sm hover:text-primary hover:underline text-left block"
                          >
                            {item.name}
                          </button>
                          <div className="flex items-center gap-1.5 text-[11px] text-muted-foreground font-mono">
                            <span>{item.passId}</span>
                            <span>•</span>
                            <span>{item.role}</span>
                          </div>
                        </div>
                      </div>
                    </TableCell>

                    {/* 2. Category */}
                    <TableCell>
                      <Badge variant="outline" className={cn('text-[11px] font-semibold border', cfg.badgeClass)}>
                        {item.categoryLabel}
                      </Badge>
                    </TableCell>

                    {/* 3. Property Association */}
                    <TableCell>
                      <button
                        type="button"
                        onClick={() => onOpenUnitModal(item.unit || item.property)}
                        className="font-mono text-xs font-bold text-primary hover:underline flex items-center gap-1.5"
                        title={`Inspect ${item.unit || item.property}`}
                      >
                        <Building className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                        <span><span className="text-muted-foreground font-normal">Authorized for: </span>{item.unit || item.property}</span>
                      </button>
                      {item.property && item.property !== item.unit && (
                        <span className="text-[10px] text-muted-foreground block truncate max-w-[150px]">
                          {item.property}
                        </span>
                      )}
                    </TableCell>

                    {/* 4. Authorized By / Host */}
                    <TableCell className="text-xs text-foreground">
                      {item.authorizedBy || item.host ? (
                        <span className="font-medium">{item.authorizedBy || item.host}</span>
                      ) : (
                        <span className="text-muted-foreground italic">Estate Administration</span>
                      )}
                    </TableCell>

                    {/* 5. Ingress & Gate */}
                    <TableCell className="text-xs">
                      <div className="flex items-center gap-1 font-mono text-foreground font-medium">
                        <Clock className="h-3 w-3 text-muted-foreground" />
                        <span>Entered: {item.checkedInTime}</span>
                      </div>
                      <div className="text-[10px] text-muted-foreground font-mono">
                        Gate: {item.gate || 'Main Gate'}
                      </div>
                    </TableCell>

                    {/* 6. Status */}
                    <TableCell>
                      <Badge
                        className={cn(
                          'text-[10px] font-bold uppercase',
                          !isExpected
                            ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20'
                            : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20'
                        )}
                      >
                        {!isExpected ? 'INSIDE' : 'EXPECTED'}
                      </Badge>
                    </TableCell>

                    {/* 7. Action: Drill Further */}
                    <TableCell className="text-right pr-6">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => onSelectPerson(item)}
                        className="h-7 px-2 text-[11px] gap-1 font-semibold"
                      >
                        <span>Inspect Access</span>
                        <ChevronRight className="w-3 h-3" />
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}
