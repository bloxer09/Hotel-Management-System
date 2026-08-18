import { formatHotelDateTime, formatHotelShort, hotelLocalTimestamp, parseHotelLocalParts } from '@/Utils/datetime';

export const OVERNIGHT_CHECKIN_HOUR = 14;

export function isOvernightArrivalTime(checkIn) {
    const parts = parseHotelLocalParts(checkIn);
    if (!parts) return false;
    return (parts.hour * 60 + parts.minute) >= (OVERNIGHT_CHECKIN_HOUR * 60);
}

export function minimumOvernightNights(checkIn) {
    return isOvernightArrivalTime(checkIn) ? 1 : 2;
}

export function overnightStayHint(checkIn) {
    if (isOvernightArrivalTime(checkIn)) {
        return 'Standard checkout: 12:00 PM.';
    }

    return 'Early check-in Overnight requires at least 2 nights. For a one-day stay, use 24 Hours.';
}

export function overnightNightOptions(checkIn, currentNights) {
    const min = minimumOvernightNights(checkIn);
    const current = Math.max(min, Number(currentNights) || min);
    const max = Math.max(14, current);

    return Array.from({ length: max - min + 1 }, (_, i) => min + i);
}

export function stayDurationLabel(booking) {
    if (!booking) return '';

    if (booking.booking_type === 'overnight') {
        const nights = Math.max(1, Number(booking.num_nights || 1));
        return nights === 1 ? '1 night' : `${nights} nights`;
    }

    const hours = Number(booking.short_time_hours || 0);
    if (isTruncatedShortStay(booking)) {
        return `${hours} hours (paid package)`;
    }

    return `${hours} hours`;
}

export function isTruncatedShortStay(booking) {
    if (!booking || booking.booking_type !== 'short_time') return false;
    const hours = Number(booking.short_time_hours || 0);
    if (![3, 6, 12].includes(hours)) return false;

    const checkIn = hotelLocalTimestamp(booking.check_in);
    const checkOut = hotelLocalTimestamp(booking.expected_check_out);
    if (!Number.isFinite(checkIn) || !Number.isFinite(checkOut)) return false;

    return checkOut < checkIn + hours * 3600 * 1000;
}

export function formatStaySchedule(booking) {
    return {
        checkIn: formatHotelShort(booking?.check_in),
        checkOut: formatHotelShort(booking?.expected_check_out),
        duration: stayDurationLabel(booking),
    };
}

export function formatExpectedCheckout(value) {
    return formatHotelDateTime(value);
}
