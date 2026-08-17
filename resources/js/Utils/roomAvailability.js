/**
 * Reservation room picker copy. Future-date availability is based on
 * stay overlap, not the room's current physical status.
 */
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
