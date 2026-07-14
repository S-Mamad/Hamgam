(function () {
    'use strict';

    function apiRoot() {
        const path = window.location.pathname || '';
        if (path.startsWith('/monitor')) {
            return '/monitor/api';
        }
        return '/php/monitor/api';
    }

    const STORAGE_KEY = 'zamanak_monitor_key';

    const state = {
        apiKey: '',
        activeTab: 'dashboard',
        periodHours: 24,
        eventsOffset: 0,
        eventsLimit: 50,
        eventsTotal: 0,
        refreshTimer: null,
        chart: null,
    };

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => Array.from(document.querySelectorAll(sel));

    function getApiKey() {
        const params = new URLSearchParams(window.location.search);
        const fromUrl = params.get('key');
        if (fromUrl) {
            sessionStorage.setItem(STORAGE_KEY, fromUrl);
            return fromUrl;
        }
        return sessionStorage.getItem(STORAGE_KEY) || '';
    }

    async function api(path, params = {}) {
        const url = new URL(apiRoot() + '/' + path.replace(/^\//, ''), window.location.origin);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });

        const res = await fetch(url.toString(), {
            headers: { 'X-Monitor-Key': state.apiKey },
            cache: 'no-store',
        });

        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.error || ('HTTP ' + res.status));
        }
        return data;
    }

    function formatDate(value) {
        if (!value) return '—';
        const d = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return value;
        return d.toLocaleString('fa-IR', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        });
    }

    function levelClass(level) {
        return 'level-pill level-' + (level || 'info');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setUpdated() {
        $('#last-updated').textContent = 'آخرین بروزرسانی: ' + formatDate(new Date().toISOString());
    }

    function showLogin(error) {
        $('#login-screen').classList.remove('hidden');
        $('#app').classList.add('hidden');
        const err = $('#login-error');
        if (error) {
            err.textContent = error;
            err.classList.remove('hidden');
        } else {
            err.classList.add('hidden');
        }
    }

    function showApp() {
        $('#login-screen').classList.add('hidden');
        $('#app').classList.remove('hidden');
    }

    async function verifyAndBoot() {
        state.apiKey = getApiKey();
        if (!state.apiKey) {
            showLogin();
            return;
        }

        try {
            await api('overview.php');
            showApp();
            if (!eventsBound) {
                eventsBound = true;
                bindEvents();
            }
            await refreshAll();
            startAutoRefresh();
        } catch (e) {
            sessionStorage.removeItem(STORAGE_KEY);
            showLogin(e.message || 'کلید نامعتبر است');
        }
    }

    function bindEvents() {
        $('#login-btn').addEventListener('click', async () => {
            const key = $('#login-key').value.trim();
            if (!key) return;
            sessionStorage.setItem(STORAGE_KEY, key);
            state.apiKey = key;
            try {
                await api('overview.php');
                showApp();
                if (!eventsBound) {
                    eventsBound = true;
                    bindEvents();
                }
                await refreshAll();
                startAutoRefresh();
            } catch (e) {
                showLogin(e.message);
            }
        });

        $('#logout-btn').addEventListener('click', () => {
            sessionStorage.removeItem(STORAGE_KEY);
            location.reload();
        });

        $('#refresh-btn').addEventListener('click', () => refreshAll());
        $('#period-select').addEventListener('change', (e) => {
            state.periodHours = parseInt(e.target.value, 10) || 24;
            refreshAll();
        });

        $('#auto-refresh').addEventListener('change', (e) => {
            if (e.target.checked) startAutoRefresh();
            else stopAutoRefresh();
        });

        $$('.nav-item').forEach((btn) => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });

        $('#filter-apply').addEventListener('click', () => {
            state.eventsOffset = 0;
            loadEvents();
        });

        $('#filter-clear').addEventListener('click', () => {
            $('#filter-search').value = '';
            $('#filter-channel').value = '';
            $('#filter-level').value = '';
            $('#filter-category').value = '';
            $('#filter-user').value = '';
            $('#filter-request').value = '';
            state.eventsOffset = 0;
            loadEvents();
        });

        $('#events-prev').addEventListener('click', () => {
            state.eventsOffset = Math.max(0, state.eventsOffset - state.eventsLimit);
            loadEvents();
        });

        $('#events-next').addEventListener('click', () => {
            if (state.eventsOffset + state.eventsLimit < state.eventsTotal) {
                state.eventsOffset += state.eventsLimit;
                loadEvents();
            }
        });

        $('#users-search').addEventListener('input', renderUsersFilter);

        $$('[data-close-modal]').forEach((el) => {
            el.addEventListener('click', () => $('#event-modal').classList.add('hidden'));
        });
    }

    let eventsBound = false;

    function switchTab(tab) {
        state.activeTab = tab;
        $$('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
        $$('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === 'tab-' + tab));

        const titles = {
            dashboard: 'داشبورد',
            events: 'رویدادها',
            users: 'پزشکان',
            logs: 'لاگ فایل',
            system: 'سیستم',
        };
        $('#page-title').textContent = titles[tab] || tab;
        refreshAll();
    }

    function startAutoRefresh() {
        stopAutoRefresh();
        state.refreshTimer = setInterval(() => refreshAll(), 5000);
    }

    function stopAutoRefresh() {
        if (state.refreshTimer) clearInterval(state.refreshTimer);
        state.refreshTimer = null;
    }

    async function refreshAll() {
        setUpdated();
        try {
            if (state.activeTab === 'dashboard') await loadDashboard();
            if (state.activeTab === 'events') await loadEvents();
            if (state.activeTab === 'users') await loadUsers();
            if (state.activeTab === 'logs') await loadLogTail();
            if (state.activeTab === 'system') await loadSystem();
        } catch (e) {
            console.error(e);
        }
    }

    async function loadDashboard() {
        const [overviewRes, statsRes] = await Promise.all([
            api('overview.php'),
            api('stats.php', { hours: state.periodHours }),
        ]);

        const sys = overviewRes.overview || {};
        const checks = sys.checks || {};
        const stats = statsRes.overview || {};

        const banner = $('#status-banner');
        banner.className = 'status-banner status-' + (sys.status || 'ok');
        const statusLabels = { ok: 'سیستم سالم', degraded: 'نیاز به توجه', critical: 'بحرانی' };
        $('#status-text').textContent = statusLabels[sys.status] || sys.status || '—';

        $('#stats-grid').innerHTML = [
            statCard('کل رویدادها', stats.total || 0),
            statCard('خطاها', stats.errors || 0, 'error'),
            statCard('هشدارها', stats.warnings || 0, 'warning'),
            statCard('وب‌هوک‌ها', stats.webhooks || 0),
            statCard('درخواست HTTP', stats.http_calls || 0),
            statCard('Cron', stats.cron_events || 0),
            statCard('پزشکان متصل', checks.users_connected || 0, 'success'),
            statCard('میانگین مدت (ms)', Math.round(stats.avg_duration_ms || 0)),
        ].join('');

        renderChannelList(statsRes.by_channel || []);
        renderHourlyChart(statsRes.by_hour || []);
        renderRecentErrors(statsRes.recent_errors || []);

        const channelSelect = $('#filter-channel');
        const current = channelSelect.value;
        channelSelect.innerHTML = '<option value="">همه کانال‌ها</option>' +
            (statsRes.channels || []).map((c) => '<option value="' + escapeHtml(c) + '">' + escapeHtml(c) + '</option>').join('');
        channelSelect.value = current;
    }

    function statCard(label, value, cls) {
        return '<div class="stat-card ' + (cls || '') + '"><div class="label">' + escapeHtml(label) +
            '</div><div class="value">' + escapeHtml(String(value)) + '</div></div>';
    }

    function renderChannelList(channels) {
        const el = $('#channel-list');
        if (!channels.length) {
            el.innerHTML = '<p class="muted">داده‌ای نیست</p>';
            return;
        }
        el.innerHTML = channels.map((row) => {
            const errors = parseInt(row.errors || 0, 10);
            return '<div class="channel-item"><span>' + escapeHtml(row.channel) +
                '</span><span><strong>' + row.total + '</strong>' +
                (errors ? ' <span class="errors">(' + errors + ' خطا)</span>' : '') + '</span></div>';
        }).join('');
    }

    function renderHourlyChart(rows) {
        const canvas = $('#chart-hourly');
        const ctx = canvas.getContext('2d');
        const w = canvas.parentElement.clientWidth - 32;
        canvas.width = w;
        canvas.height = 220;

        ctx.clearRect(0, 0, w, 220);
        if (!rows.length) {
            ctx.fillStyle = '#93a0bd';
            ctx.font = '14px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('داده‌ای برای نمودار نیست', w / 2, 110);
            return;
        }

        const max = Math.max(...rows.map((r) => parseInt(r.total || 0, 10)), 1);
        const pad = { t: 20, r: 16, b: 36, l: 40 };
        const chartW = w - pad.l - pad.r;
        const chartH = 220 - pad.t - pad.b;
        const barW = chartW / rows.length;

        rows.forEach((row, i) => {
            const total = parseInt(row.total || 0, 10);
            const errors = parseInt(row.errors || 0, 10);
            const h = (total / max) * chartH;
            const x = pad.l + i * barW + 2;
            const y = pad.t + chartH - h;

            ctx.fillStyle = 'rgba(79, 140, 255, 0.75)';
            ctx.fillRect(x, y, Math.max(barW - 4, 2), h);

            if (errors > 0) {
                const eh = (errors / max) * chartH;
                ctx.fillStyle = 'rgba(239, 68, 68, 0.9)';
                ctx.fillRect(x, pad.t + chartH - eh, Math.max(barW - 4, 2), eh);
            }
        });

        ctx.strokeStyle = 'rgba(255,255,255,0.1)';
        ctx.beginPath();
        ctx.moveTo(pad.l, pad.t + chartH);
        ctx.lineTo(pad.l + chartW, pad.t + chartH);
        ctx.stroke();

        ctx.fillStyle = '#93a0bd';
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'center';
        const step = Math.max(1, Math.ceil(rows.length / 6));
        rows.forEach((row, i) => {
            if (i % step !== 0 && i !== rows.length - 1) return;
            const label = (row.hour_bucket || '').slice(11, 16);
            ctx.fillText(label, pad.l + i * barW + barW / 2, 220 - 10);
        });
    }

    function renderRecentErrors(errors) {
        const el = $('#recent-errors');
        if (!errors.length) {
            el.innerHTML = '<p class="muted" style="padding:12px">خطایی ثبت نشده ✓</p>';
            return;
        }
        el.innerHTML = errors.map((ev) => eventItemHtml(ev)).join('');
        el.querySelectorAll('.event-item').forEach((item) => {
            item.addEventListener('click', () => openEventDetail(parseInt(item.dataset.id, 10)));
        });
    }

    function eventItemHtml(ev) {
        return '<div class="event-item" data-id="' + ev.id + '">' +
            '<div class="meta"><span>' + formatDate(ev.created_at) + '</span>' +
            '<span class="' + levelClass(ev.level) + '">' + escapeHtml(ev.level) + '</span>' +
            '<span>' + escapeHtml(ev.channel) + '</span></div>' +
            '<div>' + escapeHtml(ev.message) + '</div></div>';
    }

    async function loadEvents() {
        const params = {
            hours: state.periodHours,
            limit: state.eventsLimit,
            offset: state.eventsOffset,
            search: $('#filter-search').value.trim(),
            channel: $('#filter-channel').value,
            level: $('#filter-level').value,
            category: $('#filter-category').value,
            user_id: $('#filter-user').value.trim(),
            request_id: $('#filter-request').value.trim(),
        };

        const res = await api('events.php', params);
        const events = res.events || [];
        state.eventsTotal = res.pagination?.total || 0;

        $('#events-count').textContent = state.eventsTotal;
        $('#events-page-info').textContent =
            (state.eventsOffset + 1) + '–' + Math.min(state.eventsOffset + state.eventsLimit, state.eventsTotal) +
            ' از ' + state.eventsTotal;

        const tbody = $('#events-body');
        tbody.innerHTML = events.map((ev) => {
            return '<tr data-id="' + ev.id + '">' +
                '<td>' + formatDate(ev.created_at) + '</td>' +
                '<td><span class="' + levelClass(ev.level) + '">' + escapeHtml(ev.level) + '</span></td>' +
                '<td>' + escapeHtml(ev.channel) + '</td>' +
                '<td>' + escapeHtml(ev.action || '—') + '</td>' +
                '<td class="message-cell" title="' + escapeHtml(ev.message) + '">' + escapeHtml(ev.message) + '</td>' +
                '<td>' + escapeHtml(ev.user_id || '—') + '</td>' +
                '<td>' + (ev.duration_ms ? ev.duration_ms + 'ms' : '—') + '</td>' +
                '<td><button type="button" class="ghost-btn btn-detail">جزئیات</button></td></tr>';
        }).join('');

        tbody.querySelectorAll('.btn-detail, tr').forEach((el) => {
            el.addEventListener('click', (e) => {
                const tr = el.closest('tr');
                if (!tr) return;
                e.stopPropagation();
                openEventDetail(parseInt(tr.dataset.id, 10));
            });
        });
    }

    async function openEventDetail(id) {
        const res = await api('event.php', { id });
        const ev = res.event;
        if (!ev) return;

        $('#event-detail').innerHTML =
            '<div class="detail-grid">' +
            detailRow('ID', ev.id) +
            detailRow('زمان', formatDate(ev.created_at)) +
            detailRow('Request ID', ev.request_id) +
            detailRow('کانال', ev.channel) +
            detailRow('سطح', ev.level) +
            detailRow('دسته', ev.category) +
            detailRow('عمل', ev.action || '—') +
            detailRow('کاربر', ev.user_id || '—') +
            detailRow('Entity', (ev.entity_type || '—') + ' / ' + (ev.entity_id || '—')) +
            detailRow('HTTP Status', ev.http_status || '—') +
            detailRow('مدت', ev.duration_ms ? ev.duration_ms + ' ms' : '—') +
            detailRow('IP', ev.ip_address || '—') +
            detailRow('پیام', ev.message) +
            '</div>' +
            (ev.context ? '<h4 style="margin:16px 0 8px">Context</h4><pre class="json-block">' +
                escapeHtml(JSON.stringify(ev.context, null, 2)) + '</pre>' : '');

        $('#event-modal').classList.remove('hidden');
    }

    function detailRow(key, value) {
        return '<div class="k">' + escapeHtml(key) + '</div><div class="v">' + escapeHtml(String(value)) + '</div>';
    }

    let usersCache = [];

    async function loadUsers() {
        const res = await api('users.php', { limit: 200 });
        usersCache = res.users || [];
        renderUsersFilter();
    }

    function renderUsersFilter() {
        const q = ($('#users-search')?.value || '').trim().toLowerCase();
        const rows = usersCache.filter((u) => {
            if (!q) return true;
            return (u.user_id || '').includes(q) || (u.email || '').toLowerCase().includes(q);
        });

        $('#users-body').innerHTML = rows.map((u) => {
            const watchCls = u.watch_state === 'active' ? 'watch-active' :
                u.watch_state === 'expired' ? 'watch-expired' : 'watch-none';
            const syncMsg = u.last_sync_status?.message || u.last_sync_status?.status || '—';

            return '<tr>' +
                '<td>' + escapeHtml(u.user_id) + '</td>' +
                '<td>' + escapeHtml(u.email || '—') + '</td>' +
                '<td>' + (u.connected ? '✓ متصل' : '✗ قطع') + '</td>' +
                '<td>' + (u.auto_vacation ? 'فعال' : 'غیرفعال') + '</td>' +
                '<td class="' + watchCls + '">' + escapeHtml(u.watch_state) + '</td>' +
                '<td class="message-cell" title="' + escapeHtml(syncMsg) + '">' + escapeHtml(String(syncMsg).slice(0, 80)) + '</td>' +
                '<td>' + formatDate(u.updated_at) + '</td></tr>';
        }).join('');
    }

    async function loadLogTail() {
        const res = await api('log-tail.php', { lines: 300 });
        $('#log-size').textContent = ((res.size_bytes || 0) / 1024).toFixed(1) + ' KB';
        $('#log-tail').textContent = (res.lines || []).join('\n') || '(فایل خالی است)';
    }

    async function loadSystem() {
        const res = await api('overview.php');
        const checks = res.overview?.checks || {};
        $('#system-checks').innerHTML = Object.entries(checks).map(([k, v]) => {
            return '<div class="check-item"><div class="key">' + escapeHtml(k) + '</div>' +
                '<div class="val">' + escapeHtml(typeof v === 'object' ? JSON.stringify(v) : String(v)) + '</div></div>';
        }).join('');
    }

    document.addEventListener('DOMContentLoaded', verifyAndBoot);
})();
