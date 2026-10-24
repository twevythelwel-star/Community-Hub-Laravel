/**
 * GIS Modules Index
 * 
 * Central export point for all GIS-related modules:
 * - LocationModule: Core GIS functionality
 * - MapModule: Map rendering and tile providers
 * - GeofenceModule: Geofence-specific functionality
 */

export { default as LocationModule } from './LocationModule';
export type {
  Coordinate,
  CoordinateFormat,
  DistanceUnit,
  AreaUnit,
  PolygonMetrics,
  BoundingBox,
  GeocodingResult,
  ReverseGeocodingResult,
} from './LocationModule';

export { default as MapModule } from './MapModule';
export type {
  MapProvider,
  MapType,
  MapControls,
  TileProviderConfig,
  MapConfig,
  MapLayer,
  MapMarker,
  MapEventHandler,
} from './MapModule';

export { default as GeofenceModule } from './GeofenceModule';
export type {
  GeofenceStatus,
  GeofenceSeverity,
  GeofenceGate,
  GeofenceConfig,
  GeofenceValidationResult,
  PerimeterInfo,
  GeofenceAlert,
  GeofenceEvent,
} from './GeofenceModule';
