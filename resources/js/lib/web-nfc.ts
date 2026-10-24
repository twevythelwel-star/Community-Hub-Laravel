/**
 * Web NFC (Chrome on Android): write a pass's signed NFC code to a keycard,
 * and read it back at the gate. A browser cannot make the phone itself act
 * as a contactless card, so the keycard or fob is what gets tapped.
 *
 * The code written is the pass's signed NFC message (NFC:CHW1.<id>.<sig>);
 * the gate accepts it like the wallet pass, and refuses it once the pass is
 * reported lost.
 */

interface NdefRecord {
  recordType: string;
  encoding?: string;
  data?: DataView;
}

interface NdefReadingEvent extends Event {
  message: { records: NdefRecord[] };
}

interface NdefReader extends EventTarget {
  write(message: { records: Array<{ recordType: 'text'; data: string }> }, options?: { signal?: AbortSignal }): Promise<void>;
  scan(options?: { signal?: AbortSignal }): Promise<void>;
}

type NdefReaderConstructor = new () => NdefReader;

function ndefReader(): NdefReader {
  const Reader = (window as unknown as { NDEFReader?: NdefReaderConstructor }).NDEFReader;
  if (!Reader) {
    throw new Error('NFC is not available in this browser.');
  }

  return new Reader();
}

export function isWebNfcSupported(): boolean {
  return typeof window !== 'undefined' && 'NDEFReader' in window;
}

/** Resolves once a tag held to the phone has been written. */
export async function writeNfcText(text: string, signal?: AbortSignal): Promise<void> {
  await ndefReader().write({ records: [{ recordType: 'text', data: text }] }, { signal });
}

/** Resolves with the first text record on the next tag held to the phone. */
export function readNfcText(signal?: AbortSignal): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = ndefReader();

    reader.addEventListener('reading', (event) => {
      const record = (event as NdefReadingEvent).message.records.find((r) => r.recordType === 'text' && r.data);
      if (record?.data) {
        resolve(new TextDecoder(record.encoding || 'utf-8').decode(record.data).trim());
      } else {
        reject(new Error('This tag holds no gate pass.'));
      }
    }, { once: true });

    reader.addEventListener('readingerror', () => reject(new Error('The tag could not be read. Hold it still and try again.')), { once: true });
    signal?.addEventListener('abort', () => reject(new DOMException('Cancelled', 'AbortError')), { once: true });

    reader.scan({ signal }).catch(reject);
  });
}

/** A message for the person, from a Web NFC failure. */
export function nfcErrorMessage(error: unknown): string | null {
  const name = (error as { name?: string })?.name;
  if (name === 'AbortError') {
    return null;
  }
  if (name === 'NotAllowedError') {
    return 'NFC permission was refused. Allow NFC for this site, and make sure NFC is switched on in the phone settings.';
  }
  if (name === 'NotSupportedError') {
    return 'This phone has no NFC, or NFC is switched off.';
  }

  return (error as { message?: string })?.message || 'NFC failed. Try again.';
}
