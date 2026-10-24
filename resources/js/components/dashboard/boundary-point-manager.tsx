import React, { useState, useEffect, useMemo } from 'react';
import { useAuth } from '@/context/auth-context';
import { useToast } from '@/hooks/use-toast';
import { router } from '@inertiajs/react';
import { 
  BoundaryPoint,
  BoundaryConfig,
  CommunityInfo
} from '@/lib/boundary-manager/types';
import { 
  INITIAL_BOUNDARY_POINTS,
  validateBoundary,
  saveDraftDebounced,
  publishBoundary,
  parseCoordinateString,
  parseBulkCoordinates
} from '@/lib/boundary-manager/service';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { 
  ShieldCheck, 
  History, 
  Download, 
  Upload, 
  FileText 
} from 'lucide-react';

import { BoundaryCommunityInfoCard } from './boundary/boundary-community-info-card';
import { BoundaryPointsList } from './boundary/boundary-points-list';
import { BoundaryMapPreviewCard } from './boundary/boundary-map-preview-card';
import { BoundaryAuditTab } from './boundary/boundary-audit-tab';
import { BulkCoordinatesDialog, GeoJsonImportDialog } from './boundary/boundary-point-dialogs';

export type BoundaryPointManagerProps = {
  /** Latest draft (or published fallback) from the server. */
  initialConfig: BoundaryConfig;
  /** Community metadata, previously the hardcoded COMMUNITY_INFO constant. */
  community: CommunityInfo;
  /** Whether the viewer holds `manageBoundary`. */
  canManage?: boolean;
  /** Historical versions for rollback/replace. */
  previousVersions?: {
    id: number;
    version: number;
    status: string;
    pointsCount: number;
    publishedAt: string | null;
    publishedBy: string | null;
    points: BoundaryPoint[];
  }[];
};

export default function BoundaryPointManager({
  initialConfig,
  community,
  canManage = false,
  previousVersions = [],
}: BoundaryPointManagerProps) {
  const { user } = useAuth();
  const { toast } = useToast();

  const [config, setConfig] = useState<BoundaryConfig>(initialConfig);
  const [points, setPoints] = useState<BoundaryPoint[]>(config.points);
  const [selectedPointIndex, setSelectedPointIndex] = useState<number | null>(0);
  const [activeTab, setActiveTab] = useState<'mapping' | 'audit'>('mapping');

  // String buffers for responsive manual typing without numeric parse locks
  const [inputBuffer, setInputBuffer] = useState<Record<number, { lat: string; lng: string }>>({});
  const [quickPasteInputs, setQuickPasteInputs] = useState<Record<number, string>>({});

  // Direct Point Coordinate Updater State
  const [directPasteInput, setDirectPasteInput] = useState<string>('');
  const [directLatInput, setDirectLatInput] = useState<string>('');
  const [directLngInput, setDirectLngInput] = useState<string>('');

  // Map view focus target
  const [focusTarget, setFocusTarget] = useState<{ lat: number; lng: number; zoom?: number; timestamp: number } | null>(null);

  // Interactive map modes and modals
  const [isClickToPlaceActive, setIsClickToPlaceActive] = useState<boolean>(false);
  const [isBulkModalOpen, setIsBulkModalOpen] = useState<boolean>(false);
  const [isImportModalOpen, setIsImportModalOpen] = useState<boolean>(false);
  const [importGeoJsonText, setImportGeoJsonText] = useState<string>('');
  const [isImporting, setIsImporting] = useState<boolean>(false);
  const [bulkText, setBulkText] = useState<string>('');

  const syncInputBufferFromPoints = (pts: BoundaryPoint[]) => {
    const nextBuffer: Record<number, { lat: string; lng: string }> = {};
    pts.forEach((p, idx) => {
      nextBuffer[idx] = {
        lat: p.lat.toString(),
        lng: p.lng.toString(),
      };
    });
    setInputBuffer(nextBuffer);
  };

  useEffect(() => {
    const loaded = initialConfig;
    setConfig(loaded);
    setPoints(loaded.points);
    syncInputBufferFromPoints(loaded.points);
    if (loaded.points.length > 0) {
      setDirectLatInput(loaded.points[0].lat.toString());
      setDirectLngInput(loaded.points[0].lng.toString());
      setFocusTarget({
        lat: loaded.points[0].lat,
        lng: loaded.points[0].lng,
        zoom: 17,
        timestamp: Date.now(),
      });
    }
  }, [initialConfig.version, initialConfig.status]);

  useEffect(() => {
    const idx = selectedPointIndex ?? 0;
    const pt = points[idx];
    if (pt) {
      setDirectLatInput(pt.lat.toString());
      setDirectLngInput(pt.lng.toString());
    }
  }, [selectedPointIndex, points]);

  const isSystemAdmin = user?.role === 'System Admin' || user?.role === 'Admin';
  const canEditBoundary = canManage;

  const validation = useMemo(() => {
    return validateBoundary(points);
  }, [points]);

  const handleLatChange = (index: number, val: string) => {
    if (!canEditBoundary) return;
    setInputBuffer(prev => ({
      ...prev,
      [index]: {
        lat: val,
        lng: prev[index]?.lng ?? (points[index]?.lng.toString() || ''),
      },
    }));
  };

  const handleLngChange = (index: number, val: string) => {
    if (!canEditBoundary) return;
    setInputBuffer(prev => ({
      ...prev,
      [index]: {
        lat: prev[index]?.lat ?? (points[index]?.lat.toString() || ''),
        lng: val,
      },
    }));
  };

  const commitPointCoordinates = (
    index: number,
    cleanLat: number,
    cleanLng: number,
    sourceDesc?: string
  ) => {
    let updatedPoints: BoundaryPoint[] = [];
    setPoints(prev => {
      const updated = [...prev];
      if (updated[index]) {
        updated[index] = { ...updated[index], lat: cleanLat, lng: cleanLng };
      }
      updatedPoints = updated;
      return updated;
    });

    setInputBuffer(prev => ({
      ...prev,
      [index]: { lat: cleanLat.toString(), lng: cleanLng.toString() },
    }));

    if (selectedPointIndex === index) {
      setDirectLatInput(cleanLat.toString());
      setDirectLngInput(cleanLng.toString());
    }

    setConfig(prev => {
      const nextPts = updatedPoints.length > 0 ? updatedPoints : prev.points;
      const updatedConfig: BoundaryConfig = {
        ...prev,
        points: nextPts,
        status: 'DRAFT',
      };
      saveDraftDebounced(updatedConfig.points);
      return updatedConfig;
    });

    setFocusTarget({
      lat: cleanLat,
      lng: cleanLng,
      zoom: 17,
      timestamp: Date.now(),
    });

    toast({
      title: `${points[index]?.label || `Point ${index + 1}`} Coordinates Updated & Held`,
      description: `${sourceDesc ? `${sourceDesc} — ` : ''}Map view shifted to Lat: ${cleanLat}, Lng: ${cleanLng}. Coordinates held in memory and local storage until updated.`,
    });
  };

  const handleFocusPoint = (index: number) => {
    const pt = points[index];
    if (!pt) return;
    setSelectedPointIndex(index);
    setFocusTarget({
      lat: pt.lat,
      lng: pt.lng,
      zoom: 17,
      timestamp: Date.now(),
    });
    toast({
      title: `Map Focused on ${pt.label}`,
      description: `Shifted view to Lat: ${pt.lat}, Lng: ${pt.lng}`,
    });
  };

  const handleCommitPoint = (index: number) => {
    if (!canEditBoundary) return;
    const current = inputBuffer[index];
    if (!current) return;

    const latNum = parseFloat(current.lat);
    const lngNum = parseFloat(current.lng);

    if (isNaN(latNum) || latNum < -90 || latNum > 90) {
      toast({
        variant: 'destructive',
        title: 'Invalid Latitude',
        description: `Latitude must be a valid number between -90° and 90°. Entered: "${current.lat}".`,
      });
      return;
    }

    if (isNaN(lngNum) || lngNum < -180 || lngNum > 180) {
      toast({
        variant: 'destructive',
        title: 'Invalid Longitude',
        description: `Longitude must be a valid number between -180° and 180°. Entered: "${current.lng}".`,
      });
      return;
    }

    const cleanLat = +latNum.toFixed(6);
    const cleanLng = +lngNum.toFixed(6);

    commitPointCoordinates(index, cleanLat, cleanLng, 'Card inputs applied');
  };

  const handleApplyDirectPaste = () => {
    if (!canEditBoundary) return;
    const idx = selectedPointIndex ?? 0;
    if (!directPasteInput.trim()) {
      toast({
        variant: 'destructive',
        title: 'Empty Coordinate Input',
        description: 'Please paste or type coordinates from Google Maps (e.g. 18.44976, -77.9144841).',
      });
      return;
    }

    const parsed = parseCoordinateString(directPasteInput);
    if (parsed) {
      setDirectPasteInput('');
      commitPointCoordinates(idx, parsed.lat, parsed.lng, 'Google Maps paste applied');
    } else {
      toast({
        variant: 'destructive',
        title: 'Could Not Parse Coordinates',
        description: 'Format unrecognized. Example: 18.44976, -77.9144841 or 18°28\'36.7"N 77°55\'33.7"W',
      });
    }
  };

  const handleApplyDirectManual = () => {
    if (!canEditBoundary) return;
    const idx = selectedPointIndex ?? 0;
    const latNum = parseFloat(directLatInput);
    const lngNum = parseFloat(directLngInput);

    if (isNaN(latNum) || latNum < -90 || latNum > 90) {
      toast({
        variant: 'destructive',
        title: 'Invalid Latitude',
        description: 'Latitude must be a valid number between -90° and 90°. Example: 18.449760',
      });
      return;
    }

    if (isNaN(lngNum) || lngNum < -180 || lngNum > 180) {
      toast({
        variant: 'destructive',
        title: 'Invalid Longitude',
        description: 'Longitude must be a valid number between -180° and 180°. Example: -77.914484',
      });
      return;
    }

    const cleanLat = +latNum.toFixed(6);
    const cleanLng = +lngNum.toFixed(6);

    commitPointCoordinates(idx, cleanLat, cleanLng, 'Direct updater applied');
  };

  const handleQuickPaste = (index: number, pasteStr: string) => {
    if (!canEditBoundary || !pasteStr.trim()) return;
    const parsed = parseCoordinateString(pasteStr);
    if (parsed) {
      setQuickPasteInputs(prev => ({ ...prev, [index]: '' }));
      commitPointCoordinates(index, parsed.lat, parsed.lng, 'Single card paste applied');
    } else {
      toast({
        variant: 'destructive',
        title: 'Unrecognized Coordinate Format',
        description: 'Please enter decimal (e.g. 18.476861, -77.926028) or DMS (e.g. 18°28\'36.7"N 77°55\'33.7"W).',
      });
    }
  };

  const handlePointDrag = (index: number, lat: number, lng: number) => {
    if (!canEditBoundary) return;
    let updatedPoints: BoundaryPoint[] = [];
    setPoints(prev => {
      const updated = [...prev];
      updated[index] = {
        ...updated[index],
        lat,
        lng,
      };
      updatedPoints = updated;
      return updated;
    });
    setInputBuffer(prev => ({
      ...prev,
      [index]: { lat: lat.toString(), lng: lng.toString() },
    }));
    if (selectedPointIndex === index) {
      setDirectLatInput(lat.toString());
      setDirectLngInput(lng.toString());
    }
    setConfig(prev => {
      const updatedConfig: BoundaryConfig = {
        ...prev,
        points: updatedPoints.length > 0 ? updatedPoints : prev.points,
        status: 'DRAFT',
      };
      saveDraftDebounced(updatedConfig.points);
      return updatedConfig;
    });
  };

  const handleMapClick = (lat: number, lng: number) => {
    if (!canEditBoundary || selectedPointIndex === null) return;
    if (isClickToPlaceActive) {
      commitPointCoordinates(selectedPointIndex, lat, lng, 'Map clicked location');
    }
  };

  const handleAddPoint = () => {
    if (!canEditBoundary) return;
    if (points.length >= 8) {
      toast({
        variant: 'destructive',
        title: 'Maximum Points Reached (8 Max)',
        description: 'The community boundary manager restricts polygons to a maximum of 8 points.',
      });
      return;
    }

    const nextId = points.length + 1;
    const lastPoint = points[points.length - 1] || { lat: 18.476861, lng: -77.926028 };
    const angle = (nextId / 8) * 2 * Math.PI;
    const offsetLat = +(lastPoint.lat + 0.0006 * Math.sin(angle)).toFixed(6);
    const offsetLng = +(lastPoint.lng + 0.0006 * Math.cos(angle)).toFixed(6);

    const newPoint: BoundaryPoint = {
      id: nextId,
      label: `Point ${nextId}`,
      lat: offsetLat,
      lng: offsetLng,
      isOptional: nextId > 4,
    };

    const nextPts = [...points, newPoint];
    setPoints(nextPts);
    syncInputBufferFromPoints(nextPts);
    setSelectedPointIndex(points.length);
    const updatedCfg: BoundaryConfig = { ...config, points: nextPts, status: 'DRAFT' };
    setConfig(updatedCfg);
    saveDraftDebounced(updatedCfg.points);
    setFocusTarget({ lat: newPoint.lat, lng: newPoint.lng, zoom: 17, timestamp: Date.now() });

    toast({
      title: `Point ${nextId} Added`,
      description: `New boundary vertex created. Coordinates held in storage and focused on map.`,
    });
  };

  const handleRemovePoint = (index: number) => {
    if (!canEditBoundary) return;
    if (points.length <= 4) {
      toast({
        variant: 'destructive',
        title: 'Minimum 4 Points Required',
        description: 'A community boundary requires at least 4 points. Points 1 to 4 cannot be removed.',
      });
      return;
    }

    const updated = points.filter((_, i) => i !== index).map((p, i) => ({
      ...p,
      id: i + 1,
      label: `Point ${i + 1}`,
      isOptional: i >= 4,
    }));

    setPoints(updated);
    syncInputBufferFromPoints(updated);
    if (selectedPointIndex === index) {
      setSelectedPointIndex(Math.max(0, index - 1));
    }
    const updatedCfg: BoundaryConfig = { ...config, points: updated, status: 'DRAFT' };
    setConfig(updatedCfg);
    saveDraftDebounced(updatedCfg.points);

    toast({
      title: 'Point Removed',
      description: `Boundary resized to ${updated.length} points and updated in storage.`,
    });
  };

  const handleMovePoint = (index: number, direction: 'up' | 'down') => {
    if (!canEditBoundary) return;
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= points.length) return;

    const nextPts = [...points];
    const temp = nextPts[index];
    nextPts[index] = nextPts[targetIndex];
    nextPts[targetIndex] = temp;

    const reindexed = nextPts.map((p, i) => ({
      ...p,
      id: i + 1,
      label: `Point ${i + 1}`,
      isOptional: i >= 4,
    }));

    setPoints(reindexed);
    syncInputBufferFromPoints(reindexed);
    setSelectedPointIndex(targetIndex);

    const updatedCfg: BoundaryConfig = { ...config, points: reindexed, status: 'DRAFT' };
    setConfig(updatedCfg);
    saveDraftDebounced(updatedCfg.points);

    toast({
      title: 'Points Reordered',
      description: `Point ${index + 1} moved to position ${targetIndex + 1}. Polygon recalculated.`,
    });
  };

  const handleInsertReferenceCoord = () => {
    if (!canEditBoundary) return;
    const refLat = community.referenceCoord.lat || 18.4766;
    const refLng = community.referenceCoord.lng || -77.9257;

    const targetIdx = selectedPointIndex ?? 0;
    commitPointCoordinates(targetIdx, refLat, refLng, 'Benchmark coordinate applied');
    toast({
      title: 'Reference Benchmark Coordinate Applied',
      description: `Inserted ${refLat}, ${refLng} (${community.referenceCoord.description || 'Community Datum Reference'}) into Point ${targetIdx + 1}.`,
    });
  };

  const handleReplaceWithVersion = (versionId: number, versionNum: number) => {
    if (!canEditBoundary) return;
    router.post(route('boundary.replace', versionId), {}, {
      preserveScroll: true,
      onSuccess: () => {
        toast({
          title: `Boundary Replaced with v${versionNum}`,
          description: `The active estate perimeter has been replaced and published with snapshot from Version ${versionNum}.`,
        });
      },
    });
  };

  const handleLoadPreset = (presetType: 'montego' | 'quad' | 'octagon') => {
    if (!canEditBoundary) return;

    let newPts: BoundaryPoint[] = [];
    if (presetType === 'montego') {
      newPts = INITIAL_BOUNDARY_POINTS;
    } else if (presetType === 'quad') {
      newPts = [
        { id: 1, label: 'Point 1', lat: 18.476861, lng: -77.926028, isOptional: false },
        { id: 2, label: 'Point 2', lat: 18.478350, lng: -77.926028, isOptional: false },
        { id: 3, label: 'Point 3', lat: 18.478350, lng: -77.923500, isOptional: false },
        { id: 4, label: 'Point 4', lat: 18.476861, lng: -77.923500, isOptional: false },
      ];
    } else if (presetType === 'octagon') {
      newPts = [
        { id: 1, label: 'Point 1', lat: 18.476861, lng: -77.926028, isOptional: false },
        { id: 2, label: 'Point 2', lat: 18.478100, lng: -77.927500, isOptional: false },
        { id: 3, label: 'Point 3', lat: 18.479400, lng: -77.926500, isOptional: false },
        { id: 4, label: 'Point 4', lat: 18.479600, lng: -77.924200, isOptional: false },
        { id: 5, label: 'Point 5', lat: 18.478400, lng: -77.922800, isOptional: true },
        { id: 6, label: 'Point 6', lat: 18.476000, lng: -77.922500, isOptional: true },
        { id: 7, label: 'Point 7', lat: 18.474600, lng: -77.924500, isOptional: true },
        { id: 8, label: 'Point 8', lat: 18.475200, lng: -77.926600, isOptional: true },
      ];
    }

    setPoints(newPts);
    syncInputBufferFromPoints(newPts);
    setSelectedPointIndex(0);
    const updatedCfg: BoundaryConfig = { ...config, points: newPts, status: 'DRAFT' };
    setConfig(updatedCfg);
    saveDraftDebounced(updatedCfg.points);
    setFocusTarget({ lat: newPts[0].lat, lng: newPts[0].lng, zoom: 17, timestamp: Date.now() });

    toast({
      title: 'Preset Boundary Loaded',
      description: `Loaded ${newPts.length}-point template. Focused on Point 1: ${newPts[0].lat}, ${newPts[0].lng}.`,
    });
  };

  const handleApplyBulkText = () => {
    if (!canEditBoundary) return;
    const { points: parsedPoints } = parseBulkCoordinates(bulkText);
    
    if (parsedPoints.length < 4) {
      toast({
        variant: 'destructive',
        title: 'Minimum 4 Points Required',
        description: `Parsed ${parsedPoints.length} valid points from text. At least 4 points are required (maximum 8).`,
      });
      return;
    }

    const finalPoints = parsedPoints.slice(0, 8);
    setPoints(finalPoints);
    syncInputBufferFromPoints(finalPoints);
    setSelectedPointIndex(0);
    const updatedCfg: BoundaryConfig = { ...config, points: finalPoints, status: 'DRAFT' };
    setConfig(updatedCfg);
    saveDraftDebounced(updatedCfg.points);
    setFocusTarget({ lat: finalPoints[0].lat, lng: finalPoints[0].lng, zoom: 17, timestamp: Date.now() });
    setIsBulkModalOpen(false);

    toast({
      title: 'Bulk Coordinates Applied Successfully',
      description: `Loaded ${finalPoints.length} points and held in storage. Map focused on Point 1.`,
    });
  };

  const handleOpenBulkEditor = () => {
    const formatted = points.map(p => `${p.label}: ${p.lat}, ${p.lng}`).join('\n');
    setBulkText(formatted);
    setIsBulkModalOpen(true);
  };

  const handleExportGeoJson = () => {
    window.location.href = '/map/boundary/export';
  };

  const handleImportGeoJson = async () => {
    if (!importGeoJsonText.trim()) {
      toast({
        variant: 'destructive',
        title: 'Empty GeoJSON',
        description: 'Please paste GeoJSON text or choose a GeoJSON file.',
      });
      return;
    }

    setIsImporting(true);
    try {
      const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
      const res = await fetch('/map/boundary/import', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ geojson: importGeoJsonText }),
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.message || 'Failed to parse GeoJSON boundary.');
      }

      setPoints(data.points);
      syncInputBufferFromPoints(data.points);
      setSelectedPointIndex(0);

      const updatedCfg: BoundaryConfig = { ...config, points: data.points, status: 'DRAFT' };
      setConfig(updatedCfg);
      saveDraftDebounced(updatedCfg.points);

      if (data.points[0]) {
        setFocusTarget({ lat: data.points[0].lat, lng: data.points[0].lng, zoom: 17, timestamp: Date.now() });
      }

      setIsImportModalOpen(false);
      setImportGeoJsonText('');
      toast({
        title: 'GeoJSON Imported',
        description: `Successfully loaded ${data.points.length} boundary points.`,
      });
    } catch (err: any) {
      toast({
        variant: 'destructive',
        title: 'Import Failed',
        description: err.message || 'Unable to import GeoJSON polygon.',
      });
    } finally {
      setIsImporting(false);
    }
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = (event) => {
      const content = event.target?.result as string;
      if (content) {
        setImportGeoJsonText(content);
      }
    };
    reader.readAsText(file);
  };

  const handleSaveDraft = () => {
    if (!canEditBoundary) {
      toast({ variant: 'destructive', title: 'Permission Denied', description: 'Only Admins can modify boundaries.' });
      return;
    }

    const updatedConfig: BoundaryConfig = {
      ...config,
      status: 'DRAFT',
      points,
    };
    setConfig(updatedConfig);
    saveDraftDebounced(updatedConfig.points);

    toast({
      title: 'Draft Saved',
      description: `Saved ${points.length} boundary points locally as draft. Not yet published to live gates.`,
    });
  };

  const handlePublishBoundary = () => {
    if (!canEditBoundary) {
      toast({
        variant: 'destructive',
        title: 'Permission Denied',
        description: 'Only Administrators possess authority to publish official community boundaries.',
      });
      return;
    }

    if (!validation.canPublish) {
      toast({
        variant: 'destructive',
        title: 'Boundary Validation Failed',
        description: validation.errors[0] || 'Points do not form a valid, non-intersecting polygon.',
      });
      return;
    }

    publishBoundary(points, {
      notes: `Published from the boundary manager: ${validation.areaAcres} acres across ${points.length} vertices.`,
      onSuccess: () => {
        toast({
          title: 'Boundary Published',
          description:
            'The official geofence is now live across the community map, resident dashboards and the gate pass engine.',
        });
      },
      onError: (errors) => {
        toast({
          variant: 'destructive',
          title: 'Boundary Not Published',
          description:
            errors?.points ?? 'The server rejected this boundary. Check the point count and shape.',
        });
      },
    });
  };

  return (
    <div className="space-y-6">
      {/* ── System Admin Security & Authority Banner ── */}
      <div className="p-4 rounded-2xl border flex flex-col md:flex-row items-start md:items-center justify-between gap-4 bg-emerald-500/10 border-emerald-500/30 text-emerald-950 dark:text-emerald-100">
        <div className="flex items-center gap-3">
          <div className="p-2 rounded-xl bg-emerald-500/20 text-emerald-600">
            <ShieldCheck className="h-6 w-6" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h2 className="text-base font-bold tracking-tight">
                Community Boundary Management & Coordinate Authority
              </h2>
              <Badge variant="outline" className="font-mono text-[10px] border-emerald-500/40 text-emerald-700 dark:text-emerald-300 bg-emerald-500/10">
                FULL EDIT &amp; COORDINATE UPDATE RIGHTS
              </Badge>
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Authorized to enter point coordinates, paste Google Maps locations (e.g. 18.44976, -77.9144841), drag terrain vertices, and publish geofence perimeters.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 self-stretch md:self-auto flex-wrap">
          <Button
            variant="outline"
            size="sm"
            onClick={handleExportGeoJson}
            className="text-xs h-8 gap-1.5 font-medium border-border"
            title="Download GeoJSON feature for GIS / CAD mapping"
          >
            <Download className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
            <span>Export GeoJSON</span>
          </Button>

          {isSystemAdmin && (
            <>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setIsImportModalOpen(true)}
                className="text-xs h-8 gap-1.5 font-medium border-border"
                title="Import polygon boundary from GeoJSON file or text"
              >
                <Upload className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                <span>Import GeoJSON</span>
              </Button>

              <Button
                variant="outline"
                size="sm"
                onClick={handleOpenBulkEditor}
                className="text-xs h-8 gap-1.5 font-medium border-border"
              >
                <FileText className="h-3.5 w-3.5 text-primary" />
                <span>Bulk Paste Coordinates</span>
              </Button>
            </>
          )}

          <Button
            variant="outline"
            size="sm"
            onClick={() => setActiveTab(activeTab === 'mapping' ? 'audit' : 'mapping')}
            className="text-xs h-8 gap-1.5 font-medium border-border"
          >
            <History className="h-3.5 w-3.5" />
            <span>{activeTab === 'mapping' ? 'View Audit History' : 'Back to Boundary Map'}</span>
          </Button>
        </div>
      </div>

      {/* ── Bulk Coordinates Paste Modal ── */}
      <BulkCoordinatesDialog
        open={isBulkModalOpen}
        onOpenChange={setIsBulkModalOpen}
        bulkText={bulkText}
        onBulkTextChange={setBulkText}
        onApply={handleApplyBulkText}
      />

      {/* ── GeoJSON Import Modal ── */}
      <GeoJsonImportDialog
        open={isImportModalOpen}
        onOpenChange={setIsImportModalOpen}
        onFileUpload={handleFileUpload}
        geoJsonText={importGeoJsonText}
        onGeoJsonTextChange={setImportGeoJsonText}
        onApply={handleImportGeoJson}
        isImporting={isImporting}
      />

      {activeTab === 'audit' ? (
        <BoundaryAuditTab
          auditHistory={config.auditHistory}
          previousVersions={previousVersions}
          canEditBoundary={canEditBoundary}
          onReplaceWithVersion={handleReplaceWithVersion}
        />
      ) : (
        /* ── Main Boundary Point Manager ── */
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
          {/* Left Column: Community Info & Boundary Point Inputs (5 cols) */}
          <div className="lg:col-span-5 space-y-6">
            <BoundaryCommunityInfoCard
              community={community}
              config={config}
              points={points}
              onFocusPoint={handleFocusPoint}
            />

            <BoundaryPointsList
              canEditBoundary={canEditBoundary}
              points={points}
              validation={validation}
              selectedPointIndex={selectedPointIndex}
              onSelectPointIndex={setSelectedPointIndex}
              isClickToPlaceActive={isClickToPlaceActive}
              onToggleClickToPlace={() => setIsClickToPlaceActive(!isClickToPlaceActive)}
              onLoadPreset={handleLoadPreset}
              onInsertReferenceCoord={handleInsertReferenceCoord}
              onFocusPoint={handleFocusPoint}
              directPasteInput={directPasteInput}
              onDirectPasteInputChange={setDirectPasteInput}
              onApplyDirectPaste={handleApplyDirectPaste}
              directLatInput={directLatInput}
              onDirectLatInputChange={setDirectLatInput}
              directLngInput={directLngInput}
              onDirectLngInputChange={setDirectLngInput}
              onApplyDirectManual={handleApplyDirectManual}
              inputBuffer={inputBuffer}
              onLatChange={handleLatChange}
              onLngChange={handleLngChange}
              onCommitPoint={handleCommitPoint}
              onMovePoint={handleMovePoint}
              onRemovePoint={handleRemovePoint}
              onAddPoint={handleAddPoint}
              quickPasteInputs={quickPasteInputs}
              onQuickPasteInputChange={(idx, val) => setQuickPasteInputs(prev => ({ ...prev, [idx]: val }))}
              onQuickPaste={handleQuickPaste}
            />
          </div>

          {/* Right Column: Preview Boundary Map & Save/Publish Controls (7 cols) */}
          <div className="lg:col-span-7 space-y-6">
            <BoundaryMapPreviewCard
              validation={validation}
              isClickToPlaceActive={isClickToPlaceActive}
              canEditBoundary={canEditBoundary}
              points={points}
              selectedPointIndex={selectedPointIndex}
              config={config}
              focusTarget={focusTarget}
              onPointDrag={handlePointDrag}
              onSelectPoint={setSelectedPointIndex}
              onMapClick={handleMapClick}
              onSaveDraft={handleSaveDraft}
              onPublishBoundary={handlePublishBoundary}
            />
          </div>
        </div>
      )}
    </div>
  );
}
