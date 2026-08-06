/**
 * Standardized Philippine Peso Currency Formatter
 * 
 * @param {number|string} amount
 * @param {object} options
 * @returns {string} Formatted currency string (e.g. "₱1,250.00")
 */
export const formatPHP = (amount, options = {}) => {
    const numericValue = Number(amount) || 0;
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        ...options,
    }).format(numericValue);
};

/**
 * Parses currency input string to a clean float number
 * 
 * @param {string|number} value
 * @returns {number}
 */
export const parseCurrencyFloat = (value) => {
    if (typeof value === 'number') return value;
    if (!value) return 0;
    const cleanStr = String(value).replace(/[^0-9.-]+/g, '');
    return parseFloat(cleanStr) || 0;
};
