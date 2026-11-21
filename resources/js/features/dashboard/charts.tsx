/**
 * Small hand-drawn charts for the home screen. No chart library: three simple shapes are all it needs.
 * Rules followed (docs/UI_UX_GUIDELINES.md, "Charts"): thin marks, rounded ends, a white gap between
 * touching marks, grey hairline grid, values in normal text colour, a legend whenever there are two
 * or more series, and the same numbers available as a table for screen readers.
 */
import { useState, type ReactNode } from 'react';
import { formatCompactRupees, formatMoney } from '@/lib/money';

export type ColumnPoint = { key: string; label: string; longLabel: string; value: number; note?: string };

/** Round the top of the axis up to a clean number of rupees and return three ticks. */
function axis(maxPaise: number): number[] {
    const max = Math.max(maxPaise, 10000);
    const magnitude = 10 ** Math.floor(Math.log10(max));
    const top = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= max) ?? max;
    return [0, top / 2, top];
}

/** One column per day. Single series, so the card title names it and there is no legend. */
export function ColumnChart({ points, tableCaption, valueHeading, labelHeading }: { points: ColumnPoint[]; tableCaption: string; valueHeading: string; labelHeading: string }) {
    const [active, setActive] = useState<number | null>(null);
    const width = 640;
    const height = 210;
    const pad = { left: 46, right: 8, top: 12, bottom: 26 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;
    const ticks = axis(Math.max(...points.map((p) => p.value), 0));
    const top = ticks[ticks.length - 1];
    const band = plotW / points.length;
    const barW = Math.min(24, band - 6);
    const y = (value: number) => pad.top + plotH - (value / top) * plotH;
    const shown = active === null ? null : points[active];

    return (
        <div className="relative">
            <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label={tableCaption}>
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line x1={pad.left} x2={width - pad.right} y1={y(tick)} y2={y(tick)} stroke="var(--color-line)" strokeWidth="1" />
                        <text x={pad.left - 8} y={y(tick) + 4} textAnchor="end" fontSize="11" fill="var(--color-ink-soft)">
                            {formatCompactRupees(tick)}
                        </text>
                    </g>
                ))}
                {points.map((point, index) => {
                    const x = pad.left + band * index + (band - barW) / 2;
                    const h = Math.max(point.value > 0 ? 2 : 0, pad.top + plotH - y(point.value));
                    const r = Math.min(4, h);
                    const base = pad.top + plotH;
                    return (
                        <g key={point.key}>
                            {/* Rounded at the data end, square at the baseline. */}
                            {h > 0 && (
                                <path
                                    d={`M${x},${base} V${base - h + r} Q${x},${base - h} ${x + r},${base - h} H${x + barW - r} Q${x + barW},${base - h} ${x + barW},${base - h + r} V${base} Z`}
                                    className="grow-y" style={{ animationDelay: `${index * 25}ms` }} fill="var(--color-primary)"
                                    opacity={active === null || active === index ? 1 : 0.45}
                                />
                            )}
                            {(index % 2 === points.length % 2 ? false : true) && (
                                <text x={x + barW / 2} y={height - 8} textAnchor="middle" fontSize="11" fill="var(--color-ink-soft)">
                                    {point.label}
                                </text>
                            )}
                            {/* The whole band is the hover and touch target, not just the thin bar. */}
                            <rect
                                x={pad.left + band * index}
                                y={pad.top}
                                width={band}
                                height={plotH + pad.bottom}
                                fill="transparent"
                                tabIndex={0}
                                aria-label={`${point.longLabel}: ${formatMoney(point.value)}`}
                                onMouseEnter={() => setActive(index)}
                                onMouseLeave={() => setActive(null)}
                                onFocus={() => setActive(index)}
                                onBlur={() => setActive(null)}
                                onClick={() => setActive(index)}
                            />
                        </g>
                    );
                })}
            </svg>
            {shown && active !== null && (
                <div
                    className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-lg border border-line bg-white px-3 py-2 text-sm shadow-pop"
                    style={{ left: `${((pad.left + band * (active + 0.5)) / width) * 100}%` }}
                >
                    <p className="whitespace-nowrap text-ink-soft">{shown.longLabel}</p>
                    <p className="whitespace-nowrap font-bold">{formatMoney(shown.value)}</p>
                    {shown.note && <p className="whitespace-nowrap text-ink-soft">{shown.note}</p>}
                </div>
            )}
            <table className="sr-only">
                <caption>{tableCaption}</caption>
                <thead>
                    <tr>
                        <th>{labelHeading}</th>
                        <th>{valueHeading}</th>
                    </tr>
                </thead>
                <tbody>
                    {points.map((point) => (
                        <tr key={point.key}>
                            <td>{point.longLabel}</td>
                            <td>{formatMoney(point.value)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export type Segment = { key: string; label: string; value: number; color: string; icon?: ReactNode };

/** Parts of a whole as one bar with white gaps, and a legend that carries every value in words. */
export function SplitBar({ segments, emptyText }: { segments: Segment[]; emptyText: string }) {
    const total = segments.reduce((sum, segment) => sum + segment.value, 0);

    return (
        <div>
            {total > 0 ? (
                <div className="grow-x flex h-3 gap-0.5 overflow-hidden rounded-full" aria-hidden>
                    {segments
                        .filter((segment) => segment.value > 0)
                        .map((segment) => (
                            <span key={segment.key} style={{ width: `${(segment.value / total) * 100}%`, background: segment.color }} className="min-w-1.5 rounded-sm" />
                        ))}
                </div>
            ) : (
                <p className="rounded-lg bg-surface px-3 py-2 text-sm text-ink-soft">{emptyText}</p>
            )}
            <ul className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2">
                {segments.map((segment) => (
                    <li key={segment.key} className="flex items-center gap-2">
                        <span className="size-3 shrink-0 rounded-sm" style={{ background: segment.color }} aria-hidden />
                        <span className="min-w-0">
                            <span className="block truncate text-sm text-ink-soft">{segment.label}</span>
                            <span className="block font-semibold">
                                {formatMoney(segment.value)}
                                {total > 0 && <span className="ml-1 text-sm font-normal text-ink-soft">{Math.round((segment.value / total) * 100)}%</span>}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** A ranked list where each row has a thin bar showing its size against the biggest. */
export function RankList({ rows }: { rows: { key: string | number; label: ReactNode; value: number; color: string }[] }) {
    const max = Math.max(...rows.map((row) => row.value), 1);

    return (
        <ol className="flex flex-col gap-3">
            {rows.map((row) => (
                <li key={row.key}>
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="min-w-0 truncate font-medium">{row.label}</span>
                        <span className="shrink-0 font-semibold">{formatMoney(row.value)}</span>
                    </div>
                    <div className="mt-1 h-2 rounded-full bg-surface" aria-hidden>
                        <div className="grow-x h-2 rounded-full" style={{ width: `${Math.max((row.value / max) * 100, 2)}%`, background: row.color }} />
                    </div>
                </li>
            ))}
        </ol>
    );
}
