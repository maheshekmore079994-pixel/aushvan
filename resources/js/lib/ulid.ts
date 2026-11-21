const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32

/**
 * A 26-character id that sorts by time. Each cart gets one when it is
 * started; the server uses it to recognise a bill it has already saved.
 */
export function ulid(now: number = Date.now()): string {
    let time = '';
    let remaining = now;
    for (let i = 0; i < 10; i++) {
        time = ALPHABET[remaining % 32] + time;
        remaining = Math.floor(remaining / 32);
    }

    const random = crypto.getRandomValues(new Uint8Array(16));
    let tail = '';
    for (let i = 0; i < 16; i++) tail += ALPHABET[random[i] % 32];

    return time + tail;
}
