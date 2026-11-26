import { clsx } from 'clsx';
import { useId } from 'react';

export type MascotMood = 'hello' | 'happy' | 'empty' | 'sad';

/**
 * Ashu (short for Ashwini / Ashwin; owner decision D-066) — the shop's own mascot: a capsule in a shopkeeper's cap.
 * The capsule stands for the medical counter, the cap and apron for the general store.
 * Original artwork, drawn here as SVG so it needs no image file and stays sharp at any size.
 *
 * It is decoration: always hidden from screen readers, and it never carries information
 * that is not also written in words beside it. Motion stops when the device asks for less motion.
 *
 *  hello — waving (sign-in, greeting)        happy — arms up (something went well)
 *  empty — peering into an empty box         sad   — something failed / no connection
 */
export function Mascot({ mood = 'hello', className, still = false }: { mood?: MascotMood; className?: string; still?: boolean }) {
    const clip = useId();
    const lookDown = mood === 'empty' ? 2 : 0;

    return (
        <svg viewBox="0 0 120 140" className={clsx('mascot', !still && 'mascot-live', className)} aria-hidden focusable="false">
            <defs>
                <clipPath id={clip}>
                    <rect x="30" y="20" width="60" height="104" rx="30" />
                </clipPath>
            </defs>

            <ellipse cx="60" cy="133" rx="27" ry="4" fill="#1b2236" opacity=".09" />

            <g className="mascot-body">
                {/* feet */}
                <ellipse cx="49" cy="126" rx="8" ry="4.5" fill="#0b7d74" />
                <ellipse cx="71" cy="126" rx="8" ry="4.5" fill="#0b7d74" />

                {/* left arm */}
                {mood === 'happy' ? (
                    <g>
                        <path d="M32 86 Q18 80 15 64" fill="none" stroke="#0b7d74" strokeWidth="7" strokeLinecap="round" />
                        <circle cx="15" cy="62" r="5.5" fill="#eceefd" stroke="#c9cef7" strokeWidth="1" />
                    </g>
                ) : (
                    <g>
                        <path d="M32 86 Q20 92 19 106" fill="none" stroke="#0b7d74" strokeWidth="7" strokeLinecap="round" />
                        <circle cx="19" cy="108" r="5.5" fill="#eceefd" stroke="#c9cef7" strokeWidth="1" />
                    </g>
                )}

                {/* capsule: pale top half (the face), teal bottom half */}
                <g clipPath={`url(#${clip})`}>
                    <rect x="30" y="20" width="60" height="58" fill="#eceefd" />
                    <rect x="30" y="78" width="60" height="46" fill="#0d9e94" />
                    <rect x="30" y="76" width="60" height="4" fill="#0b7d74" opacity=".35" />
                    <rect x="36" y="26" width="7" height="92" rx="3.5" fill="#fff" opacity=".35" />
                </g>

                {/* apron with the green cross */}
                <rect x="49" y="89" width="22" height="22" rx="6" fill="#fff" />
                <path d="M60 94 v12 M54 100 h12" stroke="#12946a" strokeWidth="4.5" strokeLinecap="round" />

                {/* shopkeeper's cap */}
                <path d="M31 46 Q33 17 60 17 Q87 17 89 46 Z" fill="#4353e0" />
                <rect x="28" y="42" width="72" height="8" rx="4" fill="#3340b8" />
                <circle cx="60" cy="19" r="3.5" fill="#3340b8" />

                {/* face */}
                <g className="mascot-eyes">
                    <circle cx="50" cy={59 + lookDown} r="3.6" fill="#1b2236" />
                    <circle cx="70" cy={59 + lookDown} r="3.6" fill="#1b2236" />
                    <circle cx="51.2" cy={57.8 + lookDown} r="1.1" fill="#fff" />
                    <circle cx="71.2" cy={57.8 + lookDown} r="1.1" fill="#fff" />
                </g>
                {mood === 'sad' && <path d="M45 52 l8 2.5 M75 52 l-8 2.5" stroke="#1b2236" strokeWidth="2" strokeLinecap="round" />}
                <circle cx="43" cy="66" r="4" fill="#f59fb4" opacity=".55" />
                <circle cx="77" cy="66" r="4" fill="#f59fb4" opacity=".55" />
                {mood === 'happy' && <path d="M52 65 Q60 76 68 65 Z" fill="#1b2236" />}
                {mood === 'hello' && <path d="M52 66 Q60 73 68 66" fill="none" stroke="#1b2236" strokeWidth="2.4" strokeLinecap="round" />}
                {mood === 'empty' && <ellipse cx="60" cy="69" rx="3" ry="3.4" fill="#1b2236" />}
                {mood === 'sad' && <path d="M53 71 Q60 65 67 71" fill="none" stroke="#1b2236" strokeWidth="2.4" strokeLinecap="round" />}

                {/* right arm, by mood */}
                {(mood === 'hello' || mood === 'happy') && (
                    <g className={mood === 'hello' ? 'mascot-wave' : undefined}>
                        <path d="M88 86 Q102 80 105 64" fill="none" stroke="#0b7d74" strokeWidth="7" strokeLinecap="round" />
                        <circle cx="105" cy="62" r="5.5" fill="#eceefd" stroke="#c9cef7" strokeWidth="1" />
                    </g>
                )}
                {mood === 'sad' && (
                    <g>
                        <path d="M88 86 Q100 92 101 106" fill="none" stroke="#0b7d74" strokeWidth="7" strokeLinecap="round" />
                        <circle cx="101" cy="108" r="5.5" fill="#eceefd" stroke="#c9cef7" strokeWidth="1" />
                    </g>
                )}
                {mood === 'empty' && (
                    <g>
                        {/* an open, empty box held out in front */}
                        <path d="M88 88 Q96 92 96 100" fill="none" stroke="#0b7d74" strokeWidth="7" strokeLinecap="round" />
                        <path d="M84 100 h30 v20 a3 3 0 0 1 -3 3 h-24 a3 3 0 0 1 -3 -3 z" fill="#f2c98a" />
                        <path d="M84 100 l-6 -8 h8 l6 8 z M114 100 l6 -8 h-8 l-6 8 z" fill="#e0ad62" />
                        <path d="M84 100 h30" stroke="#b9853c" strokeWidth="1.5" />
                    </g>
                )}
            </g>

            {mood === 'happy' && (
                <g className="mascot-sparkle" fill="#eda100">
                    <path d="M12 38 l2 5 5 2 -5 2 -2 5 -2 -5 -5 -2 5 -2 z" />
                    <path d="M108 30 l1.6 4 4 1.6 -4 1.6 -1.6 4 -1.6 -4 -4 -1.6 4 -1.6 z" />
                    <path d="M100 8 l1.2 3 3 1.2 -3 1.2 -1.2 3 -1.2 -3 -3 -1.2 3 -1.2 z" />
                </g>
            )}
            {mood === 'sad' && <path d="M94 50 q4 7 0 10 q-4 -3 0 -10 z" fill="#2a78d6" opacity=".8" />}
        </svg>
    );
}
