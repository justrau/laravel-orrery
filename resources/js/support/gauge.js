/**
 * The geometry of the wait gauge: a half circle from 0 (left) to 4× the wait
 * limit (right). The limit sits at a quarter of the arc. The zones are:
 * 0–1× neutral, 1–3× warning, 3–4× danger.
 */

/** The scale end, as a multiple of the limit. */
export const SCALE_LIMITS = 4;

/** The zones as fractions of the arc: [from, to, kind]. */
export const ZONES = [
    [0, 0.25, 'neutral'],
    [0.25, 0.75, 'warning'],
    [0.75, 1, 'danger'],
];

/**
 * The fraction of the arc (0..1) for an oldest age against a limit. A value
 * past the scale end stays at the end. Null when it cannot be drawn: no
 * limit, or an unknown age.
 */
export function gaugeFraction(oldestAge, threshold) {
    if (!(threshold > 0) || typeof oldestAge !== 'number' || !Number.isFinite(oldestAge)) {
        return null;
    }

    return Math.min(1, Math.max(0, oldestAge / (threshold * SCALE_LIMITS)));
}

/** The point at the fraction `f` of the half circle with centre (cx, cy) and radius r. */
export function arcPoint(cx, cy, r, f) {
    const angle = Math.PI * (1 - f);

    return {x: cx + r * Math.cos(angle), y: cy - r * Math.sin(angle)};
}

function fixed(value) {
    return value.toFixed(2);
}

/** The SVG path of the arc from fraction f0 to f1 (clockwise over the top). */
export function arcPath(cx, cy, r, f0, f1) {
    const start = arcPoint(cx, cy, r, f0);
    const end = arcPoint(cx, cy, r, f1);

    return `M${fixed(start.x)} ${fixed(start.y)}A${r} ${r} 0 0 1 ${fixed(end.x)} ${fixed(end.y)}`;
}

/** The SVG path of the radial tick at fraction f, `half` units inside and outside the arc. */
export function tickPath(cx, cy, r, f, half) {
    const inner = arcPoint(cx, cy, r - half, f);
    const outer = arcPoint(cx, cy, r + half, f);

    return `M${fixed(inner.x)} ${fixed(inner.y)}L${fixed(outer.x)} ${fixed(outer.y)}`;
}

/** "2.2×": the oldest age as a multiple of the limit, with one decimal. */
export function limitMultiple(oldestAge, threshold) {
    if (!(threshold > 0) || typeof oldestAge !== 'number') return null;

    const multiple = oldestAge / threshold;

    return `${multiple >= 10 ? Math.round(multiple) : multiple.toFixed(1).replace(/\.0$/, '')}×`;
}
