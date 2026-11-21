import { Camera, Flashlight, ScanBarcode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { canScanWithCamera, cleanCode, liveCameraAvailable, loadBarcodeReader, readPhoto } from '@/lib/barcodeReader';
import { Alert, Button, Modal } from './ui';

/** What the screen did with a code. Shown inside the scanner, which then stays open for the next pack. */
export type ScanResult = { ok: boolean; text: string; action?: { label: string; run: () => void } };

/**
 * Return a result to keep scanning; return nothing when one code is all that was wanted.
 * `stillOpen` is false once the scanner has been closed: a lookup that finishes after that must change nothing.
 */
export type OnCode = (code: string, stillOpen: () => boolean) => Promise<ScanResult | void> | ScanResult | void;

type Problem = 'insecure' | 'blocked' | 'no_camera' | 'failed';

/**
 * A barcode that has been acted on counts again only after it has been out of the picture for this long,
 * so a pack held still, or read on and off in poor light, is never added twice.
 */
export const SAME_CODE_GAP_MS = 3000;

/**
 * Decides which barcode, if any, to act on in each camera frame.
 *  - A code must be read in two frames running, so one misread frame adds nothing.
 *  - Every code is remembered on its own: a pack with two barcodes, or two packs in view, cannot make
 *    the scanner add the same thing over and over as the reader flips between them.
 */
export function scanMemory(gap = SAME_CODE_GAP_MS) {
    const handled = new Map<string, number>();
    let previous = new Set<string>();
    return {
        /** The codes read in this frame; returns the one to act on now, or null. */
        frame(codes: string[], now: number): string | null {
            let pick: string | null = null;
            for (const code of new Set(codes)) {
                const at = handled.get(code);
                if (at !== undefined && now - at < gap) handled.set(code, now); // still in view: keep waiting
                else if (previous.has(code) && pick === null) pick = code;
            }
            previous = new Set(codes);
            return pick;
        },
        /** Call when the code has been dealt with (the lookup may have taken a while). */
        done(code: string, now: number) {
            handled.set(code, now);
            previous = new Set();
        },
    };
}

let sound: AudioContext | null = null;

/** Phones allow sound only after a tap, so the one sound channel is opened by the tap on the Scan button. */
function primeSound() {
    try {
        sound ??= new window.AudioContext();
        if (sound.state === 'suspended') void sound.resume();
    } catch {
        sound = null;
    }
}

function signal(ok: boolean) {
    try {
        navigator.vibrate?.(ok ? 60 : [60, 80, 60]);
        if (!sound) return;
        const tone = sound.createOscillator();
        const volume = sound.createGain();
        tone.frequency.value = ok ? 1250 : 320;
        volume.gain.value = 0.15;
        tone.connect(volume).connect(sound.destination);
        tone.start();
        tone.stop(sound.currentTime + (ok ? 0.09 : 0.25));
    } catch {
        // No sound or vibration on this device: the words on the screen still say what happened.
    }
}

function ScannerBody({ onCode, onClose, onRetry }: { onCode: OnCode; onClose: () => void; onRetry: () => void }) {
    const { t } = useTranslation();
    const video = useRef<HTMLVideoElement>(null);
    const file = useRef<HTMLInputElement>(null);
    const track = useRef<MediaStreamTrack | null>(null);
    const alive = useRef(true);
    const handler = useRef(onCode);
    handler.current = onCode;
    const [phase, setPhase] = useState<'starting' | 'live' | 'photo'>('starting');
    const [problem, setProblem] = useState<Problem | null>(null);
    const [result, setResult] = useState<ScanResult | null>(null);
    const [checking, setChecking] = useState(false);
    const [reading, setReading] = useState(false);
    const [light, setLight] = useState<boolean | null>(null); // null: this camera has no light

    async function handle(code: string) {
        let answer: ScanResult | void;
        setChecking(true);
        try {
            answer = await handler.current(code, () => alive.current);
        } catch {
            answer = { ok: false, text: t('app.error_generic') };
        }
        if (!alive.current) return; // closed while the lookup was on its way: say and do nothing
        setChecking(false);
        signal(answer ? answer.ok : true);
        if (answer) setResult(answer);
    }

    useEffect(() => {
        alive.current = true;
        let stopped = false;
        let stream: MediaStream | null = null;
        let timer = 0;
        const giveUp = (why: Problem) => {
            stream?.getTracks().forEach((each) => each.stop());
            if (!stopped) {
                setProblem(why);
                setPhase('photo');
            }
        };

        void (async () => {
            if (!liveCameraAvailable()) return giveUp('insecure');
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                });
            } catch (error) {
                const name = (error as { name?: string } | null)?.name ?? '';
                return giveUp(name === 'NotAllowedError' || name === 'SecurityError' ? 'blocked' : name === 'NotFoundError' || name === 'OverconstrainedError' ? 'no_camera' : 'failed');
            }
            const screen = video.current;
            if (stopped || !screen) return stream.getTracks().forEach((each) => each.stop());
            screen.srcObject = stream;
            try {
                await screen.play();
            } catch {
                // The picture starts by itself (autoplay) on browsers that refuse this call.
            }
            track.current = stream.getVideoTracks()[0] ?? null;
            // A phone call, a locked screen or another app can take the camera away.
            track.current?.addEventListener?.('ended', () => giveUp('failed'));
            const abilities = track.current?.getCapabilities?.() as { torch?: boolean } | undefined;
            if (abilities?.torch && !stopped) setLight(false);

            let reader;
            try {
                reader = await loadBarcodeReader();
            } catch {
                return giveUp('failed');
            }
            if (stopped) return;
            setPhase('live');

            const memory = scanMemory();
            const look = async () => {
                if (stopped) return;
                if (screen.readyState >= 2) {
                    try {
                        const codes = (await reader.detect(screen)).map((found) => cleanCode(found.rawValue)).filter((code): code is string => code !== null);
                        const code = stopped ? null : memory.frame(codes, Date.now());
                        if (code) {
                            await handle(code);
                            memory.done(code, Date.now());
                        }
                    } catch {
                        // One unreadable frame; the next one is tried.
                    }
                }
                if (!stopped) timer = window.setTimeout(look, 120);
            };
            void look();
        })();

        return () => {
            stopped = true;
            alive.current = false;
            window.clearTimeout(timer);
            stream?.getTracks().forEach((each) => each.stop());
        };
        // Runs once per opening: the dialog mounts this component when it opens and removes it when it closes.
    }, []);

    async function onPhoto(picked: File | undefined) {
        if (!picked) return;
        setReading(true);
        setResult(null);
        try {
            const code = await readPhoto(picked);
            if (!alive.current) return;
            if (code) await handle(code);
            else {
                signal(false);
                setResult({ ok: false, text: t('scan.photo_unread') });
            }
        } catch {
            if (alive.current) setResult({ ok: false, text: t('scan.failed') });
        } finally {
            if (alive.current) setReading(false);
            if (file.current) file.current.value = ''; // the same photo can be picked again
        }
    }

    async function toggleLight() {
        const next = !light;
        try {
            await track.current?.applyConstraints({ advanced: [{ torch: next } as MediaTrackConstraintSet] });
            setLight(next);
        } catch {
            setLight(null);
        }
    }

    return (
        // Takes the cursor when the dialog opens, so a stray Enter (a plug-in scanner sends one) does not press Close.
        <div className="flex flex-col gap-3 outline-none" tabIndex={-1} data-autofocus>
            {phase !== 'photo' && (
                <div className="relative overflow-hidden rounded-lg bg-rail">
                    <video ref={video} playsInline muted autoPlay className="block aspect-[4/3] w-full object-cover" />
                    <div className="pointer-events-none absolute inset-x-[8%] top-1/2 h-[38%] -translate-y-1/2 rounded-lg border-2 border-white/90 shadow-[0_0_0_100vmax_rgb(0_0_0/0.35)]" aria-hidden />
                    {phase === 'starting' && <p className="absolute inset-0 flex items-center justify-center text-white">{t('scan.starting')}</p>}
                </div>
            )}

            {phase === 'live' && <p className="text-center text-ink-soft">{t('scan.aim')}</p>}
            {problem && <Alert tone={problem === 'insecure' ? 'info' : 'warning'}>{t(`scan.${problem}`)}</Alert>}

            <p
                role="status"
                aria-live="polite"
                className={
                    checking
                        ? 'rounded-lg bg-primary-soft px-4 py-3 text-center text-base font-semibold text-primary-dark'
                        : result
                          ? `rounded-lg px-4 py-3 text-center text-base font-semibold ${result.ok ? 'bg-success-soft text-success-dark' : 'bg-danger-soft text-danger-dark'}`
                          : 'sr-only'
                }
            >
                {checking ? t('scan.checking') : result?.text}
            </p>
            {!checking && result?.action && (
                <Button variant="secondary" onClick={result.action.run} className="self-center">
                    {result.action.label}
                </Button>
            )}

            {/* With no camera at all the picture is chosen from the phone's files; otherwise the camera app opens. */}
            <input ref={file} type="file" accept="image/*" capture={problem === 'no_camera' ? undefined : 'environment'} className="hidden" onChange={(event) => void onPhoto(event.target.files?.[0])} />

            <div className="flex flex-wrap items-center justify-end gap-2">
                {phase === 'live' && light !== null && (
                    <Button variant="secondary" aria-pressed={light} onClick={() => void toggleLight()} icon={<Flashlight className="size-4" aria-hidden />}>
                        {t('scan.light')}
                    </Button>
                )}
                {problem === 'failed' && (
                    <Button variant="secondary" onClick={onRetry}>
                        {t('common.retry')}
                    </Button>
                )}
                {phase === 'photo' && (
                    <Button busy={reading || checking} onClick={() => file.current?.click()} icon={<Camera className="size-4" aria-hidden />} className="flex-1">
                        {t('scan.photo')}
                    </Button>
                )}
                <Button variant={phase === 'photo' ? 'secondary' : 'primary'} onClick={onClose}>
                    {t('scan.done')}
                </Button>
            </div>
        </div>
    );
}

/** A dialog that reads barcodes with the device's camera. */
export function CameraScanner({ open, onClose, onCode }: { open: boolean; onClose: () => void; onCode: OnCode }) {
    const { t } = useTranslation();
    const [attempt, setAttempt] = useState(0);
    return (
        <Modal title={t('scan.title')} open={open} onClose={onClose} closeLabel={t('common.close')}>
            <ScannerBody key={attempt} onCode={onCode} onClose={onClose} onRetry={() => setAttempt((n) => n + 1)} />
        </Modal>
    );
}

/** A PC with a mouse is offered the button only if it really has a camera; a phone or tablet always has one. */
function useCameraOffered(): boolean {
    const [offered, setOffered] = useState(canScanWithCamera);
    useEffect(() => {
        const touch = typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
        if (touch || !liveCameraAvailable() || typeof navigator.mediaDevices.enumerateDevices !== 'function') return;
        let current = true;
        navigator.mediaDevices
            .enumerateDevices()
            .then((devices) => current && setOffered(devices.some((device) => device.kind === 'videoinput')))
            .catch(() => undefined);
        return () => {
            current = false;
        };
    }, []);
    return offered;
}

/**
 * The camera button beside a search or barcode box, with its scanner. It is absent on a device with no camera,
 * so a counter PC with a plug-in scanner is not cluttered. `onClosed` runs after the scanner has closed.
 */
export function ScanButton({ onCode, onClosed, className }: { onCode: OnCode; onClosed?: () => void; className?: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const offered = useCameraOffered();
    if (!offered) return null;

    const close = () => {
        setOpen(false);
        // After the dialog has handed the cursor back to this button.
        if (onClosed) window.setTimeout(onClosed, 0);
    };

    return (
        <>
            <button
                type="button"
                onClick={() => {
                    primeSound();
                    setOpen(true);
                }}
                aria-label={t('scan.button')}
                title={t('scan.button')}
                className={`inline-flex items-center justify-center rounded-lg text-primary transition hover:bg-primary-soft active:scale-95 ${className ?? ''}`}
            >
                <ScanBarcode className="size-6" aria-hidden />
            </button>
            <CameraScanner
                open={open}
                onClose={close}
                onCode={async (code, stillOpen) => {
                    const answer = await onCode(code, stillOpen);
                    if (!answer && stillOpen()) close();
                    return answer;
                }}
            />
        </>
    );
}
