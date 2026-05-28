    </div><!-- end container-fluid -->
</div><!-- end main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= BASE_URL ?>assets/js/main.js"></script>

<script>
// Live clock
function updateClock() {
    const el = document.getElementById('liveClock');
    if (el) {
        const now = new Date();
        const h = now.getHours() % 12 || 12;
        const m = String(now.getMinutes()).padStart(2, '0');
        const ampm = now.getHours() >= 12 ? 'PM' : 'AM';
        el.textContent = h + ':' + m + ' ' + ampm;
    }
}
setInterval(updateClock, 1000);

// Sidebar toggle
document.getElementById('sidebarToggle')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('mainContent').classList.toggle('expanded');
});

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        const bsAlert = new bootstrap.Alert(a);
        bsAlert.close();
    });
}, 5000);
</script>

<?php if (!empty($canSeeNotifications)): ?>
<script>
const BASE_URL = <?= json_encode(BASE_URL) ?>;
const notificationSeenKeys = new Set();
let notificationInitialized = false;
let notificationAudioContext = null;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char];
    });
}

function unlockNotificationAudio() {
    if (typeof window.AudioContext === 'undefined' && typeof window.webkitAudioContext === 'undefined') {
        return;
    }
    if (!notificationAudioContext) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        notificationAudioContext = new Ctx();
    }
    if (notificationAudioContext.state === 'suspended') {
        notificationAudioContext.resume().catch(() => {});
    }
}

window.addEventListener('click', unlockNotificationAudio, { once: true });
window.addEventListener('keydown', unlockNotificationAudio, { once: true });

function playNotificationSound() {
    try {
        unlockNotificationAudio();
        if (!notificationAudioContext) return;
        const ctx = notificationAudioContext;
        const now = ctx.currentTime;
        [0, 0.22].forEach((offset, index) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = index === 0 ? 880 : 660;
            gain.gain.setValueAtTime(0.0001, now + offset);
            gain.gain.exponentialRampToValueAtTime(0.12, now + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(now + offset);
            osc.stop(now + offset + 0.2);
        });
    } catch (error) {
        console.warn('Notification sound failed:', error);
    }
}

function showNotificationToast(item) {
    const container = document.getElementById('notificationToastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    const isCheckout = item.type === 'checkout';
    const isDanger = item.state === 'overdue' || item.state === 'out_of_stock';
    const title = isCheckout
        ? (item.state === 'overdue' ? 'Overdue Checkout' : 'Upcoming Checkout')
        : (item.state === 'out_of_stock' ? 'Out of Stock' : 'Low Stock Alert');
    const subtitle = isCheckout
        ? `Expected out: ${escapeHtml(item.expected_check_out_label)}`
        : `Stock: ${escapeHtml(item.current_stock)} ${escapeHtml(item.unit)} • Minimum: ${escapeHtml(item.minimum_stock)}`;

    toast.className = 'toast align-items-center border-0 text-bg-' + (isDanger ? 'danger' : 'warning');
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <div class="fw-bold mb-1"><i class="fas ${isCheckout ? 'fa-bell' : 'fa-box-open'} me-2"></i>${title}</div>
                <div>${escapeHtml(item.message)}</div>
                <div class="small opacity-75 mt-1">${subtitle}</div>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;

    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 7000 });
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    bsToast.show();
}

function buildCheckoutNotificationHtml(item) {
    return `
        <a href="${item.target_url}" class="dropdown-item checkout-alert-item ${item.state}">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold small">Room ${escapeHtml(item.room_number)} <span class="text-muted fw-normal">• ${escapeHtml(item.guest_name)}</span></div>
                    <div class="small text-wrap">${escapeHtml(item.message)}</div>
                    <div class="text-muted" style="font-size:0.74rem">${escapeHtml(item.expected_check_out_label)} • ${escapeHtml(item.room_type)}</div>
                </div>
                <span class="badge ${item.state === 'overdue' ? 'bg-danger' : 'bg-warning text-dark'}">${item.state === 'overdue' ? 'Overdue' : '5 min'}</span>
            </div>
        </a>`;
}

function buildInventoryNotificationHtml(item) {
    return `
        <a href="${item.target_url}" class="dropdown-item checkout-alert-item ${item.state}">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold small">${escapeHtml(item.item_name)}</div>
                    <div class="small text-wrap">${escapeHtml(item.message)}</div>
                    <div class="text-muted" style="font-size:0.74rem">${escapeHtml(item.category)} • ${escapeHtml(item.current_stock)} ${escapeHtml(item.unit)} left</div>
                </div>
                <span class="badge ${item.state === 'out_of_stock' ? 'bg-danger' : 'bg-info text-dark'}">${item.state === 'out_of_stock' ? 'Out' : 'Low'}</span>
            </div>
        </a>`;
}

function renderNotifications(items) {
    const countEl = document.getElementById('notificationCount');
    const listEl = document.getElementById('notificationList');
    const bellEl = document.getElementById('notificationsToggle');
    if (!countEl || !listEl || !bellEl) return;

    const total = items.length;
    countEl.textContent = total;
    countEl.classList.toggle('d-none', total === 0);
    bellEl.classList.toggle('btn-outline-light', total === 0);
    bellEl.classList.toggle('btn-warning', total > 0);
    bellEl.classList.toggle('text-dark', total > 0);

    if (!total) {
        listEl.innerHTML = '<div class="px-3 py-3 text-center text-muted small">No notifications right now.</div>';
        return;
    }

    const inventoryItems = items.filter(item => item.type === 'inventory');
    const checkoutItems = items.filter(item => item.type === 'checkout');
    const sections = [];

    if (inventoryItems.length) {
        sections.push(`
            <div class="px-3 py-2 border-bottom bg-light">
                <div class="fw-bold small text-uppercase text-muted">Inventory Alerts</div>
                <div class="small text-muted">Most critical low-stock items first</div>
            </div>
            ${inventoryItems.map(buildInventoryNotificationHtml).join('')}`);
    }

    if (checkoutItems.length) {
        sections.push(`
            <div class="px-3 py-2 border-top border-bottom bg-light">
                <div class="fw-bold small text-uppercase text-muted">Checkout Alerts</div>
                <div class="small text-muted">Rooms that need front desk follow-up</div>
            </div>
            ${checkoutItems.map(buildCheckoutNotificationHtml).join('')}`);
    }

    listEl.innerHTML = sections.join('');
}

async function loadNotifications() {
    try {
        const response = await fetch(`${BASE_URL}includes/notifications.php`, { cache: 'no-store' });
        if (!response.ok) return;
        const payload = await response.json();
        const items = Array.isArray(payload.items) ? payload.items : [];
        renderNotifications(items);

        const newItems = [];
        items.forEach(item => {
            if (!notificationSeenKeys.has(item.alert_key)) {
                newItems.push(item);
                notificationSeenKeys.add(item.alert_key);
            }
        });

        if (notificationInitialized && newItems.length > 0) {
            playNotificationSound();
            newItems.forEach(showNotificationToast);
        }
        notificationInitialized = true;
    } catch (error) {
        console.warn('Notifications failed to load:', error);
    }
}

loadNotifications();
setInterval(loadNotifications, 30000);
</script>
<?php endif; ?>

<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
