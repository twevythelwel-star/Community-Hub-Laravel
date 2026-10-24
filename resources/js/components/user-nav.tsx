import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { CreditCard, KeyRound, LogOut, Palette, Settings, User } from "lucide-react";
import { Link } from "@inertiajs/react";
import { useAuth } from "@/context/auth-context";
import { useBranding } from "@/context/branding-context";

export function UserNav() {
  const { user, logout } = useAuth();
  const { branding } = useBranding();

  if (!user) {
    return null;
  }

  const getInitials = (name: string) => {
    if (!name) return 'U';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
      return `${parts[0][0]}${parts[1][0]}`.toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
  };

  const getRoleBadgeClass = (role: string) => {
    switch (role) {
      case 'System Admin':
        return 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20';
      case 'Admin':
        return 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20';
      case 'Security':
        return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20';
      case 'Homeowner':
        return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20';
      case 'Temporary Homeowner':
        return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20';
      case 'Staff':
        return 'bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20';
      default:
        return 'bg-muted text-muted-foreground';
    }
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" className="relative h-9 w-9 rounded-full ring-1 ring-border/60 hover:ring-primary/40 transition-all p-0">
          <Avatar className="h-9 w-9 border border-primary/20">
            <AvatarFallback className="bg-primary/10 text-primary font-bold text-xs">
              {getInitials(user.name)}
            </AvatarFallback>
          </Avatar>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-60" align="end" forceMount>
        <DropdownMenuLabel className="font-normal py-2">
          <div className="flex flex-col space-y-1.5">
            {branding.communityName && (
              <span className="text-[10px] font-bold tracking-wider uppercase text-primary leading-none">
                {branding.communityName}
              </span>
            )}
            <div className="flex items-center justify-between gap-2">
              <p className="text-sm font-semibold leading-none truncate">{user.displayName || user.name}</p>
              <Badge variant="outline" className={`text-[10px] px-1.5 py-0 h-4 border ${getRoleBadgeClass(user.role)}`}>
                {user.role}
              </Badge>
            </div>
            <p className="text-xs leading-none text-muted-foreground truncate">
              {user.email}
            </p>
          </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <Link href="/dashboard/profile">
            <DropdownMenuItem className="cursor-pointer">
              <User className="mr-2 h-4 w-4 text-muted-foreground" />
              <span>Profile Details</span>
            </DropdownMenuItem>
          </Link>
          <Link href="/dashboard/profile">
            <DropdownMenuItem className="cursor-pointer">
              <Palette className="mr-2 h-4 w-4 text-muted-foreground" />
              <span>Theme & Appearance</span>
            </DropdownMenuItem>
          </Link>
          {(user.role === 'Homeowner' || user.role === 'Admin' || user.role === 'System Admin') && (
            <Link href="/dashboard/renters">
              <DropdownMenuItem className="cursor-pointer">
                <KeyRound className="mr-2 h-4 w-4 text-muted-foreground" />
                <span>My Renters</span>
              </DropdownMenuItem>
            </Link>
          )}
          {(user.role === 'Homeowner' || user.role === 'Temporary Homeowner' || user.role === 'Admin' || user.role === 'System Admin') && (
            <Link href="/dashboard/billing">
              <DropdownMenuItem className="cursor-pointer">
                <CreditCard className="mr-2 h-4 w-4 text-muted-foreground" />
                <span>Billing & Assessment</span>
              </DropdownMenuItem>
            </Link>
          )}
          <Link href="/dashboard/settings">
            <DropdownMenuItem className="cursor-pointer">
              <Settings className="mr-2 h-4 w-4 text-muted-foreground" />
              <span>Settings</span>
            </DropdownMenuItem>
          </Link>
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={(e) => {
            e.preventDefault();
            logout();
          }}
          onClick={(e) => {
            e.preventDefault();
            logout();
          }}
          className="text-destructive cursor-pointer focus:text-destructive flex items-center w-full font-medium"
        >
          <LogOut className="mr-2 h-4 w-4" />
          <span>Log out</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
