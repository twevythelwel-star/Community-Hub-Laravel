/**
 * Capability-aware payment method detection.
 *
 * Implements client-side device & browser capability checks for:
 *   - Apple Pay (ApplePaySession, Safari, iOS / macOS)
 *   - Google Pay (PaymentRequest API, Android, Chrome / Chromium)
 *   - Samsung Pay (Samsung Galaxy hardware, Samsung Internet)
 *   - Tap to Pay / Contactless (Card-present reader or Tap-to-Pay provider)
 *
 * CRITICAL DOMAIN DISTINCTION:
 * 1. Homeowner's phone paying with NFC:
 *    Phone -> Apple Pay / Google Pay / Samsung Pay -> NFC -> Payment Terminal
 * 2. Homeowner tapping a physical card:
 *    Physical Card -> NFC/Contactless Terminal -> Payment Processor
 *
 * For scenario #2, CommunityHub needs a supported card-present terminal or
 * Tap-to-Pay provider (e.g. Stripe Terminal / BBPOS reader / staff tablet),
 * not merely an NFC button on a desktop or personal website.
 */

export type SimulatedDeviceType = 'real' | 'iphone' | 'samsung' | 'desktop' | 'terminal';

export type WalletCapabilityState = 'AVAILABLE' | 'CONDITIONAL' | 'UNAVAILABLE';

export interface WalletCapability {
  id: 'apple_pay' | 'google_pay' | 'samsung_pay' | 'tap_nfc';
  name: string;
  shortName: string;
  state: WalletCapabilityState;
  badgeLabel: string;
  badgeVariant: 'success' | 'warning' | 'muted';
  reason: string;
  isSupported: boolean;
}

export interface DeviceProfile {
  deviceType: SimulatedDeviceType;
  label: string;
  isSimulated: boolean;
  capabilities: Record<'apple_pay' | 'google_pay' | 'samsung_pay' | 'tap_nfc', WalletCapability>;
}

/**
 * Evaluates device capabilities based on hardware detection or simulated test environment.
 * Accepts optional `hasInPersonProvider` flag indicating if the estate has an active
 * card-present reader (e.g. Stripe Terminal) registered.
 */
export function evaluateCapabilities(
  simulatedDevice: SimulatedDeviceType = 'real',
  hasInPersonProvider: boolean = false
): DeviceProfile {
  if (simulatedDevice === 'iphone') {
    return {
      deviceType: 'iphone',
      label: 'Apple iPhone (iOS Safari)',
      isSimulated: true,
      capabilities: {
        apple_pay: {
          id: 'apple_pay',
          name: 'Apple Pay',
          shortName: 'Apple Pay',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Native Apple Secure Enclave & Face ID supported on this iOS device.',
          isSupported: true,
        },
        google_pay: {
          id: 'google_pay',
          name: 'Google Pay',
          shortName: 'Google Pay',
          state: 'CONDITIONAL',
          badgeLabel: 'Available where supported',
          badgeVariant: 'warning',
          reason: 'Supported via Google Account or Chrome web checkout where configured.',
          isSupported: true,
        },
        samsung_pay: {
          id: 'samsung_pay',
          name: 'Samsung Pay',
          shortName: 'Samsung Pay',
          state: 'UNAVAILABLE',
          badgeLabel: 'Not available',
          badgeVariant: 'muted',
          reason: 'Samsung Pay requires Samsung Galaxy hardware with Knox security.',
          isSupported: false,
        },
        tap_nfc: {
          id: 'tap_nfc',
          name: 'Tap to Pay / Contactless',
          shortName: 'Tap to Pay / Contactless',
          state: hasInPersonProvider ? 'AVAILABLE' : 'UNAVAILABLE',
          badgeLabel: hasInPersonProvider ? 'Terminal Ready' : 'Requires Terminal',
          badgeVariant: hasInPersonProvider ? 'success' : 'muted',
          reason: hasInPersonProvider
            ? 'Paired Tap to Pay contactless reader ready.'
            : 'Tapping a physical card requires an active Tap-to-Pay provider or registered card-present terminal at the community office.',
          isSupported: hasInPersonProvider,
        },
      },
    };
  }

  if (simulatedDevice === 'samsung') {
    return {
      deviceType: 'samsung',
      label: 'Samsung Galaxy (Android / One UI)',
      isSimulated: true,
      capabilities: {
        apple_pay: {
          id: 'apple_pay',
          name: 'Apple Pay',
          shortName: 'Apple Pay',
          state: 'UNAVAILABLE',
          badgeLabel: 'Not available',
          badgeVariant: 'muted',
          reason: 'Apple Pay is exclusively supported on Apple iOS and macOS devices.',
          isSupported: false,
        },
        google_pay: {
          id: 'google_pay',
          name: 'Google Pay',
          shortName: 'Google Pay',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Google Play Services & Google Wallet active on Android.',
          isSupported: true,
        },
        samsung_pay: {
          id: 'samsung_pay',
          name: 'Samsung Pay',
          shortName: 'Samsung Pay',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Samsung Wallet & Knox biometric authentication detected.',
          isSupported: true,
        },
        tap_nfc: {
          id: 'tap_nfc',
          name: 'Tap to Pay / Contactless',
          shortName: 'Tap to Pay / Contactless',
          state: hasInPersonProvider ? 'AVAILABLE' : 'UNAVAILABLE',
          badgeLabel: hasInPersonProvider ? 'Terminal Ready' : 'Requires Terminal',
          badgeVariant: hasInPersonProvider ? 'success' : 'muted',
          reason: hasInPersonProvider
            ? 'Paired Tap to Pay contactless reader ready.'
            : 'Tapping a physical card requires an active Tap-to-Pay provider or registered card-present terminal at the community office.',
          isSupported: hasInPersonProvider,
        },
      },
    };
  }

  if (simulatedDevice === 'desktop') {
    return {
      deviceType: 'desktop',
      label: 'Desktop Browser (Chrome / Edge / Windows / Mac)',
      isSimulated: true,
      capabilities: {
        apple_pay: {
          id: 'apple_pay',
          name: 'Apple Pay',
          shortName: 'Apple Pay',
          state: 'CONDITIONAL',
          badgeLabel: 'Depends on browser/device',
          badgeVariant: 'warning',
          reason: 'Requires Safari on macOS with Touch ID or paired Apple Watch.',
          isSupported: false,
        },
        google_pay: {
          id: 'google_pay',
          name: 'Google Pay',
          shortName: 'Google Pay',
          state: 'CONDITIONAL',
          badgeLabel: 'Depends on browser/device',
          badgeVariant: 'warning',
          reason: 'Available on Chrome / Edge with cards saved in Google Account or PaymentRequest API.',
          isSupported: true,
        },
        samsung_pay: {
          id: 'samsung_pay',
          name: 'Samsung Pay',
          shortName: 'Samsung Pay',
          state: 'UNAVAILABLE',
          badgeLabel: 'Usually unavailable',
          badgeVariant: 'muted',
          reason: 'Samsung Pay does not operate on standard desktop operating systems.',
          isSupported: false,
        },
        tap_nfc: {
          id: 'tap_nfc',
          name: 'Tap to Pay / Contactless',
          shortName: 'Tap to Pay / Contactless',
          state: 'UNAVAILABLE',
          badgeLabel: 'Not available',
          badgeVariant: 'muted',
          reason: 'Desktop computers lack integrated card-present contactless terminals.',
          isSupported: false,
        },
      },
    };
  }

  if (simulatedDevice === 'terminal') {
    return {
      deviceType: 'terminal',
      label: 'Community Office (Registered Stripe Terminal / POS Reader)',
      isSimulated: true,
      capabilities: {
        apple_pay: {
          id: 'apple_pay',
          name: 'Apple Pay',
          shortName: 'Apple Pay',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Terminal reader accepts NFC Apple Pay phone tap.',
          isSupported: true,
        },
        google_pay: {
          id: 'google_pay',
          name: 'Google Pay',
          shortName: 'Google Pay',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Terminal reader accepts NFC Google Pay phone tap.',
          isSupported: true,
        },
        samsung_pay: {
          id: 'samsung_pay',
          name: 'Samsung Pay',
          shortName: 'Samsung Pay',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Terminal reader accepts NFC Samsung Pay phone tap.',
          isSupported: true,
        },
        tap_nfc: {
          id: 'tap_nfc',
          name: 'Tap to Pay / Contactless',
          shortName: 'Tap to Pay / Contactless',
          state: 'AVAILABLE',
          badgeLabel: 'AVAILABLE',
          badgeVariant: 'success',
          reason: 'Connected card-present terminal actively taking physical card contactless taps.',
          isSupported: true,
        },
      },
    };
  }

  // ── REAL-TIME DETECTION (window / navigator inspection) ─────────────────────
  const isClient = typeof window !== 'undefined' && typeof navigator !== 'undefined';
  const ua = isClient ? navigator.userAgent : '';

  const isIOS = /iPhone|iPad|iPod/i.test(ua);
  const isMac = /Macintosh/i.test(ua);
  const isAndroid = /Android/i.test(ua);
  const isSamsung = isAndroid && (/SamsungBrowser|SM-[A-Z0-9]+|SAMSUNG/i.test(ua) || (navigator as any)?.userAgentData?.brands?.some((b: any) => /Samsung/i.test(b.brand)));
  const isMobile = isIOS || isAndroid;

  // Apple Pay real check
  let applePayState: WalletCapabilityState = 'UNAVAILABLE';
  let applePayLabel = 'Not available';
  let applePayVariant: 'success' | 'warning' | 'muted' = 'muted';
  let applePayReason = 'Apple Pay requires Safari on iOS or macOS devices.';
  let applePaySupported = false;

  if (isIOS) {
    if (isClient && (window as any).ApplePaySession) {
      try {
        const canPay = (window as any).ApplePaySession.canMakePayments();
        if (canPay) {
          applePayState = 'AVAILABLE';
          applePayLabel = 'AVAILABLE';
          applePayVariant = 'success';
          applePayReason = 'Apple Pay session confirmed on this iOS device.';
          applePaySupported = true;
        } else {
          applePayState = 'CONDITIONAL';
          applePayLabel = 'Available where supported';
          applePayVariant = 'warning';
          applePayReason = 'iOS device detected; set up a card in Apple Wallet.';
          applePaySupported = true;
        }
      } catch {
        applePayState = 'AVAILABLE';
        applePayLabel = 'AVAILABLE';
        applePayVariant = 'success';
        applePayReason = 'iOS device with Apple Pay support.';
        applePaySupported = true;
      }
    } else {
      applePayState = 'CONDITIONAL';
      applePayLabel = 'Available where supported';
      applePayVariant = 'warning';
      applePayReason = 'Use Safari browser on iOS for 1-tap Apple Pay.';
      applePaySupported = true;
    }
  } else if (isMac) {
    applePayState = 'CONDITIONAL';
    applePayLabel = 'Depends on browser/device';
    applePayVariant = 'warning';
    applePayReason = 'Requires Safari on macOS with Touch ID or paired Apple Watch.';
    applePaySupported = false;
  }

  // Google Pay real check
  let googlePayState: WalletCapabilityState = 'UNAVAILABLE';
  let googlePayLabel = 'Not available';
  let googlePayVariant: 'success' | 'warning' | 'muted' = 'muted';
  let googlePayReason = 'Google Pay is not detected on this browser.';
  let googlePaySupported = false;

  if (isAndroid) {
    googlePayState = 'AVAILABLE';
    googlePayLabel = 'AVAILABLE';
    googlePayVariant = 'success';
    googlePayReason = 'Google Wallet / Play Services active on Android.';
    googlePaySupported = true;
  } else if (isClient && (window as any).PaymentRequest) {
    googlePayState = 'CONDITIONAL';
    googlePayLabel = 'Depends on browser/device';
    googlePayVariant = 'warning';
    googlePayReason = 'Payment Request API supported; available if card linked to Google Account.';
    googlePaySupported = true;
  } else if (isIOS) {
    googlePayState = 'CONDITIONAL';
    googlePayLabel = 'Available where supported';
    googlePayVariant = 'warning';
    googlePayReason = 'Available via Google Chrome or Google Account web checkout.';
    googlePaySupported = false;
  } else {
    googlePayState = 'CONDITIONAL';
    googlePayLabel = 'Depends on browser/device';
    googlePayVariant = 'warning';
    googlePayReason = 'Supported in Chrome and Edge when logged into Google Account.';
    googlePaySupported = true;
  }

  // Samsung Pay real check
  let samsungPayState: WalletCapabilityState = 'UNAVAILABLE';
  let samsungPayLabel = isMobile ? 'Not available' : 'Usually unavailable';
  let samsungPayVariant: 'success' | 'warning' | 'muted' = 'muted';
  let samsungPayReason = 'Samsung Pay requires Samsung Galaxy hardware with Knox security.';
  let samsungPaySupported = false;

  if (isSamsung) {
    samsungPayState = 'AVAILABLE';
    samsungPayLabel = 'AVAILABLE';
    samsungPayVariant = 'success';
    samsungPayReason = 'Samsung Galaxy device detected with Samsung Wallet.';
    samsungPaySupported = true;
  }

  // Tap to Pay / Contactless real check
  let tapNfcState: WalletCapabilityState = 'UNAVAILABLE';
  let tapNfcLabel = 'Not available';
  let tapNfcVariant: 'success' | 'warning' | 'muted' = 'muted';
  let tapNfcReason = 'Desktop browsers do not have physical card-present contactless terminals.';
  let tapNfcSupported = false;

  if (hasInPersonProvider) {
    tapNfcState = 'AVAILABLE';
    tapNfcLabel = 'Terminal Ready';
    tapNfcVariant = 'success';
    tapNfcReason = 'Connected card-present terminal ready to accept physical card taps.';
    tapNfcSupported = true;
  } else if (isMobile) {
    tapNfcState = 'UNAVAILABLE';
    tapNfcLabel = 'Requires Terminal';
    tapNfcVariant = 'muted';
    tapNfcReason = 'Tapping a physical card requires an active Tap-to-Pay provider or registered card-present terminal at the community office.';
    tapNfcSupported = false;
  }

  const detectedLabel = isIOS 
    ? 'Apple iPhone / iPad' 
    : isSamsung 
    ? 'Samsung Galaxy' 
    : isAndroid 
    ? 'Android Device' 
    : 'Desktop Browser';

  return {
    deviceType: 'real',
    label: detectedLabel,
    isSimulated: false,
    capabilities: {
      apple_pay: {
        id: 'apple_pay',
        name: 'Apple Pay',
        shortName: 'Apple Pay',
        state: applePayState,
        badgeLabel: applePayLabel,
        badgeVariant: applePayVariant,
        reason: applePayReason,
        isSupported: applePaySupported,
      },
      google_pay: {
        id: 'google_pay',
        name: 'Google Pay',
        shortName: 'Google Pay',
        state: googlePayState,
        badgeLabel: googlePayLabel,
        badgeVariant: googlePayVariant,
        reason: googlePayReason,
        isSupported: googlePaySupported,
      },
      samsung_pay: {
        id: 'samsung_pay',
        name: 'Samsung Pay',
        shortName: 'Samsung Pay',
        state: samsungPayState,
        badgeLabel: samsungPayLabel,
        badgeVariant: samsungPayVariant,
        reason: samsungPayReason,
        isSupported: samsungPaySupported,
      },
      tap_nfc: {
        id: 'tap_nfc',
        name: 'Tap to Pay / Contactless',
        shortName: 'Tap to Pay / Contactless',
        state: tapNfcState,
        badgeLabel: tapNfcLabel,
        badgeVariant: tapNfcVariant,
        reason: tapNfcReason,
        isSupported: tapNfcSupported,
      },
    },
  };
}
