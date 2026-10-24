
import React from 'react';
import type { QRShape, PassCategory } from './types';
import { cn } from '@/lib/utils';
import { 
  Shield, 
  Crown, 
  Home, 
  KeyRound, 
  Wrench, 
  Sparkles,
  CheckCircle2
} from 'lucide-react';

interface ShapeContainerProps {
  shape: QRShape;
  category: PassCategory;
  themeColor: string;
  accentColor: string;
  isAnimated?: boolean;
  children: React.ReactNode;
  className?: string;
  size?: number; // Size in px
}

/**
 * Returns the CSS clip-path or SVG framing for a given QR shape.
 */
export function getShapeClipPath(shape: QRShape): string {
  switch (shape) {
    case 'STAR_8':
      // 8-point administrative octagram star
      return 'polygon(50% 0%, 63% 18%, 85% 15%, 82% 37%, 100% 50%, 82% 63%, 85% 85%, 63% 82%, 50% 100%, 37% 82%, 15% 85%, 18% 63%, 0% 50%, 18% 37%, 15% 15%, 37% 18%)';
    case 'OCTAGON':
      // Regular 8-sided administrative octagon
      return 'polygon(30% 0%, 70% 0%, 100% 30%, 100% 70%, 70% 100%, 30% 100%, 0% 70%, 0% 30%)';
    case 'HEXAGON':
      // 6-sided homeowner hexagon (house/estate geometry)
      return 'polygon(50% 0%, 93% 25%, 93% 75%, 50% 100%, 7% 75%, 7% 25%)';
    case 'ROUNDED_SQUARE':
      // Rounded squircle frame for resident/renter
      return 'inset(0% round 20%)';
    case 'CIRCLE':
      // Coin/disk rounded circle
      return 'circle(50% at 50% 50%)';
    case 'DIAMOND':
      // Precision rhombus diamond for staff operations
      return 'polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%)';
    case 'SHIELD':
      // Heraldic Tactical Security Shield (Image 2 style)
      return 'polygon(50% 0%, 94% 8%, 100% 28%, 100% 68%, 50% 100%, 0% 68%, 0% 28%, 6% 8%)';
    case 'HOUSE_HEX':
      // Custom hex/house hybrid frame for homeowner staff
      return 'polygon(50% 0%, 100% 28%, 100% 75%, 75% 100%, 25% 100%, 0% 75%, 0% 28%)';
    case 'PENTAGON':
      // Regular pentagon for approved contractors
      return 'polygon(50% 0%, 100% 38%, 82% 100%, 18% 100%, 0% 38%)';
    case 'TICKET':
      // Admission ticket, notched on both sides, for authorized delegates
      return 'polygon(0% 0%, 100% 0%, 100% 38%, 92% 50%, 100% 62%, 100% 100%, 0% 100%, 0% 62%, 8% 50%, 0% 38%)';
    case 'ARCH':
      // Doorway: rounded top, square base, for long-term occupants
      return 'inset(0% round 50% 50% 8% 8%)';
    default:
      return 'none';
  }
}

/**
 * Category shape badge icon component
 */
export function CategoryShapeIcon({ shape, className, color }: { shape: QRShape; className?: string; color?: string }) {
  const iconStyle = color ? { color } : undefined;
  
  switch (shape) {
    case 'STAR_8':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="12 2 15 8 21 8 16.5 12.5 18 19 12 15 6 19 7.5 12.5 3 8 9 8 12 2" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'OCTAGON':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'HEXAGON':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="12 2 21 7 21 17 12 22 3 17 3 7 12 2" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'ROUNDED_SQUARE':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <rect x="3" y="3" width="18" height="18" rx="5" ry="5" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'CIRCLE':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <circle cx="12" cy="12" r="10" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'DIAMOND':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="12 2 22 12 12 22 2 12 12 2" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'SHIELD':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'HOUSE_HEX':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="12 2 22 8 22 18 17 22 7 22 2 18 2 8 12 2" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'PENTAGON':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="12 2 22 9.5 18 22 6 22 2 9.5 12 2" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'TICKET':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <polygon points="2 4 22 4 22 10 20 12 22 14 22 20 2 20 2 14 4 12 2 10 2 4" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    case 'ARCH':
      return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className} style={iconStyle}>
          <path d="M4 22V11a8 8 0 0 1 16 0v11z" fill="currentColor" fillOpacity="0.15" />
        </svg>
      );
    default:
      return null;
  }
}

/**
 * Renders the precision shaped framing bezel enclosing the QR code.
 * Preserves 100% QR readability by seating the scannable code on a high-contrast quiet pad
 * surrounded by the distinct shaped geometric border, animated sweep, and corner accents.
 */
export function QRShapedBezel({
  shape,
  category,
  themeColor,
  accentColor,
  isAnimated = true,
  children,
  className,
  size = 220,
}: ShapeContainerProps) {
  const outerSize = size + 36;

  return (
    <div 
      className={cn("relative flex items-center justify-center select-none", className)}
      style={{ width: outerSize, height: outerSize }}
    >
      {/* ── Geometric Background Shape Glow & Bezel ── */}
      <div 
        className={cn(
          "absolute inset-0 transition-transform duration-500",
          isAnimated && "animate-pulse"
        )}
        style={{
          clipPath: getShapeClipPath(shape),
          backgroundColor: `${themeColor}22`,
          boxShadow: `0 0 25px ${themeColor}44`,
        }}
      />

      {/* ── Geometric Outer Shaped Border ── */}
      <div 
        className="absolute inset-1.5 transition-all duration-300 pointer-events-none"
        style={{
          clipPath: getShapeClipPath(shape),
          backgroundColor: themeColor,
          opacity: 0.85,
        }}
      />

      {/* ── Geometric Inner Cutout ── */}
      <div 
        className="absolute inset-3 transition-all duration-300"
        style={{
          clipPath: getShapeClipPath(shape),
          backgroundColor: '#0F172A', // Deep slate interior backing
        }}
      />

      {/* ── High-Contrast Inner Quiet-Zone QR Pad ── */}
      <div 
        className="relative z-10 flex items-center justify-center bg-white rounded-xl p-3 shadow-lg border border-slate-200"
        style={{
          width: size,
          height: size,
        }}
      >
        {/* Animated radar scanline sweep for dynamic credential representation */}
        {isAnimated && (
          <div 
            className="absolute inset-x-2 h-1 bg-gradient-to-r from-transparent via-emerald-400 to-transparent opacity-75 z-20 pointer-events-none animate-bounce"
            style={{
              boxShadow: `0 0 8px ${accentColor}`,
            }}
          />
        )}

        {/* The actual QR Code Matrix */}
        <div className="relative z-10 flex items-center justify-center">
          {children}
        </div>

        {/* Center Shaped Micro-Badge / Watermark Emblem */}
        <div 
          className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full flex items-center justify-center border-2 border-white shadow-md"
          style={{ backgroundColor: themeColor }}
        >
          <CategoryShapeIcon shape={shape} className="w-4 h-4 text-white" />
        </div>
      </div>

      {/* ── Shape Geometric Corner Accent Indicators ── */}
      <div className="absolute -bottom-2.5 z-20 px-2.5 py-0.5 rounded-full text-[10px] font-bold font-mono tracking-wider text-white shadow-md border border-white/25 flex items-center gap-1"
        style={{ backgroundColor: themeColor }}
      >
        <CategoryShapeIcon shape={shape} className="w-3 h-3" />
        <span>{shape}</span>
      </div>
    </div>
  );
}
