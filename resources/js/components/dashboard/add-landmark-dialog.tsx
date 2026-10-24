
import { useState, useEffect } from 'react';
import { 
  CommunityLandmark, 
  LandmarkCategory, 
  LANDMARK_PIN_CONFIGS, 
  estimateElevation,
  toDMS 
} from '@/lib/geofence-utils';
import { parseCoordinateString } from '@/lib/boundary-manager/service';
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
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { 
  MapPin, 
  Shield, 
  Building2, 
  Trees, 
  Check, 
  Clipboard, 
  Crosshair, 
  Sparkles 
} from 'lucide-react';

interface AddLandmarkDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAddLandmark: (landmark: CommunityLandmark) => void;
  initialCoordinates?: [number, number] | null;
}

export function AddLandmarkDialog({
  open,
  onOpenChange,
  onAddLandmark,
  initialCoordinates,
}: AddLandmarkDialogProps) {
  const { toast } = useToast();

  const [category, setCategory] = useState<LandmarkCategory>('Security Gate');
  const [name, setName] = useState<string>('Main Security Gate & Access Control');
  const [description, setDescription] = useState<string>('');
  const [latInput, setLatInput] = useState<string>('18.4750');
  const [lngInput, setLngInput] = useState<string>('-77.9257');
  const [pasteInput, setPasteInput] = useState<string>('');

  const config = LANDMARK_PIN_CONFIGS[category];

  // Sync initial coordinates when opened
  useEffect(() => {
    if (initialCoordinates) {
      setLatInput(initialCoordinates[0].toFixed(6));
      setLngInput(initialCoordinates[1].toFixed(6));
    }
  }, [initialCoordinates, open]);

  // Update default name and placeholder when category switches
  const handleSelectCategory = (cat: LandmarkCategory) => {
    setCategory(cat);
    const catConfig = LANDMARK_PIN_CONFIGS[cat];
    if (catConfig.presetNames.length > 0) {
      setName(catConfig.presetNames[0]);
    }
    setDescription(catConfig.descriptionPlaceholder);
  };

  // Parse Google Maps paste string
  const handleApplyPaste = () => {
    if (!pasteInput.trim()) return;
    const parsed = parseCoordinateString(pasteInput);
    if (parsed) {
      setLatInput(parsed.lat.toString());
      setLngInput(parsed.lng.toString());
      setPasteInput('');
      toast({
        title: 'Coordinates Extracted',
        description: `Parsed Lat: ${parsed.lat}, Lng: ${parsed.lng} from input.`,
      });
    } else {
      toast({
        variant: 'destructive',
        title: 'Unrecognized Coordinates',
        description: 'Please enter decimal degrees (e.g. 18.44269, -77.91219) or Google Maps URL.',
      });
    }
  };

  // Submit new landmark pin
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const lat = parseFloat(latInput);
    const lng = parseFloat(lngInput);

    if (isNaN(lat) || lat < -90 || lat > 90) {
      toast({
        variant: 'destructive',
        title: 'Invalid Latitude',
        description: 'Latitude must be a valid number between -90° and 90° (e.g. 18.4750).',
      });
      return;
    }

    if (isNaN(lng) || lng < -180 || lng > 180) {
      toast({
        variant: 'destructive',
        title: 'Invalid Longitude',
        description: 'Longitude must be a valid number between -180° and 180° (e.g. -77.9257).',
      });
      return;
    }

    if (!name.trim()) {
      toast({
        variant: 'destructive',
        title: 'Name Required',
        description: 'Please provide a title or identifier for this landmark pin.',
      });
      return;
    }

    const cleanLat = +lat.toFixed(6);
    const cleanLng = +lng.toFixed(6);
    const elev = estimateElevation(cleanLat, cleanLng);

    const newLandmark: CommunityLandmark = {
      id: `pin-${category.toLowerCase().replace(/\s+/g, '-')}-${Date.now()}`,
      name: name.trim(),
      category,
      coordinates: [cleanLat, cleanLng],
      elevation: elev,
      description: description.trim() || config.descriptionPlaceholder,
      iconType: config.iconType,
      color: config.color,
      // No `isCustom`: the landmarks table has no such column. The flag used to
      // separate user pins from the hardcoded fixtures, and those are gone —
      // every landmark is now a row somebody created.
    };

    onAddLandmark(newLandmark);
    onOpenChange(false);

    toast({
      title: `${config.label} Pin Added to Map!`,
      description: `Placed ${newLandmark.name} with ${config.badgeLabel} at ${cleanLat}, ${cleanLng}.`,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl max-h-[92vh] overflow-y-auto">
        <DialogHeader>
          <div className="flex items-center gap-2 mb-1">
            <span 
              className="p-1.5 rounded-lg text-white shadow-sm flex items-center justify-center"
              style={{ backgroundColor: config.color }}
            >
              <MapPin className="h-5 w-5" />
            </span>
            <div>
              <DialogTitle className="text-lg font-bold">Add Community Landmark Pin</DialogTitle>
              <DialogDescription className="text-xs text-muted-foreground">
                Drop high-visibility colored pins to map community security portals, clubhouses, and recreational parks.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 py-2 text-xs">
          {/* 1. Pin Category Selector (Green Security Gate / Red Community Center / Blue Park) */}
          <div className="space-y-2">
            <Label className="text-xs font-semibold text-foreground">
              Select Pin Type &amp; Color (Required)
            </Label>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
              {/* Green Pin: Security Gate */}
              <div
                onClick={() => handleSelectCategory('Security Gate')}
                className={`p-3 rounded-xl border-2 cursor-pointer transition-all flex flex-col justify-between ${
                  category === 'Security Gate'
                    ? 'border-emerald-500 bg-emerald-500/10 shadow-sm ring-1 ring-emerald-500'
                    : 'border-border bg-card hover:border-emerald-500/40 hover:bg-muted/30'
                }`}
              >
                <div className="flex items-center justify-between mb-1.5">
                  <span className="p-1.5 rounded-lg bg-emerald-500 text-white shadow-sm">
                    <Shield className="h-4 w-4" />
                  </span>
                  <Badge variant="outline" className="text-[9px] font-mono border-emerald-500/40 text-emerald-600 dark:text-emerald-400 bg-emerald-500/10">
                    GREEN PIN
                  </Badge>
                </div>
                <strong className="text-xs text-foreground block">Security Gate</strong>
                <span className="text-[10px] text-muted-foreground mt-0.5 block leading-tight">
                  Guardhouse, barrier arms, RFID access portal.
                </span>
              </div>

              {/* Red Pin: Community Center */}
              <div
                onClick={() => handleSelectCategory('Community Center')}
                className={`p-3 rounded-xl border-2 cursor-pointer transition-all flex flex-col justify-between ${
                  category === 'Community Center'
                    ? 'border-rose-500 bg-rose-500/10 shadow-sm ring-1 ring-rose-500'
                    : 'border-border bg-card hover:border-rose-500/40 hover:bg-muted/30'
                }`}
              >
                <div className="flex items-center justify-between mb-1.5">
                  <span className="p-1.5 rounded-lg bg-rose-500 text-white shadow-sm">
                    <Building2 className="h-4 w-4" />
                  </span>
                  <Badge variant="outline" className="text-[9px] font-mono border-rose-500/40 text-rose-600 dark:text-rose-400 bg-rose-500/10">
                    RED PIN
                  </Badge>
                </div>
                <strong className="text-xs text-foreground block">Community Center</strong>
                <span className="text-[10px] text-muted-foreground mt-0.5 block leading-tight">
                  Clubhouse, HOA office, lounge, meeting pavilion.
                </span>
              </div>

              {/* Blue Pin: Park */}
              <div
                onClick={() => handleSelectCategory('Park')}
                className={`p-3 rounded-xl border-2 cursor-pointer transition-all flex flex-col justify-between ${
                  category === 'Park'
                    ? 'border-blue-500 bg-blue-500/10 shadow-sm ring-1 ring-blue-500'
                    : 'border-border bg-card hover:border-blue-500/40 hover:bg-muted/30'
                }`}
              >
                <div className="flex items-center justify-between mb-1.5">
                  <span className="p-1.5 rounded-lg bg-blue-500 text-white shadow-sm">
                    <Trees className="h-4 w-4" />
                  </span>
                  <Badge variant="outline" className="text-[9px] font-mono border-blue-500/40 text-blue-600 dark:text-blue-400 bg-blue-500/10">
                    BLUE PIN
                  </Badge>
                </div>
                <strong className="text-xs text-foreground block">Parks &amp; Recreation</strong>
                <span className="text-[10px] text-muted-foreground mt-0.5 block leading-tight">
                  Central park, playground, tennis courts, trails.
                </span>
              </div>
            </div>
          </div>

          {/* 2. Pin Title with Quick Suggestions */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label className="text-xs font-semibold text-foreground">Pin Title / Identifier</Label>
              <span className="text-[10px] text-muted-foreground">Click quick suggestion or type custom</span>
            </div>
            <Input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={`e.g. ${config.presetNames[0]}`}
              className="h-8 text-xs font-medium bg-background"
              required
            />
            {/* Quick Name Chips */}
            <div className="flex items-center gap-1.5 flex-wrap pt-0.5">
              {config.presetNames.map((preset, idx) => (
                <button
                  key={idx}
                  type="button"
                  onClick={() => setName(preset)}
                  className="text-[10px] px-2 py-0.5 rounded-full border bg-muted/40 hover:bg-muted transition-colors text-muted-foreground hover:text-foreground"
                >
                  + {preset.split('&')[0].trim()}
                </button>
              ))}
            </div>
          </div>

          {/* 3. Description Field */}
          <div className="space-y-1.5">
            <Label className="text-xs font-semibold text-foreground">Description &amp; Access Notes (Optional)</Label>
            <Textarea
              rows={2}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder={config.descriptionPlaceholder}
              className="text-xs leading-relaxed"
            />
          </div>

          {/* 4. Coordinate Location Inputs & Google Maps Paste */}
          <div className="p-3 rounded-xl border bg-muted/20 space-y-2.5">
            <div className="flex items-center justify-between">
              <Label className="text-xs font-semibold text-foreground flex items-center gap-1.5">
                <Crosshair className="h-3.5 w-3.5 text-primary" />
                <span>Geographical Coordinates</span>
              </Label>
              {initialCoordinates && (
                <Badge variant="outline" className="text-[9px] font-mono border-primary/40 text-primary">
                  Pre-filled from map click
                </Badge>
              )}
            </div>

            {/* Google Maps Fast Paste Bar */}
            <div className="flex gap-1.5">
              <Input
                type="text"
                value={pasteInput}
                onChange={(e) => setPasteInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    handleApplyPaste();
                  }
                }}
                placeholder="Paste from Google Maps (e.g. 18.44269, -77.91219)"
                className="h-8 text-xs font-mono bg-background"
              />
              <Button
                type="button"
                size="sm"
                variant="secondary"
                onClick={handleApplyPaste}
                disabled={!pasteInput.trim()}
                className="h-8 px-3 text-xs font-semibold shrink-0"
              >
                Parse
              </Button>
            </div>

            {/* Exact Lat / Lng Grid */}
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label className="text-[10px] text-muted-foreground font-mono">Latitude (°N)</Label>
                <Input
                  type="text"
                  inputMode="decimal"
                  value={latInput}
                  onChange={(e) => setLatInput(e.target.value)}
                  placeholder="e.g. 18.475000"
                  className="h-8 text-xs font-mono bg-background"
                  required
                />
              </div>
              <div>
                <Label className="text-[10px] text-muted-foreground font-mono">Longitude (°W)</Label>
                <Input
                  type="text"
                  inputMode="decimal"
                  value={lngInput}
                  onChange={(e) => setLngInput(e.target.value)}
                  placeholder="e.g. -77.925700"
                  className="h-8 text-xs font-mono bg-background"
                  required
                />
              </div>
            </div>
          </div>

          {/* 5. Live Pin Preview Card */}
          <div className="p-3 rounded-xl border bg-card flex items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              {/* Dynamic SVG Pin Preview */}
              <div className="relative flex flex-col items-center">
                <svg width="34" height="42" viewBox="0 0 34 42" fill="none" xmlns="http://www.w3.org/2000/svg" style={{ filter: 'drop-shadow(0 3px 5px rgba(0,0,0,0.35))' }}>
                  <path d="M17 0C7.61116 0 0 7.61116 0 17C0 27.5 14.5 40.5 16.2 42C16.6 42.4 17.4 42.4 17.8 42C19.5 40.5 34 27.5 34 17C34 7.61116 26.3888 0 17 0Z" fill={config.color} stroke="#FFFFFF" strokeWidth="1.2" />
                  <circle cx="17" cy="16" r="7.5" fill="#FFFFFF" />
                  {category === 'Security Gate' && (
                    <path d="M17 10.5L13 12.5V15.7C13 18.2 14.7 20.5 17 21.3C19.3 20.5 21 18.2 21 15.7V12.5L17 10.5Z" fill={config.color} />
                  )}
                  {category === 'Community Center' && (
                    <path d="M13 21V13.8L17 10.5L21 13.8V21H18V16.2H16V21H13Z" fill={config.color} />
                  )}
                  {category === 'Park' && (
                    <path d="M17 10.5L13.5 14.5H15.2L13 17.5H15.8V21H18.2V17.5H21L18.8 14.5H20.5L17 10.5Z" fill={config.color} />
                  )}
                </svg>
              </div>

              <div>
                <div className="flex items-center gap-1.5">
                  <span className="font-bold text-xs text-foreground">{name || 'Unnamed Landmark'}</span>
                  <Badge 
                    variant="outline" 
                    className="text-[9px] font-mono font-bold" 
                    style={{ borderColor: `${config.color}60`, color: config.color, backgroundColor: `${config.color}15` }}
                  >
                    {config.badgeLabel}
                  </Badge>
                </div>
                <div className="text-[10px] font-mono text-muted-foreground mt-0.5">
                  Coordinates: {latInput || '0.000000'}°, {lngInput || '0.000000'}°
                </div>
              </div>
            </div>

            <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              Map Preview
            </span>
          </div>

          <DialogFooter className="gap-2 sm:gap-0 pt-2">
            <Button type="button" variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button 
              type="submit" 
              size="sm" 
              className="gap-1.5 font-bold shadow-md text-white"
              style={{ backgroundColor: config.color }}
            >
              <Check className="h-4 w-4" />
              <span>Add {config.label} Pin to Map</span>
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
