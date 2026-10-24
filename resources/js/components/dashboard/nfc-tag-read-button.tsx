import { useEffect, useRef, useState } from 'react';
import { Nfc, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useToast } from '@/hooks/use-toast';
import { isWebNfcSupported, nfcErrorMessage, readNfcText } from '@/lib/web-nfc';

/**
 * Reads a resident's NFC keycard on a guard's Android phone (Web NFC) and
 * hands the code to the scanner, like a scanned QR. Renders nothing where
 * the browser has no Web NFC.
 */
export default function NfcTagReadButton({ onRead, disabled }: { onRead: (code: string) => void; disabled?: boolean }) {
  const { toast } = useToast();
  const [supported, setSupported] = useState(false);
  const [listening, setListening] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    setSupported(isWebNfcSupported());

    return () => abortRef.current?.abort();
  }, []);

  if (!supported) {
    return null;
  }

  const start = async () => {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;
    setListening(true);

    try {
      onRead(await readNfcText(controller.signal));
    } catch (error) {
      const message = nfcErrorMessage(error);
      if (message) {
        toast({ title: 'NFC tag not read', description: message, variant: 'destructive' });
      }
    } finally {
      controller.abort();
      setListening(false);
    }
  };

  return listening ? (
    <Button type="button" variant="outline" className="w-full h-11 gap-2" onClick={() => abortRef.current?.abort()}>
      <X className="w-4 h-4" />
      Hold the keycard to the back of this phone… (cancel)
    </Button>
  ) : (
    <Button type="button" variant="outline" className="w-full h-11 gap-2" onClick={start} disabled={disabled}>
      <Nfc className="w-4 h-4" />
      Read NFC keycard
    </Button>
  );
}
