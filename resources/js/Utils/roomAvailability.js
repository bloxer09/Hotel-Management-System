/**
 * Reservation room picker copy. Future-date availability is based on
 * stay overlap, not the room's current physical status.
 */
import { formatHotelTime } from '@/Utils/datetime';
export function reservationAvailabilityNote(status) {
    if (!status || status === 'vacant') {
        return null;
    }

    const current = status === 'occupied'
        ? 'Currently occupied'
        : status === 'cleaning'
            ? 'Currently cleaning'
            : status === 'out_of_order'
                ? 'Currently out of service'
                : `Currently ${status.replaceAll('_', ' ')}`;

    return `${current} — available for selected dates`;
}

export function checkInRoomAvailabilityNote(room) {
    if (room?.temporarily_available && room.next_reserved_check_in) {
        const buffer = Number(room.turnover_buffer_minutes ?? 20);
        const latestSafe = room.safe_checkout_cutoff || room.available_until;
        return {
            tone: 'temporary',
            lines: [
                `Next Reservation: ${formatHotelTime(room.next_reserved_check_in)}`,
                `Cleaning Time: ${buffer} minutes`,
                `Latest Safe Checkout: ${latestSafe ? formatHotelTime(latestSafe) : '—'}`,
            ],
        };
    }

    return {
        tone: 'available',
        lines: ['Available'],
    };
}
