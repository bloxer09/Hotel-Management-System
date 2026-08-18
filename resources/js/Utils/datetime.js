/**
 * Hotel-local stay timestamps (check_in / check_out / expected_check_out)
 * are naive Asia/Manila wall-clock values. Format the stored digits
 * directly — never let the browser treat them as UTC (trailing Z) or
 * convert them through the local timezone.
 *
 * System timestamps such as created_at remain true UTC instants and
 * should keep using native Date parsing.
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function parseHotelLocalParts(value) {
    if (value == null || value === '') return null;

    const str = String(value).trim();
    const naive = str
        .replace('T', ' ')
        .replace(/\.\d+/, '')
        .replace(/[Zz]$/, '')
        .replace(/[+\-]\d{2}:?\d{2}$/, '')
        .trim();

    const match = naive.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!match) return null;

    return {
        year: Number(match[1]),
        month: Number(match[2]),
        day: Number(match[3]),
        hour: match[4] != null ? Number(match[4]) : 0,
        minute: match[5] != null ? Number(match[5]) : 0,
        second: match[6] != null ? Number(match[6]) : 0,
    };
}

function pad(n) {
    return String(n).padStart(2, '0');
}

function hour12Label(hour, minute) {
    const suffix = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return `${pad(hour12)}:${pad(minute)} ${suffix}`;
}

export const formatHotelDate = (dateStr) => {
    const parts = parseHotelLocalParts(dateStr);
    if (!parts) return '—';
    return `${MONTHS[parts.month - 1]} ${parts.day}, ${parts.year}`;
};

export const formatHotelTime = (dateStr) => {
    const parts = parseHotelLocalParts(dateStr);
    if (!parts) return '—';
    return hour12Label(parts.hour, parts.minute);
};

export const formatHotelDateTime = (dateStr) => {
    const parts = parseHotelLocalParts(dateStr);
    if (!parts) return '—';
    return `${formatHotelDate(dateStr)} ${formatHotelTime(dateStr)}`;
};

export const formatHotelShort = (dateStr) => {
    const parts = parseHotelLocalParts(dateStr);
    if (!parts) return '—';
    return `${MONTHS[parts.month - 1]} ${pad(parts.day)}, ${hour12Label(parts.hour, parts.minute)}`;
};

/** Ordering-only timestamp from naive hotel-local digits. */
export const hotelLocalTimestamp = (dateStr) => {
    const parts = parseHotelLocalParts(dateStr);
    if (!parts) return NaN;
    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
};

export const hotelLocalNowParts = () => {
    const formatted = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date());
    const get = (type) => Number(formatted.find((part) => part.type === type)?.value || 0);

    return {
        year: get('year'),
        month: get('month'),
        day: get('day'),
        hour: get('hour') % 24,
        minute: get('minute'),
        second: get('second'),
    };
};

export const hotelLocalNowTimestamp = () => {
    const parts = hotelLocalNowParts();
    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
};

/**
 * ShiftSession started_at/ended_at and other system/audit timestamps are
 * true UTC instants. Naive "YYYY-MM-DD HH:mm:ss" values must be treated as
 * UTC, then converted to Asia/Manila for display. Do not use formatHotelDate
 * (stay-field helper) for these.
 */
export function parseUtcInstant(value) {
    if (value == null || value === '') return null;

    const str = String(value).trim();
    if (!str) return null;

    if (/[zZ]$|[+\-]\d{2}:?\d{2}$/.test(str)) {
        const parsed = new Date(str);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    const naive = str
        .replace('T', ' ')
        .replace(/\.\d+/, '')
        .trim();
    const match = naive.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ ](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!match) {
        const fallback = new Date(str);
        return Number.isNaN(fallback.getTime()) ? null : fallback;
    }

    const iso = `${match[1]}-${match[2]}-${match[3]}T${match[4] ?? '00'}:${match[5] ?? '00'}:${match[6] ?? '00'}Z`;
    const parsed = new Date(iso);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export const formatUtcToManila = (value) => {
    const date = parseUtcInstant(value);
    if (!date) return '—';

    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).format(date);
};

export function toHotelDatetimeLocal(value) {
    const parts = parseHotelLocalParts(value);
    if (!parts) return '';
    return `${parts.year}-${pad(parts.month)}-${pad(parts.day)}T${pad(parts.hour)}:${pad(parts.minute)}`;
}
