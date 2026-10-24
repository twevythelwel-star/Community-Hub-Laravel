

import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { WelcomeAnimation } from '@/components/welcome-animation';

import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuItem,
  SidebarMenuButton,
  SidebarMenuSub,
  SidebarMenuSubItem,
  SidebarMenuSubButton,
  SidebarProvider,
  SidebarTrigger,
} from '@/components/ui/sidebar';
import {
  Collapsible,
  CollapsibleTrigger,
  CollapsibleContent,
} from '@/components/ui/collapsible';
import {
  Bell,
  BookUser,
  Building2,
  Calendar,
  ChevronDown,
  ClipboardCheck,
  ClipboardList,
  CreditCard,
  Gift,
  HeartHandshake,
  History,
  Home,
  KeyRound,
  Map,
  MessageSquarePlus,
  Moon,
  PiggyBank,
  QrCode,
  Search,
  Settings,
  ShieldOff,
  Siren,
  LifeBuoy,
  Sun,
  User,
  UserCheck,
  UserCog,
  UserX,
  Users,
  BadgeCheck,
  ListTree,
  ShieldCheck,
  Scan,
  Wallet,
  Car,
  SquareParking,
  ShieldAlert,
  LogOut,
  Landmark,
  FileText,
} from 'lucide-react';
import { useDarkMode } from '@/hooks/use-dark-mode';
import { Logo } from '@/components/logo';
import { UserNav } from '@/components/user-nav';
import { Input } from '@/components/ui/input';
import { useAuth } from '@/context/auth-context';
import type { UserRole } from '@/types';
import { useIsClient } from '@/hooks/use-is-client';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { MyGatePassDialog } from '@/components/dashboard/my-gate-pass-dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Toaster } from '@/components/ui/toaster';
import { FlashToaster } from '@/components/flash-toaster';
import { PasswordConfirmationDialog } from '@/components/password-confirmation-dialog';
import { BrandingProvider } from '@/context/branding-context';
import { ThemeProvider } from '@/context/theme-context';
import { EmergencyBroadcastBanner } from '@/components/dashboard/emergency-broadcast-banner';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Plus } from "lucide-react";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  // Inertia exposes the current URL on the page object; `usePathname` was a
  // next/navigation hook and has no Inertia equivalent.
  const { url, props } = usePage();
  const pathname = url.split('?')[0];
  const branding = (props as Record<string, any>).branding;
  const communityName = branding?.communityName || branding?.themeTokens?.communityName;

  const { user, loading, logout, justSignedIn } = useAuth();
  const isClient = useIsClient();
  const [isPassOpen, setPassOpen] = useState(false);
  const { isDark, toggle: toggleDark } = useDarkMode();

  const [showSplash, setShowSplash] = useState<boolean>(() => {
    if (typeof window === 'undefined') return false;
    const sessionFlag = sessionStorage.getItem('play_community_splash') === 'true';
    if (sessionFlag) {
      sessionStorage.removeItem('play_community_splash');
      return true;
    }
    return false;
  });

  useEffect(() => {
    if (justSignedIn) {
      setShowSplash(true);
    }
  }, [justSignedIn]);

  /*
   * The original redirected to "/" from the client whenever no user was in
   * context. That guard is gone because it is no longer meaningful: the `auth`
   * middleware rejects the request before this layout renders, and if a session
   * expires mid-visit Laravel returns a redirect that Inertia follows on its
   * own. A client-side check could only ever hide the UI, never the data.
   */


  /*
   * `roles` above is the only thing that decides what a user sees.
   *
   * Two special cases used to sit here and both were wrong. The Staff branch
   * returned a hardcoded [Profile, Gate Pass] and discarded the `roles` arrays
   * entirely — so the deliberate additions of Staff to Notifications and to
   * Safety Alert, each with a comment above it explaining why Staff needed
   * them, were dead configuration that never reached a screen. The Security
   * branch recomputed the same filter under a second name and then appended
   * Profile "if missing", which it never was, because Profile's roles already
   * list Security. It was a no-op guarding against nothing.
   */
  const ownershipNav = (props as Record<string, any>).ownershipNav;
  const ownershipLabel = ownershipNav?.label || 'Ownership & Representatives';
  const ownershipType = ownershipNav?.type || 'company';
  const OwnershipIcon = ownershipType === 'company'
    ? Building2
    : ownershipType === 'individual'
    ? HeartHandshake
    : ownershipType === 'trust'
    ? Landmark
    : FileText;

interface NavSubItem {
  href: string;
  label: string;
  icon?: React.ElementType;
}

interface NavSection {
  title: string;
  href: string;
  icon: React.ElementType;
  subItems?: NavSubItem[];
}

  const navSections = useMemo((): NavSection[] => {
    if (!user) return [];

    const role = user.role;

    if (role === 'Security') {
      return [
        {
          title: 'Credentials & Gate',
          href: '/dashboard/gate-scanner',
          icon: Scan,
          subItems: [
            { href: '/dashboard/gate-scanner', label: 'Gate Scanner', icon: Scan },
            { href: '/dashboard/gate-pass', label: 'Gate Passes', icon: BadgeCheck },
            { href: '/dashboard/access-log', label: 'Access Activity Log', icon: ListTree },
            { href: '/dashboard/occupancy', label: "Who's On Property", icon: Users },
          ],
        },
        {
          title: 'Emergency & Safety',
          href: '/dashboard/safety',
          icon: ShieldAlert,
          subItems: [
            { href: '/dashboard/safety', label: 'Distress & Incidents', icon: ShieldAlert },
            { href: '/dashboard/silent-assistance', label: 'Silent Assistance', icon: LifeBuoy },
            { href: '/dashboard/warnings', label: 'Safety Advisories', icon: Siren },
            { href: '/dashboard/safety/credential', label: 'Credential Assistance', icon: BadgeCheck },
          ],
        },
        {
          title: 'People & Access',
          href: '/dashboard/visitors',
          icon: UserCheck,
          subItems: [
            { href: '/dashboard/visitors', label: 'Visitor Check-In', icon: User },
            { href: '/dashboard/block-list', label: 'Block List', icon: ShieldOff },
            { href: '/dashboard/access-governance', label: 'Access Governance', icon: ShieldAlert },
          ],
        },
        {
          title: 'Operations Dashboard',
          href: '/dashboard',
          icon: Home,
        },
        {
          title: 'Community & Map',
          href: '/dashboard/map',
          icon: Map,
          subItems: [
            { href: '/dashboard/map', label: 'Perimeter Map', icon: Map },
            { href: '/dashboard/guidelines', label: 'Guidelines', icon: BookUser },
          ],
        },
      ];
    }

    if (role === 'Staff') {
      return [
        {
          title: 'Dashboard',
          href: '/dashboard',
          icon: Home,
        },
        {
          title: 'Credentials & Gate',
          href: '/dashboard/gate-pass',
          icon: BadgeCheck,
          subItems: [
            { href: '/dashboard/gate-pass', label: 'My Credential', icon: BadgeCheck },
            { href: '/dashboard/wallet', label: 'Access Wallet', icon: Wallet },
          ],
        },
        {
          title: 'Emergency & Safety',
          href: '/dashboard/safety',
          icon: LifeBuoy,
          subItems: [
            { href: '/dashboard/safety', label: 'Safety Hub', icon: LifeBuoy },
            { href: '/dashboard/safety/credential', label: 'Credential Assistance', icon: LifeBuoy },
            { href: '/dashboard/warnings', label: 'Safety Alerts', icon: Siren },
          ],
        },
        {
          title: 'Community',
          href: '/dashboard/notifications',
          icon: Bell,
          subItems: [
            { href: '/dashboard/notifications', label: 'Notifications', icon: Bell },
            { href: '/dashboard/feedback', label: 'Submit Feedback', icon: MessageSquarePlus },
          ],
        },
      ];
    }

    if (role === 'Admin' || role === 'System Admin') {
      return [
        {
          title: 'Dashboard',
          href: '/dashboard',
          icon: Home,
          subItems: [
            { href: '/dashboard', label: 'Executive Overview', icon: Home },
            { href: '/dashboard/occupancy', label: "Who's On Property", icon: Users },
            { href: '/dashboard/ownership', label: ownershipLabel, icon: OwnershipIcon },
          ],
        },
        {
          title: 'People & Access',
          href: '/dashboard/delegation',
          icon: UserCheck,
          subItems: [
            { href: '/dashboard/delegation', label: 'People & Access Hub', icon: UserCheck },
            { href: '/dashboard/directory', label: 'Resident Directory', icon: Users },
            { href: '/dashboard/household', label: 'Family & Household', icon: HeartHandshake },
            { href: '/dashboard/renters', label: 'Renters', icon: KeyRound },
            { href: '/dashboard/visitors', label: 'Visitors', icon: User },
            { href: '/dashboard/block-list', label: 'Block List', icon: ShieldOff },
          ],
        },
        {
          title: 'Credentials & Gate',
          href: '/dashboard/gate-pass',
          icon: BadgeCheck,
          subItems: [
            { href: '/dashboard/gate-pass', label: 'Gate Passes & Policies', icon: BadgeCheck },
            { href: '/dashboard/gate-scanner', label: 'Gate Scanner', icon: Scan },
            { href: '/dashboard/access-governance', label: 'Access Governance', icon: ShieldAlert },
            { href: '/dashboard/access-log', label: 'Access Activity Log', icon: ListTree },
            { href: '/dashboard/vehicles', label: 'Vehicles & ANPR', icon: Car },
            { href: '/dashboard/parking', label: 'Parking Passes', icon: SquareParking },
          ],
        },
        {
          title: 'Emergency & Safety',
          href: '/dashboard/safety',
          icon: LifeBuoy,
          subItems: [
            { href: '/dashboard/safety', label: 'Emergency Command Hub', icon: LifeBuoy },
            { href: '/dashboard/silent-assistance', label: 'Silent Assistance Triage', icon: ShieldAlert },
            { href: '/dashboard/warnings', label: 'Safety Alerts & Broadcasts', icon: Siren },
          ],
        },
        {
          title: 'Payments & Community',
          href: '/dashboard/billing',
          icon: CreditCard,
          subItems: [
            { href: '/dashboard/billing', label: 'Dues & Ledger', icon: CreditCard },
            { href: '/dashboard/fundraising', label: 'Fundraising', icon: PiggyBank },
            { href: '/dashboard/map', label: 'Community Map', icon: Map },
            { href: '/dashboard/map?tab=boundary', label: 'Perimeter Boundary', icon: ShieldCheck },
            { href: '/dashboard/calendar', label: 'Calendar & Events', icon: Calendar },
            { href: '/dashboard/updates', label: 'Community Updates', icon: ClipboardList },
            { href: '/dashboard/guidelines', label: 'Guidelines', icon: BookUser },
            { href: '/dashboard/deals', label: 'Perks & Savings', icon: Gift },
          ],
        },
        {
          title: 'Administration',
          href: '/dashboard/settings',
          icon: Settings,
          subItems: [
            { href: '/dashboard/settings', label: 'System Settings', icon: Settings },
            { href: '/dashboard/review-feedback', label: 'Review Feedback', icon: ClipboardCheck },
            ...(role === 'System Admin'
              ? [{ href: '/dashboard/changelog', label: 'App Changelog', icon: History }]
              : []),
          ],
        },
      ];
    }

    // Default: Homeowner & Temporary Homeowner
    const homeownerDashboardItems: NavSubItem[] = [
      { href: '/dashboard', label: 'Property Overview', icon: Home },
      { href: '/dashboard/ownership', label: ownershipLabel, icon: OwnershipIcon },
    ];
    if (ownershipType === 'company') {
      homeownerDashboardItems.push({ href: '/dashboard/company', label: 'Company Properties', icon: Building2 });
    }
    homeownerDashboardItems.push({ href: '/dashboard/occupancy', label: "Who's On Property", icon: Users });

    return [
      {
        title: 'Dashboard',
        href: '/dashboard',
        icon: Home,
        subItems: homeownerDashboardItems,
      },
      {
        title: 'People & Access',
        href: '/dashboard/delegation',
        icon: UserCheck,
        subItems: [
          { href: '/dashboard/delegation', label: 'People & Delegates', icon: UserCheck },
          { href: '/dashboard/renters', label: 'Renters & Leases', icon: KeyRound },
          { href: '/dashboard/visitors', label: 'Expected Visitors', icon: User },
        ],
      },
      {
        title: 'Credentials & Gate',
        href: '/dashboard/wallet',
        icon: BadgeCheck,
        subItems: [
          { href: '/dashboard/wallet', label: 'Access Wallet', icon: Wallet },
          { href: '/dashboard/gate-pass', label: 'My Gate Pass', icon: BadgeCheck },
          { href: '/dashboard/vehicles', label: 'Vehicles & Parking', icon: Car },
          { href: '/dashboard/parking', label: 'Parking Passes', icon: SquareParking },
        ],
      },
      {
        title: 'Payments & Community',
        href: '/dashboard/billing',
        icon: CreditCard,
        subItems: [
          { href: '/dashboard/billing', label: 'Dues & Payments', icon: CreditCard },
          { href: '/dashboard/fundraising', label: 'Fundraising', icon: PiggyBank },
          { href: '/dashboard/map', label: 'Community Map', icon: Map },
          { href: '/dashboard/calendar', label: 'Calendar & Events', icon: Calendar },
          { href: '/dashboard/updates', label: 'Community Updates', icon: ClipboardList },
          { href: '/dashboard/deals', label: 'Perks & Savings', icon: Gift },
          { href: '/dashboard/guidelines', label: 'Guidelines', icon: BookUser },
        ],
      },
      {
        title: 'Emergency & Safety',
        href: '/dashboard/safety',
        icon: LifeBuoy,
        subItems: [
          { href: '/dashboard/safety', label: 'Safety & Contacts', icon: LifeBuoy },
          { href: '/dashboard/silent-assistance', label: 'Silent Assistance', icon: ShieldAlert },
          { href: '/dashboard/warnings', label: 'Safety Alerts', icon: Siren },
        ],
      },
    ];
  }, [user, ownershipLabel, ownershipType, OwnershipIcon]);

  const [openSections, setOpenSections] = useState<Record<string, boolean>>({});

  useEffect(() => {
    navSections.forEach((section) => {
      const isCurrentSection =
        section.href === pathname ||
        section.subItems?.some((sub) => sub.href.split('?')[0] === pathname);

      if (isCurrentSection) {
        setOpenSections((prev) => ({ ...prev, [section.title]: true }));
      }
    });
  }, [pathname, navSections]);

  const toggleSection = (title: string) => {
    setOpenSections((prev) => ({
      ...prev,
      [title]: !prev[title],
    }));
  };

  if (!isClient || loading || !user) {
    return <div className="flex h-screen w-full items-center justify-center">Loading...</div>;
  }

  return (
    /*
     * Provider chain.
     *
     * The Next.js build mounted these in components/providers.tsx via the root
     * layout. Auth, billing and map state are now page props and need no
     * provider; these four remain because each does real work:
     *
     *   TooltipProvider  — Radix Tooltip throws without an ancestor.
     *   ThemeProvider    — holds the optimistic theme state and applies it to
     *                      CSS variables; also loads the selected web font.
     *   BrandingProvider — same, for the estate palette and logo.
     *   Toaster          — useToast() renders nothing without it (below).
     */
    <TooltipProvider delayDuration={200}>
      {showSplash && user && (
        <WelcomeAnimation
          communityName={communityName}
          username={user.displayName || user.name}
          onComplete={() => setShowSplash(false)}
        />
      )}
      <ThemeProvider>
        <BrandingProvider>
          <SidebarProvider>
            <MyGatePassDialog open={isPassOpen} onOpenChange={setPassOpen} />
      <Sidebar>
        <SidebarHeader>
          <Logo />
        </SidebarHeader>
        <SidebarContent>
          <SidebarMenu>
            {navSections.map((section) => {
              const hasSubItems = Boolean(section.subItems && section.subItems.length > 0);
              const isSectionActive =
                pathname === section.href ||
                Boolean(section.subItems?.some((sub) => sub.href.split('?')[0] === pathname));
              const isOpen = openSections[section.title] ?? isSectionActive;
              const SectionIcon = section.icon;

              if (!hasSubItems) {
                return (
                  <SidebarMenuItem key={section.title}>
                    <SidebarMenuButton
                      asChild
                      isActive={pathname === section.href}
                      tooltip={section.title}
                    >
                      <Link href={section.href}>
                        <SectionIcon className="h-4 w-4" />
                        <span className="font-medium">{section.title}</span>
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                );
              }

              return (
                <Collapsible
                  key={section.title}
                  open={isOpen}
                  onOpenChange={() => toggleSection(section.title)}
                  className="group/collapsible"
                >
                  <SidebarMenuItem>
                    <div className="flex items-center w-full">
                      <SidebarMenuButton
                        asChild
                        isActive={isSectionActive}
                        tooltip={section.title}
                        className="flex-1"
                      >
                        <Link href={section.href}>
                          <SectionIcon className="h-4 w-4" />
                          <span className="font-medium truncate">{section.title}</span>
                        </Link>
                      </SidebarMenuButton>
                      <CollapsibleTrigger asChild>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground group-data-[collapsible=icon]:hidden shrink-0"
                          aria-label={`Toggle ${section.title}`}
                        >
                          <ChevronDown
                            className={cn(
                              "h-3.5 w-3.5 transition-transform duration-200",
                              isOpen && "rotate-180"
                            )}
                          />
                        </Button>
                      </CollapsibleTrigger>
                    </div>

                    <CollapsibleContent>
                      <SidebarMenuSub>
                        {section.subItems?.map((sub) => {
                          const isSubActive = pathname === sub.href.split('?')[0];
                          const SubIcon = sub.icon;
                          return (
                            <SidebarMenuSubItem key={sub.href}>
                              <SidebarMenuSubButton asChild isActive={isSubActive}>
                                <Link href={sub.href} className="flex items-center gap-2">
                                  {SubIcon && (
                                    <SubIcon className="h-3.5 w-3.5 shrink-0 opacity-70" />
                                  )}
                                  <span className="truncate">{sub.label}</span>
                                </Link>
                              </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                          );
                        })}
                      </SidebarMenuSub>
                    </CollapsibleContent>
                  </SidebarMenuItem>
                </Collapsible>
              );
            })}
          </SidebarMenu>
        </SidebarContent>
        <SidebarFooter className="p-3 border-t border-border/40">
          <div className="flex items-center justify-between gap-2 w-full">
            <UserNav />
            <Button
              variant="ghost"
              size="sm"
              onClick={logout}
              className="text-xs text-muted-foreground hover:text-destructive hover:bg-destructive/10 px-2 py-1 h-8 flex items-center gap-1.5 transition-colors"
              title="Log out of Community Hub"
            >
              <LogOut className="h-4 w-4 text-muted-foreground hover:text-destructive" />
              <span className="hidden sm:inline font-medium">Log out</span>
            </Button>
          </div>
        </SidebarFooter>
      </Sidebar>
      <SidebarInset>
        <header className="sticky top-0 z-30 flex h-14 sm:h-16 items-center gap-4 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 sm:px-6 shadow-sm">
          <SidebarTrigger />
          <div className="relative ml-auto flex-1 md:grow-0">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder="Search..."
              className="w-full max-w-xs md:max-w-none rounded-lg bg-background pl-8 md:w-[200px] lg:w-[320px]"
            />
          </div>
          <div className="hidden items-center gap-2 sm:flex">
             <Tooltip>
                 <TooltipTrigger asChild>
                     <Button variant="ghost" size="icon" onClick={() => setPassOpen(true)} aria-label="View Gate Pass">
                        <QrCode className="h-5 w-5" />
                        <span className="sr-only">View Gate Pass</span>
                    </Button>
                 </TooltipTrigger>
                 <TooltipContent>View Gate Pass</TooltipContent>
             </Tooltip>
             <Tooltip>
                 <TooltipTrigger asChild>
                     <Button variant="ghost" size="icon" onClick={toggleDark} aria-label="Toggle dark mode">
                        {isDark ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
                    </Button>
                 </TooltipTrigger>
                 <TooltipContent>{isDark ? 'Light Mode' : 'Dark Mode'}</TooltipContent>
             </Tooltip>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="default"
                    size="sm"
                    className="flex items-center gap-1.5 h-9 px-3 sm:px-4 rounded-full shadow-md hover:shadow-lg transition-all duration-200 bg-primary text-primary-foreground"
                    aria-label="Quick Action"
                  >
                    <Plus className="h-4 w-4" />
                    <span className="hidden sm:inline font-medium">Quick Action</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  <DropdownMenuLabel>Quick Actions</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem asChild>
                    <Link href="/dashboard/directory?action=add" className="cursor-pointer">
                      <Users className="mr-2 h-4 w-4" />
                      <span>Add Resident</span>
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/dashboard/calendar?action=create" className="cursor-pointer">
                      <Calendar className="mr-2 h-4 w-4" />
                      <span>Create Event</span>
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/dashboard/updates?action=new" className="cursor-pointer">
                      <Bell className="mr-2 h-4 w-4" />
                      <span>New Announcement</span>
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild>
                    <Link href="/dashboard/visitors?action=register" className="cursor-pointer">
                      <User className="mr-2 h-4 w-4" />
                      <span>Register Visitor</span>
                    </Link>
                  </DropdownMenuItem>
                  {(user?.role === 'Homeowner' || user?.role === 'Admin' || user?.role === 'System Admin') && (
                    <DropdownMenuItem asChild>
                      <Link href="/dashboard/renters" className="cursor-pointer">
                        <KeyRound className="mr-2 h-4 w-4" />
                        <span>Manage Renters</span>
                      </Link>
                    </DropdownMenuItem>
                  )}
                </DropdownMenuContent>
              </DropdownMenu>
            <UserNav />
          </div>
        </header>
        <main key={pathname} className="flex flex-1 flex-col gap-4 p-4 sm:px-6 sm:py-6 md:gap-8 animate-fade-in">
          <EmergencyBroadcastBanner />
          {children}
        </main>
      </SidebarInset>

          <Toaster />
          <FlashToaster />
          <PasswordConfirmationDialog />
        </SidebarProvider>
      </BrandingProvider>
      </ThemeProvider>
    </TooltipProvider>
  );
}
