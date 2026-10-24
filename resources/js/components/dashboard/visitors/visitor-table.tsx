import { router } from '@inertiajs/react';
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import {
  MoreHorizontal,
  Camera,
  ShieldOff,
  Share2,
  FileDown,
  Copy,
  Edit,
  MessageSquare,
  Car,
  Search,
  Send,
  Clock,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { format, parseISO } from 'date-fns';
import { cn } from '@/lib/utils';
import { CategoryShapeIcon } from '@/lib/gate-pass-engine/shapes';
import { CATEGORY_SHAPES } from '@/lib/gate-pass-engine/config';
import type { PassStatus } from '@/lib/gate-pass-engine/types';
import { NOT_ADMISSIBLE, type Paginated, type VisitorRow } from './visitor-types';

type VisitorTableProps = {
  visitors: Paginated<VisitorRow>;
  filters: { status?: string; search?: string; tab?: string };
  canManage: boolean;
  currentTab: string;
  plateQuery: string;
  setPlateQuery: (q: string) => void;
  onSelectVisitor: (v: VisitorRow) => void;
  onEditVisitor: (v: VisitorRow) => void;
  onSharePass: (v: VisitorRow) => void;
  onCopyPassLink: (v: VisitorRow) => void;
  onShareViaWhatsApp: (v: VisitorRow) => void;
  onServerResend: (v: VisitorRow, channel: 'sms' | 'whatsapp' | 'email') => void;
  onExtendPass: (v: VisitorRow, hours: number) => void;
  onDeleteVisitor: (v: VisitorRow) => void;
  onChangeStatus: (v: VisitorRow, action: 'check-in' | 'check-out') => void;
  onTransitionPass: (v: VisitorRow, to: PassStatus, label: string) => void;
};

export function VisitorTable({
  visitors,
  filters,
  canManage,
  currentTab,
  plateQuery,
  setPlateQuery,
  onSelectVisitor,
  onEditVisitor,
  onSharePass,
  onCopyPassLink,
  onShareViaWhatsApp,
  onServerResend,
  onExtendPass,
  onDeleteVisitor,
  onChangeStatus,
  onTransitionPass,
}: VisitorTableProps) {
  const filteredVisitors = visitors.data.filter((v) => {
    if (!plateQuery.trim()) return true;
    const q = plateQuery.toLowerCase();
    return (
      v.name.toLowerCase().includes(q) ||
      (v.contact && v.contact.toLowerCase().includes(q)) ||
      (v.vehicle && v.vehicle.toLowerCase().includes(q)) ||
      (v.hostLot && v.hostLot.toLowerCase().includes(q))
    );
  });

  return (
    <Card className="rounded-2xl shadow-xs border">
      <CardHeader className="pb-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <CardTitle className="text-xl font-bold">
              {canManage
                ? currentTab === 'history'
                  ? 'Visitor History'
                  : currentTab === 'rejected'
                    ? 'Rejected Visitors & Denials'
                    : "Today's Visitors"
                : 'Registered Visitors'}
            </CardTitle>
            <CardDescription className="text-xs">
              {canManage
                ? 'Security access registry with time logs and instant gate control.'
                : 'Visitors registered for your property.'}
              {visitors.total > 0 && ` Showing ${visitors.from}–${visitors.to} of ${visitors.total}.`}
            </CardDescription>
          </div>

          <div className="flex items-center gap-2 w-full sm:w-auto">
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
              <Input
                type="search"
                placeholder="Search name, phone, plate..."
                value={plateQuery}
                onChange={(e) => setPlateQuery(e.target.value)}
                className="pl-8 h-8 text-xs rounded-xl"
              />
            </div>
          </div>
        </div>
      </CardHeader>

      <CardContent className="overflow-x-auto p-0">
        <Table>
          <TableHeader>
            <TableRow className="bg-muted/40">
              <TableHead className="font-bold">Visitor</TableHead>
              <TableHead className="font-bold">Type</TableHead>
              <TableHead className="font-bold">Host</TableHead>
              <TableHead className="font-bold">Status</TableHead>
              <TableHead className="font-bold">Time</TableHead>
              <TableHead className="font-bold text-right pr-6">Actions</TableHead>
            </TableRow>
          </TableHeader>

          <TableBody>
            {filteredVisitors.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-12 text-center text-sm text-muted-foreground">
                  {plateQuery
                    ? `No visitors match "${plateQuery}".`
                    : filters.search
                      ? 'No visitors match that search query.'
                      : currentTab === 'rejected'
                        ? 'Zero rejected visitors recorded today.'
                        : 'No visitors recorded under this category.'}
                </TableCell>
              </TableRow>
            )}

            {filteredVisitors.map((visitor) => (
              <TableRow
                key={visitor.id}
                className={cn(
                  visitor.isBlocked && 'bg-destructive/10 hover:bg-destructive/20',
                  visitor.expired && 'opacity-60',
                )}
              >
                {/* Column 1: Visitor */}
                <TableCell className="font-semibold text-foreground">
                  <div className="flex items-center gap-2">
                    <span>{visitor.name}</span>
                    {visitor.isBlocked && (
                      <Tooltip>
                        <TooltipTrigger>
                          <ShieldOff className="h-4 w-4 text-destructive shrink-0" />
                        </TooltipTrigger>
                        <TooltipContent>
                          <p>This individual is on the community blocklist.</p>
                        </TooltipContent>
                      </Tooltip>
                    )}
                    {visitor.expired && (
                      <Badge variant="outline" className="text-[10px] text-rose-500 border-rose-500/30">
                        No-show
                      </Badge>
                    )}
                  </div>
                  {visitor.arrivalWindow && (
                    <div className="mt-1 flex items-center gap-1.5 text-[11px] text-muted-foreground font-normal">
                      <Clock className="w-3 h-3 text-primary shrink-0" />
                      <span className="font-mono text-foreground/80">{visitor.arrivalWindow}</span>
                    </div>
                  )}
                  {visitor.parkingInstructions && (
                    <div className="mt-0.5 flex items-center gap-1.5 text-[10px] text-muted-foreground/80 font-normal">
                      <Car className="w-3 h-3 text-muted-foreground shrink-0" />
                      <span className="truncate max-w-[200px]" title={visitor.parkingInstructions}>
                        {visitor.parkingInstructions}
                      </span>
                    </div>
                  )}
                </TableCell>

                {/* Column 2: Type */}
                <TableCell className="text-xs text-muted-foreground font-medium">
                  {visitor.type}
                </TableCell>

                {/* Column 3: Host */}
                <TableCell className="text-xs font-mono font-medium text-foreground">
                  {visitor.hostLot || visitor.homeowner || '#104'}
                </TableCell>

                {/* Column 4: Status */}
                <TableCell>
                  <Badge
                    variant={
                      visitor.status === 'Checked In'
                        ? 'default'
                        : visitor.status === 'Checked Out'
                          ? 'secondary'
                          : 'outline'
                    }
                    className={cn(
                      'text-xs font-bold',
                      visitor.status === 'Checked In' && 'bg-emerald-600 text-white hover:bg-emerald-700'
                    )}
                  >
                    {visitor.status === 'Checked In' ? 'Inside' : visitor.status}
                  </Badge>
                  {visitor.pass && (
                    <div className="mt-1.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                      <CategoryShapeIcon
                        shape={CATEGORY_SHAPES[visitor.pass.category]}
                        className="h-3.5 w-3.5 shrink-0"
                        color={visitor.pass.color.hex}
                      />
                      <span className="font-mono">{visitor.pass.passId}</span>
                      <span aria-hidden>·</span>
                      <span className={cn(NOT_ADMISSIBLE.includes(visitor.pass.status) && 'text-destructive font-semibold')}>
                        {visitor.pass.statusLabel}
                      </span>
                      {visitor.pass.actions.length > 0 && (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button size="sm" variant="ghost" className="h-5 px-1.5 text-[11px]">
                              Pass ▾<span className="sr-only"> actions for {visitor.name}</span>
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="start">
                            <DropdownMenuLabel>Pass {visitor.pass.passId}</DropdownMenuLabel>
                            {visitor.pass.actions.map((action) => (
                              <DropdownMenuItem
                                key={action.status}
                                className={cn(['REJECTED', 'REVOKED', 'CANCELLED'].includes(action.status) && 'text-destructive')}
                                onClick={() => onTransitionPass(visitor, action.status, action.label)}
                              >
                                {action.label}
                              </DropdownMenuItem>
                            ))}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      )}
                    </div>
                  )}
                </TableCell>

                {/* Column 5: Time */}
                <TableCell className="text-xs font-mono text-muted-foreground">
                  {visitor.timeDisplay || format(parseISO(visitor.expectedAt), 'g:i A')}
                </TableCell>

                {/* Column 6: Actions */}
                <TableCell className="text-right pr-6">
                  {canManage ? (
                    <div className="flex items-center justify-end gap-2">
                      <Button
                        size="sm"
                        variant={visitor.status === 'Checked In' ? 'default' : 'outline'}
                        onClick={() => onChangeStatus(visitor, visitor.status === 'Checked In' ? 'check-out' : 'check-in')}
                        disabled={
                          visitor.isBlocked ||
                          visitor.expired ||
                          (visitor.status !== 'Checked In' && visitor.pass != null && NOT_ADMISSIBLE.includes(visitor.pass.status)) ||
                          (visitor.status === 'Checked Out' && (visitor.pass == null || visitor.pass.singleEntry))
                        }
                        className={cn(
                          'h-8 px-3 text-xs font-bold',
                          visitor.status === 'Checked In' && 'bg-blue-600 hover:bg-blue-700 text-white'
                        )}
                      >
                        {visitor.status === 'Checked In' ? 'Check Out' : 'Check In'}
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        className="h-8 w-8 text-muted-foreground hover:text-foreground"
                        onClick={() => onSelectVisitor(visitor)}
                        title={`View ID for ${visitor.name}`}
                      >
                        <Camera className="h-4 w-4" />
                        <span className="sr-only">View ID for {visitor.name}</span>
                      </Button>
                    </div>
                  ) : (
                    <div className="flex items-center justify-end gap-1">
                      {visitor.shareToken && (
                        <Button
                          size="sm"
                          variant="outline"
                          className="h-8 px-2.5 text-xs gap-1.5 border-emerald-500/30 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                          onClick={() => onSharePass(visitor)}
                          title="Share Guest Pass"
                        >
                          <Share2 className="h-3.5 w-3.5" />
                          <span className="hidden sm:inline">Share</span>
                        </Button>
                      )}
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button aria-haspopup="true" size="icon" variant="ghost">
                            <MoreHorizontal className="h-4 w-4" />
                            <span className="sr-only">Actions for {visitor.name}</span>
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                          <DropdownMenuLabel>Actions</DropdownMenuLabel>
                          {visitor.shareToken && (
                            <>
                              <DropdownMenuItem onClick={() => onSharePass(visitor)}>
                                <Share2 className="h-4 w-4 mr-2 text-indigo-500" />
                                Quick Share Pass...
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onCopyPassLink(visitor)}>
                                <Copy className="h-4 w-4 mr-2" />
                                Copy Pass Link
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onShareViaWhatsApp(visitor)}>
                                <Share2 className="h-4 w-4 mr-2 text-emerald-600" />
                                Open in WhatsApp Web
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onServerResend(visitor, 'sms')}>
                                <Send className="h-4 w-4 mr-2 text-primary" />
                                Dispatch via Twilio SMS
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onServerResend(visitor, 'whatsapp')}>
                                <Share2 className="h-4 w-4 mr-2 text-emerald-600" />
                                Dispatch via WhatsApp
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onServerResend(visitor, 'email')}>
                                <MessageSquare className="h-4 w-4 mr-2 text-blue-500" />
                                Dispatch via Email
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onExtendPass(visitor, 2)}>
                                <Clock className="h-4 w-4 mr-2 text-amber-500" />
                                Extend Validity (+2h)
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => onExtendPass(visitor, 24)}>
                                <Clock className="h-4 w-4 mr-2 text-amber-600" />
                                Extend Validity (+24h)
                              </DropdownMenuItem>
                              <DropdownMenuItem asChild>
                                <a href={`/guest/pass/${visitor.shareToken}/pdf`} target="_blank" rel="noopener noreferrer">
                                  <FileDown className="h-4 w-4 mr-2" />
                                  Download PDF
                                </a>
                              </DropdownMenuItem>
                            </>
                          )}
                          <DropdownMenuItem onClick={() => onEditVisitor(visitor)}>
                            <Edit className="h-4 w-4 mr-2" />
                            Edit Visitor
                          </DropdownMenuItem>
                          <DropdownMenuItem onClick={() => onSelectVisitor(visitor)}>
                            View ID
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="text-destructive"
                            onClick={() => onDeleteVisitor(visitor)}
                          >
                            Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>

        {/* Server-side pagination */}
        {visitors.links.length > 3 && (
          <nav className="flex flex-wrap items-center justify-center gap-1 p-4 border-t" aria-label="Pagination">
            {visitors.links.map((link, index) => (
              <Button
                key={index}
                size="sm"
                variant={link.active ? 'default' : 'outline'}
                disabled={!link.url}
                onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                className="h-8 min-w-8 px-2 text-xs"
                dangerouslySetInnerHTML={{ __html: link.label }}
              />
            ))}
          </nav>
        )}
      </CardContent>
    </Card>
  );
}
