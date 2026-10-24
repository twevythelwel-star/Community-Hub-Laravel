import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import axios from 'axios';
import {
  Car,
  Zap,
  ShieldCheck,
  ShieldAlert,
  Clock,
  Plus,
  RefreshCw,
  Search,
  CheckCircle2,
  AlertTriangle,
  User,
  Users,
  MapPin,
  Calendar,
  Sparkles,
  Camera,
  Trash2,
  ExternalLink,
  SquareParking
} from 'lucide-react';
import { cn } from '@/lib/utils';

export interface VehicleData {
  id: number;
  user_id: number;
  household_member_id?: number | null;
  property?: string | null;
  license_plate: string;
  jurisdiction: string;
  make: string;
  model: string;
  color: string;
  year?: number | null;
  parking_location?: string | null;
  is_ev: boolean;
  is_temporary: boolean;
  valid_until?: string | null;
  anpr_enabled: boolean;
  status: 'active' | 'suspended' | 'expired';
  notes?: string | null;
  created_at: string;
  household_member?: {
    id: number;
    name: string;
    role_in_household: string;
  } | null;
  user?: {
    id: number;
    name: string;
    email: string;
    property?: string;
  } | null;
}

interface HouseholdMemberOption {
  id: number;
  name: string;
  role_in_household: string;
}

interface Props {
  vehicles: VehicleData[];
  estateVehicles?: VehicleData[];
  householdMembers: HouseholdMemberOption[];
  stats: {
    total: number;
    anprEnabled: number;
    electricVehicles: number;
    temporary: number;
    estateTotal: number;
  };
  isSecurityOrAdmin: boolean;
}

export default function VehiclesDashboard({
  vehicles = [],
  estateVehicles = [],
  householdMembers = [],
  stats,
  isSecurityOrAdmin = false,
}: Props) {
  const { toast } = useToast();
  const [activeTab, setActiveTab] = useState<'my' | 'estate'>('my');
  const [searchQuery, setSearchQuery] = useState('');
  const [isRegisterOpen, setIsRegisterOpen] = useState(false);
  const [isAnprSimOpen, setIsAnprSimOpen] = useState(false);

  // ANPR Search Simulator
  const [anprPlateInput, setAnprPlateInput] = useState('XXX-1234');
  const [isAnprSearching, setIsAnprSearching] = useState(false);
  const [anprResult, setAnprResult] = useState<any | null>(null);

  // Form state
  const [formPlate, setFormPlate] = useState('');
  const [formJurisdiction, setFormJurisdiction] = useState('Jamaica');
  const [formMake, setFormMake] = useState('');
  const [formModel, setFormModel] = useState('');
  const [formColor, setFormColor] = useState('');
  const [formYear, setFormYear] = useState<string>('');
  const [formBay, setFormBay] = useState('');
  const [formHouseholdMemberId, setFormHouseholdMemberId] = useState<string>('');
  const [formIsEv, setFormIsEv] = useState(false);
  const [formIsTemporary, setFormIsTemporary] = useState(false);
  const [formValidUntil, setFormValidUntil] = useState('');
  const [formAnprEnabled, setFormAnprEnabled] = useState(true);
  const [formNotes, setFormNotes] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const displayList = activeTab === 'estate' && isSecurityOrAdmin ? estateVehicles : vehicles;

  const filteredVehicles = displayList.filter((v) => {
    const q = searchQuery.toLowerCase().trim();
    if (!q) return true;
    return (
      v.license_plate.toLowerCase().includes(q) ||
      v.make.toLowerCase().includes(q) ||
      v.model.toLowerCase().includes(q) ||
      v.color.toLowerCase().includes(q) ||
      (v.parking_location && v.parking_location.toLowerCase().includes(q)) ||
      (v.household_member && v.household_member.name.toLowerCase().includes(q)) ||
      (v.user && v.user.name.toLowerCase().includes(q))
    );
  });

  const handleAnprLookup = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!anprPlateInput.trim()) return;

    setIsAnprSearching(true);
    setAnprResult(null);

    try {
      const res = await axios.get('/dashboard/vehicles/anpr/lookup', {
        params: { plate: anprPlateInput.trim() },
      });
      setAnprResult(res.data);
      if (res.data.found && res.data.authorized) {
        toast({
          title: 'ANPR Clearance: AUTHORIZED',
          description: `${res.data.owner?.name} (${res.data.vehicle?.make} ${res.data.vehicle?.model}) - Gate Barrier Opened.`,
        });
      } else {
        toast({
          variant: 'destructive',
          title: 'ANPR Clearance: HOLD VEHICLE',
          description: res.data.message || 'Plate not registered for automatic boom gate entry.',
        });
      }
    } catch (err: any) {
      // An unregistered plate is a 404 with its own message, not a failure.
      if (err.response?.status === 404 && err.response.data) {
        setAnprResult(err.response.data);
        toast({
          variant: 'destructive',
          title: 'ANPR Clearance: HOLD VEHICLE',
          description: err.response.data.message || 'Plate not registered for automatic boom gate entry.',
        });
        return;
      }
      setAnprResult({
        found: false,
        authorized: false,
        message: 'Plate lookup failed or error connecting to ANPR camera engine.',
      });
    } finally {
      setIsAnprSearching(false);
    }
  };

  const handleRegisterSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);

    router.post(
      '/dashboard/vehicles',
      {
        license_plate: formPlate,
        jurisdiction: formJurisdiction,
        make: formMake,
        model: formModel,
        color: formColor,
        year: formYear ? parseInt(formYear) : null,
        parking_location: formBay,
        household_member_id: formHouseholdMemberId ? parseInt(formHouseholdMemberId) : null,
        is_ev: formIsEv,
        is_temporary: formIsTemporary,
        valid_until: formIsTemporary ? formValidUntil : null,
        anpr_enabled: formAnprEnabled,
        notes: formNotes,
      },
      {
        onSuccess: () => {
          setIsRegisterOpen(false);
          setIsSubmitting(false);
          // reset
          setFormPlate('');
          setFormMake('');
          setFormModel('');
          setFormColor('');
          setFormBay('');
          setFormIsEv(false);
          setFormIsTemporary(false);
          toast({
            title: 'Vehicle Registered',
            description: `${formMake} ${formModel} (${formPlate}) successfully linked to your residence.`,
          });
        },
        onError: () => {
          setIsSubmitting(false);
          toast({
            variant: 'destructive',
            title: 'Registration Failed',
            description: 'Could not register vehicle. Check that the plate is unique and required fields are filled.',
          });
        },
      }
    );
  };

  const handleDeleteVehicle = (vehicle: VehicleData) => {
    if (!confirm(`Are you sure you want to remove vehicle ${vehicle.license_plate} (${vehicle.make} ${vehicle.model})?`)) {
      return;
    }

    router.delete(`/dashboard/vehicles/${vehicle.id}`, {
      onSuccess: () => {
        toast({
          title: 'Vehicle Removed',
          description: `Vehicle ${vehicle.license_plate} has been unregistered.`,
        });
      },
    });
  };

  return (
    <DashboardLayout>
      <Head title="Vehicle Management & ANPR" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-border/40 pb-5">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <div className="p-2 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20">
                <Car className="h-6 w-6" />
              </div>
              <h1 className="text-2xl font-bold tracking-tight">Vehicles & Automatic Number Plate Recognition (ANPR)</h1>
              <Badge variant="outline" className="ml-2 text-xs bg-blue-500/10 text-blue-400 border-blue-500/30">
                Jamaican Registry Standard
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
              Connect residents and household members to vehicles. Supports optical plate scanning, EV staging, and temporary vehicle passes.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setIsAnprSimOpen(true);
                handleAnprLookup();
              }}
              className="gap-2 border-blue-500/30 text-blue-400 hover:bg-blue-500/10"
            >
              <Camera className="h-4 w-4" />
              ANPR Camera Simulator
            </Button>
            <Button
              size="sm"
              onClick={() => setIsRegisterOpen(true)}
              className="gap-2 bg-blue-600 hover:bg-blue-500 text-white"
            >
              <Plus className="h-4 w-4" />
              Register Vehicle
            </Button>
          </div>
        </div>

        {/* Metric Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card className="p-4 bg-card/60 border-border/60">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground font-medium uppercase">Registered Vehicles</span>
              <Car className="h-4 w-4 text-blue-400" />
            </div>
            <div className="text-2xl font-bold mt-1">{stats.total}</div>
            <div className="text-[11px] text-muted-foreground mt-0.5">Linked to your property</div>
          </Card>

          <Card className="p-4 bg-card/60 border-border/60">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground font-medium uppercase">ANPR Fast Lane</span>
              <ShieldCheck className="h-4 w-4 text-emerald-400" />
            </div>
            <div className="text-2xl font-bold mt-1 text-emerald-400">{stats.anprEnabled}</div>
            <div className="text-[11px] text-muted-foreground mt-0.5">Auto-cleared boom gate entry</div>
          </Card>

          <Card className="p-4 bg-card/60 border-border/60">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground font-medium uppercase">Electric Vehicles</span>
              <Zap className="h-4 w-4 text-amber-400" />
            </div>
            <div className="text-2xl font-bold mt-1 text-amber-400">{stats.electricVehicles}</div>
            <div className="text-[11px] text-muted-foreground mt-0.5">EV charger bay eligible</div>
          </Card>

          <Card className="p-4 bg-card/60 border-border/60">
            <div className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground font-medium uppercase">Temporary Vehicles</span>
              <Clock className="h-4 w-4 text-purple-400" />
            </div>
            <div className="text-2xl font-bold mt-1 text-purple-400">{stats.temporary}</div>
            <div className="text-[11px] text-muted-foreground mt-0.5">Rental / guest clearance</div>
          </Card>
        </div>

        {/* Tab Selection & Search */}
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-muted/20 p-2.5 rounded-xl border border-border/40">
          <div className="flex items-center gap-2 w-full sm:w-auto">
            {isSecurityOrAdmin && (
              <div className="inline-flex rounded-lg border border-border/60 p-0.5 bg-muted/40">
                <button
                  onClick={() => setActiveTab('my')}
                  className={cn(
                    'px-3 py-1 text-xs font-semibold rounded-md transition-colors',
                    activeTab === 'my' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                  )}
                >
                  My Residence ({vehicles.length})
                </button>
                <button
                  onClick={() => setActiveTab('estate')}
                  className={cn(
                    'px-3 py-1 text-xs font-semibold rounded-md transition-colors',
                    activeTab === 'estate' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'
                  )}
                >
                  Estate Directory ({stats.estateTotal})
                </button>
              </div>
            )}

            <div className="relative w-full sm:w-72">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search Plate, Make, Model, or Driver..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-8 h-9 text-xs"
              />
            </div>
          </div>

          <div className="text-xs text-muted-foreground">
            Displaying <strong className="text-foreground">{filteredVehicles.length}</strong> vehicle records
          </div>
        </div>

        {/* Vehicle Cards Grid */}
        {filteredVehicles.length === 0 ? (
          <Card className="border-dashed py-12 text-center">
            <CardContent className="space-y-3">
              <div className="mx-auto w-12 h-12 rounded-full bg-muted/60 flex items-center justify-center text-muted-foreground">
                <Car className="h-6 w-6" />
              </div>
              <h3 className="text-base font-semibold">No Vehicles Found</h3>
              <p className="text-sm text-muted-foreground max-w-sm mx-auto">
                No vehicles registered yet. Register your car to use ANPR fast-lane entry at the gate.
              </p>
              <div className="pt-2 flex justify-center gap-2">
                <Button size="sm" onClick={() => setIsRegisterOpen(true)} className="bg-blue-600 hover:bg-blue-500 text-white">
                  Register Vehicle
                </Button>
              </div>
            </CardContent>
          </Card>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            {filteredVehicles.map((vehicle) => {
              const driverName = vehicle.household_member?.name || vehicle.user?.name || 'Registered Resident';
              const driverRole = vehicle.household_member?.role_in_household || 'Primary Homeowner';

              return (
                <Card
                  key={vehicle.id}
                  className="overflow-hidden border border-border/70 bg-card/80 hover:shadow-lg transition-all duration-200 group"
                >
                  {/* Plate Banner */}
                  <div className="bg-neutral-900 text-white px-4 py-3 flex items-center justify-between border-b border-neutral-800">
                    <div className="flex items-center gap-2">
                      <div className="w-2 h-2 rounded-full bg-yellow-400" />
                      <div className="font-mono text-xs uppercase tracking-widest text-neutral-400">
                        {vehicle.jurisdiction}
                      </div>
                    </div>
                    <div className="font-mono font-black text-lg tracking-wider text-yellow-300">
                      {vehicle.license_plate}
                    </div>
                  </div>

                  <CardHeader className="pb-3 pt-3.5">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <CardTitle className="text-base font-bold flex items-center gap-1.5">
                          <span>{vehicle.make} {vehicle.model}</span>
                          {vehicle.year && <span className="text-xs text-muted-foreground font-normal">({vehicle.year})</span>}
                        </CardTitle>
                        <CardDescription className="text-xs flex items-center gap-1 mt-0.5">
                          <span className="inline-block w-2.5 h-2.5 rounded-full border border-black/20" style={{ backgroundColor: vehicle.color.toLowerCase() === 'white' ? '#f5f5f5' : vehicle.color.toLowerCase() }} />
                          <span className="capitalize">{vehicle.color}</span>
                        </CardDescription>
                      </div>

                      <div className="flex flex-col items-end gap-1">
                        {vehicle.anpr_enabled ? (
                          <Badge variant="outline" className="text-[10px] bg-emerald-500/10 text-emerald-400 border-emerald-500/30 gap-1">
                            <ShieldCheck className="h-3 w-3" /> ANPR Fast Lane
                          </Badge>
                        ) : (
                          <Badge variant="outline" className="text-[10px] text-muted-foreground">
                            Manual Scan
                          </Badge>
                        )}
                        {vehicle.is_ev && (
                          <Badge variant="outline" className="text-[10px] bg-amber-500/10 text-amber-400 border-amber-500/30 gap-1">
                            <Zap className="h-3 w-3" /> EV / Hybrid
                          </Badge>
                        )}
                      </div>
                    </div>
                  </CardHeader>

                  <CardContent className="space-y-3 pb-3 text-xs">
                    {/* Driver Identity Card */}
                    <div className="p-2.5 rounded-lg bg-muted/40 border border-border/50 flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full bg-blue-500/20 text-blue-400 flex items-center justify-center font-bold text-xs">
                        {driverName.charAt(0)}
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="font-semibold text-foreground truncate">{driverName}</div>
                        <div className="text-[11px] text-muted-foreground capitalize flex items-center gap-1">
                          <User className="h-3 w-3" /> {driverRole}
                        </div>
                      </div>
                    </div>

                    {/* Location & Temporary Status */}
                    <div className="space-y-1.5 pt-1">
                      <div className="flex items-center justify-between text-muted-foreground text-[11px]">
                        <span className="flex items-center gap-1">
                          <MapPin className="h-3 w-3" /> Assigned Bay
                        </span>
                        <span className="font-mono text-foreground font-semibold">
                          {vehicle.parking_location || 'Open Resident Parking'}
                        </span>
                      </div>

                      {vehicle.is_temporary && (
                        <div className="flex items-center justify-between text-[11px] text-purple-400 bg-purple-500/10 p-1.5 rounded border border-purple-500/20">
                          <span className="flex items-center gap-1">
                            <Clock className="h-3 w-3" /> Temporary Pass Expires
                          </span>
                          <span className="font-mono font-semibold">
                            {vehicle.valid_until ? new Date(vehicle.valid_until).toLocaleDateString() : 'Set Date'}
                          </span>
                        </div>
                      )}

                      {vehicle.notes && (
                        <div className="text-[11px] text-muted-foreground italic bg-muted/20 p-1.5 rounded">
                          "{vehicle.notes}"
                        </div>
                      )}
                    </div>
                  </CardContent>

                  <CardFooter className="pt-2 pb-3 border-t border-border/40 bg-muted/10 flex items-center justify-between gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setAnprPlateInput(vehicle.license_plate);
                        setIsAnprSimOpen(true);
                        setTimeout(() => handleAnprLookup(), 100);
                      }}
                      className="h-8 text-xs gap-1 text-blue-400 hover:text-blue-300 hover:bg-blue-500/10"
                    >
                      <Camera className="h-3.5 w-3.5" /> Test ANPR
                    </Button>

                    <div className="flex items-center gap-1">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.get('/dashboard/parking')}
                        className="h-8 text-xs gap-1 border-border/60"
                      >
                        <SquareParking className="h-3.5 w-3.5 text-emerald-400" />
                        Parking Passes
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDeleteVehicle(vehicle)}
                        className="h-8 w-8 p-0 text-muted-foreground hover:text-destructive hover:bg-destructive/10"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </CardFooter>
                </Card>
              );
            })}
          </div>
        )}
      </div>

      {/* ANPR Camera Simulation Dialog */}
      <Dialog open={isAnprSimOpen} onOpenChange={setIsAnprSimOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20">
                <Camera className="h-5 w-5" />
              </div>
              <DialogTitle>Gate ANPR Camera Recognition</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Simulates the optical recognition camera at the estate entry gate looking up a vehicle plate in real time.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleAnprLookup} className="space-y-4 pt-1">
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold">Simulated Detected License Plate</Label>
              <div className="flex gap-2">
                <Input
                  required
                  placeholder="e.g. XXX-1234 or 9821-JA"
                  value={anprPlateInput}
                  onChange={(e) => setAnprPlateInput(e.target.value.toUpperCase())}
                  className="font-mono text-sm uppercase tracking-wider font-bold"
                />
                <Button type="submit" disabled={isAnprSearching} className="bg-blue-600 hover:bg-blue-500 text-white text-xs">
                  {isAnprSearching ? <RefreshCw className="h-4 w-4 animate-spin" /> : 'Scan Plate'}
                </Button>
              </div>
              <p className="text-[11px] text-muted-foreground">
                Enter a registered plate, or an unregistered one to see a refusal.
              </p>
            </div>

            {anprResult && (
              <div
                className={cn(
                  'p-4 rounded-xl border text-xs space-y-3 animate-in fade-in-50',
                  anprResult.authorized
                    ? 'bg-emerald-500/10 border-emerald-500/40 text-emerald-300'
                    : 'bg-destructive/10 border-destructive/40 text-destructive'
                )}
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2 font-bold text-sm">
                    {anprResult.authorized ? (
                      <CheckCircle2 className="h-5 w-5 text-emerald-400" />
                    ) : (
                      <AlertTriangle className="h-5 w-5 text-destructive" />
                    )}
                    {anprResult.authorized ? 'BOOM GATE OPEN — AUTHORIZED' : 'GATE CLOSED — UNVERIFIED'}
                  </div>
                  <Badge variant="outline" className="font-mono text-[10px]">
                    {anprResult.normalized_plate}
                  </Badge>
                </div>

                <p className="text-xs opacity-90">{anprResult.message}</p>

                {anprResult.found && (
                  <div className="pt-2 border-t border-current/20 grid grid-cols-2 gap-2 text-foreground font-sans">
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Driver / Owner</span>
                      <span className="font-semibold">{anprResult.owner?.name}</span>
                    </div>
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Vehicle</span>
                      <span className="font-medium">
                        {anprResult.vehicle?.color} {anprResult.vehicle?.make} {anprResult.vehicle?.model}
                      </span>
                    </div>
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Residence</span>
                      <span>{anprResult.property || 'Estate Lot'}</span>
                    </div>
                    <div>
                      <span className="text-[10px] text-muted-foreground block">Assigned Bay</span>
                      <span className="font-mono font-semibold">{anprResult.vehicle?.parking_location || 'Open Bay'}</span>
                    </div>
                  </div>
                )}
              </div>
            )}
          </form>

          <DialogFooter>
            <Button variant="ghost" size="sm" onClick={() => setIsAnprSimOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Register Vehicle Modal */}
      <Dialog open={isRegisterOpen} onOpenChange={setIsRegisterOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20">
                <Plus className="h-5 w-5" />
              </div>
              <DialogTitle>Register Vehicle</DialogTitle>
            </div>
            <DialogDescription className="text-xs">
              Connect a vehicle to your estate residence and household members for ANPR entry and parking permit allocation.
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleRegisterSubmit} className="space-y-4 pt-1">
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">License Plate *</Label>
                <Input
                  required
                  placeholder="e.g. XXX-1234 or 9821-JA"
                  value={formPlate}
                  onChange={(e) => setFormPlate(e.target.value.toUpperCase())}
                  className="font-mono text-xs uppercase"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Jurisdiction</Label>
                <Input
                  value={formJurisdiction}
                  onChange={(e) => setFormJurisdiction(e.target.value)}
                  className="text-xs"
                />
              </div>
            </div>

            <div className="grid grid-cols-3 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Make *</Label>
                <Input
                  required
                  placeholder="e.g. Toyota"
                  value={formMake}
                  onChange={(e) => setFormMake(e.target.value)}
                  className="text-xs"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Model *</Label>
                <Input
                  required
                  placeholder="e.g. Land Cruiser"
                  value={formModel}
                  onChange={(e) => setFormModel(e.target.value)}
                  className="text-xs"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Year</Label>
                <Input
                  type="number"
                  placeholder="2024"
                  value={formYear}
                  onChange={(e) => setFormYear(e.target.value)}
                  className="text-xs"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Color *</Label>
                <Input
                  required
                  placeholder="e.g. Pearl White, Silver, Black"
                  value={formColor}
                  onChange={(e) => setFormColor(e.target.value)}
                  className="text-xs"
                />
              </div>

              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Parking Location / Bay</Label>
                <Input
                  placeholder="e.g. Bay R-14 (Garage)"
                  value={formBay}
                  onChange={(e) => setFormBay(e.target.value)}
                  className="text-xs"
                />
              </div>
            </div>

            {/* Household Member Link */}
            {householdMembers.length > 0 && (
              <div className="space-y-1.5">
                <Label className="text-xs font-semibold">Assign to Household Member</Label>
                <select
                  value={formHouseholdMemberId}
                  onChange={(e) => setFormHouseholdMemberId(e.target.value)}
                  className="w-full text-xs h-9 rounded-md border border-input bg-background px-3 py-1 text-foreground shadow-sm"
                >
                  <option value="">Primary Homeowner</option>
                  {householdMembers.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.role_in_household})
                    </option>
                  ))}
                </select>
              </div>
            )}

            {/* Checkbox toggles */}
            <div className="space-y-3 pt-2 border-t border-border/40">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="text-xs font-semibold">ANPR Auto-Clearance</Label>
                  <p className="text-[11px] text-muted-foreground">Allow entry gate cameras to automatically open the boom barrier.</p>
                </div>
                <Switch checked={formAnprEnabled} onCheckedChange={setFormAnprEnabled} />
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="text-xs font-semibold flex items-center gap-1">
                    <Zap className="h-3.5 w-3.5 text-amber-400" /> Electric Vehicle (EV)
                  </Label>
                  <p className="text-[11px] text-muted-foreground">Qualifies for community EV charging station priority bays.</p>
                </div>
                <Switch checked={formIsEv} onCheckedChange={setFormIsEv} />
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label className="text-xs font-semibold flex items-center gap-1">
                    <Clock className="h-3.5 w-3.5 text-purple-400" /> Temporary Vehicle (Rental / Loaner)
                  </Label>
                  <p className="text-[11px] text-muted-foreground">Temporary clearance that automatically expires.</p>
                </div>
                <Switch checked={formIsTemporary} onCheckedChange={setFormIsTemporary} />
              </div>

              {formIsTemporary && (
                <div className="space-y-1.5 pl-4 border-l-2 border-purple-500/50">
                  <Label className="text-xs font-semibold">Pass Expiration Date *</Label>
                  <Input
                    type="date"
                    required
                    value={formValidUntil}
                    onChange={(e) => setFormValidUntil(e.target.value)}
                    className="text-xs"
                  />
                </div>
              )}
            </div>

            <DialogFooter className="pt-2">
              <Button type="button" variant="ghost" size="sm" onClick={() => setIsRegisterOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" size="sm" disabled={isSubmitting} className="bg-blue-600 hover:bg-blue-500 text-white">
                {isSubmitting ? <RefreshCw className="h-4 w-4 animate-spin mr-1" /> : <Plus className="h-4 w-4 mr-1" />}
                Save Vehicle
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </DashboardLayout>
  );
}
