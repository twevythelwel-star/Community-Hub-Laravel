import { useEffect, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { ThemeCustomizer } from '@/components/dashboard/theme-customizer';
import { BrandingSettings } from '@/components/dashboard/branding-settings';
import { useMap } from '@/context/map-context';
import { useToast } from '@/hooks/use-toast';
import { Trash2, Plus } from 'lucide-react';

type Props = {
  preferences: {
    theme: 'light' | 'dark' | 'system';
    mapShowBoundary: boolean;
    mapShowLandmarks: boolean;
    notifyEmail: boolean;
    notifyPush: boolean;
    notifySms: boolean;
  };
  branding: {
    appName: string;
    logoUrl: string | null;
    primaryColor: string | null;
    accentColor: string | null;
    backgroundColor: string | null;
    defaultTheme: string;
    themeTokens: Record<string, unknown>;
  };
  can: { brand: boolean; manageBoundary: boolean };
};

export default function SettingsPage({ preferences, can }: Props) {
  const { coordinates, setCoordinates, isProvisional, version } = useMap();
  const { toast } = useToast();

  const [localCoords, setLocalCoords] = useState<[number, number][]>([]);
  const [isPublishing, setIsPublishing] = useState(false);

  useEffect(() => {
    setLocalCoords(coordinates);
  }, [coordinates]);

  /*
   * The three notification switches were uncontrolled — `defaultChecked` on the
   * first, nothing on the others — and "Save Preferences" had no handler. They
   * are now controlled and persisted to `user_preferences`.
   */
  const notifications = useForm({
    notify_email: preferences.notifyEmail,
    notify_push: preferences.notifyPush,
    notify_sms: preferences.notifySms,
  });

  const saveNotifications = () => {
    notifications.patch('/dashboard/settings', {
      preserveScroll: true,
      onSuccess: () =>
        toast({
          title: 'Preferences Saved',
          description: 'Your notification channels have been updated.',
        }),
      onError: () =>
        toast({
          variant: 'destructive',
          title: 'Could Not Save',
          description: 'Your notification preferences were not updated.',
        }),
    });
  };

  const savePreference = (key: 'map_show_boundary' | 'map_show_landmarks', value: boolean) => {
    router.patch('/dashboard/settings', { [key]: value }, { preserveScroll: true });
  };

  // ── Boundary editor ──────────────────────────────────────────────

  const handleCoordChange = (index: number, position: 'lat' | 'lon', value: string) => {
    const numValue = parseFloat(value);
    if (Number.isNaN(numValue)) return;

    const next = [...localCoords];
    next[index] = position === 'lat'
      ? [numValue, next[index][1]]
      : [next[index][0], numValue];

    setLocalCoords(next);
  };

  const addCoordinate = () => {
    // The schema stores at most 8 boundary points.
    if (localCoords.length >= 8) {
      toast({
        variant: 'destructive',
        title: 'Maximum 8 Points',
        description: 'A boundary supports up to 8 survey points.',
      });
      return;
    }

    const last = localCoords[localCoords.length - 1] || [18.47, -77.92];
    setLocalCoords([...localCoords, [last[0] + 0.0001, last[1] + 0.0001]]);
  };

  const removeCoordinate = (index: number) => {
    // BoundaryController requires 4 to 8 points, not 3 — the original allowed a
    // triangle that the server would then reject.
    if (localCoords.length <= 4) {
      toast({
        variant: 'destructive',
        title: 'Minimum 4 Points',
        description: 'A published boundary requires at least 4 coordinate points.',
      });
      return;
    }

    setLocalCoords(localCoords.filter((_, i) => i !== index));
  };

  const handlePublishBoundary = () => {
    if (localCoords.some((c) => Number.isNaN(c[0]) || Number.isNaN(c[1]))) {
      toast({
        variant: 'destructive',
        title: 'Invalid Input',
        description: 'All latitude and longitude values must be valid numbers.',
      });
      return;
    }

    if (localCoords.length < 4 || localCoords.length > 8) {
      toast({
        variant: 'destructive',
        title: 'Invalid Shape',
        description: 'A boundary needs between 4 and 8 coordinate points.',
      });
      return;
    }

    const outOfRange = localCoords.some(
      ([lat, lng]) => lat < -90 || lat > 90 || lng < -180 || lng > 180,
    );

    if (outOfRange) {
      toast({
        variant: 'destructive',
        title: 'Coordinate Range Error',
        description: 'Latitudes must be [-90, 90] and longitudes [-180, 180].',
      });
      return;
    }

    setIsPublishing(true);

    /*
     * Publishing is a server round-trip that re-validates the polygon, so the
     * confirmation waits for the response. The original wrote to localStorage
     * and toasted "Success" unconditionally — it could not fail.
     */
    setCoordinates(localCoords, {
      notes: `Published from Settings (${localCoords.length} vertices).`,
      onSuccess: () => {
        setIsPublishing(false);
        toast({
          title: 'Boundary Published',
          description: 'Every resident, map and gate now uses these coordinates.',
        });
      },
      onError: () => {
        setIsPublishing(false);
        toast({
          variant: 'destructive',
          title: 'Boundary Not Published',
          description: 'The server rejected this boundary. Check the point count and coordinates.',
        });
      },
    });
  };

  return (
    <DashboardLayout>
      <Head title="Settings" />

      <div className="grid gap-8 pb-12">
        <div>
          <h1 className="font-headline text-3xl font-bold">Settings</h1>
          <p className="text-muted-foreground">
            Manage your account and application preferences.
          </p>
        </div>

        {/*
          Branding and theme are estate-wide, so both are gated on `manageUsers`
          — the same gate SettingsController enforces. The original showed them
          only to System Admin while the server accepted Admin too.
        */}
        {can.brand && (
          <>
            <Card>
              <CardHeader>
                <CardTitle>Branding</CardTitle>
                <CardDescription>
                  Customize the name and logo of your community application. Changes apply to
                  everyone.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <BrandingSettings />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Theme Customization</CardTitle>
                <CardDescription>
                  Set the community palette and typeface. These are shared settings, not
                  per-device preferences.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <ThemeCustomizer />
              </CardContent>
            </Card>
          </>
        )}

        {can.manageBoundary && (
          <Card>
            <CardHeader>
              <CardTitle>Map Configuration</CardTitle>
              <CardDescription>
                Define the community boundary as an ordered list of polygon coordinates.
                Points 1–4 are required; 5–8 are optional.
                {isProvisional
                  ? ' No boundary has been published yet — these are provisional defaults.'
                  : ` Currently published: version ${version}.`}
              </CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
              <div className="space-y-4">
                {localCoords.map((coord, index) => (
                  <div key={index} className="flex items-center gap-2">
                    <Label className="w-10 text-center text-muted-foreground">{index + 1}.</Label>
                    <div className="grid flex-1 grid-cols-2 gap-2">
                      <Input
                        type="number"
                        placeholder="Latitude"
                        value={coord[0]}
                        onChange={(e) => handleCoordChange(index, 'lat', e.target.value)}
                        step="any"
                        aria-label={`Point ${index + 1} latitude`}
                      />
                      <Input
                        type="number"
                        placeholder="Longitude"
                        value={coord[1]}
                        onChange={(e) => handleCoordChange(index, 'lon', e.target.value)}
                        step="any"
                        aria-label={`Point ${index + 1} longitude`}
                      />
                    </div>
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => removeCoordinate(index)}
                      disabled={localCoords.length <= 4}
                      aria-label={`Remove point ${index + 1}`}
                    >
                      <Trash2 className="h-4 w-4 text-destructive" />
                    </Button>
                  </div>
                ))}
              </div>

              <div className="flex flex-wrap gap-2 pt-2">
                <Button onClick={handlePublishBoundary} disabled={isPublishing}>
                  {isPublishing ? 'Publishing…' : 'Publish Boundary'}
                </Button>
                <Button
                  variant="outline"
                  onClick={addCoordinate}
                  disabled={localCoords.length >= 8}
                  className="gap-1.5"
                >
                  <Plus className="h-4 w-4" />
                  Add Coordinate
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Per-user map display toggles, saved immediately. */}
        <Card>
          <CardHeader>
            <CardTitle>Map Display</CardTitle>
            <CardDescription>How the community map looks for you.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between rounded-lg border p-4">
              <div className="space-y-0.5">
                <Label htmlFor="show-boundary" className="text-base">Show Boundary</Label>
                <p className="text-sm text-muted-foreground">
                  Draw the community perimeter on maps.
                </p>
              </div>
              <Switch
                id="show-boundary"
                defaultChecked={preferences.mapShowBoundary}
                onCheckedChange={(checked) => savePreference('map_show_boundary', checked)}
              />
            </div>

            <div className="flex items-center justify-between rounded-lg border p-4">
              <div className="space-y-0.5">
                <Label htmlFor="show-landmarks" className="text-base">Show Landmarks</Label>
                <p className="text-sm text-muted-foreground">
                  Display gates, clubhouses and parks as pins.
                </p>
              </div>
              <Switch
                id="show-landmarks"
                defaultChecked={preferences.mapShowLandmarks}
                onCheckedChange={(checked) => savePreference('map_show_landmarks', checked)}
              />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Notification Settings</CardTitle>
            <CardDescription>Choose how you want to be notified.</CardDescription>
          </CardHeader>

          <CardContent className="space-y-4">
            <div className="flex items-center justify-between rounded-lg border p-4">
              <div className="space-y-0.5">
                <Label htmlFor="email-notifications" className="text-base">
                  Email Notifications
                </Label>
                <p className="text-sm text-muted-foreground">
                  Receive updates and alerts via email.
                </p>
              </div>
              <Switch
                id="email-notifications"
                checked={notifications.data.notify_email}
                onCheckedChange={(checked) => notifications.setData('notify_email', checked)}
              />
            </div>

            <div className="flex items-center justify-between rounded-lg border p-4">
              <div className="space-y-0.5">
                <Label htmlFor="push-notifications" className="text-base">
                  Push Notifications
                </Label>
                <p className="text-sm text-muted-foreground">
                  Get instant alerts on your mobile device.
                </p>
              </div>
              <Switch
                id="push-notifications"
                checked={notifications.data.notify_push}
                onCheckedChange={(checked) => notifications.setData('notify_push', checked)}
              />
            </div>

            <div className="flex items-center justify-between rounded-lg border p-4">
              <div className="space-y-0.5">
                <Label htmlFor="sms-notifications" className="text-base">
                  SMS Notifications
                </Label>
                <p className="text-sm text-muted-foreground">
                  Receive critical alerts via text message.
                </p>
              </div>
              <Switch
                id="sms-notifications"
                checked={notifications.data.notify_sms}
                onCheckedChange={(checked) => notifications.setData('notify_sms', checked)}
              />
            </div>

            <Button onClick={saveNotifications} disabled={notifications.processing}>
              {notifications.processing ? 'Saving…' : 'Save Preferences'}
            </Button>

            <p className="text-xs text-muted-foreground">
              These choices are recorded against your account. Delivery itself needs a mail,
              push and SMS provider to be configured — see the README.
            </p>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
