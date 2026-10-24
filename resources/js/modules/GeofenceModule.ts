/**
 * GeofenceModule - Geofence-specific functionality
 * 
 * Specialized geofence operations building on LocationModule:
 * - Geofence validation and testing
 * - Gate location and management
 * - Perimeter analysis
 * - Boundary configuration
 * - Geofence-specific metrics
 */

import LocationModule, { Coordinate, PolygonMetrics } from './LocationModule';

export type GeofenceStatus = 'active' | 'draft' | 'expired' | 'inactive';

export type GeofenceSeverity = 'low' | 'medium' | 'high' | 'critical';

export interface GeofenceGate {
  id: string;
  name: string;
  coordinates: Coordinate;
  type: 'main' | 'secondary' | 'service' | 'emergency';
  status: 'active' | 'inactive' | 'maintenance';
  lastAccess?: Date;
}

export interface GeofenceConfig {
  id: string;
  name: string;
  status: GeofenceStatus;
  coordinates: Coordinate[];
  createdAt: Date;
  updatedAt: Date;
  publishedAt?: Date;
  expiresAt?: Date;
  gates: GeofenceGate[];
  maxArea?: number; // Maximum allowed area in square meters
  minPoints?: number; // Minimum required points
  maxPoints?: number; // Maximum allowed points
}

export interface GeofenceValidationResult {
  isValid: boolean;
  canPublish: boolean;
  pointCount: number;
  errors: string[];
  warnings: string[];
  metrics: PolygonMetrics;
}

export interface PerimeterInfo {
  isInside: boolean;
  distanceMeters: number;
  nearestVertexIndex: number;
  nearestGate: {
    name: string;
    distance: number;
    coordinates: Coordinate;
  };
  bearing?: number;
}

export interface GeofenceAlert {
  type: 'warning' | 'error' | 'info';
  severity: GeofenceSeverity;
  message: string;
  timestamp: Date;
  location?: Coordinate;
}

export interface GeofenceEvent {
  type: 'entry' | 'exit' | 'attempt' | 'breach';
  timestamp: Date;
  location: Coordinate;
  entity: string;
  gate?: string;
  success: boolean;
}

class GeofenceModule {
  private static readonly DEFAULT_MIN_POINTS = 4;
  private static readonly DEFAULT_MAX_POINTS = 8;
  private static readonly DEFAULT_MAX_AREA = 10000000; // 10 sq km
  private static readonly MIN_VERTEX_DISTANCE = 2; // meters

  /**
   * Validate a geofence configuration
   * @param coordinates - Geofence vertices
   * @param config - Optional geofence configuration constraints
   * @returns Validation result
   */
  static validateGeofence(
    coordinates: Coordinate[],
    config?: Partial<GeofenceConfig>
  ): GeofenceValidationResult {
    const errors: string[] = [];
    const warnings: string[] = [];
    const count = coordinates.length;

    const minPoints = config?.minPoints ?? this.DEFAULT_MIN_POINTS;
    const maxPoints = config?.maxPoints ?? this.DEFAULT_MAX_POINTS;
    const maxArea = config?.maxArea ?? this.DEFAULT_MAX_AREA;

    // Point count validation
    if (count < minPoints) {
      errors.push(`A geofence needs at least ${minPoints} points; ${count} supplied.`);
    }

    if (count > maxPoints) {
      errors.push(`A geofence supports at most ${maxPoints} points; ${count} supplied.`);
    }

    // Coordinate validation
    coordinates.forEach((point, index) => {
      if (!LocationModule.isValidCoordinate(point)) {
        errors.push(`Point ${index + 1} has invalid coordinates.`);
      }
    });

    // Vertex proximity validation
    for (let i = 0; i < count; i++) {
      for (let j = i + 1; j < count; j++) {
        const distance = LocationModule.haversineDistance(coordinates[i], coordinates[j]);
        if (distance < this.MIN_VERTEX_DISTANCE) {
          warnings.push(
            `Point ${i + 1} and Point ${j + 1} are within ${this.MIN_VERTEX_DISTANCE}m of each other; may cause vertex collapse.`
          );
        }
      }
    }

    // Self-intersection validation
    if (errors.length === 0 && count >= 3 && this.checkSelfIntersection(coordinates)) {
      errors.push('Geofence self-intersects. The perimeter must not cross itself.');
    }

    // Area validation
    const metrics = LocationModule.calculatePolygonMetrics(coordinates);
    if (metrics.area.sqMeters > 0 && metrics.area.sqMeters < 1000) {
      warnings.push('Enclosed area is under 1,000 m², which is unusually small for a community geofence.');
    }

    if (metrics.area.sqMeters > maxArea) {
      errors.push(`Enclosed area exceeds maximum allowed size of ${maxArea} m².`);
    }

    const isValid = errors.length === 0;
    const canPublish = isValid && count >= minPoints;

    return {
      isValid,
      canPublish,
      pointCount: count,
      errors,
      warnings,
      metrics,
    };
  }

  /**
   * Check if a polygon self-intersects
   * @param polygon - Polygon vertices
   * @returns True if polygon self-intersects
   */
  private static checkSelfIntersection(polygon: Coordinate[]): boolean {
    const n = polygon.length;

    for (let i = 0; i < n; i++) {
      const a1 = polygon[i];
      const a2 = polygon[(i + 1) % n];

      for (let j = i + 1; j < n; j++) {
        // Skip adjacent edges and the closing edge
        if (Math.abs(i - j) <= 1 || (i === 0 && j === n - 1)) {
          continue;
        }

        if (this.segmentsIntersect(a1, a2, polygon[j], polygon[(j + 1) % n])) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Check if two line segments intersect
   * @param p1 - First segment start
   * @param p2 - First segment end
   * @param p3 - Second segment start
   * @param p4 - Second segment end
   * @returns True if segments intersect
   */
  private static segmentsIntersect(
    p1: Coordinate,
    p2: Coordinate,
    p3: Coordinate,
    p4: Coordinate
  ): boolean {
    const ccw = (a: Coordinate, b: Coordinate, c: Coordinate): boolean =>
      (c[1] - a[1]) * (b[0] - a[0]) > (b[1] - a[1]) * (c[0] - a[0]);

    return (
      ccw(p1, p3, p4) !== ccw(p2, p3, p4) && ccw(p1, p2, p3) !== ccw(p1, p2, p4)
    );
  }

  /**
   * Get perimeter information for a point relative to a geofence
   * @param point - Point to analyze
   * @param geofence - Geofence configuration
   * @returns Perimeter information
   */
  static getPerimeterInfo(point: Coordinate, geofence: GeofenceConfig): PerimeterInfo {
    const isInside = LocationModule.isPointInPolygon(point, geofence.coordinates);
    let minDistance = Number.POSITIVE_INFINITY;
    let nearestVertexIndex = 0;

    // Find nearest vertex
    geofence.coordinates.forEach((vertex, index) => {
      const distance = LocationModule.haversineDistance(point, vertex);
      if (distance < minDistance) {
        minDistance = distance;
        nearestVertexIndex = index;
      }
    });

    // Find nearest gate
    const gates = geofence.gates || this.getDefaultGates();
    const nearestGate = gates
      .map((gate) => ({
        ...gate,
        distance: LocationModule.haversineDistance(point, gate.coordinates),
      }))
      .sort((a, b) => a.distance - b.distance)[0];

    // Calculate bearing from nearest vertex
    const bearing = LocationModule.calculateBearing(
      point,
      geofence.coordinates[nearestVertexIndex]
    );

    return {
      isInside,
      distanceMeters: Number.isFinite(minDistance) ? minDistance : 0,
      nearestVertexIndex,
      nearestGate,
      bearing,
    };
  }

  /**
   * Get default gate configuration
   * @returns Default gates array
   */
  private static getDefaultGates(): GeofenceGate[] {
    return [
      {
        id: 'gate-main',
        name: 'Main Security Gate',
        coordinates: [18.4750, -77.9257],
        type: 'main',
        status: 'active',
      },
      {
        id: 'gate-north',
        name: 'North Perimeter Checkpoint',
        coordinates: [18.4782, -77.9258],
        type: 'secondary',
        status: 'active',
      },
    ];
  }

  /**
   * Test if a point is within geofence bounds
   * @param point - Point to test
   * @param geofence - Geofence configuration
   * @returns True if point is within geofence
   */
  static isWithinGeofence(point: Coordinate, geofence: GeofenceConfig): boolean {
    if (geofence.status !== 'active') {
      return false;
    }

    return LocationModule.isPointInPolygon(point, geofence.coordinates);
  }

  /**
   * Calculate distance from point to geofence perimeter
   * @param point - Point to measure from
   * @param geofence - Geofence configuration
   * @returns Distance to perimeter in meters
   */
  static distanceToPerimeter(point: Coordinate, geofence: GeofenceConfig): number {
    let minDistance = Number.POSITIVE_INFINITY;

    geofence.coordinates.forEach((vertex) => {
      const distance = LocationModule.haversineDistance(point, vertex);
      if (distance < minDistance) {
        minDistance = distance;
      }
    });

    // Also check distance to edges
    for (let i = 0; i < geofence.coordinates.length; i++) {
      const p1 = geofence.coordinates[i];
      const p2 = geofence.coordinates[(i + 1) % geofence.coordinates.length];
      const edgeDistance = this.pointToSegmentDistance(point, p1, p2);
      if (edgeDistance < minDistance) {
        minDistance = edgeDistance;
      }
    }

    return Number.isFinite(minDistance) ? minDistance : 0;
  }

  /**
   * Calculate distance from point to line segment
   * @param point - Point
   * @param segmentStart - Segment start
   * @param segmentEnd - Segment end
   * @returns Distance to segment in meters
   */
  private static pointToSegmentDistance(
    point: Coordinate,
    segmentStart: Coordinate,
    segmentEnd: Coordinate
  ): number {
    const dx = segmentEnd[1] - segmentStart[1];
    const dy = segmentEnd[0] - segmentStart[0];
    const mag = Math.sqrt(dx * dx + dy * dy);

    if (mag === 0) {
      return LocationModule.haversineDistance(point, segmentStart);
    }

    const u = ((point[1] - segmentStart[1]) * dx + (point[0] - segmentStart[0]) * dy) / (mag * mag);

    if (u < 0 || u > 1) {
      return Math.min(
        LocationModule.haversineDistance(point, segmentStart),
        LocationModule.haversineDistance(point, segmentEnd)
      );
    }

    const intersectionX = segmentStart[1] + u * dx;
    const intersectionY = segmentStart[0] + u * dy;
    const intersection: Coordinate = [intersectionY, intersectionX];

    return LocationModule.haversineDistance(point, intersection);
  }

  /**
   * Generate geofence alerts based on configuration
   * @param geofence - Geofence configuration
   * @returns Array of alerts
   */
  static generateGeofenceAlerts(geofence: GeofenceConfig): GeofenceAlert[] {
    const alerts: GeofenceAlert[] = [];

    // Check expiration
    if (geofence.expiresAt && new Date(geofence.expiresAt) < new Date()) {
      alerts.push({
        type: 'error',
        severity: 'critical',
        message: 'Geofence has expired and is no longer active.',
        timestamp: new Date(),
      });
    }

    // Check status
    if (geofence.status === 'draft') {
      alerts.push({
        type: 'info',
        severity: 'low',
        message: 'Geofence is in draft status and not yet active.',
        timestamp: new Date(),
      });
    }

    // Check gate status
    const inactiveGates = geofence.gates?.filter(gate => gate.status !== 'active') || [];
    if (inactiveGates.length > 0) {
      alerts.push({
        type: 'warning',
        severity: 'medium',
        message: `${inactiveGates.length} gate(s) are inactive or under maintenance.`,
        timestamp: new Date(),
      });
    }

    // Check for unusually small or large area
    const metrics = LocationModule.calculatePolygonMetrics(geofence.coordinates);
    if (metrics.area.sqMeters < 1000) {
      alerts.push({
        type: 'warning',
        severity: 'low',
        message: 'Geofence area is unusually small (< 1,000 m²).',
        timestamp: new Date(),
      });
    }

    if (metrics.area.sqMeters > 10000000) {
      alerts.push({
        type: 'warning',
        severity: 'medium',
        message: 'Geofence area is very large (> 10 km²). Consider optimizing for performance.',
        timestamp: new Date(),
      });
    }

    return alerts;
  }

  /**
   * Record a geofence event
   * @param event - Geofence event to record
   * @returns Event record
   */
  static recordEvent(event: GeofenceEvent): GeofenceEvent {
    // In production, this would save to a database or logging system
    console.log('Geofence event recorded:', event);
    return event;
  }

  /**
   * Generate geofence breach alert
   * @param location - Breach location
   * @param geofence - Geofence configuration
   * @returns Alert details
   */
  static generateBreachAlert(
    location: Coordinate,
    geofence: GeofenceConfig
  ): GeofenceAlert {
    const perimeterInfo = this.getPerimeterInfo(location, geofence);

    return {
      type: 'error',
      severity: 'critical',
      message: `Geofence breach detected at ${LocationModule.formatCoordinate(location)}. Distance: ${perimeterInfo.distanceMeters}m from perimeter.`,
      timestamp: new Date(),
      location,
    };
  }

  /**
   * Export geofence as GeoJSON
   * @param geofence - Geofence configuration
   * @returns GeoJSON feature
   */
  static exportAsGeoJSON(geofence: GeofenceConfig): GeoJSON.Feature<GeoJSON.Polygon> {
    const coordinates = geofence.coordinates.map(c => [c[1], c[0]]);
    coordinates.push(coordinates[0]); // Close the polygon

    return {
      type: 'Feature',
      properties: {
        id: geofence.id,
        name: geofence.name,
        status: geofence.status,
        createdAt: geofence.createdAt.toISOString(),
        updatedAt: geofence.updatedAt.toISOString(),
        publishedAt: geofence.publishedAt?.toISOString(),
        gates: geofence.gates,
      },
      geometry: {
        type: 'Polygon',
        coordinates: [coordinates],
      },
    };
  }

  /**
   * Import geofence from GeoJSON
   * @param geoJson - GeoJSON feature
   * @returns Geofence configuration
   */
  static importFromGeoJSON(geoJson: GeoJSON.Feature<GeoJSON.Polygon>): GeofenceConfig {
    if (geoJson.type !== 'Feature' || geoJson.geometry?.type !== 'Polygon') {
      throw new Error('Invalid GeoJSON: must be a Polygon Feature');
    }

    // GeoJSON positions are [lng, lat]; this module's Coordinate is [lat, lng].
    const coordinates = geoJson.geometry.coordinates[0].map(
      (coord: number[]): Coordinate => [coord[1], coord[0]]
    );

    // Remove the closing point if present
    if (
      coordinates.length > 3 &&
      coordinates[0][0] === coordinates[coordinates.length - 1][0] &&
      coordinates[0][1] === coordinates[coordinates.length - 1][1]
    ) {
      coordinates.pop();
    }

    return {
      id: (geoJson.properties as any).id || 'imported',
      name: (geoJson.properties as any).name || 'Imported Geofence',
      status: (geoJson.properties as any).status || 'draft',
      coordinates,
      createdAt: new Date((geoJson.properties as any).createdAt || Date.now()),
      updatedAt: new Date((geoJson.properties as any).updatedAt || Date.now()),
      gates: (geoJson.properties as any).gates || [],
    };
  }

  /**
   * Create geofence preset configurations
   * @param type - Preset type
   * @returns Geofence configuration
   */
  static createPreset(type: 'standard' | 'extended' | 'core'): GeofenceConfig {
    const presets: Record<string, Coordinate[]> = {
      standard: [
        [18.4781, -77.9278],
        [18.4783, -77.9239],
        [18.4752, -77.9236],
        [18.4750, -77.9276],
      ],
      extended: [
        [18.4788, -77.9284],
        [18.4791, -77.9233],
        [18.4760, -77.9228],
        [18.4744, -77.9230],
        [18.4742, -77.9282],
        [18.4765, -77.9286],
      ],
      core: [
        [18.4776, -77.9268],
        [18.4778, -77.9245],
        [18.4756, -77.9242],
        [18.4754, -77.9266],
      ],
    };

    const names: Record<string, string> = {
      standard: 'Standard Perimeter',
      extended: 'Extended Perimeter with Buffer',
      core: 'Central Residential Core',
    };

    return {
      id: `preset-${type}`,
      name: names[type],
      status: 'draft',
      coordinates: presets[type],
      createdAt: new Date(),
      updatedAt: new Date(),
      gates: this.getDefaultGates(),
    };
  }

  /**
   * Check if geofence needs renewal based on expiration date
   * @param geofence - Geofence configuration
   * @returns True if geofence needs renewal
   */
  static needsRenewal(geofence: GeofenceConfig): boolean {
    if (!geofence.expiresAt) return false;
    const expirationDate = new Date(geofence.expiresAt);
    const renewalThreshold = new Date();
    renewalThreshold.setDate(renewalThreshold.getDate() + 30); // 30 days before expiration

    return expirationDate <= renewalThreshold;
  }

  /**
   * Calculate optimal gate placement for a geofence
   * @param geofence - Geofence configuration
   * @returns Suggested gate locations
   */
  static calculateOptimalGatePlacement(geofence: GeofenceConfig): Coordinate[] {
    const coordinates = geofence.coordinates;
    const suggestions: Coordinate[] = [];

    // Suggest gates at regular intervals around perimeter
    const totalVertices = coordinates.length;
    const gateInterval = Math.max(2, Math.floor(totalVertices / 4));

    for (let i = 0; i < totalVertices; i += gateInterval) {
      const midPoint = LocationModule.midpoint(
        coordinates[i],
        coordinates[(i + gateInterval) % totalVertices]
      );
      suggestions.push(midPoint);
    }

    return suggestions;
  }

  /**
   * Generate geofence statistics for reporting
   * @param geofence - Geofence configuration
   * @returns Statistics object
   */
  static generateStatistics(geofence: GeofenceConfig) {
    const metrics = LocationModule.calculatePolygonMetrics(geofence.coordinates);
    const alerts = this.generateGeofenceAlerts(geofence);

    return {
      geofence: {
        id: geofence.id,
        name: geofence.name,
        status: geofence.status,
        pointCount: geofence.coordinates.length,
      },
      metrics,
      gates: {
        total: geofence.gates?.length || 0,
        active: geofence.gates?.filter(g => g.status === 'active').length || 0,
        inactive: geofence.gates?.filter(g => g.status !== 'active').length || 0,
      },
      alerts: {
        total: alerts.length,
        critical: alerts.filter(a => a.severity === 'critical').length,
        high: alerts.filter(a => a.severity === 'high').length,
        medium: alerts.filter(a => a.severity === 'medium').length,
        low: alerts.filter(a => a.severity === 'low').length,
      },
      timestamps: {
        created: geofence.createdAt,
        updated: geofence.updatedAt,
        published: geofence.publishedAt,
        expires: geofence.expiresAt,
      },
    };
  }
}

export default GeofenceModule;
