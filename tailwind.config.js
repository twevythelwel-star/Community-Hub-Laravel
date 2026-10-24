/** @type {import('tailwindcss').Config} */
/*
 * Scans Blade views and the ported React layer instead of the Next.js src/ tree.
 *
 * config/gatepass.php is included because the gate pass gradient and badge-border
 * class names now live there rather than in a TS constant, and Tailwind would
 * otherwise purge them as unused.
 */
module.exports = {
  darkMode: ['class'],
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.{js,ts,jsx,tsx}',
    './config/gatepass.php',
  ],
  theme: {
    extend: {
      boxShadow: {
        // Tailwind v4's name for the smallest shadow (v3's shadow-sm). Used
        // across the ported components, where it was otherwise a no-op.
        xs: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
      },
      fontFamily: {
        body: ['var(--font-family)', 'sans-serif'],
        headline: ['var(--font-family)', 'sans-serif'],
        code: ['monospace'],
      },
      colors: {
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        card: {
          DEFAULT: 'hsl(var(--card))',
          foreground: 'hsl(var(--card-foreground))',
        },
        popover: {
          DEFAULT: 'hsl(var(--popover))',
          foreground: 'hsl(var(--popover-foreground))',
        },
        primary: {
          DEFAULT: 'hsl(var(--primary))',
          foreground: 'hsl(var(--primary-foreground))',
        },
        secondary: {
          DEFAULT: 'hsl(var(--secondary))',
          foreground: 'hsl(var(--secondary-foreground))',
        },
        muted: {
          DEFAULT: 'hsl(var(--muted))',
          foreground: 'hsl(var(--muted-foreground))',
        },
        accent: {
          DEFAULT: 'hsl(var(--accent))',
          foreground: 'hsl(var(--accent-foreground))',
        },
        destructive: {
          DEFAULT: 'hsl(var(--destructive))',
          foreground: 'hsl(var(--destructive-foreground))',
        },
        border: 'hsl(var(--border))',
        input: 'hsl(var(--input))',
        ring: 'hsl(var(--ring))',
        chart: {
          '1': 'hsl(var(--chart-1))',
          '2': 'hsl(var(--chart-2))',
          '3': 'hsl(var(--chart-3))',
          '4': 'hsl(var(--chart-4))',
          '5': 'hsl(var(--chart-5))',
        },
        sidebar: {
          DEFAULT: 'hsl(var(--sidebar-background))',
          foreground: 'hsl(var(--sidebar-foreground))',
          primary: 'hsl(var(--sidebar-primary))',
          'primary-foreground': 'hsl(var(--sidebar-primary-foreground))',
          accent: 'hsl(var(--sidebar-accent))',
          'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
          border: 'hsl(var(--sidebar-border))',
          ring: 'hsl(var(--sidebar-ring))',
        },
      },
      borderRadius: {
        lg: 'var(--radius)',
        md: 'calc(var(--radius) - 2px)',
        sm: 'calc(var(--radius) - 4px)',
      },
      keyframes: {
        'accordion-down': {
          from: {
            height: '0',
          },
          to: {
            height: 'var(--radix-accordion-content-height)',
          },
        },
        'accordion-up': {
          from: {
            height: 'var(--radix-accordion-content-height)',
          },
          to: {
            height: '0',
          },
        },
        'gate-open-left': {
            from: { transform: 'translateX(0)' },
            to: { transform: 'translateX(-100%)' },
        },
        'gate-open-right': {
            from: { transform: 'translateX(0)' },
            to: { transform: 'translateX(100%)' },
        },
        'fade-in': {
            from: { opacity: '0', transform: 'scale(0.9)' },
            to: { opacity: '1', transform: 'scale(1)' },
        },
      },
      animation: {
        'accordion-down': 'accordion-down 0.2s ease-out',
        'accordion-up': 'accordion-up 0.2s ease-out',
        'gate-open-left': 'gate-open-left 1s ease-in-out forwards',
        'gate-open-right': 'gate-open-right 1s ease-in-out forwards',
        'fade-in': 'fade-in 1s ease-in-out forwards',
      },
    },
  },
  plugins: [require('tailwindcss-animate')],
};
