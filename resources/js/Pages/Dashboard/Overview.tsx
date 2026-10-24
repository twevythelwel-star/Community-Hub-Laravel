import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import type L from 'leaflet';
import dynamic from '@/lib/dynamic';
import DashboardLayout from '@/Layouts/DashboardLayout';
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
  ArrowUpRight,
  Phone,
  Activity,
  MoreHorizontal,
  Mountain,
  Settings2,
  ShieldOff,
  Lock,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Image } from '@/components/ui/image';
import { ClientFormattedDate } from '@/components/client-formatted-date';
import { FundraiserProgressCard } from '@/components/dashboard/fundraiser-progress-card';
import { useMap } from '@/context/map-context';
import { toDMS, type CommunityLandmark } from '@/lib/geofence-utils';
import { SetGeofenceDialog } from '@/components/dashboard/set-geofence-dialog';
import { OverviewWelcome } from '@/components/dashboard/overview-welcome';
import { OverviewAtAGlance } from '@/components/dashboard/overview-at-a-glance';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/*
 * Leaflet reaches for `window` at import time, so it stays code-split. The
 * `ssr: false` flag is inert under Inertia (there is no server render) but is
 * kept so the call site still reads the same — see resources/js/lib/dynamic.tsx.
 */
const LeafletMapFixed = dynamic(() => import('@/components/dashboard/LeafletMapFixed'), {
  ssr: false,
  loading: () => (
    <div className="h-full min-h-[300px] w-full flex flex-col items-center justify-center bg-muted/20 gap-2 rounded-xl">
      <Mountain className="h-8 w-8 text-primary animate-pulse" />
      <p className="text-xs text-muted-foreground font-medium">Loading terrain map…</p>
    </div>
  ),
});

type Stats = {
  month: string;
  activeResidents: number | null;
  residentsJoinedThisMonth: number | null;
  totalCollected: number;
  collectedHouseholds: number;
  outstandingDues: number;
  outstandingHouseholds: number;
  upcomingVisitors: number;
  visitorsToday: number;
  visitorsOnSite: number;
  entriesToday: number | null;
  deniedToday: number | null;
  myOutstandingBalance: number;
  myRentersCount?: number | null;
  occupancy?: {
    currentlyInside: {
      total: number;
      residents: number;
      renters: number;
      visitors: number;
      staff: number;
      contractors: number;
      legacyContacts: number;
    };
    expectedToday: {
      total: number;
      residents: number;
      renters: number;
      visitors: number;
      staff: number;
      contractors: number;
      legacyContacts: number;
    };
  };
};

type Announcement = {
  id: number;
  title: string;
  content: string;
  author: string;
  timestamp: string;
};

type UpcomingEvent = {
  id: number;
  title: string;
  summary: string;
  startDate: string;
  imageUrl: string | null;
};

type VisitorPass = {
  id: number;
  name: string;
  status: string;
  expectedAt: string;
  host: string | null;
  isBlocked: boolean;
};

type RecentVisitor = {
  id: number;
  name: string;
  type: string;
  status: string;
  expectedAt: string;
  homeowner: string | null;
  isBlocked: boolean;
};

type Payment = {
  id: number;
  reference: string;
  homeowner: string;
  amount: number;
  currency: string;
  status: string;
  date: string;
  paidAt: string | null;
};

type FundraiserSummary = {
  id: number;
  title: string;
  description: string;
  goal: number;
  raised: number;
  progress: number;
  donorCount: number;
  currency: string;
  startDate: string;
  endDate: string;
  status: 'Active' | 'Completed' | 'Upcoming' | 'Canceled';
};

type Perk = {
  id: number;
  name: string;
  logoUrl: string | null;
  aiHint: string | null;
  vouchers: { id: number; title: string; description: string }[];
};

type Resident = {
  id: number;
  name: string;
  initials: string;
  lot: string | null;
  role: string;
  avatarUrl: string | null;
  phone: string | null;
};

type Props = {
  stats: Stats;
  permissions: {
    viewActiveResidents: boolean;
    viewUpcomingVisitors: boolean;
    viewRecentVisitors: boolean;
    viewBilling: boolean;
    viewFundraisers: boolean;
    manageBoundary: boolean;
  };
  announcements: Announcement[];
  upcomingEvents: UpcomingEvent[];
  visitorPasses: VisitorPass[];
  recentVisitors: RecentVisitor[];
  recentPayments: Payment[];
  fundraisers: FundraiserSummary[];
  perks: Perk[];
  residents: Resident[];
  landmarks: CommunityLandmark[];
};

export default function Overview({
  stats,
  permissions,
  announcements,
  upcomingEvents,
  visitorPasses,
  recentVisitors,
  recentPayments,
  fundraisers,
  perks,
  residents,
  landmarks,
}: Props) {
  // The boundary is shared on every Inertia response, so every user sees the
  // same perimeter — it used to come from this browser's localStorage.
  const { coordinates, metrics, isProvisional, version } = useMap();

  const [activeLocation, setActiveLocation] = useState<number | string | null>(
    landmarks[0]?.id ?? null,
  );
  const [mapInstance, setMapInstance] = useState<L.Map | null>(null);
  const [isGeofenceDialogOpen, setIsGeofenceDialogOpen] = useState(false);

  const geoJsonLayerRef = useRef<L.GeoJSON | null>(null);
  const beaconsLayerRef = useRef<L.LayerGroup | null>(null);
  const markersLayerRef = useRef<L.LayerGroup | null>(null);

  const { center, areaAcres, perimeterMeters } = metrics;

  // Draw the geofence polygon.
  useEffect(() => {
    if (!mapInstance || coordinates.length < 3) return;

    let cancelled = false;

    void import('leaflet').then((Leaflet) => {
      if (cancelled) return;

      const LInstance = Leaflet.default;
      geoJsonLayerRef.current?.remove();

      const ring = [
        ...coordinates.map((c) => [c[1], c[0]]),
        [coordinates[0][1], coordinates[0][0]],
      ];

      const layer = LInstance.geoJSON(
        {
          type: 'Feature',
          properties: {},
          geometry: { type: 'Polygon', coordinates: [ring] },
        } as GeoJSON.Feature<GeoJSON.Polygon>,
        {
          style: {
            color: '#10B981',
            weight: 3,
            opacity: 0.95,
            // A provisional boundary is drawn faintly, so nobody mistakes the
            // fallback shape for a surveyed perimeter.
            dashArray: isProvisional ? '3, 8' : '6, 6',
            fillColor: '#10B981',
            fillOpacity: isProvisional ? 0.04 : 0.08,
          },
        },
      ).addTo(mapInstance);

      geoJsonLayerRef.current = layer;
      mapInstance.fitBounds(layer.getBounds(), { padding: [24, 24] });
    });

    return () => {
      cancelled = true;
    };
  }, [coordinates, mapInstance, isProvisional]);

  // Beacon markers on each boundary vertex.
  useEffect(() => {
    if (!mapInstance) return;

    let cancelled = false;

    void import('leaflet').then((Leaflet) => {
      if (cancelled) return;

      const LInstance = Leaflet.default;
      beaconsLayerRef.current?.remove();

      const group = LInstance.layerGroup().addTo(mapInstance);
      beaconsLayerRef.current = group;

      coordinates.forEach((coord, idx) => {
        const beaconIcon = LInstance.divIcon({
          html: `
            <div style="position: relative; display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; transform: translate(-50%, -50%);">
              <div style="position: absolute; width: 18px; height: 18px; border-radius: 50%; background: rgba(16, 185, 129, 0.4); border: 1px solid #10B981;"></div>
              <div style="width: 8px; height: 8px; border-radius: 50%; background: #10B981; border: 2px solid #FFFFFF; box-shadow: 0 0 6px rgba(16,185,129,0.8); z-index: 2;"></div>
            </div>
          `,
          className: 'geofence-beacon-icon',
          iconSize: [22, 22],
          iconAnchor: [11, 11],
        });

        LInstance.marker(coord, { icon: beaconIcon })
          .bindPopup(
            `<div style="padding: 4px; font-size: 11px;"><strong>Geofence Node #${idx + 1}</strong><br/><span style="font-family: monospace; color: #64748b;">${coord[0].toFixed(5)}°, ${coord[1].toFixed(5)}°</span></div>`,
          )
          .addTo(group);
      });
    });

    return () => {
      cancelled = true;
    };
  }, [coordinates, mapInstance]);

  // Landmark pins — now from the `landmarks` table rather than a constant.
  useEffect(() => {
    if (!mapInstance) return;

    let cancelled = false;

    void import('leaflet').then((Leaflet) => {
      if (cancelled) return;

      const LInstance = Leaflet.default;
      markersLayerRef.current?.remove();

      const group = LInstance.layerGroup().addTo(mapInstance);
      markersLayerRef.current = group;

      landmarks.forEach((landmark) => {
        const color = landmark.color ?? '#64748B';

        const icon = LInstance.divIcon({
          html: `
            <div style="position: relative; display: flex; flex-direction: column; align-items: center; transform: translate(-50%, -100%);">
              <svg width="28" height="34" viewBox="0 0 34 42" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 3px 5px rgba(0,0,0,0.4));">
                <path d="M17 0C7.61116 0 0 7.61116 0 17C0 27.5 14.5 40.5 16.2 42C16.6 42.4 17.4 42.4 17.8 42C19.5 40.5 34 27.5 34 17C34 7.61116 26.3888 0 17 0Z" fill="${color}"/>
                <circle cx="17" cy="16" r="6" fill="#FFFFFF"/>
                <circle cx="17" cy="16" r="3.5" fill="${color}"/>
              </svg>
              <div style="background: rgba(15,23,42,0.85); color: #f8fafc; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 9999px; margin-top: -4px; border: 1px solid rgba(255,255,255,0.25); white-space: nowrap;">
                ${landmark.name.split(' ')[0]}
              </div>
            </div>
          `,
          className: 'google-landmark-pin',
          iconSize: [28, 34],
          iconAnchor: [14, 34],
          popupAnchor: [0, -34],
        });

        LInstance.marker(landmark.coordinates, { icon })
          .bindPopup(
            `<div style="min-width: 190px; padding: 4px 6px;">
              <div style="font-size: 9px; font-weight: 700; color: ${color}; text-transform: uppercase;">${landmark.category}</div>
              <strong style="font-size: 12px; display: block; margin: 2px 0;">${landmark.name}</strong>
              <p style="font-size: 11px; color: #64748b; margin: 0 0 6px 0;">${landmark.description ?? ''}</p>
              <div style="font-size: 10px; font-family: monospace; color: #64748b;">${landmark.coordinates[0].toFixed(5)}°, ${landmark.coordinates[1].toFixed(5)}° · ${landmark.elevation}m</div>
            </div>`,
          )
          .addTo(group);
      });
    });

    return () => {
      cancelled = true;
    };
  }, [landmarks, mapInstance]);

  const selectLandmark = (landmark: CommunityLandmark) => {
    setActiveLocation(landmark.id);
    mapInstance?.flyTo(landmark.coordinates, 17, { duration: 1 });
  };

  const activeFundraisers = useMemo(
    () => fundraisers.filter((f) => f.status === 'Active'),
    [fundraisers],
  );

  return (
    <DashboardLayout>
      <Head title="Dashboard" />

      <div className="flex flex-1 flex-col gap-8 pb-12">

        {/* ─── WELCOME, QUICK ACTIONS + KEY NUMBERS ─── */}
        <OverviewWelcome myOutstandingBalance={stats.myOutstandingBalance} />
        <OverviewAtAGlance stats={stats} permissions={permissions} />

        {/* ─── MAP + ANNOUNCEMENTS ─── */}
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-5 xl:grid-cols-5">

          <Card className="lg:col-span-3 bg-card border-border text-card-foreground flex flex-col justify-between overflow-hidden shadow-sm relative">
            <div className="p-5 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-border bg-card/60 z-10">
              <div>
                <div className="flex items-center gap-2 flex-wrap">
                  <CardTitle className="text-lg font-bold tracking-tight text-foreground flex items-center gap-2">
                    <Mountain className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    Neighborhood Geofence Map
                  </CardTitle>
                  {isProvisional ? (
                    <Badge variant="outline" className="border-amber-500/40 text-amber-600 bg-amber-500/10 text-[10px] font-bold">
                      Provisional
                    </Badge>
                  ) : (
                    <Badge variant="outline" className="border-emerald-500/40 text-emerald-600 bg-emerald-500/10 text-[10px] font-bold">
                      Published v{version}
                    </Badge>
                  )}
                </div>
                <CardDescription className="text-xs text-muted-foreground flex items-center gap-2 mt-0.5 flex-wrap">
                  <span>Perimeter: {perimeterMeters}m</span>
                  <span>•</span>
                  <span>{areaAcres} Acres</span>
                  <span>•</span>
                  <span>{coordinates.length} Beacons</span>
                </CardDescription>
              </div>

              <div className="flex items-center gap-2 flex-wrap">
                {permissions.manageBoundary && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setIsGeofenceDialogOpen(true)}
                    className="h-8 text-xs font-semibold rounded-lg gap-1.5 border-primary/40 text-primary hover:bg-primary/10 shadow-sm"
                  >
                    <Settings2 className="h-3.5 w-3.5" />
                    <span>Set Geofence</span>
                  </Button>
                )}

                <Link href="/dashboard/map">
                  <Button size="sm" variant="ghost" className="h-8 text-xs font-semibold gap-1 text-muted-foreground hover:text-foreground">
                    <span>Full Map</span>
                    <ArrowUpRight className="h-3.5 w-3.5" />
                  </Button>
                </Link>
              </div>
            </div>

            <CardContent className="p-4 flex flex-1 flex-col md:flex-row gap-4 relative justify-between items-stretch">
              <div className="flex flex-col justify-center gap-2 w-full md:w-44 shrink-0 z-10">
                <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground px-1">
                  Community Points
                </span>

                {landmarks.length === 0 && (
                  <p className="px-1 text-[11px] text-muted-foreground">
                    No landmarks yet.
                    {permissions.manageBoundary && ' Add them from the full map.'}
                  </p>
                )}

                {landmarks.map((landmark) => {
                  const isActive = activeLocation === landmark.id;

                  return (
                    <button
                      key={landmark.id}
                      type="button"
                      onClick={() => selectLandmark(landmark)}
                      aria-current={isActive}
                      className={cn(
                        'p-2 rounded-lg border text-left flex items-center gap-2 transition-all duration-200 text-xs w-full',
                        isActive
                          ? 'bg-primary/15 border-primary text-primary font-semibold shadow-xs'
                          : 'bg-muted/40 border-border text-muted-foreground hover:text-foreground hover:bg-muted/70',
                      )}
                    >
                      <span
                        className="w-2.5 h-2.5 rounded-full shrink-0"
                        style={{ backgroundColor: landmark.color ?? '#64748B' }}
                      />
                      <div className="truncate flex-1">
                        <div className="truncate font-medium text-foreground">{landmark.name}</div>
                        <div className="text-[10px] text-muted-foreground truncate">{landmark.category}</div>
                      </div>
                    </button>
                  );
                })}
              </div>

              <div className="flex-1 min-h-[300px] h-[300px] md:h-auto rounded-xl border border-border overflow-hidden relative shadow-inner">
                <LeafletMapFixed
                  center={center}
                  zoom={16}
                  mapType="terrain"
                  onReady={setMapInstance}
                  style={{ height: '100%', width: '100%', minHeight: '280px' }}
                />
              </div>
            </CardContent>

            <div className="p-4 pt-3 border-t border-border flex items-center justify-between gap-3 bg-muted/20 flex-wrap">
              <span className="text-xs text-muted-foreground flex items-center gap-2 font-mono">
                <span className="h-2 w-2 rounded-full bg-emerald-500 inline-block animate-pulse" />
                <span>{coordinates.length} nodes mapped</span>
                <span className="hidden sm:inline text-muted-foreground/60">•</span>
                <span className="hidden sm:inline text-[11px]">
                  {toDMS(center[0], true)} {toDMS(center[1], false)}
                </span>
              </span>

              {/*
                The original rendered a "Security Gate Locked / Gate Opened"
                toggle driven purely by local state — clicking it changed a label
                and nothing else. On a guard's dashboard that is worse than no
                control at all, because it reads as confirmation that the barrier
                moved. It is disabled until a real gate controller is integrated.
              */}
              <Tooltip>
                <TooltipTrigger asChild>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled
                    className="text-xs font-semibold h-8 rounded-lg gap-1.5"
                  >
                    <Lock className="h-3.5 w-3.5" />
                    <span>Gate Control Unavailable</span>
                  </Button>
                </TooltipTrigger>
                <TooltipContent className="max-w-[260px]">
                  <p className="text-xs">
                    Remote barrier operation needs a gate-controller integration. The
                    previous button only changed its own label — it never opened anything.
                  </p>
                </TooltipContent>
              </Tooltip>
            </div>
          </Card>

          {/* Announcements */}
          <Card className="lg:col-span-2 bg-card border-border text-card-foreground flex flex-col overflow-hidden shadow-sm">
            <div className="p-6 pb-0 flex items-center justify-between">
              <CardTitle className="text-lg font-bold tracking-tight text-foreground">
                Community Announcements
              </CardTitle>
              <Link href="/dashboard/notifications">
                <Button size="icon" variant="ghost" className="h-8 w-8 text-muted-foreground hover:text-foreground">
                  <MoreHorizontal className="h-4 w-4" />
                  <span className="sr-only">All notifications</span>
                </Button>
              </Link>
            </div>

            <CardContent className="p-6 flex-1 flex flex-col gap-4 overflow-y-auto max-h-[360px]">
              {/* Next event, with its date chip — previously a hardcoded BBQ card. */}
              {upcomingEvents.slice(0, 1).map((event) => (
                <div
                  key={event.id}
                  className="bg-muted/30 border border-border rounded-xl overflow-hidden flex flex-col hover:border-border/80 transition-colors"
                >
                  {event.imageUrl && (
                    <div className="h-24 bg-muted relative overflow-hidden shrink-0">
                      <Image src={event.imageUrl} alt="" fill className="object-cover opacity-90" />
                      <div className="absolute top-3 left-3 bg-background/95 border border-border rounded px-2 py-1 flex flex-col items-center min-w-[42px] leading-tight shadow-md text-foreground">
                        <span className="text-[10px] text-muted-foreground font-bold uppercase tracking-wider">
                          <ClientFormattedDate date={event.startDate} formatString="MMM" />
                        </span>
                        <span className="text-sm font-extrabold text-emerald-600 dark:text-emerald-400">
                          <ClientFormattedDate date={event.startDate} formatString="d" />
                        </span>
                      </div>
                    </div>
                  )}
                  <div className="p-3.5 flex flex-col gap-1">
                    <h4 className="text-sm font-bold text-foreground leading-tight">{event.title}</h4>
                    <p className="text-xs text-muted-foreground line-clamp-2">{event.summary}</p>
                    <div className="flex items-center gap-2 mt-2">
                      <Badge variant="outline" className="text-[10px] py-0 h-4">Event</Badge>
                      <span className="text-[10px] text-muted-foreground">
                        <ClientFormattedDate date={event.startDate} formatString="hh:mm a" />
                      </span>
                    </div>
                  </div>
                </div>
              ))}

              {announcements.length === 0 && upcomingEvents.length === 0 && (
                <p className="py-6 text-center text-sm text-muted-foreground">
                  No announcements right now.
                </p>
              )}

              {announcements.map((announcement) => (
                <div
                  key={announcement.id}
                  className="bg-muted/30 border border-border rounded-xl p-4 flex gap-3.5 hover:border-border/80 transition-colors"
                >
                  <div className="h-10 w-10 rounded-lg bg-primary/10 border border-primary/20 flex items-center justify-center shrink-0 text-primary font-bold text-lg">
                    📢
                  </div>
                  <div className="flex-1 flex flex-col gap-1 min-w-0">
                    <h4 className="text-sm font-bold text-foreground">{announcement.title}</h4>
                    <p className="text-xs text-muted-foreground line-clamp-2">{announcement.content}</p>
                    <span className="text-[10px] text-muted-foreground mt-1">
                      {announcement.author} ·{' '}
                      <ClientFormattedDate date={announcement.timestamp} formatString="MMM d, hh:mm a" />
                    </span>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* ─── VISITOR PASSES + RESIDENTS ─── */}
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-5 xl:grid-cols-5">

          <Card className="lg:col-span-3 bg-card border-border text-card-foreground flex flex-col overflow-hidden shadow-sm">
            <div className="p-6 pb-0 flex items-center justify-between">
              <div>
                <CardTitle className="text-lg font-bold tracking-tight text-foreground">
                  Visitor Gate Passes
                </CardTitle>
                <CardDescription className="text-xs text-muted-foreground">
                  Entry clearances authorized today
                </CardDescription>
              </div>
              <Link href="/dashboard/visitors">
                <Button size="icon" variant="ghost" className="h-8 w-8 text-muted-foreground hover:text-foreground">
                  <MoreHorizontal className="h-4 w-4" />
                  <span className="sr-only">All visitors</span>
                </Button>
              </Link>
            </div>

            <CardContent className="p-6 flex flex-col md:flex-row gap-4 overflow-x-auto">
              {visitorPasses.length === 0 && (
                <p className="py-4 text-sm text-muted-foreground">
                  No visitors cleared for today.
                </p>
              )}

              {visitorPasses.map((pass) => (
                <div
                  key={pass.id}
                  className={cn(
                    'flex-1 min-w-[200px] bg-muted/30 border rounded-xl p-4 flex flex-col gap-4 transition-all duration-200 relative group',
                    pass.isBlocked
                      ? 'border-destructive/50 bg-destructive/5'
                      : 'border-border hover:border-primary/40',
                  )}
                >
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center font-bold text-primary text-xs shrink-0">
                      {pass.name
                        .split(' ')
                        .slice(0, 2)
                        .map((p) => p[0])
                        .join('')}
                    </div>
                    <div className="flex-1 flex flex-col leading-tight min-w-0">
                      <span className="text-xs font-bold text-foreground truncate group-hover:text-primary transition-colors">
                        {pass.name}
                      </span>
                      <span className="text-[10px] text-muted-foreground truncate">
                        {pass.host ? `${pass.host} Guest` : 'Guest'}
                      </span>
                    </div>
                    {pass.isBlocked ? (
                      <ShieldOff className="h-3.5 w-3.5 text-destructive shrink-0" />
                    ) : (
                      <span
                        className={cn(
                          'h-2 w-2 rounded-full shrink-0',
                          pass.status === 'Checked In' ? 'bg-emerald-500' : 'bg-muted-foreground/40',
                        )}
                      />
                    )}
                  </div>

                  <div className="flex flex-col gap-1.5 text-[10px] text-muted-foreground font-semibold border-t border-b border-border py-3">
                    <div className="flex justify-between">
                      <span>Expected</span>
                      <span className="text-foreground">
                        <ClientFormattedDate date={pass.expectedAt} formatString="hh:mm a" />
                      </span>
                    </div>
                    <div className="flex justify-between">
                      <span>Status</span>
                      <span className="text-foreground">{pass.status}</span>
                    </div>
                  </div>

                  <div className="flex items-center justify-between">
                    <span
                      className={cn(
                        'text-[9px] font-bold rounded-full px-2 py-0.5 border',
                        pass.isBlocked
                          ? 'text-destructive bg-destructive/10 border-destructive/30'
                          : 'text-emerald-700 dark:text-emerald-400 bg-emerald-500/15 border-emerald-500/30',
                      )}
                    >
                      ● {pass.isBlocked ? 'Blocked' : 'Approved'}
                    </span>
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>

          {/* Resident Spotlight */}
          <Card className="lg:col-span-2 bg-card border-border text-card-foreground flex flex-col overflow-hidden shadow-sm">
            <div className="p-6 pb-0 flex items-center justify-between">
              <CardTitle className="text-lg font-bold tracking-tight text-foreground">
                Resident Spotlight
              </CardTitle>
              <Link href="/dashboard/directory">
                <Button size="icon" variant="ghost" className="h-8 w-8 text-muted-foreground hover:text-foreground">
                  <MoreHorizontal className="h-4 w-4" />
                  <span className="sr-only">Full directory</span>
                </Button>
              </Link>
            </div>

            <CardContent className="p-6 flex-1 grid grid-cols-2 gap-3 overflow-y-auto max-h-[300px] content-start">
              {residents.length === 0 && (
                <p className="col-span-2 text-sm text-muted-foreground">No residents listed.</p>
              )}

              {residents.map((resident) => (
                <div
                  key={resident.id}
                  className="bg-muted/30 border border-border rounded-xl p-3 flex flex-col gap-2 hover:border-border/80 transition-colors"
                >
                  <div className="flex items-center gap-2 min-w-0">
                    <div className="h-8 w-8 rounded-full bg-primary/10 border border-primary/20 flex items-center justify-center font-bold text-primary text-xs shrink-0 overflow-hidden">
                      {resident.avatarUrl ? (
                        <Image src={resident.avatarUrl} alt="" className="h-full w-full object-cover" />
                      ) : (
                        resident.initials
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <h5 className="text-xs font-bold text-foreground truncate">{resident.name}</h5>
                      <p className="text-[9px] text-muted-foreground font-semibold uppercase truncate">
                        {resident.lot ?? 'Unassigned'} · {resident.role}
                      </p>
                    </div>
                  </div>

                  {/*
                    The phone button is only rendered when the server supplied a
                    number, which it does only for viewers who may manage users.
                    The original showed phone and message buttons to everyone and
                    neither did anything.
                  */}
                  {resident.phone && (
                    <div className="flex gap-1.5 mt-1 border-t border-border pt-2 justify-end">
                      <Button
                        asChild
                        size="icon"
                        variant="ghost"
                        className="h-6 w-6 rounded-md bg-muted hover:bg-primary hover:text-primary-foreground text-muted-foreground"
                      >
                        <a href={`tel:${resident.phone}`} aria-label={`Call ${resident.name}`}>
                          <Phone className="h-3 w-3" />
                        </a>
                      </Button>
                    </div>
                  )}
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* ─── FINANCIALS, FUNDRAISERS, PROMOTIONS ─── */}
        <div>
          <div className="flex items-center gap-3 mb-4 flex-wrap">
            <Badge className="bg-emerald-500/15 border border-emerald-500/30 text-emerald-700 dark:text-emerald-400">
              <Activity className="h-3 w-3 mr-1" />
              Operations Portal
            </Badge>
            <h2 className="text-xl font-bold tracking-tight text-foreground">
              Financials, Fundraisers &amp; Promotions
            </h2>
          </div>

          {permissions.viewFundraisers && activeFundraisers.length > 0 && (
            <div className="grid gap-6 md:grid-cols-2 mb-8">
              {activeFundraisers.map((fundraiser) => (
                /*
                 * Passed straight through: the card now takes the server's own
                 * shape, so the adapter that rebuilt it into the legacy
                 * `Fundraiser` type — and pinned the currency to JMD — is gone.
                 * Totals come from SQL over integer minor units.
                 */
                <FundraiserProgressCard key={fundraiser.id} fundraiser={fundraiser} />
              ))}
            </div>
          )}

          <div className="grid gap-6 md:gap-8 lg:grid-cols-2 xl:grid-cols-3">
            {permissions.viewRecentVisitors && (
              <Card className="xl:col-span-2 bg-card border-border text-card-foreground shadow-sm">
                <CardHeader className="flex flex-row items-center">
                  <div className="grid gap-1">
                    <CardTitle className="text-base font-bold text-foreground">Recent Visitors Log</CardTitle>
                    <CardDescription className="text-xs text-muted-foreground">
                      The most recent visitors registered at the gate.
                    </CardDescription>
                  </div>
                  <Button asChild size="sm" variant="outline" className="ml-auto gap-1">
                    <Link href="/dashboard/visitors">
                      View All
                      <ArrowUpRight className="h-3.5 w-3.5" />
                    </Link>
                  </Button>
                </CardHeader>
                <CardContent>
                  <Table>
                    <TableHeader>
                      <TableRow className="border-border hover:bg-transparent">
                        <TableHead className="text-muted-foreground font-semibold">Visitor</TableHead>
                        <TableHead className="hidden md:table-cell text-muted-foreground font-semibold">
                          Homeowner
                        </TableHead>
                        <TableHead className="text-right text-muted-foreground font-semibold">Date</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {recentVisitors.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={3} className="py-6 text-center text-sm text-muted-foreground">
                            No visitors logged yet.
                          </TableCell>
                        </TableRow>
                      )}

                      {recentVisitors.map((visitor) => (
                        <TableRow key={visitor.id} className="border-border hover:bg-muted/30">
                          <TableCell>
                            <div className="font-semibold text-sm text-foreground flex items-center gap-1.5">
                              {visitor.name}
                              {visitor.isBlocked && <ShieldOff className="h-3 w-3 text-destructive" />}
                            </div>
                            <div className="text-xs text-muted-foreground">
                              {visitor.type} · {visitor.status}
                            </div>
                          </TableCell>
                          <TableCell className="hidden md:table-cell text-xs text-muted-foreground">
                            {visitor.homeowner}
                          </TableCell>
                          <TableCell className="text-right text-xs">
                            <ClientFormattedDate date={visitor.expectedAt} formatString="yyyy-MM-dd" />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            )}

            {permissions.viewBilling && (
              <Card className="bg-card border-border text-card-foreground shadow-sm">
                <CardHeader className="flex flex-row items-center">
                  <div className="grid gap-1">
                    <CardTitle className="text-base font-bold text-foreground">Recent Payments</CardTitle>
                    <CardDescription className="text-xs text-muted-foreground">
                      Maintenance fees and dues.
                    </CardDescription>
                  </div>
                  <Button asChild size="sm" variant="outline" className="ml-auto gap-1">
                    <Link href="/dashboard/billing">
                      View All
                      <ArrowUpRight className="h-3.5 w-3.5" />
                    </Link>
                  </Button>
                </CardHeader>
                <CardContent>
                  <Table>
                    <TableHeader>
                      <TableRow className="border-border hover:bg-transparent">
                        <TableHead className="text-muted-foreground font-semibold">Homeowner</TableHead>
                        <TableHead className="text-muted-foreground font-semibold">Status</TableHead>
                        <TableHead className="text-right text-muted-foreground font-semibold">
                          Amount (JMD)
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {recentPayments.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={3} className="py-6 text-center text-sm text-muted-foreground">
                            No invoices yet.
                          </TableCell>
                        </TableRow>
                      )}

                      {recentPayments.map((payment) => (
                        <TableRow key={payment.id} className="border-border hover:bg-muted/30">
                          <TableCell>
                            <div className="font-semibold text-sm text-foreground">
                              {payment.homeowner.split('(')[0].trim()}
                            </div>
                            <div className="text-xs text-muted-foreground">
                              {payment.homeowner.match(/\(([^)]+)\)/)?.[1] ?? payment.reference}
                            </div>
                          </TableCell>
                          <TableCell>
                            <Badge
                              className="text-[10px]"
                              variant={payment.status === 'Paid' ? 'secondary' : 'destructive'}
                            >
                              {payment.status}
                            </Badge>
                          </TableCell>
                          <TableCell className="text-right font-mono text-xs text-foreground">
                            {payment.amount.toFixed(2)}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            )}

            <Card className="bg-card border-border text-card-foreground shadow-sm">
              <CardHeader>
                <CardTitle className="text-base font-bold text-foreground">
                  Community Perks &amp; Offers
                </CardTitle>
                <CardDescription className="text-xs text-muted-foreground">
                  Special offers from verified local businesses.
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4">
                {perks.length === 0 && (
                  <p className="text-sm text-muted-foreground">No offers available.</p>
                )}

                {perks.map((perk) => {
                  const voucher = perk.vouchers[0];

                  return (
                    <Link
                      key={perk.id}
                      href="/dashboard/deals"
                      className="flex items-center gap-4 group p-2 rounded-lg hover:bg-muted/50 transition-colors border border-transparent hover:border-border"
                    >
                      <div className="h-12 w-12 rounded-lg overflow-hidden relative border border-border shrink-0 bg-muted">
                        {perk.logoUrl && (
                          <Image
                            alt=""
                            className="object-cover"
                            fill
                            src={perk.logoUrl}
                            data-ai-hint={perk.aiHint ?? undefined}
                          />
                        )}
                      </div>
                      <div className="grid gap-0.5 min-w-0">
                        <p className="text-xs font-bold leading-snug group-hover:text-primary transition-colors text-foreground truncate">
                          {voucher?.title ?? perk.name}
                        </p>
                        <p className="text-[10px] text-muted-foreground leading-normal line-clamp-2">
                          {voucher?.description ?? `Offers from ${perk.name}.`}
                        </p>
                      </div>
                    </Link>
                  );
                })}
              </CardContent>
            </Card>
          </div>
        </div>

        {permissions.manageBoundary && (
          <SetGeofenceDialog open={isGeofenceDialogOpen} onOpenChange={setIsGeofenceDialogOpen} />
        )}
      </div>
    </DashboardLayout>
  );
}
