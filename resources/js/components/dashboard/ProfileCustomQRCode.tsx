
import React, { useMemo } from 'react';
import type { PassCategory, QRShape } from '@/lib/gate-pass-engine/types';
import { getCategoryConfig } from '@/lib/gate-pass-engine/config';
import { getShapeClipPath, CategoryShapeIcon } from '@/lib/gate-pass-engine/shapes';
import { cn } from '@/lib/utils';
import qrcode from 'qrcode-generator';

export interface ProfileCustomQRCodeProps {
  value: string;
  category: PassCategory;
  size?: number;
  className?: string;
  showScanGlow?: boolean;
  viewMode?: 'standard' | 'matrix_diagnostic';
  themeColor?: string;
  accentColor?: string;
  colorVariantName?: string;
}

/**
 * Enterprise Profile-Specific Custom QR Code Generator
 *
 * Implements the Two-Layer Architecture:
 * 1. Standardized Machine-Readable Base Layer:
 *    - Standard Level H (High) Error Correction (30% recovery threshold)
 *    - Standardized 3-finder-eye position detection patterns
 *    - Readable by common camera and scanner apps in testing
 *
 * 2. Profile-Specific Visual Layer (Matching Reference Designs):
 *    - Circular dot modules with concentric duo-tone color halo (Image 1 style)
 *    - Rounded finder eye brackets and colored inner pupils
 *    - Centered category emblem badge (Hexagonal bolt, Heraldic shield, 8-pt star, etc.)
 *    - Heraldic Shield or Geometric Enclosure (Image 2 style)
 */
export function ProfileCustomQRCode({
  value,
  category,
  size = 200,
  className,
  showScanGlow = true,
  viewMode = 'standard',
  themeColor: overrideThemeColor,
  accentColor: overrideAccentColor,
  colorVariantName,
}: ProfileCustomQRCodeProps) {
  const config = getCategoryConfig(category);
  const shape = config.shape;
  const themeColor = overrideThemeColor || config.themeColor;
  const accentColor = overrideAccentColor || config.accentColor;

  // Generate QR matrix with Level H (High: 30% error recovery)
  const qrData = useMemo(() => {
    const generateMatrix = (ecLevel: 'H' | 'M'): boolean[][] => {
      const qr = qrcode(0, ecLevel);
      qr.addData(value || 'CH-GPE:v1.EMPTY');
      qr.make();
      const count = qr.getModuleCount();
      const matrix: boolean[][] = [];
      for (let r = 0; r < count; r++) {
        const row: boolean[] = [];
        for (let c = 0; c < count; c++) {
          row.push(qr.isDark(r, c));
        }
        matrix.push(row);
      }
      return matrix;
    };

    try {
      return generateMatrix('H');
    } catch {
      try {
        return generateMatrix('M');
      } catch {
        return [];
      }
    }
  }, [value]);

  const moduleCount = qrData.length;
  const mid = Math.floor(moduleCount / 2);
  const centerRadius = 3.5; // Radius of modules cleared in the middle for center emblem

  // Helpers to detect special module locations
  const isFinderEye = (r: number, c: number) => {
    // Top-Left eye
    if (r < 7 && c < 7) return true;
    // Top-Right eye
    if (r < 7 && c >= moduleCount - 7) return true;
    // Bottom-Left eye
    if (r >= moduleCount - 7 && c < 7) return true;
    return false;
  };

  const isCenterBadgeArea = (r: number, c: number) => {
    const dist = Math.hypot(r - mid, c - mid);
    return dist < centerRadius;
  };

  // Render Category Center Emblem Badge
  const renderCenterEmblem = (centerX: number, centerY: number, radius: number) => {
    switch (shape) {
      case 'STAR_8': // SysAdmin 8-point gold star
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            {/* White outer protective isolation ring */}
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            <circle cx="0" cy="0" r={radius - 0.3} fill={config.contrastBg} stroke={accentColor} strokeWidth="0.4" />
            {/* 8-Point Star polygon */}
            <polygon
              points="0,-2.2 0.6,-0.8 2.2,-0.8 1,-0.1 1.6,1.4 0,0.6 -1.6,1.4 -1,-0.1 -2.2,-0.8 -0.6,-0.8"
              fill={accentColor}
            />
            <circle cx="0" cy="0" r="0.6" fill="#FFFFFF" />
          </g>
        );

      case 'OCTAGON': // Admin octagonal frame
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            <polygon
              points="-1.2,-2.6 1.2,-2.6 2.6,-1.2 2.6,1.2 1.2,2.6 -1.2,2.6 -2.6,1.2 -2.6,-1.2"
              fill={themeColor}
              stroke="#FFFFFF"
              strokeWidth="0.3"
            />
            <polygon
              points="-0.7,-1.8 0.7,-1.8 1.8,-0.7 1.8,0.7 0.7,1.8 -0.7,1.8 -1.8,0.7 -1.8,-0.7"
              fill={accentColor}
            />
          </g>
        );

      case 'HEXAGON': // Homeowner hexagonal house/bolt emblem (Reference Image 1)
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            {/* Outer Hexagon (bolt/nut) */}
            <polygon
              points="0,-2.6 2.3,-1.3 2.3,1.3 0,2.6 -2.3,1.3 -2.3,-1.3"
              fill="#1E3A8A" // Dark royal blue base like Image 1
              stroke="#FFFFFF"
              strokeWidth="0.35"
            />
            {/* Inner Contrasting Circle */}
            <circle cx="0" cy="0" r="1.25" fill={accentColor} />
          </g>
        );

      case 'ROUNDED_SQUARE': // Renter rounded square squircle
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            <rect x="-2.2" y="-2.2" width="4.4" height="4.4" rx="1.1" ry="1.1" fill={themeColor} stroke="#FFFFFF" strokeWidth="0.3" />
            <rect x="-1.3" y="-1.3" width="2.6" height="2.6" rx="0.6" ry="0.6" fill="#FFFFFF" />
            <circle cx="0" cy="0" r="0.85" fill={accentColor} />
          </g>
        );

      case 'CIRCLE': // Visitor concentric circle
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            <circle cx="0" cy="0" r="2.5" fill={themeColor} stroke="#FFFFFF" strokeWidth="0.3" />
            <circle cx="0" cy="0" r="1.6" fill="#FFFFFF" />
            <circle cx="0" cy="0" r="1.1" fill={accentColor} />
          </g>
        );

      case 'DIAMOND': // Staff precision rhombus
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            <polygon points="0,-2.6 2.6,0 0,2.6 -2.6,0" fill={themeColor} stroke="#FFFFFF" strokeWidth="0.35" />
            <polygon points="0,-1.5 1.5,0 0,1.5 -1.5,0" fill={accentColor} />
          </g>
        );

      case 'SHIELD': // Security heraldic shield (Reference Image 2)
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            {/* Heraldic Shield */}
            <path
              d="M0,-2.6 C1.8,-2.6 2.5,-2.2 2.5,-0.6 C2.5,1.2 1.5,2.1 0,2.8 C-1.5,2.1 -2.5,1.2 -2.5,-0.6 C-2.5,-2.2 -1.8,-2.6 0,-2.6 Z"
              fill={themeColor}
              stroke="#FFFFFF"
              strokeWidth="0.35"
            />
            {/* Inner Crest Star / Leaf */}
            <circle cx="0" cy="0" r="0.9" fill={accentColor} />
          </g>
        );

      case 'PENTAGON': // Contractor pentagon
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            <polygon points="0,-2.6 2.5,-0.8 1.55,2.2 -1.55,2.2 -2.5,-0.8" fill={themeColor} stroke="#FFFFFF" strokeWidth="0.35" />
            <circle cx="0" cy="0.1" r="0.85" fill={accentColor} />
          </g>
        );

      case 'HOUSE_HEX': // Homeowner Staff house/hex hybrid
      default:
        return (
          <g transform={`translate(${centerX}, ${centerY})`}>
            <circle cx="0" cy="0" r={radius} fill="#FFFFFF" />
            {/* House-topped Hexagon */}
            <polygon
              points="0,-2.6 2.4,-0.8 2.4,1.8 1.6,2.5 -1.6,2.5 -2.4,1.8 -2.4,-0.8"
              fill={themeColor}
              stroke="#FFFFFF"
              strokeWidth="0.35"
            />
            <circle cx="0" cy="0.4" r="0.85" fill={accentColor} />
          </g>
        );
    }
  };

  // Render a Single Finder Pattern with custom rounded brackets and pupil (Image 1 style)
  const renderFinderPattern = (originX: number, originY: number) => {
    return (
      <g transform={`translate(${originX}, ${originY})`}>
        {/* Outer 7x7 White Backing Pad */}
        <rect x="0" y="0" width="7" height="7" fill="#FFFFFF" />
        {/* Outer 7x7 Stylized Bracket (Accent Color with rounded corners) */}
        <rect
          x="0.4"
          y="0.4"
          width="6.2"
          height="6.2"
          rx="1.6"
          ry="1.6"
          fill="none"
          stroke={accentColor}
          strokeWidth="0.9"
        />
        {/* Inner 5x5 Quiet Gap */}
        <rect x="1.5" y="1.5" width="4" height="4" rx="0.9" ry="0.9" fill="#FFFFFF" />
        {/* Center 3x3 Pupil (Theme/Accent Color with soft rounded corners) */}
        <rect
          x="2.1"
          y="2.1"
          width="2.8"
          height="2.8"
          rx="0.8"
          ry="0.8"
          fill={accentColor}
        />
      </g>
    );
  };

  // SVG QR matrix padding
  const padding = 2; // Quiet zone module padding
  const svgDimension = moduleCount + padding * 2;

  // Render the core SVG QR matrix
  const qrSvgMatrix = (
    <svg
      viewBox={`0 0 ${svgDimension} ${svgDimension}`}
      className="w-full h-full select-none"
      style={{ shapeRendering: 'geometricPrecision' }}
    >
      <defs>
        {/* Dual-tone radial gradient for concentric halo effect (Image 1) */}
        <radialGradient id={`qr-halo-${category}`} cx="50%" cy="50%" r="50%">
          <stop offset="0%" stopColor="#0F172A" />
          <stop offset="28%" stopColor={accentColor} />
          <stop offset="55%" stopColor={accentColor} />
          <stop offset="85%" stopColor={themeColor} />
          <stop offset="100%" stopColor="#0F172A" />
        </radialGradient>
      </defs>

      {/* Clean high-contrast white background canvas */}
      <rect x="0" y="0" width={svgDimension} height={svgDimension} fill="#FFFFFF" />

      {/* ── QR Data Modules (Dots with Concentric Halo Coloring) ── */}
      <g transform={`translate(${padding}, ${padding})`}>
        {qrData.map((row, r) =>
          row.map((cell, c) => {
            if (!cell) return null;
            if (isFinderEye(r, c)) return null;
            if (isCenterBadgeArea(r, c)) return null;

            // Distance from center to compute concentric halo (Reference Image 1)
            const dist = Math.hypot(r - mid, c - mid);
            const isInHaloRing = dist >= mid * 0.32 && dist <= mid * 0.76;
            const dotColor = isInHaloRing ? accentColor : (themeColor || '#1E293B');

            return (
              <circle
                key={`mod-${r}-${c}`}
                cx={c + 0.5}
                cy={r + 0.5}
                r="0.42"
                fill={dotColor}
              />
            );
          })
        )}

        {/* ── 3 Standard Finder Patterns with Profile Styling ── */}
        {renderFinderPattern(0, 0)}
        {renderFinderPattern(moduleCount - 7, 0)}
        {renderFinderPattern(0, moduleCount - 7)}

        {/* ── Center Profile Emblem Badge ── */}
        {renderCenterEmblem(mid + 0.5, mid + 0.5, 3.2)}
      </g>
    </svg>
  );

  // ── VIEW MODE: STANDARD PROFILE-SHAPED BEZEL ──
  return (
    <div 
      className={cn("relative flex items-center justify-center select-none", className)}
      style={{ width: size + 36, height: size + 36 }}
    >
      {/* ── Layer 1: Geometric Outer Bezel with Glow (Profile Shape) ── */}
      <div 
        className={cn(
          "absolute inset-0 transition-transform duration-500",
          showScanGlow && "animate-pulse"
        )}
        style={{
          clipPath: getShapeClipPath(shape),
          backgroundColor: `${themeColor}25`,
          boxShadow: `0 0 30px ${themeColor}55`,
        }}
      />

      {/* Geometric Outer Border Accent */}
      <div 
        className="absolute inset-1.5 transition-all duration-300 pointer-events-none"
        style={{
          clipPath: getShapeClipPath(shape),
          backgroundColor: themeColor,
          opacity: 0.9,
        }}
      />

      {/* Geometric Inner Cutout Backing */}
      <div 
        className="absolute inset-3 transition-all duration-300"
        style={{
          clipPath: getShapeClipPath(shape),
          backgroundColor: '#0F172A',
        }}
      />

      {/* ── Layer 2: High-Contrast Quiet-Zone Pad with Custom QR Matrix ── */}
      <div 
        className="relative z-10 flex items-center justify-center bg-white rounded-2xl p-2.5 shadow-2xl border border-slate-200"
        style={{ width: size, height: size }}
      >
        {/* Radar Scanning Sweep */}
        {showScanGlow && (
          <div 
            className="absolute inset-x-2 h-0.5 bg-gradient-to-r from-transparent via-white to-transparent opacity-80 z-30 pointer-events-none animate-bounce"
            style={{ boxShadow: `0 0 10px ${accentColor}` }}
          />
        )}

        {/* Custom SVG QR Code */}
        <div className="w-full h-full flex items-center justify-center">
          {qrSvgMatrix}
        </div>
      </div>

      {/* ── Shape Geometric Identifier Pill Tag ── */}
      <div 
        className="absolute -bottom-2.5 z-20 px-2.5 py-0.5 rounded-full text-[10px] font-bold font-mono tracking-wider text-white shadow-md border border-white/30 flex items-center gap-1.5"
        style={{ backgroundColor: themeColor }}
      >
        <CategoryShapeIcon shape={shape} className="w-3 h-3 text-white" />
        <span>{config.shapeLabel.split(' ')[0]}</span>
      </div>
    </div>
  );
}
