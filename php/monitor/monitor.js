(function () {
    'use strict';

    function apiRoot() {
        const path = window.location.pathname || '';
        return path.startsWith('/monitor') ? '/monitor/api' : '/php/monitor/api';
    }

    const STORAGE_KEY = 'zamanak_monitor_key';

    const T = {
        tabs: {
            dashboard: { title: 'خلاصه وضعیت', desc: 'نمای کلی — آیا همه‌چیز درست کار می‌کند؟' },
            events:    { title: 'گزارش رویدادها', desc: 'لیست دقیق هر اتفاق در سیستم' },
            users:     { title: 'پزشکان', desc: 'اتصال Google Calendar و وضعیت sync' },
            'user-timeline': { title: 'تاریخچه پزشک', desc: 'همه کارهای سیستم روی یک پزشک — برای debug' },
            logs:      { title: 'خطاهای PHP', desc: 'فایل خطای سرور برای debug' },
            system:    { title: 'سلامت سرور', desc: 'دیتابیس، تنظیمات و زیرساخت' },
        },
        period: { 1: '۱ ساعت', 6: '۶ ساعت', 24: '۲۴ ساعت', 72: '۳ روز', 168: '۷ روز' },
        level: {
            debug: 'دیباگ', info: 'اطلاعات', warning: 'هشدار', error: 'خطا', critical: 'بحرانی',
        },
        levelIcon: { debug: '⚪', info: '🔵', warning: '🟡', error: '🔴', critical: '⛔' },
        channel: {
            system: 'سیستم', request: 'درخواست HTTP', webhook: 'وب‌هوک',
            'google-calendar': 'تقویم گوگل', 'google-vacation': 'مرخصی گوگل',
            paziresh24: 'پذیرش۲۴', hamgam: 'Hamgam', cron: 'زمان‌بندی', http: 'HTTP',
            auth: 'احراز هویت', integration: 'یکپارچه‌سازی', vacation: 'مرخصی', appointment: 'نوبت',
        },
        category: {
            system: 'سیستم', webhook: 'وب‌هوک', api: 'API', http: 'HTTP',
            auth: 'احراز هویت', cron: 'زمان‌بندی', integration: 'یکپارچه‌سازی',
            vacation: 'مرخصی', appointment: 'نوبت',
        },
        watch: { active: 'فعال', expired: 'منقضی شده', none: 'ندارد' },
        action: {
            'session.authenticated': 'ورود به Hamgam',
            'google.connected': 'اتصال Google',
            'google.disconnected': 'قطع Google',
            'settings.updated': 'ذخیره تنظیمات',
            'sync.completed': 'همگام‌سازی موفق',
            'sync.partial': 'همگام‌سازی جزئی',
            'sync.failed': 'همگام‌سازی ناموفق',
            'button.app_opened': 'باز کردن اپ',
            'button.repair_connection': 'ترمیم اتصال',
            'button.oauth_redirect': 'هدایت به OAuth',
            'button.widget_cleanup': 'حذف widget',
            'watch.registered': 'ثبت Watch',
            'watch.failed': 'خطای Watch',
            'watch.renew_failed': 'تمدید Watch ناموفق',
            'webhook.received': 'وب‌هوک تقویم',
            'webhook.sync_completed': 'پردازش وب‌هوک',
            'backfill.completed': 'Import مرخصی',
            'sync_token.repaired': 'ترمیم sync token',
            'appointment.created': 'ثبت نوبت',
            'appointment.updated': 'به‌روزرسانی نوبت',
            'appointment.cancelled': 'لغو نوبت',
            'integration.connected': 'اتصال یکپارچه‌سازی',
            'integration.failed': 'خطای یکپارچه‌سازی',
        },
        status: {
            ok:       { icon: '✓', title: 'همه‌چیز عادی است', desc: 'سیستم Hamgam بدون مشکل کار می‌کند.' },
            degraded: { icon: '⚠', title: 'برخی موارد نیاز به بررسی دارند', desc: 'خطا یا مشکل جزئی شناسایی شده — بخش «نیاز به توجه» را ببینید.' },
            critical: { icon: '✕', title: 'مشکل جدی!', desc: 'سیستم دچار مشکل بحرانی است — فوراً بررسی کنید.' },
        },
        checks: {
            database:              { label: 'دیتابیس',           desc: 'اتصال به PostgreSQL/MySQL' },
            monitor_schema:        { label: 'جداول مانیتورینگ', desc: 'ساختار جدول رویدادها' },
            monitor_enabled:       { label: 'ثبت رویداد',        desc: 'MONITOR_ENABLED' },
            monitor_events_total:  { label: 'کل رویدادهای ذخیره‌شده', desc: '' },
            users_total:           { label: 'کل پزشکان',         desc: '' },
            users_connected:       { label: 'پزشکان متصل به گوگل', desc: '' },
            events_24h:            { label: 'رویداد ۲۴ ساعت اخیر', desc: '' },
            errors_24h:            { label: 'خطای ۲۴ ساعت اخیر',  desc: '' },
            error_log_size:        { label: 'حجم فایل خطا',      desc: 'php-errors.log' },
            env_file:              { label: 'فایل تنظیمات',      desc: 'php/.env' },
            php:                   { label: 'نسخه PHP',          desc: '' },
            db_driver:             { label: 'نوع دیتابیس',       desc: '' },
            database_error:        { label: 'خطای دیتابیس',      desc: '' },
        },
    };

    const state = {
        apiKey: '', activeTab: 'dashboard', periodHours: 24,
        eventsOffset: 0, eventsLimit: 50, eventsTotal: 0,
        refreshTimer: null, usersCache: [], usersFilter: 'all',
        logRaw: [], currentEvent: null, chartRows: [],
        eventsBound: false, loading: false,
        timelineUserId: '', timelineOffset: 0, timelineLimit: 50, timelineTotal: 0,
    };

    const $ = (s) => document.querySelector(s);
    const $$ = (s) => Array.from(document.querySelectorAll(s));

    function trLevel(l)  { return T.level[l] || l; }
    function trChannel(c){
        if (!c) return '—';
        if (T.channel[c]) return T.channel[c];
        if (c.startsWith('hamgam')) return 'Hamgam';
        if (c.startsWith('google-vacation') || c.startsWith('google-calendar')) return 'مرخصی/تقویم گوگل';
        if (c.startsWith('paziresh24')) return 'پذیرش۲۴';
        if (c.startsWith('integrations')) return 'یکپارچه‌سازی';
        if (c.startsWith('webhook')) return 'وب‌هوک';
        return c;
    }
    function trCategory(c){ return T.category[c] || c; }
    function trWatch(w)  { return T.watch[w] || w; }
    function trAction(a) { return T.action[a] || a || '—'; }
    function periodLabel() { return T.period[state.periodHours] || (state.periodHours + ' ساعت'); }

    function getApiKey() {
        const fromUrl = new URLSearchParams(location.search).get('key');
        if (fromUrl) { sessionStorage.setItem(STORAGE_KEY, fromUrl); return fromUrl; }
        return sessionStorage.getItem(STORAGE_KEY) || '';
    }

    async function api(path, params = {}) {
        const url = new URL(apiRoot() + '/' + path.replace(/^\//, ''), location.origin);
        Object.entries(params).forEach(([k, v]) => { if (v != null && v !== '') url.searchParams.set(k, v); });
        const res = await fetch(url, { headers: { 'X-Monitor-Key': state.apiKey }, cache: 'no-store' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
        return data;
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function pill(level) {
        const l = level || 'info';
        return '<span class="pill pill-' + esc(l) + '">' + (T.levelIcon[l] || '') + ' ' + esc(trLevel(l)) + '</span>';
    }

    function formatDate(v) {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        return Number.isNaN(d.getTime()) ? v : d.toLocaleString('fa-IR', {
            year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit',
        });
    }

    function relativeTime(v) {
        if (!v) return '—';
        const d = new Date(String(v).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return v;
        const sec = Math.floor((Date.now() - d.getTime()) / 1000);
        if (sec < 60) return sec + ' ثانیه پیش';
        const min = Math.floor(sec / 60);
        if (min < 60) return min + ' دقیقه پیش';
        const hr = Math.floor(min / 60);
        if (hr < 24) return hr + ' ساعت پیش';
        const day = Math.floor(hr / 24);
        if (day < 7) return day + ' روز پیش';
        return formatDate(v);
    }

    function fmt(n) { return Number(n || 0).toLocaleString('fa-IR'); }

    function setUpdated() {
        $('#last-updated').textContent = 'آخرین بروزرسانی: ' + formatDate(new Date().toISOString());
    }

    function toast(msg, type) {
        const el = document.createElement('div');
        el.className = 'toast' + (type ? ' ' + type : '');
        el.textContent = msg;
        $('#toast-host').appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    function setConnStatus(ok) {
        $('#conn-status')?.classList.toggle('offline', !ok);
        if ($('#conn-label')) $('#conn-label').textContent = ok ? 'متصل به سرور' : 'قطع شده';
    }

    function emptyState(msg, hint) {
        return '<div class="empty-state"><p>' + esc(msg) + '</p>' +
            (hint ? '<small>' + esc(hint) + '</small>' : '') + '</div>';
    }

    // ── Auth ──
    function showLogin(err) {
        $('#login-screen').classList.remove('hidden');
        $('#app').classList.add('hidden');
        const e = $('#login-error');
        if (err) { e.textContent = err; e.classList.remove('hidden'); }
        else e.classList.add('hidden');
    }

    function showApp() {
        $('#login-screen').classList.add('hidden');
        $('#app').classList.remove('hidden');
    }

    async function verifyAndBoot() {
        state.apiKey = getApiKey();
        applyDeepLinkParams();
        if (!state.apiKey) { showLogin(); return; }
        try {
            await api('overview.php');
            showApp();
            if (state.timelineUserId && $('#timeline-user-id')) {
                $('#timeline-user-id').value = state.timelineUserId;
            }
            if (state.activeTab !== 'dashboard') {
                switchTab(state.activeTab, true);
            }
            await refreshAll();
            startAutoRefresh();
            setConnStatus(true);
        } catch (e) {
            sessionStorage.removeItem(STORAGE_KEY);
            setConnStatus(false);
            showLogin(e.message || 'کلید نامعتبر است');
        }
    }

    function applyDeepLinkParams() {
        const params = new URLSearchParams(location.search);
        const tab = params.get('tab');
        const user = params.get('user') || params.get('user_id');
        if (tab && T.tabs[tab]) state.activeTab = tab;
        if (user) state.timelineUserId = user.trim();
        const hours = parseInt(params.get('hours') || '', 10);
        if (hours && T.period[hours]) {
            state.periodHours = hours;
            $$('#period-pills button').forEach((b) => b.classList.toggle('active', parseInt(b.dataset.hours, 10) === hours));
        }
        const usersFilter = params.get('users_filter');
        if (usersFilter) {
            state.usersFilter = usersFilter;
            $$('#users-chips .chip').forEach((x) => x.classList.toggle('active', x.dataset.filter === usersFilter));
        }
    }

    // ── Bind ──
    function bindEvents() {
        $('#login-btn').addEventListener('click', async () => {
            const key = $('#login-key').value.trim();
            if (!key) return;
            sessionStorage.setItem(STORAGE_KEY, key);
            state.apiKey = key;
            try {
                await api('overview.php');
                showApp();
                await refreshAll();
                startAutoRefresh();
                setConnStatus(true);
            } catch (e) { showLogin(e.message); }
        });
        $('#login-key').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('#login-btn').click(); });
        $('#logout-btn').addEventListener('click', () => { sessionStorage.removeItem(STORAGE_KEY); location.reload(); });
        $('#refresh-btn').addEventListener('click', () => refreshAll(true));
        $$('#period-pills button').forEach((btn) => {
            btn.addEventListener('click', () => {
                $$('#period-pills button').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                state.periodHours = parseInt(btn.dataset.hours, 10) || 24;
                state.eventsOffset = 0;
                state.timelineOffset = 0;
                refreshAll();
            });
        });
        $('#auto-refresh').addEventListener('change', (e) => e.target.checked ? startAutoRefresh() : stopAutoRefresh());
        $$('.nav-item').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.tab)));
        $('#sidebar-toggle')?.addEventListener('click', () => $('#sidebar').classList.toggle('open'));
        $('#filter-apply').addEventListener('click', () => { state.eventsOffset = 0; loadEvents(); });
        $('#filter-clear').addEventListener('click', () => {
            ['filter-search','filter-user','filter-request'].forEach((id) => { $('#'+id).value = ''; });
            ['filter-channel','filter-level','filter-category'].forEach((id) => { $('#'+id).value = ''; });
            state.eventsOffset = 0; loadEvents();
        });
        let deb;
        $('#filter-search').addEventListener('input', () => {
            clearTimeout(deb);
            deb = setTimeout(() => { state.eventsOffset = 0; loadEvents(); }, 400);
        });
        $('#events-prev').addEventListener('click', () => { state.eventsOffset = Math.max(0, state.eventsOffset - state.eventsLimit); loadEvents(); });
        $('#events-next').addEventListener('click', () => {
            if (state.eventsOffset + state.eventsLimit < state.eventsTotal) { state.eventsOffset += state.eventsLimit; loadEvents(); }
        });
        $('#users-search').addEventListener('input', renderUsers);
        $('#users-body')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-timeline-user]');
            if (btn) openUserTimeline(btn.dataset.timelineUser);
        });
        $('#timeline-load')?.addEventListener('click', () => {
            const id = ($('#timeline-user-id')?.value || '').trim();
            if (!id) { toast('شناسه پزشک را وارد کنید', 'error'); return; }
            openUserTimeline(id);
        });
        $('#timeline-user-id')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') $('#timeline-load')?.click();
        });
        $('#timeline-prev')?.addEventListener('click', () => {
            state.timelineOffset = Math.max(0, state.timelineOffset - state.timelineLimit);
            loadUserTimeline(false);
        });
        $('#timeline-next')?.addEventListener('click', () => {
            if (state.timelineOffset + state.timelineLimit < state.timelineTotal) {
                state.timelineOffset += state.timelineLimit;
                loadUserTimeline(false);
            }
        });
        let timelineDeb;
        $('#timeline-search')?.addEventListener('input', () => {
            clearTimeout(timelineDeb);
            timelineDeb = setTimeout(() => { state.timelineOffset = 0; loadUserTimeline(false); }, 400);
        });
        $('#timeline-level')?.addEventListener('change', () => { state.timelineOffset = 0; if (state.timelineUserId) loadUserTimeline(false); });
        $('#timeline-category')?.addEventListener('change', () => { state.timelineOffset = 0; if (state.timelineUserId) loadUserTimeline(false); });
        $('#timeline-export')?.addEventListener('click', exportTimelineCsv);
        $('#timeline-events-link')?.addEventListener('click', () => {
            if (!state.timelineUserId) return;
            $('#filter-user').value = state.timelineUserId;
            $('#filter-level').value = $('#timeline-level')?.value || '';
            $('#filter-category').value = $('#timeline-category')?.value || '';
            state.eventsOffset = 0;
            switchTab('events');
        });
        $('#event-open-timeline')?.addEventListener('click', () => {
            if (state.currentEvent?.user_id) openUserTimeline(state.currentEvent.user_id);
            $('#event-modal').classList.add('hidden');
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') $('#event-modal')?.classList.add('hidden');
        });
        $$('#users-chips .chip').forEach((c) => c.addEventListener('click', () => {
            $$('#users-chips .chip').forEach((x) => x.classList.remove('active'));
            c.classList.add('active');
            state.usersFilter = c.dataset.filter;
            renderUsers();
        }));
        $('#export-csv').addEventListener('click', exportCsv);
        $('#log-search').addEventListener('input', renderLog);
        $('#log-level-filter').addEventListener('change', renderLog);
        $('#log-copy').addEventListener('click', () => navigator.clipboard.writeText($('#log-tail').textContent).then(() => toast('کپی شد')));
        $$('[data-close-modal]').forEach((el) => el.addEventListener('click', () => $('#event-modal').classList.add('hidden')));
        $('#copy-event-json').addEventListener('click', () => {
            if (state.currentEvent) navigator.clipboard.writeText(JSON.stringify(state.currentEvent, null, 2)).then(() => toast('JSON کپی شد'));
        });
        document.addEventListener('click', (e) => {
            const g = e.target.closest('[data-goto]');
            if (g) {
                switchTab(g.dataset.goto);
                if (g.dataset.filterLevel) { $('#filter-level').value = g.dataset.filterLevel; state.eventsOffset = 0; loadEvents(); }
                if (g.dataset.usersFilter) {
                    state.usersFilter = g.dataset.usersFilter;
                    $$('#users-chips .chip').forEach((x) => x.classList.toggle('active', x.dataset.filter === g.dataset.usersFilter));
                    renderUsers();
                }
            }
            const a = e.target.closest('[data-action]');
            if (a) { a.dataset.action === 'events-error' && (switchTab('events'), $('#filter-level').value = 'error', state.eventsOffset = 0, loadEvents()); }
            const userLink = e.target.closest('[data-timeline-user]');
            if (userLink && !userLink.closest('#users-body')) openUserTimeline(userLink.dataset.timelineUser);
        });
        $('#chart-area')?.addEventListener('mousemove', onChartHover);
        $('#chart-area')?.addEventListener('mouseleave', () => $('#chart-tooltip')?.classList.add('hidden'));
    }

    function switchTab(tab, skipRefresh) {
        state.activeTab = tab;
        $$('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
        $$('.panel').forEach((p) => p.classList.toggle('active', p.id === 'tab-' + tab));
        const t = T.tabs[tab] || { title: tab, desc: '' };
        $('#page-title').textContent = t.title;
        $('#page-desc').textContent = t.desc;
        $('#sidebar').classList.remove('open');
        if (!skipRefresh) refreshAll();
    }

    function startAutoRefresh() { stopAutoRefresh(); state.refreshTimer = setInterval(() => refreshAll(), 5000); }
    function stopAutoRefresh() { if (state.refreshTimer) clearInterval(state.refreshTimer); state.refreshTimer = null; }

    async function refreshAll(manual) {
        if (state.loading) return;
        state.loading = true;
        setUpdated();
        try {
            if (state.activeTab === 'dashboard') await loadDashboard();
            if (state.activeTab === 'events') await loadEvents();
            if (state.activeTab === 'users') await loadUsers();
            if (state.activeTab === 'user-timeline' && state.timelineUserId) await loadUserTimeline(false);
            if (state.activeTab === 'logs') await loadLogTail();
            if (state.activeTab === 'system') await loadSystem();
            setConnStatus(true);
            if (manual) toast('بروزرسانی شد');
        } catch (e) {
            setConnStatus(false);
            if (manual) toast(e.message || 'خطا', 'error');
        } finally { state.loading = false; }
    }

    // ── Dashboard ──
    async function loadDashboard() {
        const [overviewRes, statsRes, usersRes] = await Promise.all([
            api('overview.php'),
            api('stats.php', { hours: state.periodHours }),
            api('users.php', { limit: 200 }),
        ]);

        const sys = overviewRes.overview || {};
        const checks = sys.checks || {};
        const stats = statsRes.overview || {};
        const users = usersRes.users || [];
        state.usersCache = users;

        const total = parseInt(stats.total || 0, 10);
        const errors = parseInt(stats.errors || 0, 10);
        const warnings = parseInt(stats.warnings || 0, 10);
        const errorRate = total > 0 ? ((errors / total) * 100).toFixed(1) : '0';
        const successRate = total > 0 ? (100 - parseFloat(errorRate)).toFixed(1) : '100';
        const status = sys.status || 'ok';
        const st = T.status[status] || T.status.ok;

        // Hero
        const hero = $('#hero-status');
        hero.className = 'hero-status status-' + status;
        $('#hero-icon').textContent = st.icon;
        $('#hero-title').textContent = st.title;
        $('#hero-desc').textContent = st.desc + ' (بازه: ' + periodLabel() + ')';

        const disconnected = users.filter((u) => !u.connected).length;
        const watchExpired = users.filter((u) => u.watch_state === 'expired').length;

        $('#hero-stats').innerHTML =
            '<div class="hero-stat"><span class="hs-val">' + fmt(checks.users_connected) + '</span><span class="hs-label">پزشک متصل</span></div>' +
            '<div class="hero-stat"><span class="hs-val ' + (errors > 0 ? 'bad' : 'good') + '">' + fmt(errors) + '</span><span class="hs-label">خطا</span></div>' +
            '<div class="hero-stat"><span class="hs-val">' + fmt(total) + '</span><span class="hs-label">رویداد</span></div>' +
            '<div class="hero-stat"><span class="hs-val good">' + successRate + '%</span><span class="hs-label">موفقیت</span></div>';

        // Attention panel
        const alerts = [];
        if ((checks.database || '') !== 'ok') alerts.push({ type: 'critical', text: 'دیتابیس در دسترس نیست — سیستم کار نمی‌کند', action: 'system', label: 'بررسی سرور' });
        if (errors > 0) alerts.push({ type: 'error', text: fmt(errors) + ' خطا در ' + periodLabel() + ' گذشته', action: 'events-error', label: 'مشاهده خطاها' });
        if (warnings > 5) alerts.push({ type: 'warning', text: fmt(warnings) + ' هشدار ثبت شده', action: 'events', label: 'مشاهده رویدادها' });
        if (disconnected > 0) alerts.push({ type: 'warning', text: fmt(disconnected) + ' پزشک اتصال گوگل ندارند', action: 'users', usersFilter: 'disconnected', label: 'لیست پزشکان' });
        if (watchExpired > 0) alerts.push({ type: 'warning', text: fmt(watchExpired) + ' پزشک Watch تقویم منقضی شده', action: 'users', usersFilter: 'watch-expired', label: 'بررسی' });
        if ((checks.error_log_size_bytes || 0) > 500000) alerts.push({ type: 'warning', text: 'فایل خطای PHP بزرگ است (' + ((checks.error_log_size_bytes||0)/1024).toFixed(0) + ' KB)', action: 'logs', label: 'مشاهده لاگ' });

        const ap = $('#attention-panel');
        if (alerts.length) {
            ap.classList.remove('hidden');
            $('#attention-list').innerHTML = alerts.map((a) =>
                '<div class="attention-item type-' + a.type + '">' +
                '<span class="att-text">' + esc(a.text) + '</span>' +
                '<button type="button" class="link-btn" data-goto="' + esc(a.action === 'events-error' ? 'events' : a.action) + '"' +
                (a.action === 'events-error' ? ' data-filter-level="error"' : '') +
                (a.usersFilter ? ' data-users-filter="' + esc(a.usersFilter) + '"' : '') + '>' + esc(a.label) + ' ←</button></div>'
            ).join('');
        } else {
            ap.classList.add('hidden');
        }

        // Sidebar mini alert
        $('#sidebar-alerts').innerHTML = errors > 0
            ? '<div class="sidebar-alert bad">' + fmt(errors) + ' خطا</div>'
            : '<div class="sidebar-alert good">بدون خطا</div>';

        // Primary metrics
        $('#primary-metrics').innerHTML =
            metricCard('🔴', 'خطاها', fmt(errors), errors > 0 ? 'نیاز به بررسی' : 'بدون خطا', errors > 0 ? 'bad' : 'good') +
            metricCard('👨‍⚕️', 'پزشکان', fmt(checks.users_connected) + ' / ' + fmt(checks.users_total), 'متصل به Google', 'accent') +
            metricCard('📊', 'رویدادها', fmt(total), 'در ' + periodLabel(), '') +
            metricCard('✅', 'نرخ موفقیت', successRate + '%', errorRate + '% خطا', parseFloat(successRate) >= 95 ? 'good' : 'bad');

        renderBarList('#level-bars', (statsRes.by_level || []).map((r) => ({
            label: trLevel(r.level), total: r.total, errors: r.level === 'error' || r.level === 'critical' ? r.total : 0, key: r.level,
        })), 'level');

        renderBarList('#category-bars', (statsRes.by_category || []).map((r) => ({
            label: trCategory(r.category), total: r.total, errors: r.errors, key: r.category,
        })), 'category');

        renderBarList('#channel-list', (statsRes.by_channel || []).slice(0, 8).map((r) => ({
            label: trChannel(r.channel), total: r.total, errors: r.errors, key: r.channel,
        })), 'channel');

        state.chartRows = statsRes.by_hour || [];
        renderChart(state.chartRows);
        renderActivityFeed(statsRes.recent_activity || []);
        renderRecentErrors(statsRes.recent_errors || []);
        populateChannelFilter(statsRes.channels || []);
    }

    function metricCard(icon, title, value, sub, cls) {
        return '<div class="metric-card ' + (cls||'') + '"><span class="mc-icon">' + icon + '</span>' +
            '<div class="mc-body"><div class="mc-title">' + esc(title) + '</div>' +
            '<div class="mc-value">' + esc(String(value)) + '</div>' +
            '<div class="mc-sub">' + esc(sub) + '</div></div></div>';
    }

    function renderBarList(sel, rows, type) {
        const el = $(sel);
        if (!rows.length) {
            el.innerHTML = emptyState('هنوز داده‌ای ثبت نشده', 'وقتی سیستم فعال شود اینجا پر می‌شود');
            return;
        }
        const max = Math.max(...rows.map((r) => parseInt(r.total || 0, 10)), 1);
        el.innerHTML = rows.map((row) => {
            const t = parseInt(row.total || 0, 10);
            const err = parseInt(row.errors || 0, 10);
            const pct = (t / max) * 100;
            const fillCls = type === 'level'
                ? 'bar-fill level-' + esc(row.key || 'info')
                : 'bar-fill ' + type;
            return '<div class="bar-row">' +
                '<span class="bar-label" title="' + esc(row.key || row.label) + '">' + esc(row.label) + '</span>' +
                '<div class="bar-track"><div class="' + fillCls + '" style="width:' + pct + '%"></div></div>' +
                '<span class="bar-count">' + fmt(t) + (err ? ' <em class="bad-text">(' + fmt(err) + ' خطا)</em>' : '') + '</span></div>';
        }).join('');
    }

    // ── Chart ──
    function renderChart(rows) {
        const canvas = $('#chart-hourly');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        const w = canvas.parentElement.clientWidth - 32;
        canvas.width = w; canvas.height = 200;
        ctx.clearRect(0, 0, w, 200);

        if (!rows.length) {
            ctx.fillStyle = '#71717a'; ctx.font = '13px Vazirmatn'; ctx.textAlign = 'center';
            ctx.fillText('هنوز رویدادی ثبت نشده — منتظر فعالیت سیستم باشید', w/2, 100);
            return;
        }

        const pad = { t:16, r:12, b:32, l:36 };
        const cW = w - pad.l - pad.r, cH = 200 - pad.t - pad.b;
        const max = Math.max(...rows.map((r) => parseInt(r.total||0,10)), 1);
        const step = cW / Math.max(rows.length - 1, 1);

        for (let i = 0; i <= 4; i++) {
            const y = pad.t + (cH/4)*i;
            ctx.strokeStyle = 'rgba(255,255,255,0.04)'; ctx.beginPath();
            ctx.moveTo(pad.l, y); ctx.lineTo(pad.l+cW, y); ctx.stroke();
        }

        ctx.beginPath();
        rows.forEach((row, i) => {
            const x = pad.l + i*step, h = (parseInt(row.total||0,10)/max)*cH;
            i === 0 ? ctx.moveTo(x, pad.t+cH-h) : ctx.lineTo(x, pad.t+cH-h);
        });
        ctx.lineTo(pad.l+(rows.length-1)*step, pad.t+cH);
        ctx.lineTo(pad.l, pad.t+cH); ctx.closePath();
        ctx.fillStyle = 'rgba(45,212,191,0.12)'; ctx.fill();

        ctx.beginPath(); ctx.strokeStyle = '#2dd4bf'; ctx.lineWidth = 2;
        rows.forEach((row, i) => {
            const x = pad.l+i*step, h = (parseInt(row.total||0,10)/max)*cH;
            i === 0 ? ctx.moveTo(x, pad.t+cH-h) : ctx.lineTo(x, pad.t+cH-h);
        }); ctx.stroke();

        rows.forEach((row, i) => {
            if (parseInt(row.errors||0,10) <= 0) return;
            const x = pad.l+i*step, h = (parseInt(row.total||0,10)/max)*cH;
            ctx.beginPath(); ctx.arc(x, pad.t+cH-h, 4, 0, Math.PI*2);
            ctx.fillStyle = '#f87171'; ctx.fill();
        });

        ctx.fillStyle = '#71717a'; ctx.font = '10px JetBrains Mono'; ctx.textAlign = 'center';
        const ls = Math.max(1, Math.ceil(rows.length/6));
        rows.forEach((row, i) => {
            if (i % ls !== 0 && i !== rows.length-1) return;
            ctx.fillText((row.hour_bucket||'').slice(11,16), pad.l+i*step, 196);
        });
        canvas._chartMeta = { pad, step, rows };
    }

    function onChartHover(e) {
        const canvas = $('#chart-hourly'), meta = canvas?._chartMeta, tip = $('#chart-tooltip');
        if (!meta?.rows?.length || !tip) return;
        const rect = canvas.getBoundingClientRect();
        const idx = Math.max(0, Math.min(Math.round((e.clientX-rect.left-meta.pad.l)/meta.step), meta.rows.length-1));
        const row = meta.rows[idx];
        tip.classList.remove('hidden');
        tip.innerHTML = 'ساعت ' + esc((row.hour_bucket||'').slice(11,16)) + '<br>رویداد: ' + fmt(row.total) + ' · خطا: ' + fmt(row.errors||0);
        tip.style.left = (e.clientX-rect.left+12)+'px';
        tip.style.top = (e.clientY-rect.top-44)+'px';
    }

    function simplifyMessage(msg) {
        if (!msg) return '—';
        return msg
            .replace(/duration_ms=\d+/g, '')
            .replace(/status=(\d+)/g, (_, s) => 'کد ' + s)
            .replace(/GET |POST /g, '');
    }

    function renderActivityFeed(events) {
        const el = $('#activity-feed');
        if (!events.length) {
            el.innerHTML = emptyState('هنوز فعالیتی نیست', 'وب‌هوک‌ها و syncها اینجا نمایش داده می‌شوند');
            return;
        }
        el.innerHTML = events.slice(0, 20).map((ev) =>
            '<div class="activity-item" data-id="' + ev.id + '">' +
            '<span class="activity-time">' + relativeTime(ev.created_at) + '</span>' +
            '<div class="activity-body">' +
            '<div class="activity-msg">' + esc(ev.action ? trAction(ev.action) + ' — ' : '') + esc(simplifyMessage(ev.message)) + '</div>' +
            '<div class="activity-meta">' + pill(ev.level) +
            '<span class="tag">' + esc(trChannel(ev.channel)) + '</span>' +
            (ev.user_id ? '<span class="tag mono">' + esc(ev.user_id) + '</span>' : '') +
            '</div></div></div>'
        ).join('');
        el.querySelectorAll('.activity-item').forEach((i) => i.addEventListener('click', () => openEventDetail(parseInt(i.dataset.id,10))));
    }

    function renderRecentErrors(errors) {
        const el = $('#recent-errors');
        if (!errors.length) {
            el.innerHTML = '<div class="empty-state good-empty"><p>✓ خطایی ثبت نشده</p><small>سیستم در این بازه بدون مشکل بوده</small></div>';
            return;
        }
        el.innerHTML = errors.map((ev) =>
            '<div class="error-item" data-id="' + ev.id + '">' +
            '<div class="activity-meta">' + pill(ev.level) +
            '<span class="subtle">' + relativeTime(ev.created_at) + '</span>' +
            '<span class="tag">' + esc(trChannel(ev.channel)) + '</span></div>' +
            '<div class="msg">' + esc(simplifyMessage(ev.message)) + '</div></div>'
        ).join('');
        el.querySelectorAll('.error-item').forEach((i) => i.addEventListener('click', () => openEventDetail(parseInt(i.dataset.id,10))));
    }

    function populateChannelFilter(channels) {
        const sel = $('#filter-channel'), cur = sel.value;
        sel.innerHTML = '<option value="">همه</option>' +
            channels.map((c) => '<option value="' + esc(c) + '">' + esc(trChannel(c)) + ' (' + esc(c) + ')</option>').join('');
        sel.value = cur;
    }

    // ── Events ──
    async function loadEvents() {
        const res = await api('events.php', {
            hours: state.periodHours, limit: state.eventsLimit, offset: state.eventsOffset,
            search: $('#filter-search').value.trim(), channel: $('#filter-channel').value,
            level: $('#filter-level').value, category: $('#filter-category').value,
            user_id: $('#filter-user').value.trim(), request_id: $('#filter-request').value.trim(),
        });
        const events = res.events || [];
        state.eventsTotal = res.pagination?.total || 0;
        $('#events-count').textContent = fmt(state.eventsTotal);
        $('#events-page-info').textContent = fmt(state.eventsOffset+1) + ' تا ' + fmt(Math.min(state.eventsOffset+state.eventsLimit, state.eventsTotal)) + ' از ' + fmt(state.eventsTotal);

        const tbody = $('#events-body');
        if (!events.length) {
            tbody.innerHTML = '<tr><td colspan="8">' + emptyState('رویدادی یافت نشد', 'فیلترها را تغییر دهید یا بازه زمانی را بزرگ‌تر کنید') + '</td></tr>';
            return;
        }
        tbody.innerHTML = events.map((ev) =>
            '<tr data-id="' + ev.id + '">' +
            '<td class="mono" title="' + esc(formatDate(ev.created_at)) + '">' + relativeTime(ev.created_at) + '</td>' +
            '<td>' + pill(ev.level) + '</td>' +
            '<td><span class="tag">' + esc(trChannel(ev.channel)) + '</span></td>' +
            '<td class="mono subtle" title="' + esc(ev.action || '') + '">' + esc(trAction(ev.action)) + '</td>' +
            '<td class="msg-cell" title="' + esc(ev.message) + '">' + esc(simplifyMessage(ev.message)) + '</td>' +
            '<td class="mono">' + (ev.user_id ? '<button type="button" class="link-btn mono" data-timeline-user="' + esc(ev.user_id) + '">' + esc(ev.user_id) + '</button>' : '—') + '</td>' +
            '<td class="mono">' + (ev.duration_ms ? ev.duration_ms+'ms' : '—') + '</td>' +
            '<td><button type="button" class="btn-ghost sm">جزئیات</button></td></tr>'
        ).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => tr.addEventListener('click', (e) => {
            if (e.target.closest('[data-timeline-user]')) return;
            openEventDetail(parseInt(tr.dataset.id, 10));
        }));
        $('#events-prev').disabled = state.eventsOffset <= 0;
        $('#events-next').disabled = state.eventsOffset + state.eventsLimit >= state.eventsTotal;
    }

    async function openEventDetail(id) {
        const res = await api('event.php', { id });
        const ev = res.event;
        if (!ev) return;
        state.currentEvent = ev;
        $('#event-detail').innerHTML =
            '<div class="detail-grid">' +
            row('شناسه', ev.id) + row('زمان', formatDate(ev.created_at)) +
            row('اهمیت', trLevel(ev.level)) + row('منبع', trChannel(ev.channel) + ' (' + ev.channel + ')') +
            row('دسته', trCategory(ev.category)) + row('عملیات', trAction(ev.action) + (ev.action ? ' (' + ev.action + ')' : '')) +
            row('پزشک', ev.user_id || '—') + row('موجودیت', (ev.entity_type||'—') + ' / ' + (ev.entity_id||'—')) +
            row('HTTP', ev.http_status || '—') + row('مدت اجرا', ev.duration_ms ? ev.duration_ms+' ms' : '—') +
            row('IP', ev.ip_address || '—') + row('شناسه درخواست', ev.request_id || '—') +
            row('پیام کامل', ev.message) +
            '</div>' + (ev.context ? '<p class="section-desc" style="margin-top:16px">اطلاعات تکمیلی (JSON)</p><pre class="json-block">' + esc(JSON.stringify(ev.context,null,2)) + '</pre>' : '');
        const tlBtn = $('#event-open-timeline');
        if (tlBtn) {
            if (ev.user_id) { tlBtn.classList.remove('hidden'); }
            else { tlBtn.classList.add('hidden'); }
        }
        $('#event-modal').classList.remove('hidden');
    }

    function row(k, v) { return '<div class="k">' + esc(k) + '</div><div class="v">' + esc(String(v)) + '</div>'; }

    async function exportCsv() {
        const res = await api('events.php', { hours: state.periodHours, limit: 500, offset: 0,
            search: $('#filter-search').value.trim(), channel: $('#filter-channel').value,
            level: $('#filter-level').value, category: $('#filter-category').value,
            user_id: $('#filter-user').value.trim(), request_id: $('#filter-request').value.trim() });
        const events = res.events || [];
        if (!events.length) { toast('رویدادی نیست'); return; }
        const h = ['زمان','اهمیت','منبع','دسته','عملیات','پیام','پزشک','مدت_ms'];
        const rows = events.map((ev) => [ev.created_at, ev.level, ev.channel, ev.category, ev.action, ev.message, ev.user_id, ev.duration_ms]
            .map((v) => '"' + String(v??'').replace(/"/g,'""') + '"').join(','));
        const blob = new Blob(['\uFEFF' + h.join(',') + '\n' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = 'zamanak-' + Date.now() + '.csv'; a.click();
        toast(fmt(events.length) + ' رویداد دانلود شد');
    }

    // ── Users ──
    async function loadUsers() {
        const res = await api('users.php', { limit: 200 });
        state.usersCache = res.users || [];
        renderUsersSummary();
        renderUsers();
    }

    function renderUsersSummary() {
        const all = state.usersCache;
        const connected = all.filter((u) => u.connected).length;
        const disconnected = all.length - connected;
        const watchExpired = all.filter((u) => u.watch_state === 'expired').length;
        const autoVac = all.filter((u) => u.auto_vacation).length;

        $('#users-summary').innerHTML =
            metricCard('👥', 'کل پزشکان', fmt(all.length), 'ثبت‌شده در سیستم', '') +
            metricCard('✅', 'متصل به گوگل', fmt(connected), disconnected + ' نفر قطع', connected > 0 ? 'good' : 'bad') +
            metricCard('📅', 'Watch فعال', fmt(all.filter(u=>u.watch_state==='active').length), watchExpired + ' منقضی', watchExpired > 0 ? 'bad' : 'good') +
            metricCard('🏖', 'مرخصی خودکار', fmt(autoVac), 'فعال', 'accent');
    }

    function renderUsers() {
        const q = ($('#users-search')?.value || '').trim().toLowerCase();
        const rows = state.usersCache.filter((u) => {
            if (q && !(u.user_id||'').includes(q) && !(u.email||'').toLowerCase().includes(q)) return false;
            if (state.usersFilter === 'connected' && !u.connected) return false;
            if (state.usersFilter === 'disconnected' && u.connected) return false;
            if (state.usersFilter === 'watch-expired' && u.watch_state !== 'expired') return false;
            return true;
        });

        const tbody = $('#users-body');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8">' + emptyState('پزشکی یافت نشد', 'فیلتر یا جستجو را تغییر دهید') + '</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map((u) => {
            const sync = u.last_sync_status?.message || u.last_sync_status?.status || '—';
            const wCls = u.watch_state === 'active' ? 'pill-success' : u.watch_state === 'expired' ? 'pill-error' : 'pill-debug';
            return '<tr class="user-row">' +
                '<td class="mono">' + esc(u.user_id) + '</td>' +
                '<td>' + esc(u.email || '—') + '</td>' +
                '<td><span class="status-badge ' + (u.connected?'on':'off') + '">' + (u.connected ? '✓ متصل' : '✗ قطع') + '</span></td>' +
                '<td>' + (u.auto_vacation ? '✓ فعال' : '—') + '</td>' +
                '<td><span class="pill ' + wCls + '">' + esc(trWatch(u.watch_state)) + '</span></td>' +
                '<td class="msg-cell" title="' + esc(sync) + '">' + esc(String(sync).slice(0,50)) + '</td>' +
                '<td class="mono subtle">' + relativeTime(u.updated_at) + '</td>' +
                '<td><button type="button" class="btn-ghost sm" data-timeline-user="' + esc(u.user_id) + '">تاریخچه</button></td></tr>';
        }).join('');
    }

    function openUserTimeline(userId) {
        state.timelineUserId = String(userId || '').trim();
        state.timelineOffset = 0;
        if ($('#timeline-user-id')) $('#timeline-user-id').value = state.timelineUserId;
        switchTab('user-timeline', true);
        loadUserTimeline(true);
    }

    async function loadUserTimeline(showToast) {
        if (!state.timelineUserId) return;
        try {
            const res = await api('user-timeline.php', {
                user_id: state.timelineUserId,
                hours: state.periodHours,
                limit: state.timelineLimit,
                offset: state.timelineOffset,
                level: $('#timeline-level')?.value || '',
                category: $('#timeline-category')?.value || '',
                search: $('#timeline-search')?.value.trim() || '',
            });
            state.timelineTotal = res.pagination?.total || 0;
            renderTimelineProfile(res.profile, res.user_id);
            renderTimelineStats(res.stats || {});
            renderTimelineFeed(res.events || []);
            $('#timeline-card')?.classList.remove('hidden');
            const page = Math.floor(state.timelineOffset / state.timelineLimit) + 1;
            const pages = Math.max(1, Math.ceil(state.timelineTotal / state.timelineLimit));
            $('#timeline-page').textContent = 'صفحه ' + fmt(page) + ' از ' + fmt(pages) + ' · ' + fmt(state.timelineTotal) + ' رویداد';
            $('#timeline-count').textContent = fmt(state.timelineTotal);
            $('#timeline-prev').disabled = state.timelineOffset <= 0;
            $('#timeline-next').disabled = state.timelineOffset + state.timelineLimit >= state.timelineTotal;
            if (showToast) toast('تاریخچه پزشک ' + state.timelineUserId + ' بارگذاری شد');
        } catch (e) {
            toast(e.message || 'خطا در بارگذاری تاریخچه', 'error');
            throw e;
        }
    }

    async function exportTimelineCsv() {
        if (!state.timelineUserId) { toast('ابتدا یک پزشک انتخاب کنید', 'error'); return; }
        const res = await api('user-timeline.php', {
            user_id: state.timelineUserId,
            hours: state.periodHours,
            limit: 500,
            offset: 0,
            level: $('#timeline-level')?.value || '',
            category: $('#timeline-category')?.value || '',
            search: $('#timeline-search')?.value.trim() || '',
        });
        const events = res.events || [];
        if (!events.length) { toast('رویدادی نیست'); return; }
        const h = ['زمان','اهمیت','منبع','دسته','عملیات','پیام','مدت_ms'];
        const rows = events.map((ev) => [ev.created_at, ev.level, ev.channel, ev.category, ev.action, ev.message, ev.duration_ms]
            .map((v) => '"' + String(v??'').replace(/"/g,'""') + '"').join(','));
        const blob = new Blob(['\uFEFF' + h.join(',') + '\n' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = 'zamanak-user-' + state.timelineUserId + '-' + Date.now() + '.csv'; a.click();
        toast(fmt(events.length) + ' رویداد دانلود شد');
    }

    function renderTimelineProfile(profile, userId) {
        const el = $('#timeline-profile');
        if (!el) return;
        el.classList.remove('hidden');
        const p = profile || {};
        const connected = p.connected ? '✓ متصل به گوگل' : '✗ قطع';
        el.innerHTML =
            '<div class="timeline-profile-card">' +
            '<div class="timeline-profile-main">' +
            '<span class="timeline-profile-id mono">' + esc(userId) + '</span>' +
            '<span class="timeline-profile-email">' + esc(p.email || 'ایمیل ثبت نشده') + '</span></div>' +
            '<div class="timeline-profile-meta">' +
            '<span class="tag">' + esc(connected) + '</span>' +
            '<span class="tag">Watch: ' + esc(trWatch(p.watch_state || 'none')) + '</span>' +
            (p.auto_vacation ? '<span class="tag">مرخصی خودکار ✓</span>' : '') +
            '</div></div>';
    }

    function renderTimelineStats(stats) {
        const el = $('#timeline-stats');
        if (!el) return;
        el.classList.remove('hidden');
        el.innerHTML =
            metricCard('📋', 'کل رویدادها', fmt(stats.total || 0), 'در بازه انتخاب‌شده', '') +
            metricCard('🔴', 'خطا', fmt(stats.errors || 0), 'error / critical', (stats.errors||0) > 0 ? 'bad' : 'good') +
            metricCard('🟡', 'هشدار', fmt(stats.warnings || 0), 'warning', (stats.warnings||0) > 0 ? 'bad' : '') +
            metricCard('🕐', 'آخرین فعالیت', stats.last_event ? relativeTime(stats.last_event) : '—', stats.first_event ? 'از ' + relativeTime(stats.first_event) : '', 'accent');
    }

    function renderTimelineFeed(events) {
        const el = $('#timeline-feed');
        if (!el) return;
        if (!events.length) {
            el.innerHTML = emptyState('رویدادی برای این پزشک نیست', 'بازه زمانی را بزرگ‌تر کنید یا عملیاتی انجام دهید');
            return;
        }
        el.innerHTML = events.map((ev) =>
            '<div class="timeline-item level-' + esc(ev.level || 'info') + '" data-id="' + ev.id + '">' +
            '<div class="timeline-dot"></div>' +
            '<div class="timeline-content">' +
            '<div class="timeline-head">' +
            '<span class="timeline-action">' + esc(trAction(ev.action)) + '</span>' +
            pill(ev.level) +
            '<span class="timeline-when subtle">' + formatDate(ev.created_at) + ' · ' + relativeTime(ev.created_at) + '</span></div>' +
            '<div class="timeline-msg">' + esc(ev.message || simplifyMessage(ev.message)) + '</div>' +
            '<div class="activity-meta">' +
            '<span class="tag">' + esc(trChannel(ev.channel)) + '</span>' +
            '<span class="tag">' + esc(trCategory(ev.category)) + '</span>' +
            (ev.entity_id ? '<span class="tag mono">' + esc(ev.entity_type || 'ref') + ': ' + esc(ev.entity_id) + '</span>' : '') +
            (ev.duration_ms ? '<span class="tag mono">' + fmt(ev.duration_ms) + ' ms</span>' : '') +
            '</div></div></div>'
        ).join('');
        el.querySelectorAll('.timeline-item').forEach((i) => i.addEventListener('click', () => openEventDetail(parseInt(i.dataset.id, 10))));
    }

    // ── Logs ──
    async function loadLogTail() {
        const res = await api('log-tail.php', { lines: 400 });
        state.logRaw = res.lines || [];
        $('#log-size').textContent = ((res.size_bytes||0)/1024).toFixed(1) + ' KB';
        renderLog();
    }

    function renderLog() {
        const q = ($('#log-search')?.value || '').trim().toLowerCase();
        const lvl = $('#log-level-filter')?.value || '';
        let lines = state.logRaw;
        if (lvl) lines = lines.filter((l) => l.toLowerCase().includes(lvl));
        const el = $('#log-tail');
        if (!lines.length) { el.textContent = 'فایل خالی است یا خطایی با این فیلتر یافت نشد'; return; }
        el.innerHTML = lines.map((line) => {
            let h = esc(line);
            if (q) h = h.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi'), '<span class="hl">$1</span>');
            if (/error|fatal/i.test(line)) h = '<span class="hl-error">' + h + '</span>';
            return h;
        }).join('\n');
        el.scrollTop = el.scrollHeight;
    }

    // ── System ──
    async function loadSystem() {
        const res = await api('overview.php');
        const ov = res.overview || {}, checks = ov.checks || {}, status = ov.status || 'ok';
        const st = T.status[status] || T.status.ok;

        $('#system-status').className = 'system-hero ' + status;
        $('#system-status').innerHTML =
            '<div class="system-hero-icon">' + st.icon + '</div>' +
            '<div><h2>' + st.title + '</h2>' +
            '<p class="subtle">' + st.desc + '</p>' +
            '<p class="subtle">PHP ' + esc(checks.php||'') + ' · دیتابیس: ' + esc(checks.db_driver||'') + ' · ' + formatDate(checks.time) + '</p></div>';

        const groups = [
            { title: 'زیرساخت', keys: ['database','db_driver','php','env_file','database_error'] },
            { title: 'مانیتورینگ', keys: ['monitor_enabled','monitor_schema','monitor_events_total','error_log_size'] },
            { title: 'فعالیت', keys: ['users_total','users_connected','events_24h','errors_24h'] },
        ];

        $('#system-sections').innerHTML = groups.map((g) => {
            const cards = g.keys.filter((k) => checks[k] !== undefined || (k === 'database_error' && checks.database_error))
                .map((k) => {
                    const meta = T.checks[k] || { label: k, desc: '' };
                    let val = checks[k];
                    if (k === 'monitor_enabled') val = val ? '✓ فعال' : '✗ غیرفعال';
                    if (k === 'env_file') val = val ? '✓ موجود' : '✗ نیست';
                    if (k === 'error_log_size') val = ((checks.error_log_size_bytes||0)/1024).toFixed(1) + ' KB';
                    if (k === 'database') val = val === 'ok' ? '✓ سالم' : '✗ ' + val;
                    if (k === 'monitor_schema') val = val === 'ok' ? '✓ سالم' : '✗ ' + val;
                    const ok = (k === 'database' || k === 'monitor_schema') ? checks[k] === 'ok' : null;
                    return '<div class="sys-card ' + (ok===true?'ok':ok===false?'err':'') + '">' +
                        '<div class="key">' + esc(meta.label) + '</div>' +
                        (meta.desc ? '<div class="sys-desc">' + esc(meta.desc) + '</div>' : '') +
                        '<div class="val">' + esc(String(val ?? '—')) + '</div></div>';
                }).join('');
            return '<div class="system-group"><h3>' + esc(g.title) + '</h3><div class="system-grid">' + cards + '</div></div>';
        }).join('');
    }

    window.addEventListener('resize', () => {
        if (state.activeTab === 'dashboard' && state.chartRows.length) renderChart(state.chartRows);
    });

    document.addEventListener('DOMContentLoaded', () => {
        if (!state.eventsBound) { state.eventsBound = true; bindEvents(); }
        verifyAndBoot();
    });
})();
