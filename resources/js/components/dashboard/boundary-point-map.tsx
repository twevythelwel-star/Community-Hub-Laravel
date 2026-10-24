
import { useEffect, useRef, useState } from 'react';
import L, { Map as LeafletMap } from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { BoundaryPoint, BoundaryValidationResult } from '@/lib/boundary-manager/types';
import { toDMS } from '@/lib/geofence-utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Maximize2, Crosshair, ShieldCheck, Lock, Unlock } from 'lucide-react';

interface BoundaryPointMapProps {
  points: BoundaryPoint[];
  onPointDrag: (index: number, lat: number, lng: number) => void;
  selectedPointIndex: number | null;
  onSelectPoint: (index: number) => void;
  onMapClick?: (lat: number, lng: number) => void;
  isEditable: boolean;
  // SUPERSEDED is a real BoundaryVersion status: an older published polygon
  // that a newer publish replaced. It draws like PUBLISHED, solid and not blue.
  status: 'DRAFT' | 'PUBLISHED' | 'SUPERSEDED';
  validation: BoundaryValidationResult;
  focusTarget?: { lat: number; lng: number; zoom?: number; timestamp: number } | null;
  className?: string;
}

export default function BoundaryPointMap({
  points,
  onPointDrag,
  selectedPointIndex,
  onSelectPoint,
  onMapClick,
  isEditable,
  status,
  validation,
  focusTarget,
  className = 'h-[540px] w-full',
}: BoundaryPointMapProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<LeafletMap | null>(null);
  const markersRef = useRef<L.Marker[]>([]);
  const polygonRef = useRef<L.Polygon | null>(null);
  const polylineRef = useRef<L.Polyline | null>(null);
  const centroidMarkerRef = useRef<L.Marker | null>(null);

  // Initialize Map
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    if (mapRef.current) {
      mapRef.current.remove();
      mapRef.current = null;
    }

    const anyEl = el as any;
    if (anyEl._leaflet_id) {
      try {
        anyEl._leaflet_id = undefined;
      } catch {}
    }

    const initialCenter: [number, number] = points.length > 0
      ? [points[0].lat, points[0].lng]
      : [18.476861, -77.926028];

    const map = L.map(el, {
      center: initialCenter,
      zoom: 16,
      zoomControl: false,
      attributionControl: true,
    });
    mapRef.current = map;

    // Google Terrain Tile Layer
    L.tileLayer('https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', {
      maxZoom: 20,
      subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      attribution: 'Map data © Google Terrain',
    }).addTo(map);

    return () => {
      map.remove();
      mapRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Shift view / fly to focus target when focusTarget changes
  useEffect(() => {
    const map = mapRef.current;
    if (!map || !focusTarget) return;
    map.flyTo([focusTarget.lat, focusTarget.lng], focusTarget.zoom || 17, {
      animate: true,
      duration: 1.2,
    });
  }, [focusTarget]);

  // Handle map click
  useEffect(() => {
    const map = mapRef.current;
    if (!map || !onMapClick) return;
    const clickHandler = (e: L.LeafletMouseEvent) => {
      onMapClick(+e.latlng.lat.toFixed(6), +e.latlng.lng.toFixed(6));
    };
    map.on('click', clickHandler);
    return () => {
      map.off('click', clickHandler);
    };
  }, [onMapClick]);

  // Update Markers and Polygon when points change
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    // Clean existing markers
    markersRef.current.forEach(m => m.remove());
    markersRef.current = [];

    if (polygonRef.current) {
      polygonRef.current.remove();
      polygonRef.current = null;
    }
    if (polylineRef.current) {
      polylineRef.current.remove();
      polylineRef.current = null;
    }
    if (centroidMarkerRef.current) {
      centroidMarkerRef.current.remove();
      centroidMarkerRef.current = null;
    }

    const coords: [number, number][] = points.map(p => [p.lat, p.lng]);

    // 1. Draw connecting polyline / closed polygon
    if (coords.length >= 3) {
      const polygonColor = validation.isValid
        ? (status === 'DRAFT' ? '#0ea5e9' : '#10b981')
        : '#ef4444';

      const poly = L.polygon(coords, {
        color: polygonColor,
        fillColor: polygonColor,
        fillOpacity: validation.isValid ? 0.22 : 0.15,
        weight: 3,
        dashArray: status === 'DRAFT' ? '6, 6' : undefined,
      }).addTo(map);

      polygonRef.current = poly;

      // Centroid pill badge
      if (validation.isValid && validation.center) {
        const centroidIcon = L.divIcon({
          className: 'boundary-centroid-badge',
          html: `
            <div style="
              background: rgba(15, 23, 42, 0.85);
              backdrop-filter: blur(8px);
              color: white;
              border: 1px solid rgba(16, 185, 129, 0.5);
              padding: 4px 10px;
              border-radius: 9999px;
              font-size: 11px;
              font-weight: 700;
              font-family: monospace;
              white-space: nowrap;
              box-shadow: 0 4px 12px rgba(0,0,0,0.25);
              text-align: center;
              transform: translate(-50%, -50%);
            ">
              🛡️ ${validation.areaAcres} Acres • ${points.length} Vertices
            </div>
          `,
          iconSize: [0, 0],
        });

        centroidMarkerRef.current = L.marker(validation.center, {
          icon: centroidIcon,
          interactive: false,
        }).addTo(map);
      }
    } else if (coords.length === 2) {
      polylineRef.current = L.polyline(coords, {
        color: '#0ea5e9',
        weight: 3,
        dashArray: '4, 4',
      }).addTo(map);
    }

    // 2. Add sequential point markers
    points.forEach((pt, idx) => {
      const isSelected = selectedPointIndex === idx;
      const isPointOne = idx === 0;

      // Color coding: Point 1 (User coordinate benchmark) gets a distinct royal indigo/violet crown
      const bgGradient = isPointOne
        ? 'linear-gradient(135deg, #4338ca, #6366f1)'
        : isSelected
        ? 'linear-gradient(135deg, #0ea5e9, #2563eb)'
        : 'linear-gradient(135deg, #059669, #10b981)';

      const ringColor = isSelected ? '#38bdf8' : (isPointOne ? '#818cf8' : '#34d399');

      const markerHtml = `
        <div style="
          position: relative;
          width: 38px;
          height: 38px;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: ${isEditable ? 'grab' : 'pointer'};
          user-select: none;
        ">
          ${isPointOne ? `
            <div style="
              position: absolute;
              inset: -4px;
              border-radius: 50%;
              background: rgba(99, 102, 241, 0.35);
              animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
            "></div>
          ` : ''}
          <div style="
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: ${bgGradient};
            border: 2.5px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.35), 0 0 0 2px ${ringColor};
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 13px;
            font-family: system-ui, sans-serif;
          ">
            ${idx + 1}
          </div>
          <div style="
            position: absolute;
            bottom: -18px;
            background: rgba(15, 23, 42, 0.9);
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            white-space: nowrap;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
            border: 0.5px solid rgba(255,255,255,0.2);
          ">
            P${idx + 1}${pt.isOptional ? '*' : ''}
          </div>
        </div>
      `;

      const icon = L.divIcon({
        className: 'boundary-point-marker-icon',
        html: markerHtml,
        iconSize: [38, 38],
        iconAnchor: [19, 19],
      });

      const marker = L.marker([pt.lat, pt.lng], {
        icon,
        draggable: isEditable,
        autoPan: true,
      }).addTo(map);

      // Popup with exact coordinates
      marker.bindPopup(`
        <div style="font-family: system-ui, sans-serif; padding: 4px; min-width: 200px;">
          <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
            <strong style="font-size: 13px; color: #0f172a;">${pt.label} ${isPointOne ? '(Benchmark Ref)' : ''}</strong>
            <span style="font-size: 10px; background: #e2e8f0; color: #475569; padding: 2px 6px; border-radius: 4px; font-weight: 600;">
              ${pt.isOptional ? 'Optional' : 'Required'}
            </span>
          </div>
          <div style="font-size: 11px; color: #64748b; margin-bottom: 2px;">
            Decimal: <strong>${pt.lat.toFixed(6)}, ${pt.lng.toFixed(6)}</strong>
          </div>
          <div style="font-size: 11px; color: #64748b; margin-bottom: 6px;">
            DMS: <strong>${toDMS(pt.lat, true)} ${toDMS(pt.lng, false)}</strong>
          </div>
          ${isEditable ? `
            <div style="font-size: 10px; color: #059669; font-weight: 600; background: #ecfdf5; padding: 3px 6px; border-radius: 4px; text-align: center;">
              ⇄ Drag marker to adjust coordinate
            </div>
          ` : `
            <div style="font-size: 10px; color: #64748b; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; text-align: center;">
              🔒 Read-Only Boundary Point
            </div>
          `}
        </div>
      `, { offset: [0, -14] });

      marker.on('click', () => {
        onSelectPoint(idx);
      });

      if (isEditable) {
        marker.on('drag', (e) => {
          const latLng = (e.target as L.Marker).getLatLng();
          onPointDrag(idx, +latLng.lat.toFixed(6), +latLng.lng.toFixed(6));
        });
      }

      markersRef.current.push(marker);
    });

  }, [points, isEditable, selectedPointIndex, status, validation, onPointDrag, onSelectPoint]);

  // Fit bounds to polygon
  const handleFitBounds = () => {
    const map = mapRef.current;
    if (!map || points.length < 2) return;
    const bounds = L.latLngBounds(points.map(p => [p.lat, p.lng]));
    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 18, animate: true });
  };

  return (
    <div className="relative rounded-2xl overflow-hidden border shadow-lg bg-card">
      <div ref={containerRef} className={className} />

      {/* Floating Status / RBAC Badge Header Overlay */}
      <div className="absolute top-3 left-3 z-[400] flex items-center gap-2 pointer-events-none">
        <div className="pointer-events-auto bg-card/95 backdrop-blur-md border border-border shadow-md rounded-full px-3 py-1 flex items-center gap-2 text-xs">
          {isEditable ? (
            <>
              <Unlock className="h-3.5 w-3.5 text-emerald-600" />
              <span className="font-semibold text-foreground">SysAdmin Drag & Adjust Mode</span>
              <Badge variant="outline" className="text-[10px] h-4 font-mono border-emerald-500/30 text-emerald-600 bg-emerald-500/10">
                ACTIVE
              </Badge>
            </>
          ) : (
            <>
              <Lock className="h-3.5 w-3.5 text-amber-600" />
              <span className="font-semibold text-foreground">Read-Only Boundary View</span>
              <Badge variant="outline" className="text-[10px] h-4 font-mono border-amber-500/30 text-amber-600 bg-amber-500/10">
                RESTRICTED
              </Badge>
            </>
          )}
        </div>
      </div>

      {/* Floating Action Controls (Top-Right) */}
      <div className="absolute top-3 right-3 z-[400] flex items-center gap-1.5 pointer-events-none">
        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={() => {
            if (points.length > 0) {
              const p1 = points[0];
              const map = mapRef.current;
              if (map) {
                map.flyTo([p1.lat, p1.lng], 17, { animate: true, duration: 1.2 });
              }
            }
          }}
          className="pointer-events-auto h-8 px-2.5 rounded-full text-xs shadow-md bg-card/95 backdrop-blur-md border hover:bg-muted gap-1 font-semibold text-indigo-600 dark:text-indigo-400"
          title="Shift map view to Point 1"
        >
          <Crosshair className="h-3.5 w-3.5" />
          <span>Focus Point 1</span>
        </Button>

        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={handleFitBounds}
          className="pointer-events-auto h-8 px-2.5 rounded-full text-xs shadow-md bg-card/95 backdrop-blur-md border hover:bg-muted gap-1 font-medium"
        >
          <Maximize2 className="h-3.5 w-3.5" />
          <span>Fit Boundary</span>
        </Button>
      </div>

      {/* Floating Bottom Polygon Telemetry */}
      <div className="absolute bottom-3 left-3 right-3 z-[400] pointer-events-none flex items-center justify-between">
        <div className="pointer-events-auto bg-card/95 backdrop-blur-md border border-border shadow-md rounded-xl px-3 py-1.5 flex items-center gap-3 text-xs font-mono">
          <div className="flex items-center gap-1 text-emerald-600 font-bold">
            <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />
            <span>{points.length} Points Configured</span>
          </div>
          <span className="text-muted-foreground">•</span>
          <span className="text-foreground">Area: <strong>{validation.areaAcres} Acres</strong> ({validation.areaHectares} Ha)</span>
          <span className="text-muted-foreground">•</span>
          <span className="text-foreground">Perimeter: <strong>{validation.perimeterMeters}m</strong></span>
        </div>
      </div>
    </div>
  );
}
