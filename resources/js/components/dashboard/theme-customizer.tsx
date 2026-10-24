

import { useTheme } from '@/context/theme-context';
import { Label } from '../ui/label';
import { Input } from '../ui/input';
import { Button } from '../ui/button';
import { Card, CardContent } from '../ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select';

export function ThemeCustomizer() {
  const { theme, setTheme, resetTheme, availableFonts } = useTheme();

  const handleColorChange = (variable: string, value: string) => {
    setTheme({ ...theme, [variable]: value });
  };

  const handleFontChange = (font: string) => {
    setTheme({ ...theme, font });
  };

  return (
    <div className="space-y-6">
       <div className="space-y-4">
        <h4 className="font-medium">Colors</h4>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="space-y-2">
            <Label htmlFor="primary">Primary Color</Label>
            <Input
                id="primary"
                type="color"
                value={theme.primary}
                onChange={(e) => handleColorChange('primary', e.target.value)}
                className="h-10 p-1"
            />
            </div>
            <div className="space-y-2">
            <Label htmlFor="background">Background Color</Label>
            <Input
                id="background"
                type="color"
                value={theme.background}
                onChange={(e) => handleColorChange('background', e.target.value)}
                className="h-10 p-1"
            />
            </div>
            <div className="space-y-2">
            <Label htmlFor="accent">Accent Color</Label>
            <Input
                id="accent"
                type="color"
                value={theme.accent}
                onChange={(e) => handleColorChange('accent', e.target.value)}
                className="h-10 p-1"
            />
            </div>
        </div>
      </div>
      
       <div className="space-y-4">
            <h4 className="font-medium">Font</h4>
            <div className="grid gap-2 max-w-sm">
                <Label>Font Family</Label>
                <Select value={theme.font} onValueChange={handleFontChange}>
                    <SelectTrigger>
                        <SelectValue placeholder="Select a font" />
                    </SelectTrigger>
                    <SelectContent>
                        {availableFonts.map(font => (
                             <SelectItem key={font} value={font} style={{fontFamily: font}}>{font}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
       </div>


      <div className="space-y-2">
        <Label>Live Preview</Label>
        <Card style={{ 
            backgroundColor: theme.background, 
            borderColor: `color-mix(in srgb, ${theme.primary} 40%, #888888)`,
            fontFamily: theme.font,
        }}>
            <CardContent className="p-4">
                <div className="flex justify-between items-center">
                    <h3 style={{ color: `color-mix(in srgb, ${theme.primary} 90%, black)`}} className="font-bold text-lg">Example Card</h3>
                    <Button style={{ backgroundColor: theme.primary, color: '#ffffff' }}>A Button</Button>
                </div>
                 <p style={{ color: `color-mix(in srgb, ${theme.primary} 70%, #555555)` }} className="text-sm mt-2">This is a preview of the new theme. Use the controls above to see your changes live.</p>
                 <div className="flex gap-2 mt-4">
                    <div style={{backgroundColor: theme.accent}} className="w-8 h-8 rounded-full" />
                    <div style={{backgroundColor: `color-mix(in srgb, ${theme.accent} 70%, white)`}} className="w-8 h-8 rounded-full" />
                    <div style={{backgroundColor: `color-mix(in srgb, ${theme.accent} 40%, white)`}} className="w-8 h-8 rounded-full" />
                 </div>
            </CardContent>
        </Card>
      </div>

      <div className="flex gap-2">
        <Button onClick={resetTheme} variant="outline">
          Reset to Defaults
        </Button>
      </div>
    </div>
  );
}
