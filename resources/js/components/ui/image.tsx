import { type ImgHTMLAttributes, forwardRef } from 'react';
import { cn } from '@/lib/utils';

type ImageProps = ImgHTMLAttributes<HTMLImageElement> & {
    /**
     * Accepted for source compatibility with next/image and ignored.
     *
     * `layout`, `objectFit` and `objectPosition` are the legacy next/image API;
     * `unoptimized` disabled the Next image server. None has a meaning here —
     * they are absorbed so the original call sites keep type-checking, and the
     * equivalent styling is a `className` away.
     */
    fill?: boolean;
    priority?: boolean;
    quality?: number;
    unoptimized?: boolean;
    layout?: 'fill' | 'fixed' | 'intrinsic' | 'responsive';
    objectFit?: 'contain' | 'cover' | 'fill' | 'none' | 'scale-down';
    objectPosition?: string;
};

/**
 * Drop-in stand-in for next/image.
 *
 * next/image gave automatic resizing, format negotiation and lazy loading via
 * the Next server, none of which exists under Vite. This keeps the same call
 * sites working and preserves lazy loading and aspect-ratio hints; if you want
 * responsive sources back, generate them at build time or serve them from a
 * CDN and pass `srcSet`.
 */
export const Image = forwardRef<HTMLImageElement, ImageProps>(
    (
        {
            className,
            fill,
            priority,
            quality,
            unoptimized,
            layout,
            objectFit,
            objectPosition,
            alt = '',
            loading,
            ...props
        },
        ref,
    ) => (
        <img
            ref={ref}
            alt={alt}
            loading={loading ?? (priority ? 'eager' : 'lazy')}
            decoding="async"
            className={cn(
                (fill || layout === 'fill') && 'absolute inset-0 h-full w-full object-cover',
                objectFit && `object-${objectFit}`,
                className,
            )}
            style={objectPosition ? { objectPosition, ...props.style } : props.style}
            {...props}
        />
    ),
);

Image.displayName = 'Image';
