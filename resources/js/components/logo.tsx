

import { useState } from 'react';
import { Building2 } from 'lucide-react';
import { useBranding } from '@/context/branding-context';

export function Logo() {
  const { branding } = useBranding();
  const iconSrc = branding.iconUrl || '/community-hub-app-icon-128.webp';
  // Keyed on the source, so a newly uploaded icon gets its own chance to load.
  const [failedSrc, setFailedSrc] = useState<string | null>(null);
  const imgError = failedSrc === iconSrc;
  const size = Math.max(28, branding.iconSize || 32);

  return (
    <div className="flex items-center gap-2.5">
      {!imgError ? (
        <div
          className="relative flex items-center justify-center rounded-xl bg-gradient-to-br from-primary/10 via-background to-accent/10 p-1.5 ring-1 ring-border/70 shadow-sm flex-shrink-0"
          style={{ width: `${size}px`, height: `${size}px` }}
        >
          <img
            src={iconSrc}
            alt={`${branding.communityName || branding.appName} Logo`}
            className="h-full w-full object-contain rounded-lg"
            onError={() => setFailedSrc(iconSrc)}
          />
        </div>
      ) : (
        <div
          className="flex items-center justify-center rounded-xl bg-primary/10 text-primary p-1.5 flex-shrink-0"
          style={{ width: `${size}px`, height: `${size}px` }}
        >
          <Building2 className="h-5 w-5" />
        </div>
      )}
      <div className="flex flex-col min-w-0">
        <h1 className="font-headline text-base font-bold leading-tight text-foreground truncate">
          {branding.appName}
        </h1>
        {branding.communityName && (
          <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground truncate leading-tight">
            {branding.communityName}
          </span>
        )}
      </div>
    </div>
  );
}
