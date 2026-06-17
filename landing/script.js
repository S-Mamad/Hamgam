(function () {
    'use strict';

    document.documentElement.classList.add('js');

    const $ = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

    /* ═══════════════════════════════════════════════════════
       Scroll progress
       ═══════════════════════════════════════════════════════ */
    const scrollProgress = $('#scrollProgress');

    function updateScrollProgress() {
        const h = document.documentElement.scrollHeight - window.innerHeight;
        const pct = h > 0 ? (window.scrollY / h) * 100 : 0;
        if (scrollProgress) scrollProgress.style.width = pct + '%';
    }

    /* ═══════════════════════════════════════════════════════
       Navbar
       ═══════════════════════════════════════════════════════ */
    const navbar = $('#navbar');
    const navToggle = $('#navToggle');
    const mobileNav = $('#mobileNav');
    const sections = $$('section[id]');
    const navLinks = $$('[data-nav]');

    function highlightNav() {
        let current = '';
        sections.forEach((sec) => {
            if (window.scrollY >= sec.offsetTop - 120) current = sec.id;
        });
        navLinks.forEach((a) => {
            a.classList.toggle('active', a.getAttribute('href') === '#' + current);
        });
    }

    function onScroll() {
        navbar?.classList.toggle('scrolled', window.scrollY > 24);
        updateScrollProgress();
        highlightNav();
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    if (navToggle && mobileNav) {
        navToggle.addEventListener('click', () => {
            const open = mobileNav.classList.toggle('open');
            navToggle.classList.toggle('open', open);
            navToggle.setAttribute('aria-expanded', String(open));
            mobileNav.hidden = !open;
        });
        $$('.mobile-nav a').forEach((a) => {
            a.addEventListener('click', () => {
                mobileNav.classList.remove('open');
                navToggle.classList.remove('open');
                mobileNav.hidden = true;
            });
        });
    }

    /* ═══════════════════════════════════════════════════════
       Cursor glow (desktop only)
       ═══════════════════════════════════════════════════════ */
    const cursorGlow = $('#cursorGlow');

    if (!isTouch && cursorGlow && !prefersReducedMotion) {
        document.body.classList.add('has-cursor');
        let cx = 0, cy = 0, tx = 0, ty = 0;

        document.addEventListener('mousemove', (e) => {
            tx = e.clientX;
            ty = e.clientY;
        }, { passive: true });

        function animateCursor() {
            cx += (tx - cx) * 0.12;
            cy += (ty - cy) * 0.12;
            cursorGlow.style.left = cx + 'px';
            cursorGlow.style.top = cy + 'px';
            requestAnimationFrame(animateCursor);
        }
        animateCursor();
    }

    /* ═══════════════════════════════════════════════════════
       Particle canvas
       ═══════════════════════════════════════════════════════ */
    const canvas = $('#particleCanvas');

    if (canvas && !prefersReducedMotion) {
        const ctx = canvas.getContext('2d');
        let particles = [];
        let w, h, animId;

        function resize() {
            w = canvas.width = canvas.offsetWidth * (window.devicePixelRatio || 1);
            h = canvas.height = canvas.offsetHeight * (window.devicePixelRatio || 1);
            ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);
            w /= window.devicePixelRatio || 1;
            h /= window.devicePixelRatio || 1;
            initParticles();
        }

        function initParticles() {
            const count = Math.min(60, Math.floor(w * h / 18000));
            particles = Array.from({ length: count }, () => ({
                x: Math.random() * w,
                y: Math.random() * h,
                vx: (Math.random() - 0.5) * 0.4,
                vy: (Math.random() - 0.5) * 0.4,
                r: Math.random() * 2 + 0.5,
                alpha: Math.random() * 0.4 + 0.1,
            }));
        }

        function drawParticles() {
            ctx.clearRect(0, 0, w, h);

            particles.forEach((p, i) => {
                p.x += p.vx;
                p.y += p.vy;
                if (p.x < 0) p.x = w;
                if (p.x > w) p.x = 0;
                if (p.y < 0) p.y = h;
                if (p.y > h) p.y = 0;

                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(37, 99, 235, ${p.alpha})`;
                ctx.fill();

                for (let j = i + 1; j < particles.length; j++) {
                    const q = particles[j];
                    const dx = p.x - q.x;
                    const dy = p.y - q.y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < 120) {
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(q.x, q.y);
                        ctx.strokeStyle = `rgba(37, 99, 235, ${0.06 * (1 - dist / 120)})`;
                        ctx.lineWidth = 0.5;
                        ctx.stroke();
                    }
                }
            });

            animId = requestAnimationFrame(drawParticles);
        }

        resize();
        drawParticles();
        window.addEventListener('resize', () => {
            cancelAnimationFrame(animId);
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            resize();
            drawParticles();
        });
    }

    /* ═══════════════════════════════════════════════════════
       Hero text rotation
       ═══════════════════════════════════════════════════════ */
    const heroRotate = $('#heroRotate');
    const rotateWords = ['خودکار', 'لحظه‌ای', 'هوشمند', 'بی‌دردسر'];
    let rotateIdx = 0;

    if (heroRotate && !prefersReducedMotion) {
        setInterval(() => {
            heroRotate.classList.add('is-changing');
            setTimeout(() => {
                rotateIdx = (rotateIdx + 1) % rotateWords.length;
                heroRotate.textContent = rotateWords[rotateIdx];
                heroRotate.classList.remove('is-changing');
            }, 300);
        }, 2800);
    }

    /* ═══════════════════════════════════════════════════════
       Hero 3D tilt
       ═══════════════════════════════════════════════════════ */
    const heroTilt = $('#heroTilt');

    if (heroTilt && !isTouch && !prefersReducedMotion) {
        heroTilt.addEventListener('mousemove', (e) => {
            const rect = heroTilt.getBoundingClientRect();
            const x = (e.clientX - rect.left) / rect.width - 0.5;
            const y = (e.clientY - rect.top) / rect.height - 0.5;
            const stage = $('.sync-stage', heroTilt);
            if (stage) {
                stage.style.transform = `rotateY(${x * 12}deg) rotateX(${-y * 12}deg)`;
            }
        });
        heroTilt.addEventListener('mouseleave', () => {
            const stage = $('.sync-stage', heroTilt);
            if (stage) stage.style.transform = '';
        });
    }

    /* ═══════════════════════════════════════════════════════
       Hero sync feed animation
       ═══════════════════════════════════════════════════════ */
    const feedItems = $$('.feed-item', $('#p24Feed'));
    const calEvents = $$('.cal-event', $('#gcalFeed'));
    let feedIdx = 0;

    if (feedItems.length && !prefersReducedMotion) {
        setInterval(() => {
            feedItems.forEach((el) => el.classList.remove('feed-item--pulse'));
            calEvents.forEach((el) => el.classList.remove('cal-event--flash'));

            feedIdx = (feedIdx + 1) % feedItems.length;
            feedItems[feedIdx]?.classList.add('feed-item--pulse');
            calEvents[feedIdx]?.classList.add('cal-event--flash');
        }, 3000);
    }

    /* ═══════════════════════════════════════════════════════
       Magnetic buttons
       ═══════════════════════════════════════════════════════ */
    if (!isTouch && !prefersReducedMotion) {
        $$('.magnetic').forEach((btn) => {
            btn.addEventListener('mousemove', (e) => {
                const rect = btn.getBoundingClientRect();
                const x = e.clientX - rect.left - rect.width / 2;
                const y = e.clientY - rect.top - rect.height / 2;
                btn.style.transform = `translate(${x * 0.2}px, ${y * 0.2}px)`;
            });
            btn.addEventListener('mouseleave', () => {
                btn.style.transform = '';
            });
        });
    }

    /* ═══════════════════════════════════════════════════════
       Bento card spotlight + tilt
       ═══════════════════════════════════════════════════════ */
    $$('[data-tilt]').forEach((card) => {
        if (isTouch) return;

        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const mx = ((e.clientX - rect.left) / rect.width) * 100;
            const my = ((e.clientY - rect.top) / rect.height) * 100;
            card.style.setProperty('--mx', mx + '%');
            card.style.setProperty('--my', my + '%');

            if (!prefersReducedMotion) {
                const x = (e.clientX - rect.left) / rect.width - 0.5;
                const y = (e.clientY - rect.top) / rect.height - 0.5;
                card.style.transform = `perspective(800px) rotateY(${x * 8}deg) rotateX(${-y * 8}deg) translateY(-4px)`;
            }
        });

        card.addEventListener('mouseleave', () => {
            card.style.transform = '';
        });
    });

    /* ═══════════════════════════════════════════════════════
       Timeline step reveal
       ═══════════════════════════════════════════════════════ */
    const timelineSteps = $$('.timeline__step');

    if ('IntersectionObserver' in window) {
        const tlObs = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                    }
                });
            },
            { threshold: 0.4 }
        );
        timelineSteps.forEach((step, i) => {
            step.style.transitionDelay = i * 0.15 + 's';
            tlObs.observe(step);
        });
    } else {
        timelineSteps.forEach((s) => s.classList.add('is-visible'));
    }

    /* ═══════════════════════════════════════════════════════
       Vacation demo cycle
       ═══════════════════════════════════════════════════════ */
    const vacSteps = $$('[data-vac]');
    let vacIdx = 0;

    if (vacSteps.length && !prefersReducedMotion) {
        setInterval(() => {
            vacSteps.forEach((s) => s.classList.remove('vac-demo__step--active'));
            vacIdx = (vacIdx + 1) % vacSteps.length;
            vacSteps[vacIdx]?.classList.add('vac-demo__step--active');
        }, 2500);
    }

    /* ═══════════════════════════════════════════════════════
       Live preview (customize section)
       ═══════════════════════════════════════════════════════ */
    const previewLines = $('#previewLines');
    const previewBar = $('#previewBar');
    const previewEvent = $('.preview-event');
    const colorMap = { yellow: '#E7BA51', green: '#489160', blue: '#3f51b5', red: '#DA5234', sky: '#3b82f6' };

    function updatePreview() {
        if (!previewLines) return;

        $$('.ctrl-toggle').forEach((toggle) => {
            const field = toggle.dataset.field;
            const line = $(`[data-line="${field}"]`, previewLines);
            if (line) line.classList.toggle('is-hidden', !toggle.checked);
        });

        previewEvent?.classList.add('is-updating');
        setTimeout(() => previewEvent?.classList.remove('is-updating'), 400);
    }

    $$('.ctrl-toggle').forEach((t) => t.addEventListener('change', updatePreview));

    $$('.ctrl-dot').forEach((dot) => {
        dot.addEventListener('click', () => {
            $$('.ctrl-dot').forEach((d) => d.classList.remove('active'));
            dot.classList.add('active');
            if (previewBar) previewBar.style.background = colorMap[dot.dataset.color] || colorMap.blue;
            updatePreview();
        });
    });

    /* ═══════════════════════════════════════════════════════
       FAQ search + filter
       ═══════════════════════════════════════════════════════ */
    const faqSearch = $('#faqSearch');
    const faqCats = $('#faqCats');
    const faqList = $('#faqList');
    const faqEmpty = $('#faqEmpty');
    let activeCat = 'all';

    function filterFaq() {
        const query = (faqSearch?.value || '').trim().toLowerCase();
        const items = $$('.faq-item', faqList);
        let visible = 0;

        items.forEach((item) => {
            const cat = item.dataset.cat || '';
            const keywords = (item.dataset.keywords || '').toLowerCase();
            const text = item.textContent.toLowerCase();
            const catMatch = activeCat === 'all' || cat === activeCat;
            const searchMatch = !query || text.includes(query) || keywords.includes(query);
            const show = catMatch && searchMatch;

            item.classList.toggle('is-hidden', !show);
            if (!show && item.open) item.open = false;
            if (show) visible++;
        });

        if (faqEmpty) faqEmpty.hidden = visible > 0;
    }

    faqSearch?.addEventListener('input', filterFaq);

    faqCats?.addEventListener('click', (e) => {
        const btn = e.target.closest('.faq__cat');
        if (!btn) return;
        $$('.faq__cat', faqCats).forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        activeCat = btn.dataset.cat;
        filterFaq();
    });

    /* FAQ accordion — one open at a time */
    $$('.faq-item').forEach((item) => {
        item.addEventListener('toggle', () => {
            if (!item.open) return;
            $$('.faq-item').forEach((other) => {
                if (other !== item && other.open) other.open = false;
            });
        });
    });

    /* ═══════════════════════════════════════════════════════
       Scroll reveal
       ═══════════════════════════════════════════════════════ */
    const revealEls = $$('.reveal');

    function revealInViewport() {
        revealEls.forEach((el) => {
            const rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight * 0.92 && rect.bottom > 0) {
                el.classList.add('is-visible');
            }
        });
    }

    if ('IntersectionObserver' in window) {
        const revealObs = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObs.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.08, rootMargin: '0px 0px -40px 0px' }
        );
        revealEls.forEach((el) => revealObs.observe(el));
        requestAnimationFrame(revealInViewport);
    } else {
        revealEls.forEach((el) => el.classList.add('is-visible'));
    }

    /* ═══════════════════════════════════════════════════════
       Scenario cards stagger on scroll
       ═══════════════════════════════════════════════════════ */
    $$('[data-scenario]').forEach((card, i) => {
        card.style.transitionDelay = (i % 4) * 0.08 + 's';
    });

    /* ═══════════════════════════════════════════════════════
       Persian number counters
       ═══════════════════════════════════════════════════════ */
    const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    const toPersian = (n) => String(n).replace(/\d/g, (d) => persianDigits[+d]);

    function animateCounter(el) {
        const target = parseInt(el.dataset.count, 10);
        if (isNaN(target)) return;
        const duration = 1600;
        const start = performance.now();

        function tick(now) {
            const p = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - p, 4);
            el.textContent = toPersian(Math.round(eased * target));
            if (p < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    const counters = $$('[data-count]');
    if ('IntersectionObserver' in window) {
        const counterObs = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        animateCounter(entry.target);
                        counterObs.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.5 }
        );
        counters.forEach((el) => counterObs.observe(el));
    }

    /* ═══════════════════════════════════════════════════════
       Smooth anchor scroll
       ═══════════════════════════════════════════════════════ */
    $$('a[href^="#"]').forEach((anchor) => {
        anchor.addEventListener('click', (e) => {
            const id = anchor.getAttribute('href');
            if (!id || id === '#') return;
            const target = $(id);
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth' });
        });
    });

})();
