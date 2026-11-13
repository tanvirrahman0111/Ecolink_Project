// EcoLink - small client-side enhancements

document.addEventListener('DOMContentLoaded', function () {
    // Confirm before destructive actions (suspend, decline)
    document.querySelectorAll('button[value="suspend"], button[value="decline"]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const label = btn.value === 'suspend' ? 'suspend this user' : 'decline this match';
            if (!confirm('Are you sure you want to ' + label + '?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-dismiss flash alerts after 6 seconds
    document.querySelectorAll('.alert').forEach(function (el) {
        setTimeout(function () { el.style.display = 'none'; }, 6000);
    });

    // Try to auto-fill latitude/longitude via browser geolocation on the
    // donation upload form, if the fields are present and empty.
    const latField = document.querySelector('input[name="latitude"]');
    const lngField = document.querySelector('input[name="longitude"]');
    if (latField && lngField && !latField.value && navigator.geolocation) {
        const hint = document.createElement('small');
        hint.textContent = 'Tip: click to auto-fill your current location.';
        hint.style.cursor = 'pointer';
        hint.style.color = '#1b7a4d';
        latField.parentElement.appendChild(hint);
        hint.addEventListener('click', function () {
            navigator.geolocation.getCurrentPosition(function (pos) {
                latField.value = pos.coords.latitude.toFixed(6);
                lngField.value = pos.coords.longitude.toFixed(6);
            });
        });
    }

    // ---- Responsive helpers ------------------------------------------
    // Mobile sidebar drawer
    const body = document.body;
    const toggleBtn = document.querySelector('[data-sidebar-toggle]');
    function setSidebar(open) {
        body.classList.toggle('sidebar-open', open);
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () { setSidebar(!body.classList.contains('sidebar-open')); });
        document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
            el.addEventListener('click', function () { setSidebar(false); });
        });
        document.querySelectorAll('.sidebar .side-link').forEach(function (el) {
            el.addEventListener('click', function () { setSidebar(false); });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setSidebar(false); });
        window.addEventListener('resize', function () { if (window.innerWidth > 960) setSidebar(false); });
    }

    // Wide tables scroll sideways inside their own box instead of breaking the page
    document.querySelectorAll('table.data-table').forEach(function (t) {
        if (t.parentElement && t.parentElement.classList.contains('table-scroll')) return;
        const wrap = document.createElement('div');
        wrap.className = 'table-scroll';
        t.parentNode.insertBefore(wrap, t);
        wrap.appendChild(t);
    });
});
