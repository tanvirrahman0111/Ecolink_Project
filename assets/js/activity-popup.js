// EcoLink - live activity pop-ups (new users, donations, received items, hub updates)
(function () {
    var root = document.getElementById('eco-pop');
    if (!root) return;
    var endpoint = root.getAttribute('data-endpoint');
    var stack = root.querySelector('.eco-pop-stack');
    var toggle = root.querySelector('.eco-pop-toggle');
    var MAX_VISIBLE = 3, SHOW_MS = 7000, POLL_MS = 12000;

    var ICONS = { user: '👋', donation: '🎁', received: '✅', hub: '📦' };
    var store = {
        get: function (k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) {} },
        lget: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        lset: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
    };

    var muted = store.lget('ecoPopMuted') === '1';
    function paintToggle() { toggle.setAttribute('aria-pressed', muted ? 'true' : 'false'); }
    paintToggle();
    toggle.addEventListener('click', function () {
        muted = !muted; store.lset('ecoPopMuted', muted ? '1' : '0'); paintToggle();
        if (muted) { queue.length = 0; stack.querySelectorAll('.eco-card').forEach(dismiss); }
    });

    var seen = [];
    try { seen = JSON.parse(store.get('ecoPopSeen') || '[]'); } catch (e) { seen = []; }
    function markSeen(k) { seen.push(k); if (seen.length > 120) seen = seen.slice(-80); store.set('ecoPopSeen', JSON.stringify(seen)); }

    var queue = [], visible = 0, draining = false;

    function ago(sec) {
        if (sec < 45) return 'just now';
        if (sec < 3600) return Math.round(sec / 60) + ' min ago';
        if (sec < 86400) return Math.round(sec / 3600) + ' h ago';
        return Math.round(sec / 86400) + ' d ago';
    }

    function confetti(card) {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var box = document.createElement('div'); box.className = 'eco-confetti';
        var colors = ['#2fbf5b', '#f7c65a', '#6aa6ee', '#b690e6', '#ff8a7a'];
        for (var i = 0; i < 16; i++) {
            var p = document.createElement('i'), a = Math.random() * Math.PI * 2, d = 40 + Math.random() * 60;
            p.style.background = colors[i % colors.length];
            p.style.setProperty('--x', Math.cos(a) * d + 'px');
            p.style.setProperty('--y', Math.sin(a) * d - 20 + 'px');
            p.style.setProperty('--r', (Math.random() * 360) + 'deg');
            p.style.animationDelay = (Math.random() * 0.15) + 's';
            box.appendChild(p);
        }
        card.appendChild(box);
        setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 1400);
    }

    function dismiss(card) {
        if (!card || card._gone) return; card._gone = true;
        clearTimeout(card._t);
        card.classList.remove('show'); card.classList.add('hide');
        setTimeout(function () { if (card.parentNode) card.parentNode.removeChild(card); visible--; drain(); }, 420);
    }

    function render(ev) {
        var card = document.createElement('div');
        card.className = 'eco-card t-' + ev.type;
        card.setAttribute('role', 'status');
        card.style.setProperty('--dur', SHOW_MS + 'ms');
        var ico = document.createElement('div'); ico.className = 'eco-ico'; ico.textContent = ICONS[ev.type] || '✨';
        var body = document.createElement('div'); body.className = 'eco-body';
        var t = document.createElement('strong'); t.className = 'eco-title'; t.textContent = ev.title;
        var p = document.createElement('p'); p.className = 'eco-text'; p.textContent = ev.text;
        var tm = document.createElement('span'); tm.className = 'eco-time'; tm.textContent = ago(ev.age || 0);
        body.appendChild(t); body.appendChild(p); body.appendChild(tm);
        var x = document.createElement('button'); x.type = 'button'; x.className = 'eco-close'; x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '&times;';
        x.addEventListener('click', function () { dismiss(card); });
        var bar = document.createElement('div'); bar.className = 'eco-bar';
        card.appendChild(ico); card.appendChild(body); card.appendChild(x); card.appendChild(bar);
        stack.appendChild(card);
        visible++;
        requestAnimationFrame(function () { requestAnimationFrame(function () {
            card.classList.add('show');
            if (ev.type === 'received' || ev.type === 'user') confetti(card);
        }); });
        function arm() { card._t = setTimeout(function () { dismiss(card); }, SHOW_MS); }
        arm();
        card.addEventListener('mouseenter', function () { clearTimeout(card._t); });
        card.addEventListener('mouseleave', arm);
    }

    function drain() {
        if (draining || muted) return;
        draining = true;
        (function step() {
            if (muted || !queue.length || visible >= MAX_VISIBLE) { draining = false; return; }
            render(queue.shift());
            setTimeout(step, 1300);
        })();
    }

    function poll() {
        if (document.hidden || muted) return schedule();
        var since = store.get('ecoPopSince');
        var url = endpoint + (since ? '?since=' + encodeURIComponent(since) : '');
        fetch(url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                store.set('ecoPopSince', data.now);
                (data.events || []).forEach(function (ev) {
                    if (seen.indexOf(ev.key) !== -1) return;
                    markSeen(ev.key); queue.push(ev);
                });
                drain();
            })
            .catch(function () {})
            .then(schedule);
    }
    function schedule() { setTimeout(poll, POLL_MS); }

    setTimeout(poll, 1500);
})();
