import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Check, FileText, Upload } from 'lucide-react';

export interface BulkCoordinatesDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  bulkText: string;
  onBulkTextChange: (text: string) => void;
  onApply: () => void;
}

export function BulkCoordinatesDialog({
  open,
  onOpenChange,
  bulkText,
  onBulkTextChange,
  onApply,
}: BulkCoordinatesDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-2 mb-1">
            <span className="p-1.5 rounded-lg bg-primary/10 text-primary">
              <FileText className="h-5 w-5" />
            </span>
            <DialogTitle className="text-lg font-bold">Bulk Paste Coordinate List</DialogTitle>
          </div>
          <DialogDescription className="text-xs">
            Paste 4 to 8 coordinates (one per line). Supports raw decimal degrees (e.g. <code>18.476861, -77.926028</code>) or DMS (e.g. <code>18°28&apos;36.7&quot;N 77°55&apos;33.7&quot;W</code>) copied directly from Google Maps or surveyor reports.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-2 py-2">
          <Label className="text-xs font-semibold">Coordinate Lines (4 to 8 required)</Label>
          <Textarea
            rows={8}
            value={bulkText}
            onChange={(e) => onBulkTextChange(e.target.value)}
            placeholder={`Point 1: 18.476861, -77.926028\nPoint 2: 18.478350, -77.927200\nPoint 3: 18.479100, -77.924800\nPoint 4: 18.478400, -77.923200`}
            className="font-mono text-xs leading-relaxed"
          />
          <div className="text-[11px] text-muted-foreground">
            Tip: You can paste without &quot;Point 1:&quot; prefixes. Any standard Lat, Lng format will be automatically recognized.
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button size="sm" onClick={onApply} className="gap-1.5">
            <Check className="h-4 w-4" />
            <span>Apply Coordinates</span>
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export interface GeoJsonImportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onFileUpload: (e: React.ChangeEvent<HTMLInputElement>) => void;
  geoJsonText: string;
  onGeoJsonTextChange: (text: string) => void;
  onApply: () => void;
  isImporting: boolean;
}

export function GeoJsonImportDialog({
  open,
  onOpenChange,
  onFileUpload,
  geoJsonText,
  onGeoJsonTextChange,
  onApply,
  isImporting,
}: GeoJsonImportDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <div className="flex items-center gap-2 mb-1">
            <span className="p-1.5 rounded-lg bg-blue-500/10 text-blue-600">
              <Upload className="h-5 w-5" />
            </span>
            <DialogTitle className="text-lg font-bold">Import Boundary from GeoJSON</DialogTitle>
          </div>
          <DialogDescription className="text-xs">
            Upload a <code>.geojson</code> file or paste a GeoJSON Polygon / FeatureCollection below. Coordinates will be parsed, scaled to 4–8 perimeter vertices, and populated into your editor.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div>
            <Label className="text-xs font-semibold mb-1.5 block">Upload File</Label>
            <Input
              type="file"
              accept=".geojson,.json"
              onChange={onFileUpload}
              className="text-xs cursor-pointer file:cursor-pointer"
            />
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs font-semibold">Or Paste GeoJSON Text</Label>
            <Textarea
              rows={7}
              value={geoJsonText}
              onChange={(e) => onGeoJsonTextChange(e.target.value)}
              placeholder={`{\n  "type": "FeatureCollection",\n  "features": [\n    {\n      "type": "Feature",\n      "geometry": {\n        "type": "Polygon",\n        "coordinates": [[[-77.926, 18.476], ...]]\n      }\n    }\n  ]\n}`}
              className="font-mono text-xs leading-relaxed"
            />
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            size="sm"
            onClick={onApply}
            disabled={isImporting}
            className="gap-1.5 bg-blue-600 hover:bg-blue-700 text-white"
          >
            <Check className="h-4 w-4" />
            <span>{isImporting ? 'Importing...' : 'Parse & Apply Polygon'}</span>
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
