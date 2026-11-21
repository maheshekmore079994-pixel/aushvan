/**
 * Reads barcodes from a phone or laptop camera, so a shop can bill without buying a scanner.
 * The browser's own reader is used where it exists (Chrome on Android); everywhere else a reader that is
 * shipped with the application is loaded the first time it is needed. Nothing is fetched from another site.
 */

export type BarcodeReader = { detect(source: ImageBitmapSource): Promise<{ rawValue: string }[]> };

type NativeDetector = {
    new (options: { formats: string[] }): BarcodeReader;
    getSupportedFormats(): Promise<string[]>;
};

/**
 * The kinds printed on retail packs and on the shop's own stickers (EAN-13 or Code 128). Each has a check
 * digit; kinds without one (Code 39) are left out because a misread could be taken for another product.
 */
const FORMATS = ['ean_13', 'ean_8', 'upc_a', 'code_128'] as const;

let loading: Promise<BarcodeReader> | null = null;

export function loadBarcodeReader(): Promise<BarcodeReader> {
    loading ??= makeReader().catch((error: unknown) => {
        loading = null; // a failed download may work on the next try
        throw error;
    });
    return loading;
}

async function makeReader(): Promise<BarcodeReader> {
    const Native = (window as unknown as { BarcodeDetector?: NativeDetector }).BarcodeDetector;
    if (Native) {
        try {
            const supported = await Native.getSupportedFormats();
            if (supported.includes('ean_13') && supported.includes('code_128')) {
                return new Native({ formats: FORMATS.filter((format) => supported.includes(format)) });
            }
        } catch {
            // Fall through to the shipped reader.
        }
    }

    const [library, wasm] = await Promise.all([import('barcode-detector/ponyfill'), import('zxing-wasm/reader/zxing_reader.wasm?url')]);
    // By default the library downloads its engine from a public CDN; the shop's copy is used instead.
    library.setZXingModuleOverrides({ locateFile: (path: string, prefix: string) => (path.endsWith('.wasm') ? wasm.default : prefix + path) });
    return new library.BarcodeDetector({ formats: [...FORMATS] });
}

/** A live camera picture needs a secure address (https, or localhost). */
export function liveCameraAvailable(): boolean {
    return typeof navigator !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function';
}

/** Whether to offer camera scanning at all: a live camera, or a phone/tablet that can take a photo instead. */
export function canScanWithCamera(): boolean {
    if (liveCameraAvailable()) return true;
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
}

/** What a scanner would have typed: printable characters only, of a sensible length. */
export function cleanCode(raw: string | undefined): string | null {
    const code = (raw ?? '').trim();
    return code !== '' && code.length <= 64 && /^[\x20-\x7E]+$/.test(code) ? code : null;
}

/** Reads the barcode in a photo. Null when none could be read. */
export async function readPhoto(file: Blob): Promise<string | null> {
    const reader = await loadBarcodeReader();
    let picture: ImageBitmap | null = null;
    try {
        // Phone photos are 12 megapixels or more; a smaller copy is read faster and just as well.
        picture = await createImageBitmap(file, { resizeWidth: 1600, resizeQuality: 'high' });
    } catch {
        picture = null;
    }
    try {
        for (const found of await reader.detect(picture ?? file)) {
            const code = cleanCode(found.rawValue);
            if (code) return code;
        }
        return null;
    } finally {
        picture?.close();
    }
}
