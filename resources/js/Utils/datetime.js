/**
 * Hotel Local Wall-Clock Datetime Formatting Helpers
 */

/**
 * Formats date string to legible hotel date (e.g. "Aug 6, 2026")
 * @param {string|Date} dateStr 
 * @returns {string}
 */
export const formatHotelDate = (dateStr) => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '—';
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

/**
 * Formats time string to 12-hour hotel clock (e.g. "02:00 PM")
 * @param {string|Date} dateStr 
 * @returns {string}
 */
export const formatHotelTime = (dateStr) => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '—';
    return date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
};

/**
 * Formats full datetime string (e.g. "Aug 6, 2026 02:00 PM")
 * @param {string|Date} dateStr 
 * @returns {string}
 */
export const formatHotelDateTime = (dateStr) => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return '—';
    return `${formatHotelDate(dateStr)} ${formatHotelTime(dateStr)}`;
};
