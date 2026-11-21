import { useEffect, useState } from 'react';

/**
 * True when the screen is at most `maxWidth` pixels wide.
 * 767 (the default): a phone, where wide tables are shown as stacked cards.
 * 1023: anything below the desktop layout, where the bottom bar is shown.
 */
export function useIsNarrow(maxWidth = 767): boolean {
    const query = `(max-width: ${maxWidth}px)`;
    const supported = typeof window !== 'undefined' && typeof window.matchMedia === 'function';
    const [narrow, setNarrow] = useState(() => supported && window.matchMedia(query).matches);

    useEffect(() => {
        if (!supported) return;
        const media = window.matchMedia(query);
        const update = () => setNarrow(media.matches);
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, [supported, query]);

    return narrow;
}
