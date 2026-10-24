import React from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Layers,
  Briefcase,
  Home,
  UserCheck,
  Key,
  ChevronRight,
} from 'lucide-react';
import { HierarchyPillarItem, OwnershipProps } from './ownership-types';

export interface OwnershipModelTabProps {
  propertiesCount: number;
  activeEntity: OwnershipProps['activeEntity'];
  hierarchy: OwnershipProps['hierarchy'];
  selectedPillar: 'all' | 'entity_people' | 'occupants' | 'access_relationships' | 'credentials';
  onSelectPillar: (pillar: 'all' | 'entity_people' | 'occupants' | 'access_relationships' | 'credentials') => void;
  filteredHierarchyItems: HierarchyPillarItem[];
  allHierarchyItemsCount: number;
  onInspectItem: (item: HierarchyPillarItem) => void;
}

export function OwnershipModelTab({
  propertiesCount,
  activeEntity,
  hierarchy,
  selectedPillar,
  onSelectPillar,
  filteredHierarchyItems,
  allHierarchyItemsCount,
  onInspectItem,
}: OwnershipModelTabProps) {
  return (
    <div className="space-y-6">
      {/* Architecture Hierarchy Blueprint Card */}
      <Card className="border shadow-sm bg-gradient-to-br from-background via-muted/20 to-primary/5">
        <CardHeader className="pb-3">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
              <CardTitle className="text-base font-semibold flex items-center gap-2">
                <Layers className="h-5 w-5 text-primary" />
                The A+ Enterprise Data Architecture
              </CardTitle>
              <CardDescription className="text-xs">
                Properties belong to legal ownership entities; people hold verified functional relationships answering all 6 cardinal authorization questions.
              </CardDescription>
            </div>
            <Badge variant="outline" className="font-mono text-[11px] bg-background">
              Owner = Entity, Not Person
            </Badge>
          </div>
        </CardHeader>
        <CardContent className="space-y-4 pt-1">
          {/* Visual Tree Breakdown */}
          <div className="p-4 bg-muted/40 rounded-xl border font-mono text-xs text-foreground/90 space-y-1.5 leading-relaxed overflow-x-auto">
            <div className="text-primary font-bold">Community (Fairview Estate)</div>
            <div className="pl-4 text-foreground/80">└── Property Portfolio ({propertiesCount} Units)</div>
            <div className="pl-8 text-foreground/90 font-semibold">
              └── Ownership Entity ({activeEntity?.legal_name} • {activeEntity?.entity_type_label})
            </div>
            <div className="pl-12 text-emerald-600 dark:text-emerald-400">
              ├── Entity People ({hierarchy?.summary.total_entity_people || 0}) — Director, Trustee, Representative, Manager, Executor
            </div>
            <div className="pl-12 text-sky-600 dark:text-sky-400">
              ├── Occupants ({hierarchy?.summary.total_occupants || 0}) — Resident, Long-Term Renter, Short-Term Guest
            </div>
            <div className="pl-12 text-amber-600 dark:text-amber-400">
              ├── Access Relationships ({hierarchy?.summary.total_access_relationships || 0}) — Visitor, Staff, Contractor, Legacy Contact
            </div>
            <div className="pl-12 text-purple-600 dark:text-purple-400">
              └── Credentials ({hierarchy?.summary.total_credentials || 0}) — QR Code, NFC Keyfob, Mobile Wallet
            </div>
          </div>

          {/* 4 Pillar Filter Buttons */}
          <div className="flex flex-wrap items-center gap-2 pt-2">
            <Button
              size="sm"
              variant={selectedPillar === 'all' ? 'default' : 'outline'}
              onClick={() => onSelectPillar('all')}
              className="text-xs h-8"
            >
              All Authorizations ({allHierarchyItemsCount})
            </Button>
            <Button
              size="sm"
              variant={selectedPillar === 'entity_people' ? 'default' : 'outline'}
              onClick={() => onSelectPillar('entity_people')}
              className="text-xs h-8 gap-1.5 text-emerald-600 dark:text-emerald-400 border-emerald-500/20"
            >
              <Briefcase className="h-3.5 w-3.5" />
              Entity People ({hierarchy?.summary.total_entity_people || 0})
            </Button>
            <Button
              size="sm"
              variant={selectedPillar === 'occupants' ? 'default' : 'outline'}
              onClick={() => onSelectPillar('occupants')}
              className="text-xs h-8 gap-1.5 text-sky-600 dark:text-sky-400 border-sky-500/20"
            >
              <Home className="h-3.5 w-3.5" />
              Occupants ({hierarchy?.summary.total_occupants || 0})
            </Button>
            <Button
              size="sm"
              variant={selectedPillar === 'access_relationships' ? 'default' : 'outline'}
              onClick={() => onSelectPillar('access_relationships')}
              className="text-xs h-8 gap-1.5 text-amber-600 dark:text-amber-400 border-amber-500/20"
            >
              <UserCheck className="h-3.5 w-3.5" />
              Access Relationships ({hierarchy?.summary.total_access_relationships || 0})
            </Button>
            <Button
              size="sm"
              variant={selectedPillar === 'credentials' ? 'default' : 'outline'}
              onClick={() => onSelectPillar('credentials')}
              className="text-xs h-8 gap-1.5 text-purple-600 dark:text-purple-400 border-purple-500/20"
            >
              <Key className="h-3.5 w-3.5" />
              Credentials ({hierarchy?.summary.total_credentials || 0})
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Pillar Items Grid with 6-question summary */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {filteredHierarchyItems.map((item) => (
          <Card
            key={item.id}
            onClick={() => onInspectItem(item)}
            className="border shadow-sm hover:border-primary/50 cursor-pointer transition-all flex flex-col justify-between group"
          >
            <CardHeader className="p-4 pb-2">
              <div className="flex items-start justify-between">
                <div className="space-y-1">
                  <Badge
                    variant="outline"
                    className={`text-[10px] uppercase font-semibold tracking-wider ${
                      item.pillar === 'entity_people'
                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200'
                        : item.pillar === 'occupants'
                        ? 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300 border-sky-200'
                        : item.pillar === 'access_relationships'
                        ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200'
                        : 'bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300 border-purple-200'
                    }`}
                  >
                    {item.answers.relationship.label}
                  </Badge>
                  <CardTitle className="text-sm font-semibold text-foreground group-hover:text-primary transition-colors">
                    {item.answers.who.name}
                  </CardTitle>
                </div>
                <Badge variant={item.answers.validity.is_active ? 'default' : 'secondary'} className="text-[10px]">
                  {item.answers.validity.status}
                </Badge>
              </div>
            </CardHeader>

            <CardContent className="p-4 pt-1 space-y-2.5 text-xs text-muted-foreground">
              <div className="space-y-1">
                <div>Scope: <span className="text-foreground font-medium">{item.answers.scope.properties.join(', ')}</span></div>
                <div>Expiry: <span className="text-foreground">{item.answers.validity.valid_until}</span></div>
                <div>Authorized By: <span className="text-foreground">{item.answers.authorizer.name}</span></div>
              </div>

              <div className="pt-2 border-t flex items-center justify-between text-[11px] text-primary font-medium">
                <span>Inspect 6 Invariants</span>
                <ChevronRight className="h-3.5 w-3.5 group-hover:translate-x-0.5 transition-transform" />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
