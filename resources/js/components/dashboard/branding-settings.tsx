

import { useBranding, ThemePreset, THEME_PRESETS } from '@/context/branding-context';
import { router } from '@inertiajs/react';
import { Label } from '../ui/label';
import { Input } from '../ui/input';
import { Button } from '../ui/button';
import { Slider } from '../ui/slider';
import { useToast } from '@/hooks/use-toast';
import { ChangeEvent, useState } from 'react';
import { cn } from '@/lib/utils';
import { Check, Loader2, Save } from 'lucide-react';

export function BrandingSettings() {
  const { branding, setBranding, resetBranding } = useBranding();
  const { toast } = useToast();
  const [isSaving, setIsSaving] = useState(false);

  const handleAppNameChange = (e: ChangeEvent<HTMLInputElement>) => {
    setBranding({ appName: e.target.value });
  };

  const handleNameChange = (e: ChangeEvent<HTMLInputElement>) => {
    setBranding({ communityName: e.target.value });
  };

  const handleSave = () => {
    setIsSaving(true);
    router.patch(
      '/dashboard/settings',
      {
        app_name: branding.appName,
        community_name: branding.communityName,
        logo_url: branding.iconUrl,
        theme_tokens: {
          themePreset: branding.themePreset,
          iconSize: branding.iconSize,
          communityName: branding.communityName,
        },
      },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          setIsSaving(false);
          toast({
            title: "Settings Saved",
            description: `Community name updated to "${branding.communityName}". All documents and system screens will reflect this change.`,
          });
        },
        onError: () => {
          setIsSaving(false);
          toast({
            variant: "destructive",
            title: "Save Failed",
            description: "Unable to update community branding. Please check permissions.",
          });
        }
      }
    );
  };

  const handleIconUpload = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      if (file.size > 1024 * 1024) { // 1MB limit
        toast({
            variant: "destructive",
            title: "File Too Large",
            description: "Please upload an icon smaller than 1MB.",
        });
        return;
      }
      const reader = new FileReader();
      reader.onloadend = () => {
        setBranding({ iconUrl: reader.result as string });
      };
      reader.readAsDataURL(file);
    }
  };

  const handleSizeChange = (value: number[]) => {
    setBranding({ iconSize: value[0] });
  };
  
  const handleReset = () => {
    resetBranding();
    toast({
        title: "Branding Reset",
        description: "Community name and icon have been reset to their defaults.",
    })
  }

  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Label htmlFor="app-name">App Name</Label>
        <Input
          id="app-name"
          value={branding.appName}
          onChange={handleAppNameChange}
        />
        <p className="text-xs text-muted-foreground">The primary name of this application.</p>
      </div>

      <div className="space-y-2">
        <Label htmlFor="community-name">Community Name</Label>
        <Input
          id="community-name"
          value={branding.communityName}
          onChange={handleNameChange}
        />
        <p className="text-xs text-muted-foreground">The name of the residential community.</p>
      </div>

      <div className="space-y-2">
        <Label>Theme Preset</Label>
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
          {(Object.keys(THEME_PRESETS) as ThemePreset[]).map((preset) => {
            const colors = THEME_PRESETS[preset];
            const isActive = branding.themePreset === preset;
            return (
              <button
                key={preset}
                type="button"
                onClick={() => setBranding({ themePreset: preset })}
                style={{
                  boxShadow: isActive ? `0 6px 16px -4px hsl(${colors.primary} / 0.4)` : undefined
                }}
                className={cn(
                  "relative flex flex-col items-center justify-center p-4 rounded-xl border bg-card hover:bg-accent/5 hover:scale-[1.03] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 text-center gap-3",
                  isActive ? "border-primary ring-2 ring-primary/10 scale-[1.02]" : "border-border hover:border-muted-foreground/30"
                )}
              >
                {isActive && (
                  <div className="absolute top-1.5 right-1.5 flex items-center justify-center w-4 h-4 rounded-full bg-primary text-primary-foreground shadow-sm">
                    <Check className="w-2.5 h-2.5 stroke-[3]" />
                  </div>
                )}
                <div className="flex -space-x-1.5">
                  <span 
                    className="w-5 h-5 rounded-full border-2 border-background shadow-md" 
                    style={{ backgroundColor: `hsl(${colors.primary})` }}
                  />
                  <span 
                    className="w-5 h-5 rounded-full border-2 border-background shadow-md" 
                    style={{ backgroundColor: `hsl(${colors.accent})` }}
                  />
                </div>
                <span className="text-[11px] font-semibold tracking-wide capitalize">{preset}</span>
              </button>
            );
          })}
        </div>
        <p className="text-xs text-muted-foreground">Select a curated color palette for the application branding (15 palettes available).</p>
      </div>

      <div className="space-y-2">
        <Label htmlFor="icon-upload">Community Icon</Label>
        <Input 
          id="icon-upload" 
          type="file" 
          accept="image/png, image/jpeg, image/svg+xml, image/gif"
          onChange={handleIconUpload} 
        />
        <p className="text-xs text-muted-foreground">Upload a square image (PNG, JPG, SVG). Max 1MB.</p>
      </div>

      <div className="space-y-2">
        <Label>Icon Size</Label>
        <div className="flex items-center gap-4">
            <Slider
                value={[branding.iconSize]}
                onValueChange={handleSizeChange}
                min={24} // ~1 inch at 96dpi
                max={72} // ~3 inches at 96dpi, capped for layout sanity
                step={1}
            />
            <span className="text-sm text-muted-foreground w-12 text-right">{branding.iconSize}px</span>
        </div>
      </div>
      
      <div className="flex items-center gap-3 pt-2">
        <Button onClick={handleSave} disabled={isSaving} className="flex items-center gap-2">
          {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          <span>{isSaving ? "Saving..." : "Save Changes"}</span>
        </Button>
        <Button onClick={handleReset} variant="outline" disabled={isSaving}>
          Reset to Defaults
        </Button>
      </div>
    </div>
  );
}
