import { useState, useEffect } from "react";
import { Head } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import type { Business, FoodApp, Voucher } from "@/types";
import { useToast } from "@/hooks/use-toast";
import { PlusCircle, Trash2, Tag, Utensils, CheckCircle, ExternalLink } from "lucide-react";
import { FoodAppForm } from "@/components/dashboard/food-app-form";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { VoucherForm } from "@/components/dashboard/voucher-form";
import { submit } from "@/lib/submit";

type Props = {
  businesses: {
    id: number;
    name: string;
    logoUrl: string | null;
    aiHint: string | null;
    vouchers: {
      id: number;
      title: string;
      description: string;
      expiresAt: string | null;
    }[];
  }[];
  foodApps: {
    id: number;
    name: string;
    logoUrl: string | null;
    websiteUrl: string;
    aiHint: string | null;
    couponPercentage: number | null;
  }[];
  canManage: boolean;
};

function VoucherCard({
  voucher,
  business,
  canManage,
  isClipped,
  onClip,
  onDelete,
}: {
  voucher: { id: number; title: string; description: string; expiresAt: string | null };
  business?: { id: number; name: string };
  canManage: boolean;
  isClipped: boolean;
  onClip: (voucherId: number, title: string, businessName?: string) => void;
  onDelete: (id: number) => void;
}) {
  return (
    <Card className="flex flex-col h-full relative transition-all duration-200 hover:shadow-md border-border/80">
      <CardHeader>
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-10 h-10 rounded-full bg-primary/10 text-primary font-bold text-sm flex-shrink-0">
              {business ? business.name.charAt(0) : "V"}
            </div>
            <div>
              <CardTitle className="text-base font-semibold leading-tight">{voucher.title}</CardTitle>
              <CardDescription className="text-xs mt-0.5">{business?.name ?? "Community Partner"}</CardDescription>
            </div>
          </div>
          {canManage && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 text-muted-foreground hover:text-destructive absolute top-3 right-3"
                >
                  <Trash2 className="h-4 w-4" />
                  <span className="sr-only">Delete voucher</span>
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Delete Voucher?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This action will permanently delete &ldquo;{voucher.title}&rdquo; from active community vouchers.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={() => onDelete(voucher.id)}
                    className="bg-destructive hover:bg-destructive/90"
                  >
                    Delete
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </CardHeader>
      <CardContent className="flex-grow pb-4">
        <p className="text-sm text-muted-foreground leading-relaxed">{voucher.description}</p>
        {voucher.expiresAt && (
          <p className="text-xs text-muted-foreground/75 mt-3">
            Valid until {new Date(voucher.expiresAt).toLocaleDateString()}
          </p>
        )}
      </CardContent>
      <CardContent className="pt-0">
        <Button
          onClick={() => onClip(voucher.id, voucher.title, business?.name)}
          variant={isClipped ? "secondary" : "default"}
          className="w-full gap-2 text-sm font-medium"
          disabled={isClipped}
        >
          {isClipped ? (
            <>
              <CheckCircle className="w-4 h-4 text-emerald-600" />
              Clipped to Profile
            </>
          ) : (
            <>
              <Tag className="w-4 h-4" />
              Clip Voucher
            </>
          )}
        </Button>
      </CardContent>
    </Card>
  );
}

function FoodAppCard({
  app,
  onRemove,
  canManage,
}: {
  app: Props["foodApps"][number];
  onRemove: (id: number) => void;
  canManage: boolean;
}) {
  return (
    <Card className="transition-all duration-200 hover:shadow-sm">
      <CardContent className="pt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <div className="flex items-center justify-center w-12 h-12 rounded-xl bg-orange-500/10 text-orange-600 font-bold text-lg flex-shrink-0 border border-orange-500/20">
            {app.name.charAt(0)}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <p className="font-semibold text-base">{app.name}</p>
              {app.couponPercentage ? (
                <Badge variant="destructive" className="text-xs font-semibold px-2 py-0.5">
                  {app.couponPercentage}% OFF
                </Badge>
              ) : null}
            </div>
            <p className="text-xs text-muted-foreground mt-0.5 truncate max-w-xs">{app.websiteUrl}</p>
          </div>
        </div>
        <div className="flex items-center gap-2 self-end sm:self-auto">
          <a href={app.websiteUrl} target="_blank" rel="noopener noreferrer">
            <Button variant="outline" size="sm" className="gap-1.5">
              Order Now
              <ExternalLink className="w-3.5 h-3.5" />
            </Button>
          </a>
          {canManage && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="ghost" size="icon" className="h-9 w-9 text-muted-foreground hover:text-destructive">
                  <Trash2 className="h-4 w-4" />
                  <span className="sr-only">Remove delivery option</span>
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Remove Service?</AlertDialogTitle>
                  <AlertDialogDescription>
                    This will remove &ldquo;{app.name}&rdquo; from the estate&apos;s food delivery options.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={() => onRemove(app.id)}
                    className="bg-destructive hover:bg-destructive/90"
                  >
                    Remove
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

export default function DealsPage({ businesses, foodApps, canManage }: Props) {
  const { toast } = useToast();
  const [clippedIds, setClippedIds] = useState<number[]>([]);
  const [isAddAppOpen, setIsAddAppOpen] = useState(false);
  const [isVoucherFormOpen, setVoucherFormOpen] = useState(false);

  useEffect(() => {
    try {
      const stored = localStorage.getItem("community_hub_clipped_vouchers");
      if (stored) {
        setClippedIds(JSON.parse(stored));
      }
    } catch {
      // Fallback
    }
  }, []);

  const handleClipVoucher = (voucherId: number, title: string, businessName?: string) => {
    const next = [...clippedIds, voucherId];
    setClippedIds(next);
    try {
      localStorage.setItem("community_hub_clipped_vouchers", JSON.stringify(next));
    } catch {
      // Fallback
    }
    toast({
      title: "Voucher Clipped!",
      description: `"${title}" from ${businessName ?? "Partner"} has been saved to your profile.`,
    });
  };

  const handleAddFoodApp = (newApp: Omit<FoodApp, "id">) => {
    submit("post", "/dashboard/deals/food-apps", {
      name: newApp.name,
      website_url: newApp.websiteUrl,
      logo_url: newApp.logoUrl || null,
      ai_hint: newApp.aiHint || null,
      coupon_percentage: newApp.couponPercentage ?? null,
    }).then(
      () => toast({ title: "Service Added", description: `${newApp.name} is now available in Food Delivery.` }),
      () => toast({ variant: "destructive", title: "Error", description: "Failed to add delivery service." })
    );
  };

  const handleRemoveFoodApp = (id: number) => {
    submit("delete", `/dashboard/deals/food-apps/${id}`).then(
      () => toast({ title: "Service Removed", description: "Delivery option has been removed." }),
      () => toast({ variant: "destructive", title: "Error", description: "Could not remove delivery option." })
    );
  };

  const handleSaveVoucher = (newVoucherData: Omit<Voucher, "id">) => {
    submit("post", "/dashboard/deals/vouchers", {
      business_id: Number(newVoucherData.businessId),
      title: newVoucherData.title,
      description: newVoucherData.description,
      expires_at: newVoucherData.expiresAt || null,
    }).then(
      () => toast({ title: "Voucher Published", description: `"${newVoucherData.title}" has been published.` }),
      () => toast({ variant: "destructive", title: "Error", description: "Failed to create voucher." })
    );
  };

  const handleDeleteVoucher = (id: number) => {
    submit("delete", `/dashboard/deals/vouchers/${id}`).then(
      () => toast({ title: "Voucher Deleted", description: "Voucher has been permanently deleted." }),
      () => toast({ variant: "destructive", title: "Error", description: "Could not delete voucher." })
    );
  };

  // Flatten active vouchers from all active businesses
  const allVouchers = businesses.flatMap((b) =>
    (b.vouchers || []).map((v) => ({
      ...v,
      business: { id: b.id, name: b.name },
    }))
  );

  // Prepare business list for the voucher modal
  const businessOptions: Business[] = businesses.map((b) => ({
    id: String(b.id),
    name: b.name,
    logoUrl: b.logoUrl,
    aiHint: b.aiHint,
  }));

  return (
    <DashboardLayout>
      <Head title="Perks & Savings" />
      <div className="grid gap-8 max-w-7xl mx-auto pb-12">
        <div>
          <h1 className="font-headline text-3xl font-bold tracking-tight">Perks &amp; Savings</h1>
          <p className="text-muted-foreground mt-1">
            Exclusive community privileges, resident partner discounts, and local food delivery.
          </p>
        </div>

        {/* Community Vouchers */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-4">
            <div>
              <div className="flex items-center gap-2">
                <Tag className="w-5 h-5 text-primary" />
                <CardTitle className="text-xl">Community Vouchers</CardTitle>
              </div>
              <CardDescription className="mt-1">
                Verified discounts for estate residents from approved local partners.
              </CardDescription>
            </div>
            {canManage && businessOptions.length > 0 && (
              <VoucherForm
                open={isVoucherFormOpen}
                onOpenChange={setVoucherFormOpen}
                onSave={handleSaveVoucher}
                businesses={businessOptions}
              >
                <Button size="sm" className="gap-1.5 shadow-sm">
                  <PlusCircle className="h-4 w-4" />
                  <span>Add Voucher</span>
                </Button>
              </VoucherForm>
            )}
          </CardHeader>
          <CardContent>
            {allVouchers.length === 0 ? (
              <div className="py-12 text-center border rounded-xl border-dashed border-muted-foreground/30 bg-muted/20">
                <Tag className="w-10 h-10 text-muted-foreground/40 mx-auto mb-3" />
                <p className="text-sm font-medium text-foreground">No active vouchers available</p>
                <p className="text-xs text-muted-foreground mt-1">
                  Community partner promotions and savings will appear here once published.
                </p>
              </div>
            ) : (
              <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                {allVouchers.map((voucher) => (
                  <VoucherCard
                    key={voucher.id}
                    voucher={voucher}
                    business={voucher.business}
                    canManage={canManage}
                    isClipped={clippedIds.includes(voucher.id)}
                    onClip={handleClipVoucher}
                    onDelete={handleDeleteVoucher}
                  />
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Food Delivery Services */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-4">
            <div>
              <div className="flex items-center gap-2">
                <Utensils className="w-5 h-5 text-orange-500" />
                <CardTitle className="text-xl">Food Delivery</CardTitle>
              </div>
              <CardDescription className="mt-1">
                Direct ordering through trusted delivery partners servicing the community.
              </CardDescription>
            </div>
            {canManage && (
              <FoodAppForm onOpenChange={setIsAddAppOpen} open={isAddAppOpen} onSave={handleAddFoodApp}>
                <Button size="sm" className="gap-1.5 shadow-sm">
                  <PlusCircle className="h-4 w-4" />
                  <span>Add Service</span>
                </Button>
              </FoodAppForm>
            )}
          </CardHeader>
          <CardContent>
            {foodApps.length === 0 ? (
              <div className="py-12 text-center border rounded-xl border-dashed border-muted-foreground/30 bg-muted/20">
                <Utensils className="w-10 h-10 text-muted-foreground/40 mx-auto mb-3" />
                <p className="text-sm font-medium text-foreground">No food delivery services configured</p>
                <p className="text-xs text-muted-foreground mt-1">
                  Estate-approved dining delivery services will be listed here.
                </p>
              </div>
            ) : (
              <div className="grid gap-4">
                {foodApps.map((app) => (
                  <FoodAppCard
                    key={app.id}
                    app={app}
                    onRemove={handleRemoveFoodApp}
                    canManage={canManage}
                  />
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
