/**
 * MapModule - Map rendering and tile provider abstraction
 * 
 * Provides a unified interface for different map providers:
 * - Leaflet (current implementation)
 * - Google Maps (configurable)
 * - Mapbox (configurable)
 * - OpenStreetMap (configurable)
 * 
 * Supports multiple tile layers and map types without hardcoding
 * specific providers into components.
 */

import L, { Map as LeafletMap, TileLayer } from 'leaflet';
import 'leaflet/dist/leaflet.css';

export type MapProvider = 'leaflet' | 'google' | 'mapbox' | 'openstreetmap';

export type MapType = 'terrain' | 'roadmap' | 'satellite' | 'hybrid' | 'topo' | 'dark' | 'light';

export type MapControls = 'zoom' | 'scale' | 'fullscreen' | 'layer' | 'none';

export interface TileProviderConfig {
  url: string;
  options: L.TileLayerOptions;
  attribution: string;
  maxZoom?: number;
  minZoom?: number;
}

export interface MapConfig {
  center: [number, number];
  zoom: number;
  minZoom?: number;
  maxZoom?: number;
  maxBounds?: [[number, number], [number, number]];
  controls?: MapControls[];
  className?: string;
  style?: React.CSSProperties;
}

export interface MapLayer {
  id: string;
  name: string;
  type: MapType;
  provider: MapProvider;
  visible: boolean;
  opacity?: number;
}

export interface MapMarker {
  id: string;
  position: [number, number];
  title?: string;
  icon?: string;
  popup?: string;
  draggable?: boolean;
}

export interface MapEventHandler {
  onReady?: (map: LeafletMap) => void;
  onMapClick?: (coords: [number, number]) => void;
  onMove?: (center: [number, number], zoom: number) => void;
  onZoom?: (zoom: number) => void;
  onLayerChange?: (layerId: string) => void;
}

class MapModule {
  private static tileProviders: Record<MapType, TileProviderConfig> = {
    terrain: {
      url: 'https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}',
      options: {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      },
      attribution: 'Map data © Google Terrain',
      maxZoom: 20,
    },
    roadmap: {
      url: 'https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
      options: {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      },
      attribution: 'Map data © Google Maps',
      maxZoom: 20,
    },
    satellite: {
      url: 'https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
      options: {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      },
      attribution: 'Imagery © Google Satellite',
      maxZoom: 20,
    },
    hybrid: {
      url: 'https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
      options: {
        maxZoom: 20,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
      },
      attribution: 'Imagery © Google Hybrid',
      maxZoom: 20,
    },
    topo: {
      url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
      options: {
        maxZoom: 17,
      },
      attribution: 'Map data: © OpenStreetMap contributors, SRTM | Map style: © OpenTopoMap',
      maxZoom: 17,
    },
    dark: {
      url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
      options: {
        maxZoom: 19,
      },
      attribution: '© OpenStreetMap contributors © CARTO',
      maxZoom: 19,
    },
    light: {
      url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
      options: {
        maxZoom: 19,
      },
      attribution: '© OpenStreetMap contributors © CARTO',
      maxZoom: 19,
    },
  };

  /**
   * Get tile provider configuration for a map type
   * @param mapType - Type of map
   * @returns Tile provider configuration
   */
  static getTileProvider(mapType: MapType): TileProviderConfig {
    return this.tileProviders[mapType] || this.tileProviders.roadmap;
  }

  /**
   * Register a custom tile provider
   * @param mapType - Type identifier
   * @param config - Tile provider configuration
   */
  static registerTileProvider(mapType: MapType, config: TileProviderConfig): void {
    this.tileProviders[mapType] = config;
  }

  /**
   * Get available map types
   * @returns Array of available map types
   */
  static getAvailableMapTypes(): MapType[] {
    return Object.keys(this.tileProviders) as MapType[];
  }

  /**
   * Create a Leaflet map instance
   * @param container - DOM element for the map
   * @param config - Map configuration
   * @param handlers - Event handlers
   * @returns Leaflet map instance
   */
  static createMap(
    container: HTMLElement,
    config: MapConfig,
    handlers?: MapEventHandler
  ): LeafletMap {
    const map = L.map(container, {
      center: config.center,
      zoom: config.zoom,
      minZoom: config.minZoom,
      maxZoom: config.maxZoom,
      maxBounds: config.maxBounds,
      zoomControl: config.controls?.includes('zoom'),
      attributionControl: true,
    });

    // Add event handlers
    if (handlers?.onMapClick) {
      map.on('click', (e) => {
        handlers.onMapClick!([e.latlng.lat, e.latlng.lng]);
      });
    }

    if (handlers?.onMove) {
      map.on('moveend', () => {
        const center = map.getCenter();
        handlers.onMove!([center.lat, center.lng], map.getZoom());
      });
    }

    if (handlers?.onZoom) {
      map.on('zoomend', () => {
        handlers.onZoom!(map.getZoom());
      });
    }

    // Call ready handler
    handlers?.onReady?.(map);

    return map;
  }

  /**
   * Add a tile layer to a map
   * @param map - Leaflet map instance
   * @param mapType - Type of map layer
   * @returns Tile layer instance
   */
  static addTileLayer(map: LeafletMap, mapType: MapType): TileLayer {
    const config = this.getTileProvider(mapType);
    const tileLayer = L.tileLayer(config.url, config.options).addTo(map);
    return tileLayer;
  }

  /**
   * Switch tile layer on a map
   * @param map - Leaflet map instance
   * @param currentLayer - Current tile layer
   * @param newMapType - New map type
   * @returns New tile layer instance
   */
  static switchTileLayer(
    map: LeafletMap,
    currentLayer: TileLayer | null,
    newMapType: MapType
  ): TileLayer {
    if (currentLayer) {
      map.removeLayer(currentLayer);
    }

    return this.addTileLayer(map, newMapType);
  }

  /**
   * Add a marker to a map
   * @param map - Leaflet map instance
   * @param marker - Marker configuration
   * @returns Leaflet marker instance
   */
  static addMarker(map: LeafletMap, marker: MapMarker): L.Marker {
    const leafletMarker = L.marker(marker.position, {
      title: marker.title,
      draggable: marker.draggable,
    });

    if (marker.popup) {
      leafletMarker.bindPopup(marker.popup);
    }

    leafletMarker.addTo(map);
    return leafletMarker;
  }

  /**
   * Add multiple markers to a map
   * @param map - Leaflet map instance
   * @param markers - Array of marker configurations
   * @returns Array of Leaflet marker instances
   */
  static addMarkers(map: LeafletMap, markers: MapMarker[]): L.Marker[] {
    return markers.map(marker => this.addMarker(map, marker));
  }

  /**
   * Add a GeoJSON layer to a map
   * @param map - Leaflet map instance
   * @param geoJson - GeoJSON feature
   * @param style - Optional path style applied to every feature
   * @returns GeoJSON layer instance
   */
  static addGeoJSONLayer(
    map: LeafletMap,
    geoJson: GeoJSON.GeoJsonObject,
    style?: L.PathOptions
  ): L.GeoJSON {
    // Leaflet takes a style under `style`, not as the options object itself.
    const layer = L.geoJSON(geoJson, style ? { style } : undefined).addTo(map);
    return layer;
  }

  /**
   * Add a polygon to a map
   * @param map - Leaflet map instance
   * @param coordinates - Polygon vertices [lat, lng][]
   * @param style - Optional style options
   * @returns Polygon layer instance
   */
  static addPolygon(
    map: LeafletMap,
    coordinates: [number, number][],
    style?: L.PolylineOptions
  ): L.Polygon {
    const polygon = L.polygon(coordinates, style).addTo(map);
    return polygon;
  }

  /**
   * Add a circle to a map
   * @param map - Leaflet map instance
   * @param center - Circle center [lat, lng]
   * @param radius - Radius in meters
   * @param style - Optional style options
   * @returns Circle layer instance
   */
  static addCircle(
    map: LeafletMap,
    center: [number, number],
    radius: number,
    style?: L.CircleMarkerOptions
  ): L.Circle {
    const circle = L.circle(center, { radius, ...style }).addTo(map);
    return circle;
  }

  /**
   * Fit map bounds to include all specified coordinates
   * @param map - Leaflet map instance
   * @param coordinates - Array of coordinates
   * @param padding - Optional padding in pixels: one number for both axes, or [x, y]
   */
  static fitBounds(
    map: LeafletMap,
    coordinates: [number, number][],
    padding?: number | [number, number]
  ): void {
    if (coordinates.length === 0) return;

    // Leaflet wants a point; `??` keeps an explicit 0 instead of replacing it.
    const pad: [number, number] =
      typeof padding === 'number' ? [padding, padding] : (padding ?? [50, 50]);

    const bounds = L.latLngBounds(coordinates);
    map.fitBounds(bounds, { padding: pad });
  }

  /**
   * Set map max bounds to limit panning
   * @param map - Leaflet map instance
   * @param bounds - Array of bounds [[south, west], [north, east]]
   * @param padding - Optional padding buffer
   */
  static setMaxBounds(
    map: LeafletMap,
    bounds: [[number, number], [number, number]],
    padding: number = 0.1
  ): void {
    const latPadding = (bounds[1][0] - bounds[0][0]) * padding;
    const lngPadding = (bounds[1][1] - bounds[0][1]) * padding;

    const paddedBounds: [[number, number], [number, number]] = [
      [bounds[0][0] - latPadding, bounds[0][1] - lngPadding],
      [bounds[1][0] + latPadding, bounds[1][1] + lngPadding],
    ];

    map.setMaxBounds(paddedBounds);
  }

  /**
   * Convert GeoJSON to Leaflet format
   * @param geoJson - GeoJSON object
   * @returns Leaflet-compatible GeoJSON
   */
  static geoJsonToLeaflet(geoJson: GeoJSON.GeoJsonObject): GeoJSON.GeoJsonObject {
    return geoJson;
  }

  /**
   * Convert coordinate array to GeoJSON Polygon
   * @param coordinates - Array of [lat, lng] coordinates
   * @returns GeoJSON Polygon
   */
  static coordinatesToGeoJSONPolygon(coordinates: [number, number][]): GeoJSON.Polygon {
    return {
      type: 'Polygon',
      coordinates: [[...coordinates.map(c => [c[1], c[0]]), [coordinates[0][1], coordinates[0][0]]]],
    };
  }

  /**
   * Clean up map instance
   * @param map - Leaflet map instance
   */
  static cleanupMap(map: LeafletMap): void {
    map.remove();
  }

  /**
   * Get map bounds as coordinate array
   * @param map - Leaflet map instance
   * @returns Array of coordinates representing bounds
   */
  static getMapBounds(map: LeafletMap): [number, number][] {
    const bounds = map.getBounds();
    return [
      [bounds.getSouth(), bounds.getWest()],
      [bounds.getNorth(), bounds.getEast()],
    ];
  }

  /**
   * Get current map center
   * @param map - Leaflet map instance
   * @returns Center coordinate [lat, lng]
   */
  static getMapCenter(map: LeafletMap): [number, number] {
    const center = map.getCenter();
    return [center.lat, center.lng];
  }

  /**
   * Get current map zoom level
   * @param map - Leaflet map instance
   * @returns Zoom level
   */
  static getMapZoom(map: LeafletMap): number {
    return map.getZoom();
  }

  /**
   * Set map center
   * @param map - Leaflet map instance
   * @param center - New center coordinate [lat, lng]
   * @param zoom - Optional zoom level
   */
  static setMapCenter(map: LeafletMap, center: [number, number], zoom?: number): void {
    if (zoom !== undefined) {
      map.setView(center, zoom);
    } else {
      map.panTo(center);
    }
  }

  /**
   * Set map zoom level
   * @param map - Leaflet map instance
   * @param zoom - New zoom level
   */
  static setMapZoom(map: LeafletMap, zoom: number): void {
    map.setZoom(zoom);
  }

  /**
   * Invalidate map size (useful when container changes)
   * @param map - Leaflet map instance
   */
  static invalidateSize(map: LeafletMap): void {
    map.invalidateSize();
  }

  /**
   * Export map view as image (requires html2canvas or similar)
   * @param map - Leaflet map instance
   * @returns Promise with image data URL
   */
  static async exportMapAsImage(map: LeafletMap): Promise<string> {
    // Placeholder - would use html2canvas or similar library
    console.warn('Map export not implemented - requires html2canvas integration');
    return '';
  }

  /**
   * Create custom icon for markers
   * @param iconUrl - URL to icon image
   * @param iconSize - Icon size [width, height]
   * @param iconAnchor - Icon anchor point
   * @param popupAnchor - Popup anchor point
   * @returns Leaflet icon instance
   */
  static createCustomIcon(
    iconUrl: string,
    iconSize: [number, number],
    iconAnchor?: [number, number],
    popupAnchor?: [number, number]
  ): L.Icon {
    return L.icon({
      iconUrl,
      iconSize,
      iconAnchor: iconAnchor || [iconSize[0] / 2, iconSize[1]],
      popupAnchor: popupAnchor || [0, -iconSize[1]],
    });
  }

  /**
   * Create div icon for custom HTML markers
   * @param className - CSS class name
   * @param html - HTML content
   * @param iconSize - Icon size [width, height]
   * @returns Leaflet div icon instance
   */
  static createDivIcon(
    className: string,
    html: string,
    iconSize: [number, number]
  ): L.DivIcon {
    return L.divIcon({
      className,
      html,
      iconSize,
    });
  }
}

export default MapModule;
