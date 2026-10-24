import { Check, Palette, RotateCcw, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useBranding, THEME_PRESETS, type ThemePreset } from '@/context/branding-context';
import { cn } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';

interface ProfileThemeSelectorProps {
    className?: string;
}

export function ProfileThemeSelector({ className }: ProfileThemeSelectorProps) {
    const { branding, setUserThemePreset } = useBranding();
    const { toast } = useToast();

    const presets = Object.keys(THEME_PRESETS) as ThemePreset[];
    const isUsingCommunityDefault = !branding.userThemePreset;
    const currentActive = branding.activePreset || branding.themePreset;

    const handleSelectPreset = (preset: ThemePreset | null) => {
        setUserThemePreset(preset);
        if (preset) {
            toast({
                title: "Personal Theme Updated",
                description: `Your profile dashboard colors updated to the ${preset.charAt(0).toUpperCase() + preset.slice(1)} preset.`,
            });
        } else {
            toast({
                title: "Synchronized with Community",
                description: `Your dashboard colors now follow the ${branding.communityName} default preset (${branding.themePreset}).`,
            });
        }
    };

    return (
        <Card className={cn("border-border/80 shadow-sm", className)}>
            <CardHeader className="pb-4">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div className="space-y-1">
                        <CardTitle className="text-xl font-bold flex items-center gap-2">
                            <Palette className="w-5 h-5 text-primary" />
                            Personal Theme & Appearance
                        </CardTitle>
                        <CardDescription>
                            Customize the color palette for your personal dashboard and navigation, or follow {branding.communityName}'s default branding.
                        </CardDescription>
                    </div>

                    <div className="flex items-center gap-2">
                        {isUsingCommunityDefault ? (
                            <Badge variant="secondary" className="px-2.5 py-1 text-xs font-medium border border-border/60">
                                <Sparkles className="w-3.5 h-3.5 mr-1 text-primary" />
                                Following Estate ({branding.themePreset})
                            </Badge>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => handleSelectPreset(null)}
                                className="h-8 text-xs font-medium gap-1.5"
                            >
                                <RotateCcw className="w-3.5 h-3.5" />
                                Reset to Default
                            </Button>
                        )}
                    </div>
                </div>
            </CardHeader>

            <CardContent className="space-y-6 pt-0">
                {/* 15 Theme Presets Grid */}
                <div className="space-y-3">
                    <div className="flex items-center justify-between text-xs font-semibold text-muted-foreground">
                        <span>Select Your Preset Palette (15 Curated Schemes)</span>
                        <span>Active: <span className="text-foreground capitalize">{currentActive}</span></span>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2.5">
                        {presets.map((preset) => {
                            const colors = THEME_PRESETS[preset];
                            const isSelected = !isUsingCommunityDefault && branding.userThemePreset === preset;
                            const isInheritedActive = isUsingCommunityDefault && branding.themePreset === preset;

                            return (
                                <button
                                    key={preset}
                                    type="button"
                                    onClick={() => handleSelectPreset(preset)}
                                    style={{
                                        boxShadow: isSelected
                                            ? `0 6px 16px -4px hsl(${colors.primary} / 0.45)`
                                            : undefined,
                                    }}
                                    className={cn(
                                        "relative flex flex-col items-center justify-center p-3 rounded-xl border bg-card hover:bg-accent/5 hover:scale-[1.03] hover:-translate-y-0.5 active:scale-[0.98] transition-all duration-200 text-center gap-2.5 group",
                                        isSelected
                                            ? "border-primary ring-2 ring-primary/20 bg-primary/5"
                                            : isInheritedActive
                                                ? "border-primary/50 border-dashed"
                                                : "border-border hover:border-muted-foreground/30"
                                    )}
                                >
                                    {isSelected && (
                                        <div className="absolute top-1.5 right-1.5 flex items-center justify-center w-4 h-4 rounded-full bg-primary text-primary-foreground shadow-sm">
                                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                                        </div>
                                    )}

                                    {isInheritedActive && !isSelected && (
                                        <span className="absolute top-1.5 right-1.5 text-[9px] font-bold uppercase tracking-wider text-muted-foreground px-1 py-0.5 rounded bg-muted">
                                            Default
                                        </span>
                                    )}

                                    <div className="flex -space-x-1.5 mt-1">
                                        <span
                                            className="w-5 h-5 rounded-full border-2 border-background shadow-md transition-transform group-hover:scale-110"
                                            style={{ backgroundColor: `hsl(${colors.primary})` }}
                                            title={`Primary: ${colors.primary}`}
                                        />
                                        <span
                                            className="w-5 h-5 rounded-full border-2 border-background shadow-md transition-transform group-hover:scale-110"
                                            style={{ backgroundColor: `hsl(${colors.accent})` }}
                                            title={`Accent: ${colors.accent}`}
                                        />
                                    </div>

                                    <span className="text-xs font-semibold tracking-tight capitalize text-foreground">
                                        {preset}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Live Preview Component Bar */}
                <div className="p-4 rounded-xl border border-border/70 bg-muted/20 space-y-3">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-semibold text-foreground flex items-center gap-1.5">
                            <Sparkles className="w-3.5 h-3.5 text-primary" />
                            Live Theme Preview
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {isUsingCommunityDefault
                                ? "Displaying community default tokens"
                                : "Displaying your customized personal tokens"}
                        </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button size="sm" className="font-medium shadow-sm">
                            Primary Action
                        </Button>
                        <Button size="sm" variant="outline" className="border-primary/30 text-primary hover:bg-primary/10">
                            Outline Button
                        </Button>
                        <Badge className="bg-primary/15 text-primary border-primary/25 font-semibold">
                            Primary Badge
                        </Badge>
                        <div
                            className="px-3 py-1 rounded-md text-xs font-semibold border shadow-sm"
                            style={{
                                backgroundColor: `hsl(${THEME_PRESETS[currentActive].accent} / 0.15)`,
                                color: `hsl(${THEME_PRESETS[currentActive].accent})`,
                                borderColor: `hsl(${THEME_PRESETS[currentActive].accent} / 0.3)`,
                            }}
                        >
                            Accent Highlight
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
