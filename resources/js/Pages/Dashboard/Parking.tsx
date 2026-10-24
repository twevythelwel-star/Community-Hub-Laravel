import React, { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import QRCode from 'react-qr-code';
import axios from 'axios';
import {
  SquareParking,
  QrCode,
  ShieldCheck,
  ShieldAlert,
  Car,
  Clock,
  Printer,
  Copy,
  CheckCircle2,
  AlertTriangle,
  Plus,
  RefreshCw,
  Search,
  Check,
  MapPin,
  Calendar,
  Sparkles,
  Zap,
  Accessibility,
  Truck,
  HardHat,
  User,
  Users,
  Timer
} from 'lucide-react';
import { cn } from '@/lib/utils';

export type ParkingCategory = 'resident' | 'visitor' | 'contractor' | 'temporary' | 'accessible' | 'loading_zone';

export interface ParkingPassData {
  id: number;
  pass_id: string;
  user_id: number;
  vehicle_id?: number | null;
  category: ParkingCategory;
  license_plate: string;
  property?: string | null;
  assigned_bay?: string | null;
  holder_name: string;
  valid_from: string;
  valid_until?: string | null;
  max_duration_minutes?: number | null;
  status: 'active' | 'expired' | 'revoked' | 'suspended';
  qr_payload: string;
  metadata?: Record<string, any> | null;
  created_at: string;
  vehicle?: {
    id: number;
    make: string;
    model: string;
    color: string;
    license_plate: string;
    is_ev?: boolean;
    parking_location?: string;
  } | null;
}

interface VehicleOption {
  id: number;
  make: string;
  model: string;
  color: string;
  license_plate: string;
  parking_location?: string;
}

interface CategoryMetaItem {
  name: string;
  prefix: string;
  badge: string;
  color: string;
  description: string;
  max_duration_hours?: number | null;
  max_duration_minutes?: number | null;
}

interface Props {
  passes: ParkingPassData[];
  vehicles: VehicleOption[];
  categories: Record<ParkingCategory, CategoryMetaItem>;
  categoryStats: Record<ParkingCategory, { meta: CategoryMetaItem; count: number }>;
}

export default function ParkingDashboard({
  passes = [],
  vehicles = [],
  categories,
  categoryStats
}: Props) {
  const { toast } = useToast();
  const [selectedCategory, setSelectedCategory] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [activePassModal, setActivePassModal] = useState<ParkingPassData | null>(null);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isVerifyModalOpen, setIsVerifyModalOpen] = useState(false);
  const [copiedPassId, setCopiedPassId] = useState<string | null>(null);

  // Verification state
  const [verifyTokenOrPlate, setVerifyTokenOrPlate] = useState('');
  const [isVerifying, setIsVerifying] = useState(false);
  const [verifyResult, setVerifyResult] = useState<any | null>(null);

  // Form state
  const [formCategory, setFormCategory] = useState<ParkingCategory>('resident');
  const [formPlate, setFormPlate] = useState('');
  const [formVehicleId, setFormVehicleId] = useState<string>('');
  const [formBay, setFormBay] = useState('');
  const [formHolderName, setFormHolderName] = useState('');
  const [formProperty, setFormProperty] = useState('');
  const [formDurationMinutes, setFormDurationMinutes] = useState<number>(formCategory === 'loading_zone' ? 30 : 0);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Filtered passes
  const filteredPasses = useMemo(() => {
    return passes.filter((pass) => {
      const matchesCategory = selectedCategory === 'all' || pass.category === selectedCategory;
      const q = searchQuery.toLowerCase().trim();
      const matchesQuery = !q ||
        pass.pass_id.toLowerCase().includes(q) ||
        pass.license_plate.toLowerCase().includes(q) ||
        pass.holder_name.toLowerCase().includes(q) ||
        (pass.assigned_bay && pass.assigned_bay.toLowerCase().includes(q)) ||
        (pass.vehicle && `${pass.vehicle.make} ${pass.vehicle.model}`.toLowerCase().includes(q));

      return matchesCategory && matchesQuery;
    });
  }, [passes, selectedCategory, searchQuery]);

  const getCategoryConfig = (category: ParkingCategory) => {
    switch (category) {
      case 'resident':
        return {
          label: 'Resident Parking',
          code: 'PK-RES',
          icon: ShieldCheck,
          gradient: 'from-emerald-500/20 to-teal-500/10 border-emerald-500/40 text-emerald-400',
          badgeClass: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
          accent: 'emerald',
          dotBg: 'bg-emerald-400',
        };
      case 'visitor':
        return {
          label: 'Visitor Parking',
          code: 'PK-VIS',
          icon: Users,
          gradient: 'from-blue-500/20 to-indigo-500/10 border-blue-500/40 text-blue-400',
          badgeClass: 'bg-blue-500/20 text-blue-300 border-blue-500/40',
          accent: 'blue',
          dotBg: 'bg-blue-400',
        };
      case 'contractor':
        return {
          label: 'Contractor Parking',
          code: 'PK-CON',
          icon: HardHat,
          gradient: 'from-amber-500/20 to-yellow-500/10 border-amber-500/40 text-amber-400',
          badgeClass: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
          accent: 'amber',
          dotBg: 'bg-amber-400',
        };
      case 'temporary':
        return {
          label: 'Temporary Parking',
          code: 'PK-TMP',
          icon: Clock,
          gradient: 'from-purple-500/20 to-violet-500/10 border-purple-500/40 text-purple-400',
          badgeClass: 'bg-purple-500/20 text-purple-300 border-purple-500/40',
          accent: 'purple',
          dotBg: 'bg-purple-400',
        };
      case 'accessible':
        return {
          label: 'Accessible Parking',
          code: 'PK-ACC',
          icon: Accessibility,
          gradient: 'from-cyan-500/20 to-sky-500/10 border-cyan-500/40 text-cyan-400',
          badgeClass: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',
          accent: 'cyan',
          dotBg: 'bg-cyan-400',
        };
      case 'loading_zone':
        return {
          label: 'Loading Zone (30m)',
          code: 'PK-LDG',
          icon: Truck,
          gradient: 'from-rose-500/20 to-orange-500/10 border-rose-500/40 text-rose-400',
          badgeClass: 'bg-rose-500/20 text-rose-300 border-rose-500/40',
          accent: 'rose',
          dotBg: 'bg-rose-400',
        };
      default:
        return {
          label: 'General Parking',
          code: 'PK-GEN',
          icon: SquareParking,
          gradient: 'from-gray-500/20 to-slate-500/10 border-gray-500/40 text-gray-400',
          badgeClass: 'bg-gray-500/20 text-gray-300 border-gray-500/40',
          accent: 'gray',
          dotBg: 'bg-gray-400',
        };
    }
  };

  const handleCopyQR = (pass: ParkingPassData) => {
    navigator.clipboard.writeText(pass.qr_payload);
    setCopiedPassId(pass.pass_id);
    toast({
      title: 'QR Token Copied',
      description: `Payload for ${pass.pass_id} copied to clipboard for mobile gate/kiosk scanner.`,
    });
    setTimeout(() => setCopiedPassId(null), 2500);
  };

  const handlePrintPlacard = (pass: ParkingPassData) => {
    window.print();
  };

  const handleVerifyPass = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!verifyTokenOrPlate.trim()) return;

    setIsVerifying(true);
    setVerifyResult(null);

    try {
      const res = await axios.post('/dashboard/parking/verify', {
        token: verifyTokenOrPlate.trim(),
        license_plate: verifyTokenOrPlate.trim(),
      });
      setVerifyResult(res.data);
      if (res.data.valid) {
        toast({
          title: 'Permit Verified: VALID',
          description: `${res.data.pass?.holder_name} - ${res.data.pass?.category_name} in Bay ${res.data.pass?.assigned_bay || 'Open'}.`,
        });
      } else {
        toast({
          variant: 'destructive',
          title: 'Permit Check: INVALID',
          description: res.data.message || 'Pass not authorized.',
        });
      }
    } catch (err: any) {
      setVerifyResult({
        valid: false,
        message: err.response?.data?.message || 'Verification failed. Pass token not recognized.',
      });
    } finally {
      setIsVerifying(false);
    }
  };

  const handleCreateSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);

    router.post(
      '/dashboard/parking',
      {
        category: formCategory,
        license_plate: formPlate,
        vehicle_id: formVehicleId ? parseInt(formVehicleId) : null,
        assigned_bay: formBay,
        holder_name: formHolderName,
        property: formProperty,
        max_duration_minutes: formCategory === 'loading_zone' ? (formDurationMinutes || 30) : (formDurationMinutes || null),
      },
      {
        onSuccess: () => {
          setIsCreateModalOpen(false);
          setIsSubmitting(false);
          // reset
          setFormPlate('');
          setFormBay('');
          setFormHolderName('');
          toast({
            title: 'Parking Pass Generated',
            description: `Separate cryptographic QR credential created for ${formCategory.toUpperCase()} parking.`,
          });
        },
        onError: () => {
          setIsSubmitting(false);
          toast({
            variant: 'destructive',
            title: 'Issuance Failed',
            description: 'Could not create parking pass. Check input fields.',
          });
        }
      }
    );
  };

  const handleSelectVehicleForForm = (vId: string) => {
    setFormVehicleId(vId);
    const chosen = vehicles.find((v) => v.id.toString() === vId);
    if (chosen) {
      setFormPlate(chosen.license_plate);
      if (chosen.parking_location && !formBay) {
        setFormBay(chosen.parking_location);
      }
    }
  };

  return (
    <DashboardLayout>
      <Head title="Parking Passes & QR Credentials" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-border/40 pb-5">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <div className="p-2 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <SquareParking className="h-6 w-6" />
              </div>
              <h1 className="text-2xl font-bold tracking-tight">Parking Passes & QR Credentials</h1>
              <Badge variant="outline" className="ml-2 text-xs bg-emerald-500/10 text-emerald-400 border-emerald-500/30">
                Estate Access Control
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
              Distinct cryptographic QR credentials for Resident, Visitor, Contractor, Temporary, Accessible, and 30-min Loading Zone bays.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsVerifyModalOpen(true)}
              className="gap-2 border-emerald-500/30 hover:bg-emerald-500/10 text-emerald-400"
            >
              <Search className="h-4 w-4" />
              Verify Permit / Plate
            </Button>
            <Button
              size="sm"
              onClick={() => setIsCreateModalOpen(true)}
              className="gap-2 bg-emerald-600 hover:bg-emerald-500 text-white"
            >
              <Plus className="h-4 w-4" />
              Issue Parking Pass
            </Button>
          </div>
        </div>

        {/* 6 Category Summary Cards */}
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
          {(['resident', 'visitor', 'contractor', 'temporary', 'accessible', 'loading_zone'] as ParkingCategory[]).map((catKey) => {
            const cfg = getCategoryConfig(catKey);
            const Icon = cfg.icon;
            const count = categoryStats?.[catKey]?.count || passes.filter(p => p.category === catKey).length;
            const isSelected = selectedCategory === catKey;

            return (
              <button
                key={catKey}
                onClick={() => setSelectedCategory(isSelected ? 'all' : catKey)}
                className={cn(
                  'text-left p-3.5 rounded-xl border transition-all duration-200 relative overflow-hidden flex flex-col justify-between group',
                  isSelected
                    ? 'border-emerald-500 bg-emerald-500/10 ring-1 ring-emerald-500/50 shadow-lg shadow-emerald-500/10'
                    : 'border-border/60 bg-card/60 hover:bg-card hover:border-border'
                )}
              >
                <div className="flex items-center justify-between w-full mb-2">
                  <div className={cn('p-1.5 rounded-lg border', cfg.badgeClass)}>
                    <Icon className="h-4 w-4" />
                  </div>
                  <Badge variant="secondary" className="font-mono text-xs font-semibold">
                    {count}
                  </Badge>
                </div>
                <div>
                  <div className="font-medium text-xs text-foreground group-hover:text-emerald-400 transition-colors">
                    {cfg.label}
                  </div>
                  <div className="text-[10px] font-mono text-muted-foreground">
                    {cfg.code}
                  </div>
                </div>
              </button>
            );
          })}
        </div>

        {/* Filter & Search Bar */}
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-muted/20 p-2.5 rounded-xl border border-border/40">
          <div className="flex items-center gap-2 w-full sm:w-auto">
            <div className="relative w-full sm:w-72">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search by Plate, Name, Bay, or Pass ID..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-8 h-9 text-xs"
              />
            </div>
            {searchQuery && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setSearchQuery('')}
                className="h-9 px-2 text-xs text-muted-foreground"
              >
                Clear
              </Button>
            )}
          </div>

          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <span>Showing <strong className="text-foreground">{filteredPasses.length}</strong> of {passes.length} permits</span>
            {selectedCategory !== 'all' && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSelectedCategory('all')}
                className="h-7 text-[11px] px-2"
              >
                Reset Filter
              </Button>
            )}
          </div>
        </div>

        {/* Passes Grid */}
        {filteredPasses.length === 0 ? (
          <Card className="border-dashed py-12 text-center">
            <CardContent className="space-y-3">
              <div className="mx-auto w-12 h-12 rounded-full bg-muted/60 flex items-center justify-center text-muted-foreground">
                <SquareParking className="h-6 w-6" />
              </div>
              <h3 className="text-base font-semibold">No Parking Passes Found</h3>
              <p className="text-sm text-muted-foreground max-w-sm mx-auto">
                No active permits match your current category or search filter.
              </p>
              <div className="pt-2 flex justify-center gap-2">
                <Button size="sm" onClick={() => setSelectedCategory('all')} variant="outline">
                  Show All Categories
                </Button>
                <Button size="sm" onClick={() => setIsCreateModalOpen(true)} className="bg-emerald-600 hover:bg-emerald-500 text-white">
                  Issue Parking Pass
                </Button>
              </div>
            </CardContent>
          </Card>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            {filteredPasses.map((pass) => {
              const cfg = getCategoryConfig(pass.category);
              const Icon = cfg.icon;
              const isCopied = copiedPassId === pass.pass_id;

              return (
                <Card
                  key={pass.id}
                  className={cn(
                    'overflow-hidden border transition-all duration-200 hover:shadow-lg relative group bg-card/80 backdrop-blur-sm',
                    `hover:border-${cfg.accent}-500/50`
                  )}
                >
                  {/* Top Color Accent Line */}
                  <div className={cn('h-1.5 w-full bg-gradient-to-r', cfg.gradient)} />

                  <CardHeader className="pb-3 pt-4">
                    <div className="flex items-start justify-between gap-2">
                      <div className="space-y-1">
                        <div className="flex items-center gap-1.5">
                          <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold border', cfg.badgeClass)}>
                            <Icon className="h-3 w-3" />
                            {cfg.label}
                          </span>
                          {pass.category === 'loading_zone' && (
                            <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-mono bg-rose-500/20 text-rose-300 border border-rose-500/30">
                              <Timer className="h-2.5 w-2.5" /> 30m MAX
                            </span>
                          )}
                        </div>
                        <div className="text-xs font-mono text-muted-foreground">
                          ID: <span className="text-foreground font-semibold">{pass.pass_id}</span>
                        </div>
                      </div>

                      <Badge
                        variant="outline"
                        className={cn(
                          'text-[10px] uppercase tracking-wider',
                          pass.status === 'active'
                            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'
                            : 'bg-destructive/10 text-destructive border-destructive/30'
                        )}
                      >
                        {pass.status}
                      </Badge>
                    </div>

                    <CardTitle className="text-base font-bold flex items-center justify-between pt-1">
                      <span>{pass.holder_name}</span>
                      <span className="font-mono text-sm px-2 py-0.5 rounded bg-muted/60 border border-border/80">
                        {pass.license_plate}
                      </span>
                    </CardTitle>
                    {pass.property && (
                      <CardDescription className="text-xs flex items-center gap-1">
                        <MapPin className="h-3 w-3 text-muted-foreground" />
                        {pass.property}
                      </CardDescription>
                    )}
                  </CardHeader>

                  <CardContent className="space-y-4 pb-3">
                    {/* Visual QR & Assigned Bay Row */}
                    <div className="flex items-center justify-between gap-4 p-3 rounded-lg bg-muted/30 border border-border/40">
                      {/* Interactive Mini QR */}
                      <button
                        onClick={() => setActivePassModal(pass)}
                        className="bg-white p-2 rounded-md shadow-sm hover:scale-105 transition-transform duration-150 relative group/qr flex-shrink-0"
                        title="Click to expand QR credential"
                      >
                        <QRCode
                          value={pass.qr_payload}
                          size={76}
                          style={{ height: "auto", maxWidth: "100%", width: "100%" }}
                          viewBox="0 0 256 256"
                        />
                        <div className="absolute inset-0 bg-black/40 opacity-0 group-hover/qr:opacity-100 flex items-center justify-center rounded transition-opacity">
                          <QrCode className="h-5 w-5 text-white" />
                        </div>
                      </button>

                      {/* Bay & Vehicle Details */}
                      <div className="flex-1 min-w-0 space-y-1.5 text-xs">
                        <div>
                          <span className="text-muted-foreground block text-[10px] uppercase tracking-wider">Assigned Bay</span>
                          <span className="font-mono font-bold text-foreground text-sm flex items-center gap-1">
                            <SquareParking className="h-3.5 w-3.5 text-emerald-400" />
                            {pass.assigned_bay || 'Unassigned / Open Bay'}
                          </span>
                        </div>

                        {pass.vehicle && (
                          <div>
                            <span className="text-muted-foreground block text-[10px] uppercase tracking-wider">Vehicle</span>
                            <span className="truncate text-foreground font-medium flex items-center gap-1">
                              <Car className="h-3.5 w-3.5 text-muted-foreground flex-shrink-0" />
                              {pass.vehicle.color} {pass.vehicle.make} {pass.vehicle.model}
                              {pass.vehicle.is_ev && (
                                <Zap className="h-3 w-3 text-emerald-400 flex-shrink-0" />
                              )}
                            </span>
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Validity Info */}
                    <div className="space-y-1 pt-1 text-xs border-t border-border/30">
                      <div className="flex items-center justify-between text-muted-foreground text-[11px]">
                        <span className="flex items-center gap-1">
                          <Calendar className="h-3 w-3" /> Valid Until
                        </span>
                        <span className="font-mono text-foreground font-medium">
                          {pass.valid_until
                            ? new Date(pass.valid_until).toLocaleString('en-US', {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                              })
                            : 'Indefinite / Permanent'}
                        </span>
                      </div>
                      {pass.max_duration_minutes && (
                        <div className="flex items-center justify-between text-[11px] text-amber-400">
                          <span className="flex items-center gap-1">
                            <Clock className="h-3 w-3" /> Maximum Stay
                          </span>
                          <span className="font-mono font-semibold">{pass.max_duration_minutes} minutes</span>
                        </div>
                      )}
                    </div>
                  </CardContent>

                  <CardFooter className="pt-2 pb-3 border-t border-border/40 bg-muted/10 flex items-center justify-between gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => handleCopyQR(pass)}
                      className="h-8 text-xs gap-1.5 flex-1 hover:bg-muted"
                    >
                      {isCopied ? <Check className="h-3.5 w-3.5 text-emerald-400" /> : <Copy className="h-3.5 w-3.5 text-muted-foreground" />}
                      {isCopied ? 'Copied' : 'Copy QR'}
                    </Button>

                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setActivePassModal(pass)}
                      className="h-8 text-xs gap-1.5 flex-1 border-border/60 hover:border-emerald-500/50"
                    >
                      <QrCode className="h-3.5 w-3.5 text-emerald-400" />
                      View Pass
                    </Button>
                  </CardFooter>
                </Card>
              );
            })}
          </div>
        )}
      </div>

      {/* Full QR Credential & Windshield Placard Modal */}
      <Dialog open={!!activePassModal} onOpenChange={(open) => !open && setActivePassModal(null)}>
        {activePassModal && (
          <DialogContent className="max-w-md bg-card/95 backdrop-blur-md border border-border/80">
            <DialogHeader>
              <div className="flex items-center gap-2 mb-1">
                <span className={cn('inline-flex items-center gap-1 px-2.5 py-0.5 rounded text-xs font-semibold border', getCategoryConfig(activePassModal.category).badgeClass)}>
                  {getCategoryConfig(activePassModal.category).label}
                </span>
                <Badge variant="outline" className="font-mono text-xs">
                  {activePassModal.pass_id}
                </Badge>
              </div>
              <DialogTitle className="text-xl font-bold">
                Windshield & Gate Parking Pass
              </DialogTitle>
              <DialogDescription className="text-xs">
                Scan at estate boom gates or display on dashboard/windshield for security patrol enforcement.
              </DialogDescription>
            </DialogHeader>

            {/* Placard Container (Printable) */}
            <div className="my-2 p-5 rounded-2xl border-2 border-dashed border-emerald-500/40 bg-gradient-to-b from-card to-muted/40 shadow-inner space-y-4 text-center">
              <div className="font-mono text-[11px] text-muted-foreground uppercase tracking-widest">
                Caribbean Gated Community • Parking Authority
              </div>

              {/* Large Machine Readable QR Code */}
              <div className="inline-block p-4 bg-white rounded-2xl shadow-md border border-neutral-200">
                <QRCode
                  value={activePassModal.qr_payload}
                  size={190}
                  style={{ height: "auto", maxWidth: "100%", width: "100%" }}
                  viewBox="0 0 256 256"
                />
              </div>

              {/* Vehicle & Plate Highlight */}
              <div className="space-y-1">
                <div className="inline-block px-4 py-1.5 rounded-lg bg-black/80 dark:bg-white/10 text-white font-mono text-xl font-black tracking-wider border border-white/20">
                  {activePassModal.license_plate}
                </div>
                <div className="text-sm font-semibold text-foreground">
                  {activePassModal.holder_name}
                </div>
                {activePassModal.vehicle && (
                  <div className="text-xs text-muted-foreground">
                    {activePassModal.vehicle.color} {activePassModal.vehicle.make} {activePassModal.vehicle.model}
                  </div>
                )}
              </div>

              {/* Bay & Timing Specs */}
              <div className="grid grid-cols-2 gap-2 text-left bg-muted/40 p-3 rounded-xl border border-border/50 text-xs">
                <div>
                  <span className="text-muted-foreground block text-[10px] uppercase">Assigned Location</span>
                  <span className="font-mono font-bold text-foreground">
                    {activePassModal.assigned_bay || 'Open Bays'}
                  </span>
                </div>
                <div>
                  <span className="text-muted-foreground block text-[10px] uppercase">Permit Type</span>
                  <span className="font-medium text-foreground capitalize">
                    {activePassModal.category.replace('_', ' ')}
                  </span>
                </div>
                <div className="col-span-2 pt-1 border-t border-border/30">
                  <span className="text-muted-foreground block text-[10px] uppercase">Validity Window</span>
                  <span className="font-mono text-[11px] text-foreground">
                    {activePassModal.valid_until
                      ? `Valid until ${new Date(activePassModal.valid_until).toLocaleString()}`
                      : 'Permanent Resident Clearance'}
                  </span>
                </div>
              </div>
            </div>

            <DialogFooter className="flex flex-col sm:flex-row gap-2 pt-2">
              <Button
                variant="outline"
                onClick={() => handleCopyQR(activePassModal)}
                className="gap-2 text-xs flex-1"
              >
                {copiedPassId === activePassModal.pass_id ? <Check className="h-4 w-4 text-emerald-400" /> : <Copy className="h-4 w-4" />}
                Copy Raw Token
              </Button>
              <Button
                onClick={() => handlePrintPlacard(activePassModal)}
                className="gap-2 text-xs flex-1 bg-emerald-600 hover:bg-emerald-500 text-white"
              >
                <Printer className="h-4 w-4" />
                Print Placard
              </Button>
            </DialogFooter>
          </DialogContent>
        )}
      </Dialog>

      {/* Verify Permit Modal */}
      <Dialog open={isVerifyModalOpen} onOpenChange={setIsVerifyModalOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <Search className="h-5 w-5" />
              </div>
              <DialogTitle>Security Parking Verification</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Verify any parking permit token, QR scan string, or license plate against the active estate database.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleVerifyPass} className="space-y-4 pt-2">
            <div className="space-y-2">
              <Label className="text-xs font-semibold">QR Payload, Pass ID, or License Plate</Label>
              <div className="flex gap-2">
                <Input
                  placeholder="e.g. PK-RES-001, XXX-1234, or CHUB-PARK|..."
                  value={verifyTokenOrPlate}
                  onChange={(e) => setVerifyTokenOrPlate(e.target.value)}
                  className="font-mono text-xs"
                />
                <Button type="submit" disabled={isVerifying || !verifyTokenOrPlate.trim()} className="bg-emerald-600 hover:bg-emerald-500 text-white text-xs">
                  {isVerifying ? <RefreshCw className="h-4 w-4 animate-spin" /> : 'Verify'}
                </Button>
              </div>
            </div>

            {verifyResult && (
              <div className={cn(
                'p-4 rounded-xl border text-xs space-y-2 animate-in fade-in-50',
                verifyResult.valid
                  ? 'bg-emerald-500/10 border-emerald-500/40 text-emerald-300'
                  : 'bg-destructive/10 border-destructive/40 text-destructive'
              )}>
                <div className="flex items-center gap-2 font-bold text-sm">
                  {verifyResult.valid ? <CheckCircle2 className="h-5 w-5 text-emerald-400" /> : <AlertTriangle className="h-5 w-5 text-destructive" />}
                  {verifyResult.valid ? 'AUTHORIZED PARKING PERMIT' : 'UNAUTHORIZED / INVALID PERMIT'}
                </div>
                <p className="text-xs opacity-90">{verifyResult.message}</p>

                {verifyResult.pass && (
                  <div className="pt-2 border-t border-emerald-500/20 grid grid-cols-2 gap-2 text-foreground font-sans">
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Holder</span>
                      <span className="font-semibold">{verifyResult.pass.holder_name}</span>
                    </div>
                    <div>
                      <span className="text-[10px] text-muted-foreground block">License Plate</span>
                      <span className="font-mono font-bold">{verifyResult.pass.license_plate}</span>
                    </div>
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Permit Category</span>
                      <span className="capitalize">{verifyResult.pass.category_name}</span>
                    </div>
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Assigned Bay</span>
                      <span className="font-mono font-semibold">{verifyResult.pass.assigned_bay || 'Open'}</span>
                    </div>
                  </div>
                )}
              </div>
            )}
          </form>

          <DialogFooter>
            <Button variant="ghost" size="sm" onClick={() => setIsVerifyModalOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Issue New Parking Pass Modal */}
      <Dialog open={isCreateModalOpen} onOpenChange={setIsCreateModalOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <Plus className="h-5 w-5" />
              </div>
              <DialogTitle>Issue New Parking Credential</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Create an authenticated QR credential permit for a resident, visitor, contractor, temporary stay, accessible bay, or loading zone.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleCreateSubmit} className="space-y-4 pt-1">
            {/* Category Radio Cards */}
            <div className="space-y-2">
              <Label className="text-xs font-semibold">Parking Category</Label>
              <div className="grid grid-cols-3 gap-2">
                {(['resident', 'visitor', 'contractor', 'temporary', 'accessible', 'loading_zone'] as ParkingCategory[]).map((cat) => {
                  const cfg = getCategoryConfig(cat);
                  const Icon = cfg.icon;
                  const isSelected = formCategory === cat;

                  return (
                    <button
                      key={cat}
                      type="button"
                      onClick={() => {
                        setFormCategory(cat);
                        if (cat === 'loading_zone') setFormDurationMinutes(30);
                      }}
                      className={cn(
                        'p-2 rounded-lg border text-left flex flex-col justify-between transition-all text-xs',
                        isSelected
                          ? 'border-emerald-500 bg-emerald-500/10 ring-1 ring-emerald-500'
                          : 'border-border/60 hover:bg-muted/40'
                      )}
                    >
                      <div className="flex items-center justify-between w-full mb-1">
                        <Icon className={cn('h-3.5 w-3.5', cfg.accent === 'emerald' ? 'text-emerald-400' : 'text-foreground')} />
                        <span className="font-mono text-[9px] text-muted-foreground">{cfg.code}</span>
                      </div>
                      <span className="font-medium text-[11px] leading-tight text-foreground">{cfg.label}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Link to Registered Vehicle (Optional) */}
            {vehicles.length > 0 && (
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Link Registered Vehicle (Optional)</Label>
                <select
                  value={formVehicleId}
                  onChange={(e) => handleSelectVehicleForForm(e.target.value)}
                  className="w-full text-xs h-9 rounded-md border border-input bg-background px-3 py-1 text-foreground shadow-sm"
                >
                  <option value="">-- Manual Entry / External Vehicle --</option>
                  {vehicles.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.license_plate} - {v.color} {v.make} {v.model} ({v.parking_location || 'No Bay'})
                    </option>
                  ))}
                </select>
              </div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">License Plate *</Label>
                <Input
                  required
                  placeholder="e.g. XXX-1234 or 9821-JA"
                  value={formPlate}
                  onChange={(e) => setFormPlate(e.target.value)}
                  className="font-mono text-xs uppercase"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Assigned Bay / Zone</Label>
                <Input
                  placeholder="e.g. Bay R-14, Visitor Lot B, Staging 1"
                  value={formBay}
                  onChange={(e) => setFormBay(e.target.value)}
                  className="text-xs"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Pass Holder Name *</Label>
                <Input
                  required
                  placeholder="e.g. John Smith, Delivery Courier"
                  value={formHolderName}
                  onChange={(e) => setFormHolderName(e.target.value)}
                  className="text-xs"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Property / Unit</Label>
                <Input
                  placeholder="e.g. Lot 42, Hummingbird Way"
                  value={formProperty}
                  onChange={(e) => setFormProperty(e.target.value)}
                  className="text-xs"
                />
              </div>
            </div>

            {/* Loading Zone duration restriction */}
            {formCategory === 'loading_zone' && (
              <div className="p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs space-y-1">
                <div className="flex items-center gap-1.5 font-bold">
                  <Timer className="h-4 w-4" /> Strict 30-Minute Loading Zone Enforced
                </div>
                <p className="text-[11px] opacity-90">
                  Loading bays are reserved exclusively for rapid staging, deliveries, and moving vans. Permits automatically expire after 30 minutes.
                </p>
              </div>
            )}

            <DialogFooter className="pt-2">
              <Button type="button" variant="ghost" size="sm" onClick={() => setIsCreateModalOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" size="sm" disabled={isSubmitting} className="bg-emerald-600 hover:bg-emerald-500 text-white">
                {isSubmitting ? <RefreshCw className="h-4 w-4 animate-spin mr-1" /> : <Plus className="h-4 w-4 mr-1" />}
                Generate QR Credential
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </DashboardLayout>
  );
}
