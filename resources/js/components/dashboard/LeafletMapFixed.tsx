

import { useEffect, useRef } from "react";
import L, { Map as LeafletMap, TileLayer } from "leaflet";
import "leaflet/dist/leaflet.css";

export type MapType = 'terrain' | 'roadmap' | 'satellite' | 'topo';

type Props = {
  center?: [number, number];
  zoom?: number;
  mapType?: MapType;
  className?: string;
  style?: React.CSSProperties;
  showDefaultControls?: boolean;
  onReady?: (map: LeafletMap) => void;
  onMapClick?: (coords: [number, number]) => void;
  onMove?: (center: [number, number], zoom: number) => void;
};

const TILE_CONFIG: Record<MapType, { url: string; options: L.TileLayerOptions }> = {
  terrain: {
    url: "https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}",
    options: {
      maxZoom: 20,
      subdomains: ["mt0", "mt1", "mt2", "mt3"],
      attribution: "Map data © Google Terrain",
    },
  },
  roadmap: {
    url: "https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}",
    options: {
      maxZoom: 20,
      subdomains: ["mt0", "mt1", "mt2", "mt3"],
      attribution: "Map data © Google Maps",
    },
  },
  satellite: {
    url: "https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}",
    options: {
      maxZoom: 20,
      subdomains: ["mt0", "mt1", "mt2", "mt3"],
      attribution: "Imagery © Google Satellite",
    },
  },
  topo: {
    url: "https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png",
    options: {
      maxZoom: 17,
      attribution: "Map data: © OpenStreetMap contributors, SRTM | Map style: © OpenTopoMap",
    },
  },
};

export default function LeafletMapFixed({
  center = [18.4766, -77.9257],
  zoom = 16,
  mapType = 'terrain',
  className,
  style,
  showDefaultControls = false,
  onReady,
  onMapClick,
  onMove,
}: Props) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<LeafletMap | null>(null);
  const tileLayerRef = useRef<TileLayer | null>(null);

  // Initialize the map once; make it StrictMode/HMR safe with guard + cleanup.
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;

    // If a previous map exists, remove it cleanly.
    if (mapRef.current) {
      mapRef.current.remove();
      mapRef.current = null;
      tileLayerRef.current = null;
    }

    const anyEl = el as any;
    if (anyEl._leaflet_id) {
      try {
        anyEl._leaflet_id = undefined;
      } catch {}
    }

    // Create map with customizable controls
    const map = L.map(el, {
      zoomControl: showDefaultControls,
      attributionControl: true,
    });
    mapRef.current = map;

    map.setView(center, zoom);

    // Initial tile layer (default Google Terrain)
    const cfg = TILE_CONFIG[mapType] || TILE_CONFIG.terrain;
    const initialTileLayer = L.tileLayer(cfg.url, cfg.options).addTo(map);
    tileLayerRef.current = initialTileLayer;

    // Click handler for coordinate inspection
    map.on("click", (e) => {
      onMapClick?.([e.latlng.lat, e.latlng.lng]);
    });

    // Move/zoom change handler
    map.on("moveend", () => {
      const c = map.getCenter();
      onMove?.([c.lat, c.lng], map.getZoom());
    });

    onReady?.(map);

    return () => {
      if (mapRef.current) {
        mapRef.current.remove();
        mapRef.current = null;
        tileLayerRef.current = null;
      }
      const c = el as any;
      if (c && c._leaflet_id) c._leaflet_id = undefined;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Dynamically swap tile layers when mapType changes without remounting Leaflet
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    if (tileLayerRef.current) {
      tileLayerRef.current.remove();
    }

    const cfg = TILE_CONFIG[mapType] || TILE_CONFIG.terrain;
    const newTileLayer = L.tileLayer(cfg.url, cfg.options).addTo(map);
    tileLayerRef.current = newTileLayer;
    newTileLayer.bringToBack();
  }, [mapType]);

  // Adjust center/zoom when props change
  useEffect(() => {
    if (mapRef.current) {
      const currentCenter = mapRef.current.getCenter();
      const currentZoom = mapRef.current.getZoom();
      const dist = Math.hypot(currentCenter.lat - center[0], currentCenter.lng - center[1]);
      if (dist > 0.0001 || currentZoom !== zoom) {
        mapRef.current.setView(center, zoom, { animate: true });
      }
    }
  }, [center, zoom]);

  return (
    <div
      ref={containerRef}
      className={className}
      style={{ height: "100%", minHeight: 520, width: "100%", ...style }}
    />
  );
}

