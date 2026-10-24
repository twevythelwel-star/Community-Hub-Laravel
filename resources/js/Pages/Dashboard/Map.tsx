
import dynamic from '@/lib/dynamic';
import { useMap } from "@/context/map-context";
import { useIsClient } from "@/hooks/use-is-client";
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { Head, router, usePage } from '@inertiajs/react';
import type L from 'leaflet';
import { useEffect, useState, useRef, useMemo, useCallback, Suspense } from 'react';
import { 
  Mountain, 
  Map as MapIcon, 
  ShieldCheck, 
  Radio, 
  Settings2
} from 'lucide-react';
import { AmenityBookingDialog } from '@/components/dashboard/amenity-booking-dialog';
import type { MapType } from '@/components/dashboard/LeafletMapFixed';
import { 
  CommunityLandmark,
  LandmarkCategory,
  LANDMARK_PIN_CONFIGS,
  toDMS,
  estimateElevation,
  haversineDistance,
  isPointInGeofence,
  getGeofencePerimeterInfo,
  calculatePolygonMetrics
} from '@/lib/geofence-utils';
import { SetGeofenceDialog } from '@/components/dashboard/set-geofence-dialog';
import { AddLandmarkDialog } from '@/components/dashboard/add-landmark-dialog';
import BoundaryPointManager from '@/components/dashboard/boundary-point-manager';
import DashboardLayout from '@/Layouts/DashboardLayout';
import type { BoundaryConfig, CommunityInfo } from '@/lib/boundary-manager/types';
import { MapControlsOverlay } from '@/components/dashboard/map/map-controls-overlay';
import { MapInspectorCard } from '@/components/dashboard/map/map-inspector-card';
import { MapLandmarkFilters } from '@/components/dashboard/map/map-landmark-filters';
import { MapGeofenceTelemetry } from '@/components/dashboard/map/map-geofence-telemetry';

// Client-only to avoid SSR/hydration issues
const LeafletMapFixed = dynamic(() => import("@/components/dashboard/LeafletMapFixed"), {
  ssr: false,
  loading: () => (
    <div className="h-[620px] w-full flex flex-col items-center justify-center bg-muted/20 gap-3">
      <Mountain className="h-8 w-8 text-primary animate-pulse" />
      <p className="text-sm text-muted-foreground font-medium">Loading Google Terrain Map...</p>
    </div>
  ),
});

type MapPageProps = {
  landmarks: CommunityLandmark[];
  community: CommunityInfo;
  boundaryConfig: BoundaryConfig;
  can: { manageBoundary: boolean };
};

function MapPageContent({ landmarks, community, boundaryConfig, can }: MapPageProps) {
  const isClient = useIsClient();
  const { coordinates } = useMap();
  const { toast } = useToast();

  const [mapInstance, setMapInstance] = useState<L.Map | null>(null);
  const [mapType, setMapType] = useState<MapType>('terrain');
  const [isLocked, setIsLocked] = useState<boolean>(true);
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);
  const [searchQuery, setSearchQuery] = useState<string>('');
  
  // Landmarks arrive as props from MapController; the component no longer owns
  // the list. Previously it was read from localStorage on mount, so each browser
  // had a private set of pins.
  const [isAddPinOpen, setIsAddPinOpen] = useState<boolean>(false);
  const [isBookingOpen, setIsBookingOpen] = useState<boolean>(false);
  const [bookingAmenityName, setBookingAmenityName] = useState<string | undefined>(undefined);
  const [isClickToPlacePinActive, setIsClickToPlacePinActive] = useState<boolean>(false);
  const [pinFilterCategory, setPinFilterCategory] = useState<'all' | LandmarkCategory>('all');
  const [addPinCoords, setAddPinCoords] = useState<[number, number] | null>(null);

  // Filtered landmarks for display
  const filteredLandmarks = useMemo(() => {
    if (pinFilterCategory === 'all') return landmarks;
    return landmarks.filter(l => l.category === pinFilterCategory);
  }, [landmarks, pinFilterCategory]);

  // Counts for badge tabs
  const categoryCounts = useMemo(() => {
    const counts = {
      all: landmarks.length,
      'Security Gate': 0,
      'Community Center': 0,
      'Park': 0,
    };
    landmarks.forEach(l => {
      if (counts[l.category] !== undefined) {
        counts[l.category]++;
      }
    });
    return counts;
  }, [landmarks]);

  // Handler to add a landmark pin. Persisted server-side so every resident sees it.
  const handleAddLandmark = (newLandmark: CommunityLandmark) => {
    router.post(
      '/dashboard/map/landmarks',
      {
        name: newLandmark.name,
        category: newLandmark.category,
        description: newLandmark.description,
        lat: newLandmark.coordinates[0],
        lng: newLandmark.coordinates[1],
        icon: newLandmark.iconType,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setIsClickToPlacePinActive(false);

          // Pan to the new pin once the props have refreshed.
          if (mapInstance) {
            mapInstance.flyTo(newLandmark.coordinates, 18, { duration: 1.2 });
          }
        },
        onError: () =>
          toast({
            variant: 'destructive',
            title: 'Pin Not Added',
            description: 'The landmark could not be saved. Check the name and coordinates.',
          }),
      },
    );
  };

  // Handler to remove a landmark pin.
  const handleRemoveLandmark = useCallback(
    (id: string | number, name: string) => {
      if (!window.confirm(`Remove "${name}" from the community map for everyone?`)) return;

      router.delete(`/dashboard/map/landmarks/${id}`, {
        preserveScroll: true,
        onSuccess: () =>
          toast({
            title: 'Pin Removed',
            description: `Removed "${name}" from the community map.`,
          }),
        onError: () =>
          toast({
            variant: 'destructive',
            title: 'Pin Not Removed',
            description: 'Your role cannot modify community landmarks.',
          }),
      });
    },
    [toast],
  );

  /*
   * "Reset to Standard Pins" was removed.
   *
   * It overwrote the landmark list with a hardcoded fixture. Against
   * localStorage that only wiped your own copy; against the database it would
   * silently delete every landmark the community had added and replace them
   * with four defaults — a destructive action behind an innocuous button.
   * Landmarks are now removed one at a time, with a confirmation.
   */

  // Geofence states
  // Inertia has no useSearchParams; the current URL is on the page object.
  const { url } = usePage();
  const tabParam = new URLSearchParams(url.split('?')[1] ?? '').get('tab');
  const [showGeofenceMask, setShowGeofenceMask] = useState<boolean>(true);
  const [isGeofenceDialogOpen, setIsGeofenceDialogOpen] = useState<boolean>(false);
  const [activeView, setActiveView] = useState<'operational' | 'boundary'>(tabParam === 'boundary' ? 'boundary' : 'operational');

  useEffect(() => {
    if (tabParam === 'boundary') {
      setActiveView('boundary');
    } else if (tabParam === 'operational') {
      setActiveView('operational');
    }
  }, [tabParam]);

  // Inspected coordinate on map click
  const [inspectedCoords, setInspectedCoords] = useState<[number, number] | null>(null);
  const [activeCenter, setActiveCenter] = useState<[number, number]>([18.4766, -77.9257]);
  const [activeZoom, setActiveZoom] = useState<number>(16);

  const geoJsonLayerRef = useRef<L.GeoJSON | null>(null);
  const maskLayerRef = useRef<L.GeoJSON | null>(null);
  const markersLayerRef = useRef<L.LayerGroup | null>(null);
  const beaconsLayerRef = useRef<L.LayerGroup | null>(null);
  const inspectedMarkerRef = useRef<L.Marker | null>(null);
  const mapWrapperRef = useRef<HTMLDivElement | null>(null);

  /*
   * Mirrors the server's `manageBoundary` gate, which covers System Admin and
   * Admin. The inline role comparison here showed an Admin a read-only map
   * even though BoundaryController would have accepted their edits.
   */
  const isSystemAdmin = can.manageBoundary;

  // Polygon metrics (centroid, perimeter, area)
  const { center, areaAcres, areaHectares, perimeterMeters } = useMemo(() => {
    return calculatePolygonMetrics(coordinates);
  }, [coordinates]);

  // ── Render Inverted Geofence Mask Layer (Dims outside, highlights community) ──
  useEffect(() => {
    if (!mapInstance) return;

    import('leaflet').then((Leaflet) => {
      const LInstance = Leaflet.default;

      if (maskLayerRef.current) {
        maskLayerRef.current.remove();
        maskLayerRef.current = null;
      }

      if (!showGeofenceMask || coordinates.length < 3) return;

      const worldOuterRing = [
        [-180, -85],
        [180, -85],
        [180, 85],
        [-180, 85],
        [-180, -85],
      ];

      const communityHole = [
        ...coordinates.map(c => [c[1], c[0]]),
        [coordinates[0][1], coordinates[0][0]]
      ];

      const maskFeature: GeoJSON.Feature<GeoJSON.Polygon> = {
        type: "Feature",
        properties: {},
        geometry: {
          type: "Polygon",
          coordinates: [worldOuterRing, communityHole],
        },
      };

      const maskLayer = LInstance.geoJSON(maskFeature, {
        style: {
          fillColor: "#020817",
          fillOpacity: 0.36,
          stroke: false,
        },
        interactive: false,
      }).addTo(mapInstance);

      maskLayerRef.current = maskLayer;
      maskLayer.bringToBack();
    });
  }, [coordinates, mapInstance, showGeofenceMask]);

  // ── Render Geofence Perimeter Fence & Constraints ──
  useEffect(() => {
    if (!mapInstance) return;

    import('leaflet').then((Leaflet) => {
      const LInstance = Leaflet.default;

      if (geoJsonLayerRef.current) {
        geoJsonLayerRef.current.remove();
      }

      const geoJsonFeature: GeoJSON.Feature<GeoJSON.Polygon> = {
        type: "Feature",
        properties: {},
        geometry: {
          type: "Polygon",
          coordinates: [[...coordinates.map(c => [c[1], c[0]]), [coordinates[0][1], coordinates[0][0]]]],
        },
      };

      // Glowing Geofence Security Perimeter Fence Line
      const newLayer = LInstance.geoJSON(geoJsonFeature, {
        style: {
          color: "#10B981", // Emerald Geofence Barrier
          weight: 3.5,
          opacity: 0.95,
          dashArray: "8, 6",
          fillColor: "#10B981",
          fillOpacity: 0.08,
        }
      }).addTo(mapInstance);
      geoJsonLayerRef.current = newLayer;

      const bounds = newLayer.getBounds();

      if (isLocked) {
        mapInstance.fitBounds(bounds, { padding: [48, 48] });
        const paddedBounds = bounds.pad(0.08); // 8% exploration buffer
        mapInstance.setMaxBounds(paddedBounds);
        mapInstance.options.maxBoundsViscosity = 1.0; // Strict boundary enforcement
        const minZoomForArea = mapInstance.getBoundsZoom(bounds, true);
        mapInstance.setMinZoom(minZoomForArea);
      } else {
        mapInstance.setMaxBounds(undefined);
        mapInstance.setMinZoom(11);
      }
    });
  }, [coordinates, mapInstance, isLocked]);

  // ── Render Geofence Boundary Beacons (Radar Nodes on each corner vertex) ──
  useEffect(() => {
    if (!mapInstance) return;

    import('leaflet').then((Leaflet) => {
      const LInstance = Leaflet.default;

      if (beaconsLayerRef.current) {
        beaconsLayerRef.current.remove();
      }

      const group = LInstance.layerGroup().addTo(mapInstance);
      beaconsLayerRef.current = group;

      const nodePositions = ['NW Boundary', 'NE Boundary', 'SE Boundary', 'SW Boundary'];

      coordinates.forEach((coord, idx) => {
        const nodeName = `Geofence Node #${idx + 1} (${nodePositions[idx] || `Node ${idx + 1}`})`;
        const elev = estimateElevation(coord[0], coord[1]);

        const beaconHtml = `
          <div style="position: relative; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; cursor: pointer; transform: translate(-50%, -50%);">
            <div class="geofence-beacon-pulse" style="position: absolute; width: 26px; height: 26px; border-radius: 50%; background: rgba(16, 185, 129, 0.45); border: 1.5px solid #10B981;"></div>
            <div style="width: 12px; height: 12px; border-radius: 50%; background: #10B981; border: 2.5px solid #FFFFFF; box-shadow: 0 0 8px rgba(16,185,129,0.8); z-index: 2;"></div>
          </div>
        `;

        const beaconIcon = LInstance.divIcon({
          html: beaconHtml,
          className: 'geofence-beacon-icon',
          iconSize: [32, 32],
          iconAnchor: [16, 16],
          popupAnchor: [0, -16],
        });

        const popupContent = `
          <div style="min-width: 230px; padding: 12px 14px; font-family: inherit;">
            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
              <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10B981;"></span>
              <span style="font-size: 11px; font-weight: 700; color: #10B981; text-transform: uppercase;">Perimeter Radar Node</span>
            </div>
            <h4 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 700;">${nodeName}</h4>
            <p style="margin: 0 0 8px 0; font-size: 11px; color: #64748b; font-family: monospace;">
              ${coord[0].toFixed(5)}°N, ${Math.abs(coord[1]).toFixed(5)}°W
            </p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 6px 8px; background: rgba(100,116,139,0.08); border-radius: 6px; font-size: 11px;">
              <div><span style="color:#64748b;">Elevation</span><br/><strong>${elev}m MSL</strong></div>
              <div><span style="color:#64748b;">Status</span><br/><strong style="color:#10B981;">Armed & Active</strong></div>
            </div>
          </div>
        `;

        const marker = LInstance.marker(coord, { icon: beaconIcon }).addTo(group);
        marker.bindPopup(popupContent, { maxWidth: 280, closeButton: true });
      });
    });
  }, [coordinates, mapInstance]);

  // ── Render Google-Style Community Landmark Pins with Category Styling (Green, Red, Blue) ──
  useEffect(() => {
    if (!mapInstance) return;

    import('leaflet').then((Leaflet) => {
      const LInstance = Leaflet.default;

      if (markersLayerRef.current) {
        markersLayerRef.current.remove();
      }

      const group = LInstance.layerGroup().addTo(mapInstance);
      markersLayerRef.current = group;

      filteredLandmarks.forEach((landmark) => {
        // SVG glyph according to category
        let glyphSvg = '';
        let badgeIcon = '📍';

        if (landmark.category === 'Security Gate') {
          // Shield / Gate glyph
          glyphSvg = `<path d="M18 11.5L12.5 13.8V17.5C12.5 20.8 14.8 23.9 18 24.8C21.2 23.9 23.5 20.8 23.5 17.5V13.8L18 11.5Z" fill="${landmark.color}"/>`;
          badgeIcon = '🛡️';
        } else if (landmark.category === 'Community Center') {
          // Building / Center glyph
          glyphSvg = `<path d="M12.5 24V14.5L18 11L23.5 14.5V24H19.5V18.5H16.5V24H12.5ZM15 17H16.5V15H15V17ZM19.5 17H21V15H19.5V17Z" fill="${landmark.color}"/>`;
          badgeIcon = '🏛️';
        } else {
          // Tree / Park glyph
          glyphSvg = `<path d="M18 11L13.5 16H15.5L13 20H16.5V24H19.5V20H23L20.5 16H22.5L18 11Z" fill="${landmark.color}"/>`;
          badgeIcon = '🌳';
        }

        const pinHtml = `
          <div style="position: relative; display: flex; flex-direction: column; align-items: center; cursor: pointer; transform: translate(-50%, -100%);">
            <div style="position: absolute; bottom: 0; width: 22px; height: 9px; background: rgba(0,0,0,0.32); border-radius: 50%; filter: blur(2px);"></div>
            <svg width="36" height="46" viewBox="0 0 36 46" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 4px 6px rgba(0,0,0,0.35)); transition: transform 0.2s;">
              <path d="M18 0C8.05888 0 0 8.05888 0 18C0 29.5 15.3 43.8 17.1 45.4C17.6 45.9 18.4 45.9 18.9 45.4C20.7 43.8 36 29.5 36 18C36 8.05888 27.9411 0 18 0Z" fill="${landmark.color}"/>
              <circle cx="18" cy="18" r="9.5" fill="#FFFFFF"/>
              ${glyphSvg}
            </svg>
            <div style="background: rgba(15,23,42,0.88); color: #f8fafc; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; margin-top: -6px; border: 1.5px solid ${landmark.color}; white-space: nowrap; box-shadow: 0 2px 8px rgba(0,0,0,0.4); display: flex; align-items: center; gap: 4px;">
              <span>${badgeIcon}</span>
              <span>${landmark.name.length > 20 ? landmark.name.substring(0, 18) + '...' : landmark.name}</span>
            </div>
          </div>
        `;

        const icon = LInstance.divIcon({
          html: pinHtml,
          className: 'google-landmark-pin',
          iconSize: [36, 46],
          iconAnchor: [18, 46],
          popupAnchor: [0, -46],
        });

        const popupContent = `
          <div style="min-width: 250px; padding: 14px 16px; font-family: inherit;">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px;">
              <div style="display: flex; align-items: center; gap: 6px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 9999px; background-color: ${landmark.color};"></span>
                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: ${landmark.color};">${landmark.category}</span>
              </div>
              <span style="font-size: 10px; padding: 2px 6px; border-radius: 4px; background: rgba(100,116,139,0.12); color: #64748b; font-family: monospace;">
                ${landmark.elevation}m MSL
              </span>
            </div>
            <h4 style="margin: 0 0 6px 0; font-size: 15px; font-weight: 700; color: inherit;">${landmark.name}</h4>
            <p style="margin: 0 0 10px 0; font-size: 12px; color: #64748b; line-height: 1.4;">${landmark.description}</p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 8px; background: rgba(100,116,139,0.08); border-radius: 8px; font-size: 11px; margin-bottom: 10px;">
              <div>
                <span style="color: #64748b; display: block;">Pin Color</span>
                <strong style="color: ${landmark.color}; font-size: 12px;">${landmark.category === 'Security Gate' ? 'Emerald Green' : landmark.category === 'Community Center' ? 'Ruby Red' : 'Sky Blue'}</strong>
              </div>
              <div>
                <span style="color: #64748b; display: block;">Geofence Access</span>
                <strong style="color: #10b981; font-size: 12px;">Authorized Zone</strong>
              </div>
            </div>
            <div style="font-size: 11px; color: #64748b; font-family: monospace; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
              <span>${landmark.coordinates[0].toFixed(5)}°, ${landmark.coordinates[1].toFixed(5)}°</span>
            </div>
            <button id="btn-del-${landmark.id}" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 10px; background: rgba(239, 68, 68, 0.08); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
              Remove This Landmark Pin
            </button>
          </div>
        `;

        const marker = LInstance.marker(landmark.coordinates, { icon }).addTo(group);
        marker.bindPopup(popupContent, { maxWidth: 310, closeButton: true });

        marker.on('popupopen', () => {
          const delBtn = document.getElementById(`btn-del-${landmark.id}`);
          if (delBtn) {
            delBtn.onclick = (e) => {
              e.stopPropagation();
              handleRemoveLandmark(landmark.id, landmark.name);
              mapInstance.closePopup();
            };
          }
        });
      });
    });
  }, [mapInstance, filteredLandmarks, handleRemoveLandmark]);

  // ── Handle Map Click with Real-Time Geofence Validation & Click-to-Place ──
  const handleMapClick = useCallback((coords: [number, number]) => {
    // If Click-to-Place mode is active, trigger Add Landmark Pin directly
    if (isClickToPlacePinActive) {
      setAddPinCoords(coords);
      setIsAddPinOpen(true);
      setIsClickToPlacePinActive(false);
      toast({
        title: "Location Selected",
        description: `Coordinates ${coords[0].toFixed(5)}°, ${coords[1].toFixed(5)}° selected for new landmark pin.`,
      });
      return;
    }

    setInspectedCoords(coords);

    if (!mapInstance) return;

    import('leaflet').then((Leaflet) => {
      const LInstance = Leaflet.default;

      if (inspectedMarkerRef.current) {
        inspectedMarkerRef.current.remove();
      }

      const elev = estimateElevation(coords[0], coords[1]);
      const geofenceInfo = getGeofencePerimeterInfo(coords, coordinates);
      const dmsLat = toDMS(coords[0], true);
      const dmsLng = toDMS(coords[1], false);

      const pinColor = geofenceInfo.isInside ? '#10B981' : '#F59E0B';

      const inspectPinHtml = `
        <div style="position: relative; display: flex; flex-direction: column; align-items: center; cursor: pointer; transform: translate(-50%, -100%);">
          <div class="google-pin-pulse" style="position: absolute; bottom: 0; width: 22px; height: 12px; background: ${geofenceInfo.isInside ? 'rgba(16, 185, 129, 0.4)' : 'rgba(245, 158, 11, 0.4)'}; border-radius: 50%; z-index: -1;"></div>
          <svg width="34" height="42" viewBox="0 0 34 42" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 4px 8px rgba(0,0,0,0.45));">
            <path d="M17 0C7.61116 0 0 7.61116 0 17C0 27.5 14.5 40.5 16.2 42C16.6 42.4 17.4 42.4 17.8 42C19.5 40.5 34 27.5 34 17C34 7.61116 26.3888 0 17 0Z" fill="${pinColor}"/>
            <circle cx="17" cy="16" r="6.5" fill="#FFFFFF"/>
            <circle cx="17" cy="16" r="3.5" fill="${pinColor}"/>
          </svg>
        </div>
      `;

      const inspectIcon = LInstance.divIcon({
        html: inspectPinHtml,
        className: 'google-inspect-pin',
        iconSize: [34, 42],
        iconAnchor: [17, 42],
        popupAnchor: [0, -42],
      });

      const popupHtml = `
        <div style="min-width: 270px; padding: 14px 16px; font-family: inherit;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
            <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: ${pinColor}; letter-spacing: 0.05em;">
              ${geofenceInfo.isInside ? '🛡️ INSIDE GEOFENCE' : '⚠️ OUTSIDE GEOFENCE'}
            </span>
            <span style="font-size: 11px; color: #64748b;">${geofenceInfo.distanceMeters}m to perimeter</span>
          </div>
          <h4 style="margin: 0 0 4px 0; font-size: 16px; font-weight: 700;">${coords[0].toFixed(5)}, ${coords[1].toFixed(5)}</h4>
          <p style="margin: 0 0 8px 0; font-size: 12px; color: #64748b; font-family: monospace;">${dmsLat} ${dmsLng}</p>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 8px; background: rgba(100,116,139,0.08); border-radius: 8px; font-size: 11px; margin-bottom: 10px;">
            <div>
              <span style="color: #64748b; display: block;">Elevation</span>
              <strong style="color: inherit; font-size: 13px;">~${elev} m MSL</strong>
            </div>
            <div>
              <span style="color: #64748b; display: block;">Nearest Gate</span>
              <strong style="color: inherit; font-size: 12px;">${geofenceInfo.nearestGate.name.split(' ')[0]} (${geofenceInfo.nearestGate.distance}m)</strong>
            </div>
          </div>
          <div style="font-size: 11px; color: ${geofenceInfo.isInside ? '#10b981' : '#f59e0b'}; font-weight: 600; text-align: center;">
            ${geofenceInfo.isInside ? 'Authorized Community Cadastral Zone' : 'External Buffer Zone / Outside Monitored Bounds'}
          </div>
        </div>
      `;

      const marker = LInstance.marker(coords, { icon: inspectIcon }).addTo(mapInstance);
      marker.bindPopup(popupHtml, { maxWidth: 310, closeButton: true }).openPopup();
      inspectedMarkerRef.current = marker;
    });
  }, [mapInstance, coordinates, isClickToPlacePinActive, toast]);

  // ── Handle Coordinate or Landmark Search ──
  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!searchQuery.trim() || !mapInstance) return;

    const coordMatch = searchQuery.match(/^(-?\d+(\.\d+)?)[,\s]+(-?\d+(\.\d+)?)$/);
    if (coordMatch) {
      const lat = parseFloat(coordMatch[1]);
      const lng = parseFloat(coordMatch[3]);
      if (lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
        mapInstance.flyTo([lat, lng], 18, { duration: 1.2 });
        handleMapClick([lat, lng]);
        const inGeo = isPointInGeofence([lat, lng], coordinates);
        toast({
          title: inGeo ? "Centered (Within Geofence)" : "Centered (Outside Geofence)",
          description: `Navigated to ${lat.toFixed(5)}°, ${lng.toFixed(5)}°`,
        });
        return;
      }
    }

    const found = landmarks.find(
      (l) =>
        l.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        l.category.toLowerCase().includes(searchQuery.toLowerCase())
    );

    if (found) {
      mapInstance.flyTo(found.coordinates, 18, { duration: 1.2 });
      handleMapClick(found.coordinates);
      toast({
        title: found.name,
        description: `Elevation: ${found.elevation}m • Inside Geofence`,
      });
    } else {
      toast({
        variant: "destructive",
        title: "Location Not Found",
        description: "Enter coordinates as '18.476, -77.925' or search for 'Gate', 'Clubhouse', 'Park'.",
      });
    }
  };

  const handleRecenter = () => {
    if (!mapInstance) return;
    mapInstance.flyTo(center, 16, { duration: 1 });
    toast({
      title: "Community Centered",
      description: `Targeted centroid: ${center[0].toFixed(5)}°, ${center[1].toFixed(5)}°`,
    });
  };

  const handleZoom = (delta: number) => {
    if (!mapInstance) return;
    mapInstance.setZoom(mapInstance.getZoom() + delta);
  };

  const copyCoordinates = (coords: [number, number]) => {
    const text = `${coords[0].toFixed(6)}, ${coords[1].toFixed(6)}`;
    navigator.clipboard.writeText(text);
    toast({
      title: "Coordinates Copied",
      description: `${text} copied to clipboard in decimal degrees.`,
    });
  };

  const toggleFullscreen = () => {
    if (!mapWrapperRef.current) return;
    if (!document.fullscreenElement) {
      mapWrapperRef.current.requestFullscreen().catch(() => {});
      setIsFullscreen(true);
    } else {
      document.exitFullscreen().catch(() => {});
      setIsFullscreen(false);
    }
  };

  const handleSimulateGeofence = () => {
    if (!mapInstance) return;
    mapInstance.flyTo(center, 17, { duration: 1 });
    handleMapClick(center);
    toast({
      title: "Geofence Verification",
      description: "Centroid test confirmed: 100% within authorized community perimeter fence.",
    });
  };

  const currentInspectGeofence = useMemo(() => {
    if (!inspectedCoords) return null;
    return getGeofencePerimeterInfo(inspectedCoords, coordinates);
  }, [inspectedCoords, coordinates]);

  return (
    <div className="flex flex-col gap-6 max-w-7xl mx-auto w-full pb-10 animate-fade-in">
      {/* System Admin Geofence Editor Modal */}
      <SetGeofenceDialog 
        open={isGeofenceDialogOpen} 
        onOpenChange={setIsGeofenceDialogOpen} 
      />

      {/* ── Page Header ── */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1 flex-wrap">
            <span className="inline-flex items-center justify-center p-1.5 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
              <ShieldCheck className="h-5 w-5" />
            </span>
            <h1 className="font-headline text-3xl font-bold tracking-tight">Community Geofenced Map</h1>
            <Badge variant="outline" className="font-mono text-xs border-emerald-500/30 text-emerald-600 bg-emerald-500/5 flex items-center gap-1">
              <span className="w-2 h-2 rounded-full bg-emerald-500 animate-ping" />
              GEOFENCE ACTIVE
            </Badge>

            {/* System Admin Boundary Manager Button */}
            {isSystemAdmin && (
              <Button
                size="sm"
                variant={activeView === 'boundary' ? 'default' : 'outline'}
                onClick={() => setActiveView(activeView === 'boundary' ? 'operational' : 'boundary')}
                className="text-xs h-7 gap-1.5 border-emerald-500/40 text-emerald-600 bg-emerald-500/10 hover:bg-emerald-500/20 font-semibold"
              >
                <Settings2 className="h-3.5 w-3.5" />
                {activeView === 'boundary' ? 'Back to Operational Map' : 'Boundary Point Manager'}
              </Button>
            )}
          </div>
          <p className="text-muted-foreground text-sm">
            Google Terrain visualization with active perimeter geofencing, cadastral boundary containment, and radar monitoring.
          </p>
        </div>

        {/* View Switcher Tabs & Geofence Telemetry Badge */}
        <div className="flex items-center gap-3 flex-wrap">
          <div className="flex items-center gap-1 bg-muted/60 p-1 rounded-xl border">
            <button
              onClick={() => setActiveView('operational')}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                activeView === 'operational'
                  ? 'bg-card text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              <MapIcon className="h-3.5 w-3.5" />
              <span>Operational Map</span>
            </button>
            <button
              onClick={() => setActiveView('boundary')}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                activeView === 'boundary'
                  ? 'bg-card text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              <ShieldCheck className="h-3.5 w-3.5 text-emerald-600" />
              <span>Boundary Point Manager</span>
              {isSystemAdmin ? (
                <Badge variant="outline" className="text-[9px] h-4 font-mono border-emerald-500/40 text-emerald-600 bg-emerald-500/10">
                  SYSADMIN
                </Badge>
              ) : (
                <Badge variant="outline" className="text-[9px] h-4 font-mono border-muted-foreground/30 text-muted-foreground">
                  RESTRICTED
                </Badge>
              )}
            </button>
          </div>

          <div className="flex items-center gap-3 bg-card border rounded-xl px-3.5 py-2 text-xs shadow-sm">
            <Radio className="h-4 w-4 text-emerald-500 shrink-0 animate-pulse" />
            <div className="flex flex-col font-mono leading-tight">
              <span className="font-semibold text-foreground flex items-center gap-1.5">
                <span>Perimeter: {perimeterMeters}m</span>
                <span className="text-muted-foreground">•</span>
                <span className="text-emerald-600 font-bold">{coordinates.length} Beacons</span>
              </span>
              <span className="text-muted-foreground text-[11px]">
                {toDMS(activeCenter[0], true)} {toDMS(activeCenter[1], false)}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* ── View Switcher Conditional Rendering ── */}
      {activeView === 'boundary' ? (
        <BoundaryPointManager
          initialConfig={boundaryConfig}
          community={community}
          canManage={can.manageBoundary}
        />
      ) : (
        <>
          {/* ── Main Map Canvas Container ── */}
          <Card className="overflow-hidden border shadow-lg relative" ref={mapWrapperRef}>
            <MapControlsOverlay
              searchQuery={searchQuery}
              onSearchChange={setSearchQuery}
              onSearchSubmit={handleSearchSubmit}
              onClearSearch={() => setSearchQuery('')}
              mapType={mapType}
              onSelectMapType={setMapType}
              showGeofenceMask={showGeofenceMask}
              onToggleGeofenceMask={() => {
                setShowGeofenceMask(!showGeofenceMask);
                toast({
                  title: !showGeofenceMask ? "Geofence Mask Enabled" : "Geofence Mask Disabled",
                  description: !showGeofenceMask
                    ? "External territory dimmed; community perimeter highlighted."
                    : "Full surrounding terrain displayed.",
                });
              }}
              isClickToPlacePinActive={isClickToPlacePinActive}
              onToggleClickToPlacePin={() => {
                const nextState = !isClickToPlacePinActive;
                setIsClickToPlacePinActive(nextState);
                if (nextState) {
                  toast({
                    title: "Click-to-Place Active",
                    description: "Click anywhere on the map to place a Green Security Gate, Red Community Center, or Blue Park pin.",
                  });
                }
              }}
              onOpenAddPin={() => {
                setAddPinCoords(null);
                setIsAddPinOpen(true);
              }}
              onZoom={handleZoom}
              onRecenter={handleRecenter}
              isLocked={isLocked}
              onToggleLock={() => {
                setIsLocked(!isLocked);
                toast({
                  title: isLocked ? "Geofence Boundary Unlocked" : "Strict Geofence Lock Activated",
                  description: isLocked
                    ? "Camera can now explore beyond the community perimeter."
                    : "Camera locked strictly inside the geofence perimeter.",
                });
              }}
              isFullscreen={isFullscreen}
              onToggleFullscreen={toggleFullscreen}
            />

            {inspectedCoords && currentInspectGeofence && (
              <MapInspectorCard
                inspectedCoords={inspectedCoords}
                geofenceInfo={currentInspectGeofence}
                onClose={() => setInspectedCoords(null)}
                onCopyCoordinates={copyCoordinates}
                onDropPinHere={(coords) => {
                  setAddPinCoords(coords);
                  setIsAddPinOpen(true);
                }}
              />
            )}

            {/* Map Canvas */}
            <CardContent className="p-0 overflow-hidden">
              {isClient && (
                <LeafletMapFixed
                  center={center}
                  zoom={16}
                  mapType={mapType}
                  onReady={setMapInstance}
                  onMapClick={handleMapClick}
                  onMove={(c, z) => {
                    setActiveCenter(c);
                    setActiveZoom(z);
                  }}
                  style={{ height: isFullscreen ? "100vh" : 640 }}
                />
              )}
            </CardContent>
          </Card>

          {/* ── Landmark & Geofence Analytics Panels ── */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <MapLandmarkFilters
              filteredLandmarks={filteredLandmarks}
              categoryCounts={categoryCounts}
              pinFilterCategory={pinFilterCategory}
              onSelectCategory={setPinFilterCategory}
              onReserveFacility={(amenityName) => {
                setBookingAmenityName(amenityName);
                setIsBookingOpen(true);
              }}
              onOpenAddPin={() => {
                setAddPinCoords(null);
                setIsAddPinOpen(true);
              }}
              onJumpToLandmark={(coords) => {
                if (mapInstance) {
                  mapInstance.flyTo(coords, 18, { duration: 1.2 });
                  handleMapClick(coords);
                }
              }}
              onRemoveLandmark={handleRemoveLandmark}
            />

            <MapGeofenceTelemetry
              isSystemAdmin={isSystemAdmin}
              coordinatesCount={coordinates.length}
              areaAcres={areaAcres}
              areaHectares={areaHectares}
              perimeterMeters={perimeterMeters}
              center={center}
              onOpenGeofenceDialog={() => setIsGeofenceDialogOpen(true)}
              onSimulateGeofence={handleSimulateGeofence}
              onCopyCentroid={copyCoordinates}
            />
          </div>

      </>
      )}

      {/* Dynamic Landmark Pin Dialog */}
      <AddLandmarkDialog
        open={isAddPinOpen}
        onOpenChange={setIsAddPinOpen}
        onAddLandmark={handleAddLandmark}
        initialCoordinates={addPinCoords}
      />

      {/* System Admin Geofence Coordination Modal */}
      {isSystemAdmin && (
        <SetGeofenceDialog
          open={isGeofenceDialogOpen}
          onOpenChange={setIsGeofenceDialogOpen}
        />
      )}

      {/* Amenity Booking Dialog */}
      <AmenityBookingDialog
        open={isBookingOpen}
        onOpenChange={setIsBookingOpen}
        initialAmenityName={bookingAmenityName}
        availableAmenities={landmarks
          .filter((l) => l.category !== 'Security Gate')
          .map((l) => ({ id: l.id, name: l.name, category: l.category }))}
      />
    </div>
  );
}

export default function MapPage(props: MapPageProps) {
  return (
    <DashboardLayout>
      <Head title="Community Map" />
      <Suspense
        fallback={
          <div className="p-8 text-center text-xs text-muted-foreground">
            Loading Community Map...
          </div>
        }
      >
        <MapPageContent {...props} />
      </Suspense>
    </DashboardLayout>
  );
}
