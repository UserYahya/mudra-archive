/* Mudra Archive - shared behaviour for the public pages.
   Theme switching, header state, mobile navigation, scroll reveals, the stat
   count-up, gallery coin flips, filter panel, and the coin-page viewer with
   its magnifying loupe. No dependencies. */
(function () {
    'use strict';

    var root = document.documentElement;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /* --- Theme ----------------------------------------------------------- */
    function currentTheme() { return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; }

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-bs-theme', theme);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', theme === 'dark' ? '#0b0d12' : '#f6f1e7'); }
        $all('[data-theme-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
            btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        });
    }

    applyTheme(currentTheme());

    $all('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try { localStorage.setItem('mudra_theme', next); } catch (e) { /* private mode */ }
        });
    });

    /* --- Header: shadow once the page scrolls ------------------------------ */
    var header = document.getElementById('siteHeader');
    if (header) {
        var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /* --- Mobile navigation ------------------------------------------------- */
    var navToggle = document.querySelector('[data-nav-toggle]');
    var mobileNav = document.getElementById('mobileNav');
    if (navToggle && mobileNav) {
        var setNav = function (open) {
            mobileNav.hidden = !open;
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            var icon = navToggle.querySelector('.material-symbols-outlined');
            if (icon) { icon.textContent = open ? 'close' : 'menu'; }
        };
        navToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            setNav(mobileNav.hidden);
        });
        mobileNav.addEventListener('click', function (e) {
            if (e.target.closest('a')) { setNav(false); }
        });
        document.addEventListener('click', function (e) {
            if (!mobileNav.hidden && !mobileNav.contains(e.target)) { setNav(false); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !mobileNav.hidden) { setNav(false); navToggle.focus(); }
        });
    }

    /* --- Highlight the nav link for the section in view --------------------- */
    var navLinks = $all('.site-nav a[data-nav-section]');
    if (navLinks.length && 'IntersectionObserver' in window) {
        var sections = navLinks
            .map(function (a) { return document.getElementById(a.getAttribute('data-nav-section')); })
            .filter(Boolean);
        if (sections.length) {
            var spy = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) { return; }
                    navLinks.forEach(function (a) {
                        a.classList.toggle('is-active', a.getAttribute('data-nav-section') === entry.target.id);
                    });
                });
            }, { rootMargin: '-45% 0px -50% 0px' });
            sections.forEach(function (s) { spy.observe(s); });
        }
    }

    /* --- Reveal on scroll ----------------------------------------------------
       Only elements still below the fold when this runs are hidden, so nothing
       already on screen flickers and nothing stays hidden without this script. */
    var revealables = $all('[data-reveal]');
    if (revealables.length && !reduceMotion && 'IntersectionObserver' in window) {
        var revealer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                var el = entry.target;
                el.classList.add('reveal-in');
                el.classList.remove('reveal-pending');
                obs.unobserve(el);
            });
        }, { rootMargin: '0px 0px -8% 0px' });
        revealables.forEach(function (el) {
            if (el.getBoundingClientRect().top > window.innerHeight * 0.92) {
                el.classList.add('reveal-pending');
                revealer.observe(el);
            }
        });
    }

    /* --- Stat count-up ------------------------------------------------------ */
    var counters = $all('[data-count]');
    if (counters.length && !reduceMotion && window.requestAnimationFrame) {
        var fmt = function (n) { return Math.round(n).toLocaleString('en-US'); };
        var run = function (el) {
            var target = parseInt(el.getAttribute('data-count'), 10);
            if (!isFinite(target) || target <= 0 || target > 1000000) { return; }
            var final = el.textContent;
            var start = null;
            var duration = 1400;
            var step = function (ts) {
                if (start === null) { start = ts; }
                var t = Math.min(1, (ts - start) / duration);
                var eased = 1 - Math.pow(1 - t, 3);
                el.textContent = t < 1 ? fmt(target * eased) : final;
                if (t < 1) { window.requestAnimationFrame(step); }
            };
            window.requestAnimationFrame(step);
        };
        if ('IntersectionObserver' in window) {
            var counterObs = new IntersectionObserver(function (entries, obs) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) { run(entry.target); obs.unobserve(entry.target); }
                });
            });
            counters.forEach(function (el) { counterObs.observe(el); });
        }
    }

    /* --- Gallery: tap a coin to turn it over --------------------------------- */
    document.addEventListener('click', function (e) {
        var scene = e.target.closest('[data-flip]');
        if (!scene) { return; }
        var flipper = scene.querySelector('.coin-3d-flipper.has-reverse');
        if (flipper) { flipper.classList.toggle('flipped'); }
    });

    /* --- Gallery: selects submit their form ----------------------------------- */
    $all('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form) { sel.form.submit(); } });
    });

    /* --- Gallery: filter panel (phones) --------------------------------------- */
    var filterToggle = document.querySelector('[data-filters-toggle]');
    var filterPanel = document.getElementById('filterPanel');
    if (filterToggle && filterPanel) {
        filterToggle.addEventListener('click', function () {
            var open = filterPanel.hidden;
            filterPanel.hidden = !open;
            filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                filterPanel.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'nearest' });
                var first = filterPanel.querySelector('input, select');
                if (first) { first.focus({ preventScroll: true }); }
            }
        });
    }

    /* --- About: expand the biography ------------------------------------------- */
    var bioToggle = document.querySelector('[data-bio-toggle]');
    var bioText = document.getElementById('bioText');
    if (bioToggle && bioText) {
        bioToggle.addEventListener('click', function () {
            var collapsed = bioText.classList.toggle('bio-collapsed');
            bioToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            bioToggle.querySelector('span').textContent = collapsed ? 'Read more' : 'Show less';
            bioToggle.querySelector('.material-symbols-outlined').textContent = collapsed ? 'expand_more' : 'expand_less';
        });
    }

    /* --- Coin page: obverse/reverse viewer and loupe ---------------------------- */
    var viewer = document.querySelector('[data-viewer]');
    if (viewer) {
        var faceButtons = $all('[data-face]');
        var hasReverse = !!viewer.querySelector('.viewer-face--rev');
        var lens = viewer.querySelector('.loupe-lens');

        var showFace = function (face) {
            if (face === 'reverse' && !hasReverse) { return; }
            viewer.classList.toggle('show-reverse', face === 'reverse');
            faceButtons.forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-face') === face ? 'true' : 'false');
            });
            hideLens();
        };

        var visibleFace = function () {
            return viewer.querySelector(viewer.classList.contains('show-reverse') ? '.viewer-face--rev' : '.viewer-face--obv');
        };

        faceButtons.forEach(function (b) {
            b.addEventListener('click', function () { showFace(b.getAttribute('data-face')); });
        });
        viewer.addEventListener('click', function () {
            if (hasReverse) { showFace(viewer.classList.contains('show-reverse') ? 'obverse' : 'reverse'); }
        });

        function hideLens() { if (lens) { lens.classList.remove('is-visible'); } }

        if (lens) {
            var zoom = 2.6;
            viewer.addEventListener('pointermove', function (e) {
                if (e.pointerType !== 'mouse') { return; }
                var face = visibleFace();
                if (!face || face.classList.contains('is-placeholder')) { hideLens(); return; }
                var img = face.querySelector('img');
                if (!img || !img.naturalWidth) { hideLens(); return; }

                var r = viewer.getBoundingClientRect();
                var x = e.clientX - r.left;
                var y = e.clientY - r.top;
                var radius = r.width / 2;
                if (Math.hypot(x - radius, y - radius) > radius) { hideLens(); return; }

                // Mirror object-fit: cover, so the lens shows exactly what is under the cursor.
                var cover = Math.max(r.width / img.naturalWidth, r.height / img.naturalHeight);
                var dw = img.naturalWidth * cover;
                var dh = img.naturalHeight * cover;
                var ox = (r.width - dw) / 2;
                var oy = (r.height - dh) / 2;
                var lw = lens.offsetWidth;
                var lh = lens.offsetHeight;

                lens.style.backgroundImage = 'url("' + (img.currentSrc || img.src).replace(/"/g, '%22') + '")';
                lens.style.backgroundSize = (dw * zoom) + 'px ' + (dh * zoom) + 'px';
                lens.style.backgroundPosition = (lw / 2 - (x - ox) * zoom) + 'px ' + (lh / 2 - (y - oy) * zoom) + 'px';
                lens.style.left = (x - lw / 2) + 'px';
                lens.style.top = (y - lh / 2) + 'px';
                lens.classList.add('is-visible');
            });
            viewer.addEventListener('pointerleave', hideLens);
        }

        document.addEventListener('keydown', function (e) {
            if (e.target.closest('input, textarea, select')) { return; }
            if (e.key === 'f' || e.key === 'F') {
                showFace(viewer.classList.contains('show-reverse') ? 'obverse' : 'reverse');
            }
        });
    }
})();
