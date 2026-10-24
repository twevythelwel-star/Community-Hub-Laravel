/**
 * LocationModule - Core GIS functionality for geospatial operations
 * 
 * Provides reusable location services independent of specific map providers:
 * - Distance calculations (Haversine formula)
 * - Coordinate conversions (DMS, elevation estimation)
 * - Point-in-polygon tests
 * - Polygon metrics (area, perimeter, centroid)
 * - Geocoding utilities
 */

export type Coordinate = [number, number]; // [latitude, longitude]

export type CoordinateFormat = 'decimal' | 'dms' | 'dm';

export type DistanceUnit = 'meters' | 'kilometers' | 'miles' | 'nautical-miles';

export type AreaUnit = 'sq-meters' | 'sq-kilometers' | 'acres' | 'hectares' | 'sq-miles';

export interface PolygonMetrics {
  center: Coordinate;
  area: {
    sqMeters: number;
    sqKilometers: number;
    acres: string;
    hectares: string;
    sqMiles: string;
  };
  perimeter: {
    meters: number;
    kilometers: number;
    miles: string;
  };
}

export interface BoundingBox {
  north: number;
  south: number;
  east: number;
  west: number;
}

export interface GeocodingResult {
  address: string;
  coordinates: Coordinate;
  confidence: number;
}

export interface ReverseGeocodingResult {
  coordinates: Coordinate;
  address: string;
  components: {
    street?: string;
    city?: string;
    state?: string;
    country?: string;
    postalCode?: string;
  };
}

class LocationModule {
  private static readonly EARTH_RADIUS_M = 6371000;
  private static readonly M_PER_DEG_LNG = 111320;
  private static readonly M_PER_DEG_LAT = 110574;

  /**
   * Convert decimal degrees to DMS (Degrees/Minutes/Seconds)
   * @param deg - Decimal degree value
   * @param isLat - Whether this is a latitude (true) or longitude (false)
   * @returns DMS formatted string (e.g., "18°28'30.0"N")
   */
  static toDMS(deg: number, isLat: boolean): string {
    const absolute = Math.abs(deg);
    const degrees = Math.floor(absolute);
    const minutesNotTruncated = (absolute - degrees) * 60;
    const minutes = Math.floor(minutesNotTruncated);
    const seconds = ((minutesNotTruncated - minutes) * 60).toFixed(1);
    const direction = isLat ? (deg >= 0 ? 'N' : 'S') : deg >= 0 ? 'E' : 'W';

    return `${degrees}°${minutes}'${seconds}"${direction}`;
  }

  /**
   * Convert DMS to decimal degrees
   * @param dms - DMS string (e.g., "18°28'30.0"N")
   * @returns Decimal degree value
   */
  static fromDMS(dms: string): number {
    const matches = dms.match(/(\d+)°(\d+)'([\d.]+)"([NSEW])/i);
    if (!matches) {
      throw new Error(`Invalid DMS format: ${dms}`);
    }

    const [, degrees, minutes, seconds, direction] = matches;
    let decimal = parseFloat(degrees) + parseFloat(minutes) / 60 + parseFloat(seconds) / 3600;

    if (direction === 'S' || direction === 'W') {
      decimal = -decimal;
    }

    return decimal;
  }

  /**
   * Estimate elevation based on location (simplified interpolation)
   * In production, this would use a proper elevation API (e.g., Google Elevation API)
   * @param lat - Latitude
   * @param lng - Longitude
   * @returns Estimated elevation in meters
   */
  static estimateElevation(lat: number, lng: number): number {
    // Default implementation - should be replaced with actual elevation API
    const baseLat = 18.4750;
    const baseLng = -77.9270;
    const dLat = (lat - baseLat) * 111000;
    const dLng = (lng - baseLng) * 105000;
    const elevation = Math.round(38 + dLat * 0.08 + dLng * 0.04);

    return Math.max(15, Math.min(180, elevation));
  }

  /**
   * Calculate great-circle distance between two coordinates using Haversine formula
   * @param from - Starting coordinate [lat, lng]
   * @param to - Ending coordinate [lat, lng]
   * @param unit - Distance unit (default: meters)
   * @returns Distance in specified unit
   */
  static haversineDistance(
    from: Coordinate,
    to: Coordinate,
    unit: DistanceUnit = 'meters'
  ): number {
    const dLat = ((to[0] - from[0]) * Math.PI) / 180;
    const dLng = ((to[1] - from[1]) * Math.PI) / 180;

    const a =
      Math.sin(dLat / 2) ** 2 +
      Math.cos((from[0] * Math.PI) / 180) *
        Math.cos((to[0] * Math.PI) / 180) *
        Math.sin(dLng / 2) ** 2;

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    const distanceMeters = this.EARTH_RADIUS_M * c;

    return this.convertDistance(distanceMeters, 'meters', unit);
  }

  /**
   * Convert distance between units
   * @param value - Distance value
   * @param fromUnit - Source unit
   * @param toUnit - Target unit
   * @returns Converted distance
   */
  static convertDistance(value: number, fromUnit: DistanceUnit, toUnit: DistanceUnit): number {
    // Convert to meters first
    let meters = value;
    switch (fromUnit) {
      case 'kilometers':
        meters = value * 1000;
        break;
      case 'miles':
        meters = value * 1609.344;
        break;
      case 'nautical-miles':
        meters = value * 1852;
        break;
    }

    // Convert from meters to target unit
    switch (toUnit) {
      case 'meters':
        return meters;
      case 'kilometers':
        return meters / 1000;
      case 'miles':
        return meters / 1609.344;
      case 'nautical-miles':
        return meters / 1852;
    }
  }

  /**
   * Convert area between units
   * @param value - Area value
   * @param fromUnit - Source unit
   * @param toUnit - Target unit
   * @returns Converted area
   */
  static convertArea(value: number, fromUnit: AreaUnit, toUnit: AreaUnit): number {
    // Convert to square meters first
    let sqMeters = value;
    switch (fromUnit) {
      case 'sq-kilometers':
        sqMeters = value * 1000000;
        break;
      case 'acres':
        sqMeters = value * 4046.86;
        break;
      case 'hectares':
        sqMeters = value * 10000;
        break;
      case 'sq-miles':
        sqMeters = value * 2589988.11;
        break;
    }

    // Convert from square meters to target unit
    switch (toUnit) {
      case 'sq-meters':
        return sqMeters;
      case 'sq-kilometers':
        return sqMeters / 1000000;
      case 'acres':
        return sqMeters / 4046.86;
      case 'hectares':
        return sqMeters / 10000;
      case 'sq-miles':
        return sqMeters / 2589988.11;
    }
  }

  /**
   * Ray-casting point-in-polygon test
   * @param point - Point to test [lat, lng]
   * @param polygon - Polygon vertices [lat, lng][]
   * @returns True if point is inside polygon
   */
  static isPointInPolygon(point: Coordinate, polygon: Coordinate[]): boolean {
    if (polygon.length < 3) return false;

    const [lat, lng] = point;
    let inside = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
      const [latI, lngI] = polygon[i];
      const [latJ, lngJ] = polygon[j];

      if (lngI > lng !== lngJ > lng) {
        const denominator = lngJ - lngI;

        // Guard against division by zero (horizontal edge)
        if (denominator === 0) continue;

        if (lat < ((latJ - latI) * (lng - lngI)) / denominator + latI) {
          inside = !inside;
        }
      }
    }

    return inside;
  }

  /**
   * Calculate polygon metrics (centroid, area, perimeter)
   * @param polygon - Polygon vertices [lat, lng][]
   * @returns Polygon metrics object
   */
  static calculatePolygonMetrics(polygon: Coordinate[]): PolygonMetrics {
    if (polygon.length === 0) {
      return this.getEmptyMetrics();
    }

    const count = polygon.length;
    const center: Coordinate = [
      polygon.reduce((sum, c) => sum + c[0], 0) / count,
      polygon.reduce((sum, c) => sum + c[1], 0) / count,
    ];

    if (count < 3) {
      return this.getEmptyMetrics(center);
    }

    let area = 0;
    let perimeter = 0;
    const latScale = Math.cos((center[0] * Math.PI) / 180);

    for (let i = 0; i < count; i++) {
      const p1 = polygon[i];
      const p2 = polygon[(i + 1) % count];

      perimeter += this.haversineDistance(p1, p2);

      const x1 = (p1[1] - center[1]) * this.M_PER_DEG_LNG * latScale;
      const y1 = (p1[0] - center[0]) * this.M_PER_DEG_LAT;
      const x2 = (p2[1] - center[1]) * this.M_PER_DEG_LNG * latScale;
      const y2 = (p2[0] - center[0]) * this.M_PER_DEG_LAT;

      area += x1 * y2 - x2 * y1;
    }

    const sqMeters = Math.abs(area) / 2;

    return {
      center,
      area: {
        sqMeters,
        sqKilometers: sqMeters / 1000000,
        acres: (sqMeters * 0.000247105).toFixed(2),
        hectares: (sqMeters * 0.0001).toFixed(2),
        sqMiles: (sqMeters / 2589988.11).toFixed(2),
      },
      perimeter: {
        meters: Math.round(perimeter),
        kilometers: perimeter / 1000,
        miles: (perimeter / 1609.344).toFixed(2),
      },
    };
  }

  /**
   * Get empty metrics object
   * @param center - Optional center coordinate
   * @returns Empty metrics object
   */
  private static getEmptyMetrics(center: Coordinate = [0, 0]): PolygonMetrics {
    return {
      center,
      area: {
        sqMeters: 0,
        sqKilometers: 0,
        acres: '0.00',
        hectares: '0.00',
        sqMiles: '0.00',
      },
      perimeter: {
        meters: 0,
        kilometers: 0,
        miles: '0.00',
      },
    };
  }

  /**
   * Calculate bounding box for a set of coordinates
   * @param coordinates - Array of coordinates
   * @returns Bounding box
   */
  static calculateBoundingBox(coordinates: Coordinate[]): BoundingBox {
    if (coordinates.length === 0) {
      return { north: 0, south: 0, east: 0, west: 0 };
    }

    const lats = coordinates.map(c => c[0]);
    const lngs = coordinates.map(c => c[1]);

    return {
      north: Math.max(...lats),
      south: Math.min(...lats),
      east: Math.max(...lngs),
      west: Math.min(...lngs),
    };
  }

  /**
   * Check if a coordinate is within a bounding box
   * @param point - Point to test
   * @param bbox - Bounding box
   * @returns True if point is within bounding box
   */
  static isPointInBoundingBox(point: Coordinate, bbox: BoundingBox): boolean {
    return (
      point[0] >= bbox.south &&
      point[0] <= bbox.north &&
      point[1] >= bbox.west &&
      point[1] <= bbox.east
    );
  }

  /**
   * Calculate midpoint between two coordinates
   * @param from - Starting coordinate
   * @param to - Ending coordinate
   * @returns Midpoint coordinate
   */
  static midpoint(from: Coordinate, to: Coordinate): Coordinate {
    return [
      (from[0] + to[0]) / 2,
      (from[1] + to[1]) / 2,
    ];
  }

  /**
   * Calculate bearing between two coordinates
   * @param from - Starting coordinate
   * @param to - Ending coordinate
   * @returns Bearing in degrees (0-360)
   */
  static calculateBearing(from: Coordinate, to: Coordinate): number {
    const dLng = ((to[1] - from[1]) * Math.PI) / 180;
    const fromLat = (from[0] * Math.PI) / 180;
    const toLat = (to[0] * Math.PI) / 180;

    const x = Math.sin(dLng) * Math.cos(toLat);
    const y = Math.cos(fromLat) * Math.sin(toLat) - Math.sin(fromLat) * Math.cos(toLat) * Math.cos(dLng);

    const bearing = (Math.atan2(x, y) * 180) / Math.PI;
    return (bearing + 360) % 360;
  }

  /**
   * Simplify polygon using Douglas-Peucker algorithm
   * @param polygon - Original polygon vertices
   * @param tolerance - Tolerance in meters
   * @returns Simplified polygon
   */
  static simplifyPolygon(polygon: Coordinate[], tolerance: number = 10): Coordinate[] {
    if (polygon.length <= 2) return polygon;

    let maxDistance = 0;
    let maxIndex = 0;
    const first = polygon[0];
    const last = polygon[polygon.length - 1];

    for (let i = 1; i < polygon.length - 1; i++) {
      const distance = this.perpendicularDistance(polygon[i], first, last);
      if (distance > maxDistance) {
        maxDistance = distance;
        maxIndex = i;
      }
    }

    if (maxDistance > tolerance) {
      const left = this.simplifyPolygon(polygon.slice(0, maxIndex + 1), tolerance);
      const right = this.simplifyPolygon(polygon.slice(maxIndex), tolerance);
      return [...left.slice(0, -1), ...right];
    }

    return [first, last];
  }

  /**
   * Calculate perpendicular distance from point to line segment
   * @param point - Point
   * @param lineStart - Line segment start
   * @param lineEnd - Line segment end
   * @returns Perpendicular distance in meters
   */
  private static perpendicularDistance(
    point: Coordinate,
    lineStart: Coordinate,
    lineEnd: Coordinate
  ): number {
    const dx = lineEnd[1] - lineStart[1];
    const dy = lineEnd[0] - lineStart[0];
    const mag = Math.sqrt(dx * dx + dy * dy);

    if (mag === 0) {
      return this.haversineDistance(point, lineStart);
    }

    const u = ((point[1] - lineStart[1]) * dx + (point[0] - lineStart[0]) * dy) / (mag * mag);

    if (u < 0 || u > 1) {
      return Math.min(
        this.haversineDistance(point, lineStart),
        this.haversineDistance(point, lineEnd)
      );
    }

    const intersectionX = lineStart[1] + u * dx;
    const intersectionY = lineStart[0] + u * dy;
    const intersection: Coordinate = [intersectionY, intersectionX];

    return this.haversineDistance(point, intersection);
  }

  /**
   * Validate coordinate ranges
   * @param coordinate - Coordinate to validate
   * @returns True if coordinate is valid
   */
  static isValidCoordinate(coordinate: Coordinate): boolean {
    const [lat, lng] = coordinate;
    return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
  }

  /**
   * Format coordinate for display
   * @param coordinate - Coordinate to format
   * @param format - Format type
   * @returns Formatted coordinate string
   */
  static formatCoordinate(coordinate: Coordinate, format: CoordinateFormat = 'decimal'): string {
    const [lat, lng] = coordinate;

    switch (format) {
      case 'dms':
        return `${this.toDMS(lat, true)}, ${this.toDMS(lng, false)}`;
      case 'dm':
        const latAbs = Math.abs(lat);
        const latDeg = Math.floor(latAbs);
        const latMin = ((latAbs - latDeg) * 60).toFixed(1);
        const latDir = lat >= 0 ? 'N' : 'S';

        const lngAbs = Math.abs(lng);
        const lngDeg = Math.floor(lngAbs);
        const lngMin = ((lngAbs - lngDeg) * 60).toFixed(1);
        const lngDir = lng >= 0 ? 'E' : 'W';

        return `${latDeg}°${latMin}'${latDir}, ${lngDeg}°${lngMin}'${lngDir}`;
      default:
        return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    }
  }

  /**
   * Generate random coordinate within a bounding box
   * @param bbox - Bounding box
   * @returns Random coordinate
   */
  static randomCoordinateInBBox(bbox: BoundingBox): Coordinate {
    const lat = bbox.south + Math.random() * (bbox.north - bbox.south);
    const lng = bbox.west + Math.random() * (bbox.east - bbox.west);
    return [lat, lng];
  }

  /**
   * Geocoding - convert address to coordinates (placeholder)
   * In production, integrate with Google Maps Geocoding API, Mapbox, etc.
   * @param address - Address string
   * @returns Promise with geocoding result
   */
  static async geocode(address: string): Promise<GeocodingResult> {
    // Placeholder - implement with actual geocoding service
    console.warn('Geocoding not implemented - requires API integration');
    return {
      address,
      coordinates: [0, 0],
      confidence: 0,
    };
  }

  /**
   * Reverse geocoding - convert coordinates to address (placeholder)
   * In production, integrate with Google Maps Geocoding API, Mapbox, etc.
   * @param coordinates - Coordinate
   * @returns Promise with reverse geocoding result
   */
  static async reverseGeocode(coordinates: Coordinate): Promise<ReverseGeocodingResult> {
    // Placeholder - implement with actual geocoding service
    console.warn('Reverse geocoding not implemented - requires API integration');
    return {
      coordinates,
      address: 'Unknown location',
      components: {},
    };
  }
}

export default LocationModule;
