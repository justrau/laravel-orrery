/**
 * The rules of the filter bar's suggestion combobox (SuggestInput.vue, the
 * Class and Tag fields): the request, the options of an answer of
 * `GET /api/suggestions`, and the keys of the ARIA combobox pattern.
 *
 * Suggestions are optional: free text still works, a failed read shows no
 * list, and only a selected option fills the field with its full value.
 */

/** The wait after typing before the suggestions are read, in ms. */
export const SUGGEST_DEBOUNCE_MS = 200;

/** The fields with suggestions, and the longest text the server accepts (Http\Suggestions::MAX_QUERY). */
const FIELDS = ['class', 'tag'];
const MAX_QUERY = 255;

/** The request of the suggestions for the typed text, or null when there is none to send. */
export function suggestionRequest(field, text) {
    const q = typeof text === 'string' ? text : '';

    if (!FIELDS.includes(field) || [...q].length > MAX_QUERY) return null;

    return {url: '/api/suggestions', params: {field, q}};
}

/**
 * The options of an answer: `{key, value, primary, secondary, monitored}`.
 * A class shows its short name first and its full name second; selecting
 * fills `value`, the full name (the filter is byte-exact). A tag shows
 * itself, marked when it is monitored. Null when the body has no list (a
 * failed read, never "no suggestions").
 */
export function suggestionItems(data, field) {
    if (!data || !Array.isArray(data.values)) return null;

    const seen = new Set();
    const items = [];

    data.values.forEach(row => {
        const value = row && typeof row.value === 'string' ? row.value : '';

        if (value === '' || seen.has(value)) return;

        seen.add(value);

        const short = field === 'class' ? value.slice(value.lastIndexOf('\\') + 1) : value;
        const named = short !== '' && short !== value;

        items.push({key: value, value, primary: named ? short : value, secondary: named ? value : null, monitored: row.monitored === true});
    });

    return items;
}

/**
 * What a key does to the combobox `{open, active, count}`: the new
 * `{open, active}`, `prevent` (the key is the combobox's), `select` (the
 * index of the option to fill) and `cancel` (the pending read is dropped,
 * so its answer does not open the list again), or null when the key is not
 * its own. Down and Up open the list and move with wrap-around; Enter
 * selects the active option (without one it is the field's own Enter:
 * commit the text); Escape closes an open list; Tab closes it and moves on
 * (the blur ends the reads).
 */
export function comboKey({open, active, count}, key) {
    const last = count - 1;
    const closed = {open: false, active: null, select: null};

    switch (key) {
        case 'ArrowDown':
            if (count < 1) return null;

            return {open: true, active: !open || active === null || active >= last ? 0 : active + 1, prevent: true, select: null, cancel: false};
        case 'ArrowUp':
            if (count < 1) return null;

            return {open: true, active: !open || active === null || active <= 0 ? last : active - 1, prevent: true, select: null, cancel: false};
        case 'Enter':
            if (open && active !== null && active >= 0 && active < count) return {...closed, prevent: true, select: active, cancel: true};

            return {...closed, prevent: false, cancel: true};
        case 'Escape':
            return open ? {...closed, prevent: true, cancel: true} : null;
        case 'Tab':
            return {...closed, prevent: false, cancel: false};
        default:
            return null;
    }
}
