/* Hotel PMS - Main JavaScript */

// Initialize DataTables on any .data-table
// NOTE: DataTables cannot reliably handle tbody rows that contain colspan/rowspan.
// Some pages include a single "No data" row with colspan or summary/totals rows.
// To avoid the classic "Incorrect column count" warning, we skip DataTables
// initialization on tables that contain colspan/rowspan in tbody.
$(document).ready(function () {
    if ($('.data-table').length) {
        $('.data-table').each(function () {
            const $t = $(this);
            const hasSpan = $t.find('tbody td[colspan], tbody th[colspan], tbody td[rowspan], tbody th[rowspan]').length > 0;
            if (hasSpan) return; // keep table as-is; avoid DataTables warning

            $t.DataTable({
                responsive: true,
                pageLength: 25,
                order: [[0, 'desc']],
            });
        });
    }

    // Flatpickr datetime
    flatpickr('.flatpickr-datetime', {
        enableTime: true,
        dateFormat: 'Y-m-d H:i',
        time_24hr: false,
        allowInput: true,
    });

    // Flatpickr date only
    flatpickr('.flatpickr-date', {
        dateFormat: 'Y-m-d',
        allowInput: true,
    });
});

// Confirm delete helper
function confirmAction(msg, form) {
    if (confirm(msg || 'Are you sure?')) {
        if (form) form.submit();
        return true;
    }
    return false;
}

// Format peso
function formatPeso(n) {
    return '₱' + parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Numeric only
function numericOnly(el) {
    el.value = el.value.replace(/[^0-9.]/g, '');
}
