// روی هاست nginx مسیرهای /hamgam/* ممکن است 404 بدهند؛ php/ همیشه در دسترس است
const HAMGAM_API = window.location.origin;
const HAMGAM_API_PREFIX = `${HAMGAM_API}/php/hamgam`;
const PAZIRESH24_LAUNCHER = "https://www.paziresh24.com/_/hamgam/launcher/";
const PAZIRESH24_LAUNCHER_PATH = "/_/hamgam/launcher/";
const PAZIRESH24_LAUNCHER_APP_PATH = "/_/hamgam/launcher/?direct=true";
const PAZIRESH24_LAUNCHER_APP = "https://www.paziresh24.com/_/hamgam/launcher/?direct=true";
const DEFAULT_COLOR_ID = "9";

const googleColors = {
    "11": { name: "قرمز", hex: "#DA5234" },
    "1": { name: "آبی آسمانی", hex: "#3b82f6" },
    "10": { name: "سبز", hex: "#489160" },
    "9": { name: "آبی ارغوانی", hex: "#3f51b5" },
    "5": { name: "زرد", hex: "#E7BA51" }
};

const PREVIEW_SAMPLES = {
    patientName: "محمد محمدی",
    datetime: "شنبه - ۱۴:۳۰",
    nationalId: "4423456789",
    phone: "۰۹۱۲۳۴۵۶۷۸۹"
};

const appState = {
    connected: false,
    oauthUrl: null,
    settingsLoadedFromAuth: false,
    previewOpen: false,
    saving: false,
    changingGmail: false,
    disconnectingGoogle: false,
    medicalCenters: [],
    vacationCenterSelection: { mode: "selected", centerIds: [] },
    centersFetchState: "idle",
    centersFetchSeq: 0,
    importFutureVacationsUsed: false,
    importFutureBackfillUndoAvailable: false,
    importFutureBackfillSlotCount: 0,
    deletingSyncedBackfill: false
};

document.addEventListener("DOMContentLoaded", initApp);

function isOAuthReturn() {
    return new URLSearchParams(window.location.search).get("oauth") === "success";
}

function isGmailChangeReturn() {
    const params = new URLSearchParams(window.location.search);
    return params.get("oauth") === "success" && params.get("change") === "gmail";
}

function isOAuthErrorReturn() {
    return new URLSearchParams(window.location.search).get("oauth") === "error";
}

function getOAuthErrorMessage() {
    const params = new URLSearchParams(window.location.search);
    const reason = params.get("reason") || "";
    return getOAuthErrorMessageFromReason(reason);
}

function mapApiError(error) {
    if (typeof error !== "string" || !error) {
        return null;
    }

    const messages = {
        Unauthorized: "نشست شما منقضی شده. صفحه را از پنل پذیرش۲۴ دوباره باز کنید.",
        "User not found": "حساب کاربری یافت نشد. صفحه را از پنل پذیرش۲۴ دوباره باز کنید.",
        "Settings update failed": "تنظیمات ذخیره نشد. لطفاً صفحه را از پنل پذیرش۲۴ دوباره باز کنید.",
        "Google account not connected": "حساب Google متصل نیست. ابتدا اتصال Google را برقرار کنید.",
        "Failed to fetch medical centers": "خطا در دریافت مراکز درمانی از پذیرش۲۴",
        "Internal server error": "خطای سرور. چند لحظه بعد دوباره تلاش کنید.",
        "Invalid JSON body": "خطا در ارسال داده. صفحه را رفرش کنید و دوباره تلاش کنید.",
        "Authentication failed": "خطا در احراز هویت. صفحه را از پنل پذیرش۲۴ مجدداً باز کنید.",
        "Missing session token": "توکن نشست یافت نشد. صفحه را از پنل پذیرش۲۴ دوباره باز کنید.",
        "Invalid session token": "نشست نامعتبر است. صفحه را از پنل پذیرش۲۴ دوباره باز کنید.",
        "Method not allowed": "درخواست نامعتبر است. صفحه را رفرش کنید."
    };

    return messages[error] || error;
}

async function fetchSyncStatusOnce() {
    const token = localStorage.getItem("access_token");
    if (!token) {
        return null;
    }

    try {
        const response = await apiFetch(
            `${HAMGAM_API_PREFIX}/sync-status.php`,
            token,
            { body: apiBodyWithToken(token) }
        );
        const data = await parseJsonResponse(response);
        if (!response.ok || !data.sync_status) {
            return null;
        }
        return data.sync_status;
    } catch (error) {
        console.error("[Hamgam] sync status fetch failed:", error);
        return null;
    }
}

async function pollSyncStatus(options = {}) {
    const { maxAttempts = 30, intervalMs = 2000 } = options;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const status = await fetchSyncStatusOnce();
        if (status && !status.pending) {
            return status;
        }
        if (attempt < maxAttempts - 1) {
            await new Promise((resolve) => setTimeout(resolve, intervalMs));
        }
    }

    return fetchSyncStatusOnce();
}

function applyBackfillStatusToUi(backfill) {
    if (!backfill?.ran) {
        return;
    }

    setImportFutureVacationsUsed(true);

    const slotCount = Number(backfill.slot_count ?? backfill.imported ?? 0);
    if (slotCount > 0) {
        setImportFutureBackfillUndoAvailable(true);
        setImportFutureBackfillSlotCount(slotCount);
    } else {
        setImportFutureBackfillUndoAvailable(false);
        setImportFutureBackfillSlotCount(0);
    }
}

function showSyncStatusFeedback(status) {
    if (!status || status.pending) {
        return;
    }

    if (status.warnings?.length) {
        showWarningsIfAny({ warnings: status.warnings });
        return;
    }

    if (status.ok === false) {
        showToast("همگام‌سازی Google Calendar با مشکل مواجه شد.", "error");
        return;
    }

    const backfill = status.backfill;
    if (!backfill?.ran) {
        return;
    }

    if (backfill.imported > 0) {
        showToast(`${backfill.imported} رویداد آینده به‌عنوان مرخصی ثبت شد.`);
        applyBackfillStatusToUi(backfill);
        return;
    }

    if (backfill.failed > 0) {
        showToast("برخی رویدادهای تقویم ثبت نشدند.", "error");
        applyBackfillStatusToUi(backfill);
        return;
    }

    applyBackfillStatusToUi(backfill);
    showToast("رویداد شخصی جدیدی در ۳۰ روز آینده یافت نشد.");
}

async function maybeShowFreshConnectionToast() {
    const status = await fetchSyncStatusOnce();
    const updatedAt = status?.updated_at;
    if (!status || typeof updatedAt !== "number") {
        return;
    }

    const ageSeconds = Date.now() / 1000 - updatedAt;
    if (ageSeconds < 0 || ageSeconds > 120) {
        return;
    }

    const dedupeKey = `hamgam_fresh_connect_toast_${updatedAt}`;
    if (sessionStorage.getItem(dedupeKey)) {
        return;
    }

    sessionStorage.setItem(dedupeKey, "1");
    showToast("اتصال Google Calendar برقرار شد.");
}

async function waitForOAuthBackgroundSyncIfNeeded() {
    const status = await fetchSyncStatusOnce();
    if (!status?.pending) {
        showSyncStatusFeedback(status);
        return;
    }

    await waitForBackgroundSync({
        backfillPending: false,
        maxAttempts: 8,
        intervalMs: 1000
    });
}

async function waitForBackgroundSync(options = {}) {
    const needsBackfill = options.backfillPending === true;
    const maxAttempts = options.maxAttempts ?? (needsBackfill ? 60 : 15);
    const intervalMs = options.intervalMs ?? 2000;
    const loadingLabel = needsBackfill
        ? "در حال همگام‌سازی رویدادهای تقویم…"
        : "در حال اتصال به Google Calendar…";

    setSaveLoading(true, loadingLabel);

    const status = await pollSyncStatus({
        maxAttempts,
        intervalMs
    });

    setSaveLoading(false);

    if (status && !status.pending) {
        showSyncStatusFeedback(status);
        try {
            await openSettings();
        } catch (refreshError) {
            console.error("[Hamgam] settings refresh after sync failed:", refreshError);
        }
    } else if (needsBackfill) {
        showToast(
            "همگام‌سازی در پس‌زمینه ادامه دارد. چند دقیقه بعد نتیجه را در تنظیمات بررسی کنید.",
            "warning"
        );
    }

    return status;
}

async function checkRecentSyncStatus() {
    const status = await fetchSyncStatusOnce();
    if (!status) {
        return;
    }

    if (status.pending) {
        if (isEmbeddedInPaziresh24()) {
            return;
        }

        const finalStatus = await pollSyncStatus({ maxAttempts: 8, intervalMs: 1000 });
        showSyncStatusFeedback(finalStatus);
        return;
    }

    if (status.ok === false && status.warnings?.length) {
        showWarningsIfAny({ warnings: status.warnings });
    }
}

function cleanOAuthParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete("oauth");
    url.searchParams.delete("change");
    url.searchParams.delete("reason");
    const clean = url.pathname + (url.search ? url.search : "");
    window.history.replaceState({}, "", clean);
}

const GMAIL_CHANGE_PENDING_KEY = "hamgam_gmail_change_pending";
const OAUTH_ERROR_STORAGE_KEY = "hamgam_oauth_error";
const OAUTH_SUCCESS_STORAGE_KEY = "hamgam_oauth_success";
const GMAIL_CHANGE_MAX_AGE_MS = 15 * 60 * 1000;

function markGmailChangePending() {
    localStorage.setItem(GMAIL_CHANGE_PENDING_KEY, String(Date.now()));
}

function clearGmailChangePending() {
    localStorage.removeItem(GMAIL_CHANGE_PENDING_KEY);
}

function isGmailChangePending() {
    const raw = localStorage.getItem(GMAIL_CHANGE_PENDING_KEY);
    if (!raw) {
        return false;
    }

    const ts = Number(raw);
    if (!Number.isFinite(ts) || Date.now() - ts > GMAIL_CHANGE_MAX_AGE_MS) {
        clearGmailChangePending();
        return false;
    }

    return true;
}

async function handleGmailChangeReturnFromExternal() {
    if (!isGmailChangePending()) {
        return;
    }

    const storedSuccess = consumeStoredOAuthSuccess();
    if (storedSuccess?.change === "gmail") {
        stopGmailChangeOutcomeWatch();
        try {
            await handleStoredGmailChangeSuccess();
        } catch (refreshError) {
            console.error("[Hamgam] gmail change success refresh failed:", refreshError);
            showToast("حساب Google تغییر کرد. برای دیدن ایمیل جدید صفحه را یک‌بار رفرش کنید.");
        } finally {
            resetChangeGmailButton();
        }
        return;
    }

    const storedOAuthError = consumeStoredOAuthError();
    if (storedOAuthError) {
        stopGmailChangeOutcomeWatch();
        clearGmailChangePending();
        resetChangeGmailButton();
        const reason = typeof storedOAuthError.reason === "string" ? storedOAuthError.reason : "";
        showToast(getOAuthErrorMessageFromReason(reason), "error");
    }
}

function setupGmailChangeReturnListener() {
    const onReturn = () => {
        if (document.visibilityState && document.visibilityState !== "visible") {
            return;
        }
        void handleGmailChangeReturnFromExternal();
    };

    document.addEventListener("visibilitychange", onReturn);
    window.addEventListener("focus", onReturn);
    window.addEventListener("pageshow", onReturn);
}

function consumeStoredOAuthError() {
    const raw = localStorage.getItem(OAUTH_ERROR_STORAGE_KEY);
    if (!raw) {
        return null;
    }

    localStorage.removeItem(OAUTH_ERROR_STORAGE_KEY);

    try {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === "object" && parsed.oauth === "error") {
            return parsed;
        }
    } catch {
        return null;
    }

    return null;
}

function consumeStoredOAuthSuccess() {
    const raw = localStorage.getItem(OAUTH_SUCCESS_STORAGE_KEY);
    if (!raw) {
        return null;
    }

    localStorage.removeItem(OAUTH_SUCCESS_STORAGE_KEY);

    try {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === "object" && parsed.oauth === "success") {
            return parsed;
        }
    } catch {
        return null;
    }

    return null;
}

async function completeGmailChangeSuccess() {
    clearGmailChangePending();
    stopGmailChangeOutcomeWatch();
    resetChangeGmailButton();
    await waitForOAuthBackgroundSyncIfNeeded();
    await refreshSettingsAfterGmailChange();
    showToast("حساب Google با موفقیت تغییر کرد.");
}

async function refreshSettingsAfterGmailChange() {
    await openSettings();
    updateLiveBadge();
    updateSaveButton();
    if (isVacationPanelOpen()) {
        void fetchMedicalCenters(true);
    }
}

async function handleStoredGmailChangeSuccess() {
    await completeGmailChangeSuccess();
}

function getOAuthErrorMessageFromReason(reason) {
    const messages = {
        missing_code: "کد تأیید Google دریافت نشد. دوباره تلاش کنید.",
        auth_failed: "نشست پذیرش۲۴ منقضی شده. صفحه را از پنل دوباره باز کنید و مجدداً تغییر Gmail را انجام دهید.",
        exchange_failed: "اتصال به Google ناموفق بود. چند لحظه بعد دوباره تلاش کنید.",
        no_refresh_token: "دسترسی کامل Google داده نشد. در صفحه Google گزینه «اجازه دادن به همه» را بزنید.",
        new_account_consent: "برای تغییر حساب Google باید دسترسی کامل را بپذیرید. دوباره تلاش کنید.",
        internal_error: "خطای داخلی در اتصال Google. دوباره تلاش کنید."
    };

    return messages[reason] || "اتصال Google ناموفق بود. دوباره تلاش کنید.";
}

function tryOpenBlankOAuthWindow() {
    try {
        const popup = window.open("about:blank", "_blank", "noopener,noreferrer");
        if (!popup) {
            return null;
        }
        try {
            popup.opener = null;
        } catch {
            // noop
        }
        return popup;
    } catch {
        return null;
    }
}

function closeOAuthPreparedPopup(popup) {
    if (!popup || popup.closed) {
        return;
    }
    try {
        popup.close();
    } catch {
        // noop
    }
}

/**
 * Must run synchronously inside the click handler (before any await)
 * so popup blockers do not reject OAuth after the API round-trip.
 */
function prepareOAuthNavigation(options = {}) {
    const preferHamdast = !options.gmailChange && isEmbeddedInPaziresh24() && !!window.hamdast?.openLink;
    const usePopup = !options.gmailChange && !preferHamdast;
    return {
        preferHamdast,
        gmailChange: !!options.gmailChange,
        popup: usePopup ? tryOpenBlankOAuthWindow() : null
    };
}

async function completeOAuthNavigation(prepared, url, options = {}) {
    if (!url) {
        closeOAuthPreparedPopup(prepared?.popup);
        return "none";
    }

    if (options.gmailChange || prepared?.gmailChange) {
        const navMode = await navigateToGoogleOAuthForGmailChange(url);
        closeOAuthPreparedPopup(prepared?.popup);
        return navMode;
    }

    if (prepared?.preferHamdast) {
        try {
            const maybePromise = window.hamdast.openLink({ url });
            if (maybePromise && typeof maybePromise.then === "function") {
                await maybePromise;
            }
            closeOAuthPreparedPopup(prepared.popup);
            return "external";
        } catch (openLinkError) {
            console.error("[Hamgam] OAuth openLink failed:", openLinkError);
        }
    }

    if (prepared?.popup && !prepared.popup.closed) {
        try {
            prepared.popup.location.href = url;
            return "external";
        } catch (popupNavError) {
            console.error("[Hamgam] OAuth prepared popup navigation failed:", popupNavError);
            closeOAuthPreparedPopup(prepared.popup);
        }
    }

    return navigateToOAuthFallback(url);
}

/**
 * Google OAuth must leave the Paziresh24 iframe (top/same window — not a nested iframe).
 * @returns {Promise<"none"|"external"|"top"|"same">}
 */
async function navigateToOAuth(url) {
    return completeOAuthNavigation(prepareOAuthNavigation(), url);
}

/**
 * Gmail change: navigate in the same Paziresh24 panel/tab so OAuth callback can return to settings.
 * @returns {Promise<"none"|"external"|"top"|"same">}
 */
async function navigateToGoogleOAuthForGmailChange(url) {
    if (!url) {
        return "none";
    }

    try {
        if (window.self !== window.top) {
            window.top.location.href = url;
            return "top";
        }
    } catch {
        // cross-origin iframe — fall through to same-window navigation
    }

    try {
        window.location.assign(url);
        return "same";
    } catch (navError) {
        console.error("[Hamgam] Gmail change same-window navigation failed:", navError);
    }

    return navigateToOAuthFallback(url);
}

async function navigateToOAuthFallback(url) {
    if (!url) return "none";

    try {
        const popup = window.open(url, "_blank", "noopener,noreferrer");
        if (popup) {
            try {
                popup.opener = null;
            } catch {
                // noop
            }
            return "external";
        }
    } catch (popupError) {
        console.error("[Hamgam] OAuth window.open failed:", popupError);
    }

    try {
        const link = document.createElement("a");
        link.href = url;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        document.body.appendChild(link);
        link.click();
        link.remove();
        return "external";
    } catch (anchorError) {
        console.error("[Hamgam] OAuth anchor open failed:", anchorError);
    }

    try {
        if (window.self !== window.top) {
            window.top.location.href = url;
            return "top";
        }
    } catch {
        // cross-origin iframe — fall through to same-window navigation
    }

    window.location.href = url;
    return "same";
}

let gmailChangeOutcomeTimer = null;

function stopGmailChangeOutcomeWatch() {
    if (gmailChangeOutcomeTimer !== null) {
        window.clearInterval(gmailChangeOutcomeTimer);
        gmailChangeOutcomeTimer = null;
    }
}

function startGmailChangeOutcomeWatch() {
    stopGmailChangeOutcomeWatch();
    const startedAt = Date.now();

    gmailChangeOutcomeTimer = window.setInterval(() => {
        if (!isGmailChangePending()) {
            stopGmailChangeOutcomeWatch();
            return;
        }

        if (Date.now() - startedAt > GMAIL_CHANGE_MAX_AGE_MS) {
            stopGmailChangeOutcomeWatch();
            clearGmailChangePending();
            resetChangeGmailButton();
            return;
        }

        void handleGmailChangeReturnFromExternal();
    }, 1200);
}

function applyConnectionState(connected, googleEmail = undefined) {
    appState.connected = !!connected;
    if (appState.connected) {
        appState.oauthUrl = null;
    }
    if (googleEmail !== undefined) {
        updateGoogleAccountBanner(googleEmail || null);
    }
    updateSaveButton();
}

function resetChangeGmailButton() {
    appState.changingGmail = false;
    const btn = document.getElementById("changeGmailBtn");
    const disconnectBtn = document.getElementById("disconnectGoogleBtn");
    if (!btn) return;

    btn.disabled = false;
    btn.classList.remove("is-loading");
    btn.setAttribute("aria-busy", "false");
    if (disconnectBtn && !appState.disconnectingGoogle) {
        disconnectBtn.disabled = false;
    }
}

function resetDisconnectGoogleButton() {
    appState.disconnectingGoogle = false;
    const btn = document.getElementById("disconnectGoogleBtn");
    const changeGmailBtn = document.getElementById("changeGmailBtn");
    if (!btn) return;

    btn.disabled = false;
    btn.classList.remove("is-loading");
    btn.setAttribute("aria-busy", "false");
    if (changeGmailBtn && !appState.changingGmail) {
        changeGmailBtn.disabled = false;
    }
}

function setDisconnectGoogleLoading(loading) {
    const btn = document.getElementById("disconnectGoogleBtn");
    const changeGmailBtn = document.getElementById("changeGmailBtn");
    if (!btn) return;

    appState.disconnectingGoogle = loading;
    btn.disabled = loading;
    btn.classList.toggle("is-loading", loading);
    btn.setAttribute("aria-busy", loading ? "true" : "false");
    if (changeGmailBtn) {
        changeGmailBtn.disabled = loading || appState.changingGmail;
    }
}

function setChangeGmailLoading(loading) {
    const btn = document.getElementById("changeGmailBtn");
    const disconnectBtn = document.getElementById("disconnectGoogleBtn");
    if (!btn) return;

    appState.changingGmail = loading;
    btn.disabled = loading;
    btn.classList.toggle("is-loading", loading);
    btn.setAttribute("aria-busy", loading ? "true" : "false");
    if (disconnectBtn) {
        disconnectBtn.disabled = loading || appState.disconnectingGoogle;
    }
}

function apiHeaders(token, withJson = false) {
    const headers = {
        Authorization: `Bearer ${token}`,
        access_token: token
    };
    if (withJson) {
        headers["Content-Type"] = "application/json";
    }
    return headers;
}

function apiBodyWithToken(token, payload = {}) {
    return JSON.stringify({ access_token: token, ...payload });
}

async function parseJsonResponse(response) {
    const text = await response.text();
    try {
        const data = JSON.parse(text);
        if (typeof data === "object" && data !== null) {
            return data;
        }
    } catch {
        console.error("[Hamgam API] Non-JSON response:", text.slice(0, 400));
    }

    throw new Error("خطا در ارتباط با سرور. لطفاً چند لحظه بعد دوباره تلاش کنید.");
}

async function apiFetch(url, token, options = {}) {
    const { body = null, withJson = false, redirect = "follow" } = options;
    const response = await fetch(url, {
        method: "POST",
        headers: apiHeaders(token, withJson || body !== null),
        body,
        redirect
    });

    if (!response.ok) {
        console.error(`[Hamgam API] ${url} → HTTP ${response.status}`);
    }

    return response;
}

function isTopLevelWindow() {
    try {
        return window.self === window.top;
    } catch {
        return true;
    }
}

function isEmbeddedInPaziresh24() {
    try {
        return window.self !== window.top;
    } catch {
        return false;
    }
}

async function authenticateWithHamdast() {
    window.hamdast.initialize({ app_key: "hamgam" });

    const sessionToken = await window.hamdast.getSessionToken({
        scope: [
            "provider.profile.read",
            "provider.appointment.webhook",
            "provider.appointment.read",
            "provider.appointment.write",
            "provider.management.write"
        ]
    });

    const response = await fetch(`${HAMGAM_API_PREFIX}/auth.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            hamdast_session_token: sessionToken
        })
    });

    const data = await parseJsonResponse(response);
    if (!response.ok) {
        throw new Error(mapApiError(data.error) || "خطا در احراز هویت. لطفاً صفحه را از پنل پذیرش۲۴ مجدداً باز کنید.");
    }

    if (!data.access_token) {
        throw new Error("توکن دسترسی دریافت نشد.");
    }

    localStorage.setItem("access_token", data.access_token);
    applyConnectionState(!!data.connected, data.google_account_email || null);
    if (!appState.connected) {
        appState.oauthUrl = data.oauth_url || null;
        prefetchOAuthUrl(appState.oauthUrl);
    }
    if (data.settings && typeof data.settings === "object") {
        applySettingsToForm(data.settings);
        updateLiveBadge();
        if (typeof data.settings.connected === "boolean") {
            applyConnectionState(data.settings.connected, data.settings.google_account_email || null);
        }
        appState.settingsLoadedFromAuth = true;
    }
    showWarningsIfAny(data);
}

function prefetchOAuthUrl(url) {
    if (!url || document.querySelector('link[data-hamgam-oauth-prefetch="1"]')) {
        return;
    }

    const link = document.createElement("link");
    link.rel = "prefetch";
    link.as = "document";
    link.href = url;
    link.setAttribute("data-hamgam-oauth-prefetch", "1");
    document.head.appendChild(link);
}

async function initApp() {
    bindUiEvents();
    setupGmailChangeReturnListener();
    resetChangeGmailButton();

    const oauthReturn = isOAuthReturn();
    const oauthErrorReturn = isOAuthErrorReturn();
    const cachedToken = localStorage.getItem("access_token");
    const oauthStandaloneReturn = (oauthReturn || oauthErrorReturn) && isTopLevelWindow() && !!cachedToken;

    if (oauthReturn || oauthErrorReturn) {
        const title = document.getElementById("loading-title");
        if (title) {
            title.textContent = oauthErrorReturn ? "خطا در اتصال Google" : "در حال بارگذاری تنظیمات";
        }
    }

    try {
        if (window.hamdast) {
            await authenticateWithHamdast();
        } else if (oauthStandaloneReturn) {
            appState.connected = true;
        } else {
            throw new Error("این صفحه باید از داخل پنل پذیرش۲۴ باز شود.");
        }

        if (oauthReturn || oauthErrorReturn || !appState.settingsLoadedFromAuth) {
            await openSettings();
        }
        await applyPendingSettingsAfterOAuth();
        showApp();
        updateSaveButton();

        if (oauthReturn) {
            const gmailChanged = isGmailChangeReturn();
            cleanOAuthParams();
            if (gmailChanged) {
                try {
                    await completeGmailChangeSuccess();
                } catch (refreshError) {
                    console.error("[Hamgam] gmail change oauth return refresh failed:", refreshError);
                    showToast("حساب Google تغییر کرد. برای دیدن ایمیل جدید صفحه را یک‌بار رفرش کنید.");
                }
            } else {
                showToast("اتصال Google Calendar برقرار شد.");
                void waitForOAuthBackgroundSyncIfNeeded();
            }
        } else if (oauthErrorReturn) {
            clearGmailChangePending();
            resetChangeGmailButton();
            const errorMessage = getOAuthErrorMessage();
            cleanOAuthParams();
            showToast(errorMessage, "error");
        } else {
            const storedSuccess = consumeStoredOAuthSuccess();
            if (storedSuccess?.change === "gmail" && isGmailChangePending()) {
                try {
                    await handleStoredGmailChangeSuccess();
                } catch (refreshError) {
                    console.error("[Hamgam] gmail change success refresh failed:", refreshError);
                    showToast("حساب Google تغییر کرد. برای دیدن ایمیل جدید صفحه را یک‌بار رفرش کنید.");
                }
            }

            const storedOAuthError = consumeStoredOAuthError();
            if (storedOAuthError) {
                clearGmailChangePending();
                const reason = typeof storedOAuthError.reason === "string" ? storedOAuthError.reason : "";
                showToast(getOAuthErrorMessageFromReason(reason), "error");
            } else {
                await handleGmailChangeReturnFromExternal();
            }

            if (appState.connected) {
                void checkRecentSyncStatus();
                if (isEmbeddedInPaziresh24()) {
                    void maybeShowFreshConnectionToast();
                }
            }
        }
    } catch (error) {
        showLoadingError(error.message || "خطای ناشناخته");
        console.error("Init error:", error);
    }
}

function bindUiEvents() {
    document.querySelectorAll(".circle-opt").forEach(circle => {
        circle.addEventListener("click", () => handleColorSelect(circle));
        circle.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                handleColorSelect(circle);
            }
        });
    });

    document.querySelectorAll(".switch input").forEach(sw => {
        sw.addEventListener("click", (e) => e.stopPropagation());
        sw.addEventListener("change", () => {
            updateLiveBadge();
            pulseField(sw.closest(".field"));
            if (sw.dataset.field === "autoVacation") {
                updateVacationSubPanel();
            }
        });
    });

    const centersRefreshBtn = document.getElementById("vacationCentersRefresh");
    if (centersRefreshBtn) {
        centersRefreshBtn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            fetchMedicalCenters(true);
        });
    }

    document.querySelectorAll(".vacation-sub-checkbox:not([disabled])").forEach(checkbox => {
        checkbox.addEventListener("click", (e) => e.stopPropagation());
        checkbox.addEventListener("change", () => {
            pulseField(checkbox.closest(".vacation-sub-option"));
        });
    });

    document.querySelectorAll(".field.row").forEach(row => {
        row.addEventListener("click", (e) => {
            e.stopPropagation();
            if (e.target.closest(".switch")) return;
            const checkbox = row.querySelector('input[type="checkbox"]');
            if (!checkbox) return;
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event("change", { bubbles: true }));
        });
    });

    document.getElementById("calendarLiveBadge").addEventListener("click", toggleBadgeDetails);

    document.addEventListener("click", (e) => {
        const previewBox = document.getElementById("previewBox");
        if (appState.previewOpen && previewBox && !previewBox.contains(e.target)) {
            closePreviewDetails();
        }
    });

    const deleteSyncedBackfillBtn = document.getElementById("deleteSyncedBackfillBtn");
    if (deleteSyncedBackfillBtn) {
        deleteSyncedBackfillBtn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            void handleDeleteSyncedBackfillClick();
        });
    }

    document.getElementById("saveSettings").addEventListener("click", handleSaveClick);

    const vacationInfoToggle = document.getElementById("vacationInfoToggle");
    if (vacationInfoToggle) {
        vacationInfoToggle.addEventListener("click", toggleVacationInfo);
    }

    const changeGmailBtn = document.getElementById("changeGmailBtn");
    if (changeGmailBtn) {
        changeGmailBtn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            void handleChangeGmailClick();
        });
    }

    const disconnectGoogleBtn = document.getElementById("disconnectGoogleBtn");
    if (disconnectGoogleBtn) {
        disconnectGoogleBtn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            void handleDisconnectGoogleClick();
        });
    }

    setupHamgamConfirmDialog();
}

function isVacationPanelOpen() {
    return document.getElementById("vacationSubPanel")?.classList.contains("open") ?? false;
}

function vacationCheckMarkup() {
    return `<span class="vacation-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 6L9 17L4 12"/></svg></span>`;
}

function mapMedicalCentersError(data, response) {
    const error = typeof data?.error === "string" ? data.error : "";
    const mapped = mapApiError(error);

    if (mapped) {
        return mapped;
    }
    if (response && !response.ok) {
        return `خطا در دریافت مراکز درمانی (${response.status})`;
    }

    return "خطا در دریافت مراکز درمانی";
}

function updateVacationSubPanel() {
    const autoVacation = document.querySelector('[data-field="autoVacation"]');
    const panel = document.getElementById("vacationSubPanel");
    if (!autoVacation || !panel) return;

    const open = autoVacation.checked;
    panel.classList.toggle("open", open);
    panel.setAttribute("aria-hidden", open ? "false" : "true");
    autoVacation.setAttribute("aria-expanded", open ? "true" : "false");

    if (open && (appState.centersFetchState === "idle" || appState.centersFetchState === "error")) {
        fetchMedicalCenters(appState.centersFetchState === "error");
    } else {
        renderVacationCenters();
    }

    updateVacationSubOptionsState();
}

function getAllCenterIds(centers = appState.medicalCenters) {
    return centers.map(center => center.medical_center_id);
}

function applyDefaultVacationCenterSelection(centers) {
    if (!Array.isArray(centers) || centers.length === 0) {
        renderVacationCenters();
        return;
    }

    const allIds = getAllCenterIds(centers);
    const selection = getVacationCenterSelection();

    if (selection.mode === "all") {
        setVacationCenterSelection({ mode: "selected", centerIds: allIds });
        return;
    }

    const validSavedIds = selection.centerIds.filter(id => allIds.includes(id));
    setVacationCenterSelection({ mode: "selected", centerIds: validSavedIds });
}

function buildVacationSyncCentersPayload() {
    const centers = appState.medicalCenters;
    const selection = getVacationCenterSelection();

    if (centers.length === 0) {
        return { mode: "selected", centerIds: [] };
    }

    const allIds = getAllCenterIds(centers);
    const selectedIds = selection.mode === "all"
        ? allIds
        : selection.centerIds.filter(id => allIds.includes(id));

    if (selectedIds.length === 0) {
        return { mode: "selected", centerIds: [] };
    }

    if (selectedIds.length === allIds.length) {
        return { mode: "selected", centerIds: allIds };
    }

    return { mode: "selected", centerIds: selectedIds };
}

function normalizeVacationCenterSelection(raw) {
    if (!raw || typeof raw !== "object") {
        return { mode: "selected", centerIds: [] };
    }

    const rawMode = raw.mode === "selected" ? "selected" : (raw.mode === "all" ? "all" : "selected");
    const centerIds = Array.isArray(raw.center_ids)
        ? raw.center_ids.filter(id => typeof id === "string" && id.trim() !== "")
        : Array.isArray(raw.centerIds)
            ? raw.centerIds.filter(id => typeof id === "string" && id.trim() !== "")
            : [];

    if (rawMode === "all") {
        return {
            mode: "all",
            centerIds: [...new Set(centerIds)]
        };
    }

    return {
        mode: "selected",
        centerIds: [...new Set(centerIds)]
    };
}

function getVacationCenterSelection() {
    return normalizeVacationCenterSelection(appState.vacationCenterSelection);
}

function hasVacationCenterSelection() {
    const centers = appState.medicalCenters;
    const selection = getVacationCenterSelection();

    if (centers.length === 0) {
        return selection.mode === "all" || selection.centerIds.length > 0;
    }

    return selection.mode === "all" || selection.centerIds.length > 0;
}

function clearVacationCentersValidation() {
    const validationEl = document.getElementById("vacationCentersValidation");
    const sectionEl = document.getElementById("vacationCentersSection");
    if (validationEl) validationEl.hidden = true;
    if (sectionEl) sectionEl.classList.remove("has-validation-error");
}

function showVacationCentersValidation() {
    const validationEl = document.getElementById("vacationCentersValidation");
    const sectionEl = document.getElementById("vacationCentersSection");
    if (validationEl) validationEl.hidden = false;
    if (sectionEl) {
        sectionEl.classList.add("has-validation-error");
        pulseField(sectionEl);
        if (typeof sectionEl.scrollIntoView === "function") {
            sectionEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }
}

function updateVacationSubOptionsState() {
    const block = document.getElementById("vacationSubOptionsBlock");
    const hint = document.getElementById("vacationSubOptionsHint");
    const panelOpen = isVacationPanelOpen();
    const hasSelection = hasVacationCenterSelection();
    const inactive = panelOpen && !hasSelection;

    if (block) {
        block.classList.toggle("vacation-sub-options-block--inactive", inactive);
        block.setAttribute("aria-disabled", inactive ? "true" : "false");
    }

    if (hint) {
        hint.hidden = !inactive;
    }

    block?.querySelectorAll(".vacation-sub-option:not(.vacation-sub-option--disabled)").forEach(option => {
        const checkbox = option.querySelector(".vacation-sub-checkbox");
        if (checkbox?.dataset.field === "importFutureVacations" && appState.importFutureVacationsUsed) {
            return;
        }

        option.classList.toggle("vacation-sub-option--inactive", inactive);
        if (checkbox) {
            checkbox.disabled = inactive;
            checkbox.setAttribute("aria-disabled", inactive ? "true" : "false");
        }
    });

    applyImportFutureVacationsUiState();
}

function setImportFutureVacationsUsed(used) {
    appState.importFutureVacationsUsed = !!used;
    applyImportFutureVacationsUiState();
    applyImportFutureBackfillUndoUiState();
}

function setImportFutureBackfillUndoAvailable(available) {
    appState.importFutureBackfillUndoAvailable = !!available;
    applyImportFutureBackfillUndoUiState();
}

function setImportFutureBackfillSlotCount(count) {
    appState.importFutureBackfillSlotCount = Math.max(0, Number(count) || 0);
    applyImportFutureBackfillUndoUiState();
}

function formatBackfillDeleteToast(data) {
    const removed = Number(data?.removed ?? 0);
    const alreadyGone = Number(data?.already_gone ?? data?.not_found ?? 0);
    const failed = Number(data?.failed ?? 0);
    const suffix = failed > 0 ? ` (${failed} مورد ناموفق)` : "";

    if (removed > 0 && alreadyGone > 0) {
        return {
            message: `${removed} مرخصی حذف شد و ${alreadyGone} مورد قبلاً حذف شده بود${suffix}`,
            type: failed > 0 ? "error" : "success"
        };
    }

    if (removed > 0) {
        return {
            message: `${removed} مرخصی همگام‌شده حذف شد${suffix}`,
            type: failed > 0 ? "error" : "success"
        };
    }

    if (alreadyGone > 0) {
        return {
            message: `مرخصی فعالی یافت نشد (${alreadyGone} مورد قبلاً حذف شده بود). محدودیت همگام‌سازی ۳۰ روزه برداشته شد.`,
            type: "success"
        };
    }

    return {
        message: "محدودیت همگام‌سازی ۳۰ روزه برداشته شد.",
        type: "success"
    };
}

function applyImportFutureBackfillUndoUiState() {
    const wrap = document.getElementById("deleteSyncedBackfillWrap");
    const btn = document.getElementById("deleteSyncedBackfillBtn");
    const hint = document.getElementById("deleteSyncedBackfillHint");
    const btnText = btn?.querySelector(".vacation-sync-undo__btn-text");
    if (!wrap || !btn) {
        return;
    }

    const slotCount = appState.importFutureBackfillSlotCount;
    const hasTrackedVacations = slotCount > 0;
    const used = appState.importFutureVacationsUsed;
    const busy = appState.deletingSyncedBackfill;

    wrap.hidden = !used;
    wrap.classList.toggle("vacation-sync-undo--reset", used && !hasTrackedVacations);

    btn.disabled = busy;
    btn.classList.toggle("is-loading", busy);
    btn.setAttribute("aria-busy", busy ? "true" : "false");

    if (hint) {
        if (hasTrackedVacations) {
            hint.textContent = `${slotCount} مرخصی ثبت شده`;
            hint.hidden = false;
        } else {
            hint.textContent = "";
            hint.hidden = true;
        }
    }

    if (btnText) {
        btnText.textContent = hasTrackedVacations ? "حذف" : "فعال‌سازی مجدد";
    }

    applyImportFutureVacationsUiState();
}

function resetImportFutureVacationsAfterSyncedDelete() {
    setImportFutureBackfillUndoAvailable(false);
    setImportFutureBackfillSlotCount(0);
    setImportFutureVacationsUsed(false);

    const importFutureEl = document.querySelector('[data-field="importFutureVacations"]');
    if (importFutureEl) {
        importFutureEl.checked = false;
    }

    applyImportFutureVacationsUiState();
    updateVacationSubOptionsState();
}

async function handleDeleteSyncedBackfillClick() {
    if (!appState.importFutureVacationsUsed) {
        return;
    }

    const slotCount = appState.importFutureBackfillSlotCount;
    const confirmOptions = slotCount > 0
        ? {
            title: "حذف مرخصی‌های همگام‌شده",
            message: `${slotCount} مرخصی از پذیرش۲۴ حذف می‌شود.`,
            acceptLabel: "حذف",
            danger: true,
            variant: "danger"
        }
        : {
            title: "فعال‌سازی مجدد همگام‌سازی",
            message: "می‌توانید دوباره همگام‌سازی ۳۰ روزه را فعال کنید.",
            acceptLabel: "ادامه",
            danger: false,
            variant: "default"
        };

    await runImportFutureBackfillCleanup(confirmOptions);
}

async function runImportFutureBackfillCleanup(confirmOptions) {
    if (appState.deletingSyncedBackfill) {
        return;
    }

    const confirmed = await showHamgamConfirm(confirmOptions);
    if (!confirmed) {
        return;
    }

    const token = localStorage.getItem("access_token");
    if (!token) {
        showToast("ابتدا وارد حساب پذیرش۲۴ شوید.", "error");
        return;
    }

    appState.deletingSyncedBackfill = true;
    applyImportFutureBackfillUndoUiState();

    try {
        const response = await apiFetch(
            `${HAMGAM_API_PREFIX}/delete-synced-backfill-vacations.php`,
            token,
            { body: apiBodyWithToken(token), withJson: true }
        );
        const data = await parseJsonResponse(response);

        if (!response.ok) {
            throw new Error(mapApiError(data?.error) || "عملیات ناموفق بود.");
        }

        resetImportFutureVacationsAfterSyncedDelete();

        if (Number(data.removed) > 0) {
            showToast(`${data.removed} مرخصی حذف شد.`);
        } else if (Number(data.already_gone) > 0 || Number(data.not_found) > 0) {
            showToast("مرخصی فعالی نبود.");
        } else if (Number(data.failed) > 0) {
            showToast("برخی مرخصی‌ها حذف نشدند. دوباره تلاش کنید.", "warning");
        } else {
            showToast("انجام شد.");
        }
    } catch (error) {
        console.error("[Hamgam] import future backfill cleanup failed:", error);
        showToast(error.message || "عملیات ناموفق بود.", "error");
    } finally {
        appState.deletingSyncedBackfill = false;
        applyImportFutureBackfillUndoUiState();
    }
}

function applyImportFutureVacationsUiState() {
    const importFutureEl = document.querySelector('[data-field="importFutureVacations"]');
    const option = importFutureEl?.closest(".vacation-sub-option");
    const used = appState.importFutureVacationsUsed;

    if (!importFutureEl || !option) {
        return;
    }

    if (used) {
        importFutureEl.checked = true;
        importFutureEl.disabled = true;
        importFutureEl.setAttribute("aria-disabled", "true");
        option.classList.add("vacation-sub-option--disabled");
        option.classList.remove("vacation-sub-option--inactive");
        option.setAttribute("aria-disabled", "true");
        return;
    }

    importFutureEl.disabled = false;
    importFutureEl.removeAttribute("aria-disabled");
    option.classList.remove("vacation-sub-option--disabled");
    option.removeAttribute("aria-disabled");
}

function setVacationCenterSelection(selection) {
    appState.vacationCenterSelection = normalizeVacationCenterSelection(selection);
    clearVacationCentersValidation();
    renderVacationCenters();
    updateVacationSubOptionsState();
}

function isAllCentersSelected() {
    const centers = appState.medicalCenters;
    const selection = getVacationCenterSelection();

    if (centers.length === 0) {
        return selection.mode === "all";
    }

    if (selection.mode === "all") {
        return true;
    }

    return selection.centerIds.length === centers.length
        && centers.every(center => selection.centerIds.includes(center.medical_center_id));
}

function syncAllCentersCheckbox() {
    const allInput = document.getElementById("vacationCenterSelectAll");
    if (!allInput) return;

    const centers = appState.medicalCenters;
    if (centers.length <= 1) {
        if (centers.length === 0) {
            allInput.checked = false;
            allInput.indeterminate = false;
            return;
        }

        const selection = getVacationCenterSelection();
        const selected = selection.mode === "all" || selection.centerIds.includes(centers[0].medical_center_id);
        allInput.checked = selected;
        allInput.indeterminate = false;
        return;
    }

    const selection = getVacationCenterSelection();
    const selectedCount = selection.mode === "all"
        ? centers.length
        : selection.centerIds.length;

    allInput.checked = selectedCount === centers.length && centers.length > 0;
    allInput.indeterminate = selectedCount > 0 && selectedCount < centers.length;
}

function setCentersRefreshLoading(loading) {
    const btn = document.getElementById("vacationCentersRefresh");
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle("is-loading", loading);
    btn.setAttribute("aria-busy", loading ? "true" : "false");
}

function renderVacationCenters() {
    const listEl = document.getElementById("vacationCentersList");
    const loadingEl = document.getElementById("vacationCentersLoading");
    const errorEl = document.getElementById("vacationCentersError");
    const emptyEl = document.getElementById("vacationCentersEmpty");
    const bodyEl = document.getElementById("vacationCentersBody");
    if (!listEl) return;

    const panelOpen = isVacationPanelOpen();
    const state = panelOpen ? appState.centersFetchState : "idle";
    const centers = appState.medicalCenters;

    if (bodyEl) {
        bodyEl.dataset.state = state;
    }

    loadingEl.hidden = true;
    errorEl.hidden = true;
    emptyEl.hidden = true;
    listEl.hidden = true;
    listEl.innerHTML = "";

    if (!panelOpen) {
        updateVacationSubOptionsState();
        return;
    }

    if (state === "loading") {
        loadingEl.hidden = false;
        updateVacationSubOptionsState();
        return;
    }

    if (state === "error") {
        errorEl.hidden = false;
        updateVacationSubOptionsState();
        return;
    }

    if (state === "empty" || centers.length === 0) {
        emptyEl.hidden = false;
        updateVacationSubOptionsState();
        return;
    }

    listEl.hidden = false;

    const selection = getVacationCenterSelection();
    const showAllOption = centers.length > 1;
    const allSelected = isAllCentersSelected();
    const fragments = [];

    if (showAllOption) {
        fragments.push(`
            <label class="vacation-center-item vacation-center-item--all${allSelected ? " is-selected" : ""}" for="vacationCenterSelectAll">
                <input type="checkbox" class="vacation-center-input" id="vacationCenterSelectAll"${allSelected ? " checked" : ""}>
                ${vacationCheckMarkup()}
                <span class="vacation-center-item-body">
                    <span class="vacation-center-item-title">
                        همه مراکز
                        <span class="vacation-center-badge">پیشنهادی</span>
                    </span>
                    <span class="vacation-center-item-hint">${centers.length} مرکز درمانی</span>
                </span>
            </label>
        `);

        fragments.push(`<div class="vacation-centers-detail${allSelected ? " is-collapsed" : ""}">`);
    }

    centers.forEach(center => {
        const id = center.medical_center_id;
        const checked = selection.mode === "all" || selection.centerIds.includes(id);
        fragments.push(`
            <label class="vacation-center-item${checked ? " is-selected" : ""}" for="vacationCenter_${escapeHtml(id)}">
                <input type="checkbox" class="vacation-center-input" id="vacationCenter_${escapeHtml(id)}" data-center-id="${escapeHtml(id)}"${checked ? " checked" : ""}>
                ${vacationCheckMarkup()}
                <span class="vacation-center-item-body">
                    <span class="vacation-center-item-title">${escapeHtml(center.name || "مرکز درمانی")}</span>
                    ${center.is_active_booking ? '<span class="vacation-center-item-hint">نوبت‌دهی فعال</span>' : ""}
                </span>
            </label>
        `);
    });

    if (showAllOption) {
        fragments.push("</div>");
    }

    listEl.innerHTML = fragments.join("");

    listEl.querySelectorAll(".vacation-center-input").forEach(input => {
        input.addEventListener("change", handleVacationCenterCheckboxChange);
    });

    syncAllCentersCheckbox();
    updateVacationSubOptionsState();
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function handleVacationCenterCheckboxChange(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;

    const centers = appState.medicalCenters;
    const centerIds = centers.map(center => center.medical_center_id);

    if (input.id === "vacationCenterSelectAll") {
        if (input.checked) {
            setVacationCenterSelection({ mode: "selected", centerIds: getAllCenterIds(centers) });
        } else {
            setVacationCenterSelection({ mode: "selected", centerIds: [] });
        }
        return;
    }

    const selectedId = input.dataset.centerId;
    if (!selectedId) return;

    let nextIds = getVacationCenterSelection().mode === "all"
        ? [...centerIds]
        : [...getVacationCenterSelection().centerIds];

    if (input.checked) {
        if (!nextIds.includes(selectedId)) {
            nextIds.push(selectedId);
        }
    } else {
        nextIds = nextIds.filter(id => id !== selectedId);
    }

    if (nextIds.length === centerIds.length && centerIds.length > 0) {
        setVacationCenterSelection({ mode: "selected", centerIds: [...centerIds] });
        return;
    }

    setVacationCenterSelection({ mode: "selected", centerIds: nextIds });
}

async function fetchMedicalCenters(force = false) {
    if (!force && appState.centersFetchState === "loading") return;
    if (!force && appState.centersFetchState === "ready" && appState.medicalCenters.length > 0) return;

    const fetchSeq = ++appState.centersFetchSeq;
    appState.centersFetchState = "loading";
    setCentersRefreshLoading(true);
    renderVacationCenters();

    try {
        const token = localStorage.getItem("access_token");
        if (!token) {
            throw new Error("توکن یافت نشد");
        }

        const response = await apiFetch(
            `${HAMGAM_API_PREFIX}/medical-centers.php`,
            token,
            { body: apiBodyWithToken(token) }
        );
        const data = await parseJsonResponse(response);

        if (fetchSeq !== appState.centersFetchSeq) return;

        if (!response.ok || !Array.isArray(data.centers)) {
            throw new Error(mapMedicalCentersError(data, response));
        }

        appState.medicalCenters = data.centers;
        appState.centersFetchState = data.centers.length === 0 ? "empty" : "ready";
        applyDefaultVacationCenterSelection(data.centers);
    } catch (error) {
        if (fetchSeq !== appState.centersFetchSeq) return;

        console.error("[Hamgam] medical centers fetch failed:", error);
        appState.centersFetchState = "error";
        const errorText = document.getElementById("vacationCentersErrorText");
        if (errorText) {
            errorText.textContent = error.message || "خطا در دریافت مراکز درمانی";
        }
        renderVacationCenters();
    } finally {
        if (fetchSeq === appState.centersFetchSeq) {
            setCentersRefreshLoading(false);
        }
    }
}

function validateVacationCentersBeforeSave(autoVacation) {
    if (!autoVacation) return true;

    const centers = appState.medicalCenters;
    const selection = getVacationCenterSelection();

    if (centers.length === 0) {
        if (appState.centersFetchState === "loading") {
            showToast("لطفاً چند لحظه صبر کنید تا مراکز درمانی بارگذاری شوند.", "error");
            return false;
        }

        if (appState.centersFetchState === "error") {
            showToast("خطا در دریافت مراکز درمانی. دوباره تلاش کنید.", "error");
            return false;
        }

        showToast("مرکز درمانی یافت نشد.", "error");
        return false;
    }

    if (selection.mode === "all" || selection.centerIds.length > 0) {
        return true;
    }

    showVacationCentersValidation();
    showToast("شما مرکز درمانیتو انتخاب نکردی", "error");
    return false;
}

function toggleVacationInfo() {
    const toggle = document.getElementById("vacationInfoToggle");
    const panel = document.getElementById("vacationInfoPanel");
    if (!toggle || !panel) return;

    const open = toggle.getAttribute("aria-expanded") !== "true";
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    panel.setAttribute("aria-hidden", open ? "false" : "true");
    panel.classList.toggle("open", open);
}

function pulseField(field) {
    if (!field) return;
    field.classList.remove("field-pulse");
    void field.offsetWidth;
    field.classList.add("field-pulse");
}

function collectSettingsPayload() {
    const colorSection = document.getElementById("colorPickerSection");
    const fields = getFieldState();

    return {
        colorId: colorSection.dataset.selectedColor || DEFAULT_COLOR_ID,
        fullName: fields.fullName,
        datetime: fields.datetime,
        nationalId: fields.nationalId,
        phone: fields.phone,
        autoVacation: fields.autoVacation,
        importFutureVacations: fields.autoVacation ? fields.importFutureVacations : false,
        cancelAppointmentOnEventDelete: fields.autoVacation ? fields.cancelAppointmentOnEventDelete : false,
        cancelConflictingAppointments: fields.autoVacation ? fields.cancelConflictingAppointments : false,
        vacationSyncCenters: buildVacationSyncCentersPayload()
    };
}

function handleSaveClick() {
    if (appState.saving) return;

    const payload = collectSettingsPayload();

    if (!validateVacationCentersBeforeSave(payload.autoVacation)) {
        return;
    }

    if (appState.connected) {
        update();
        return;
    }

    if (appState.oauthUrl) {
        localStorage.setItem("hamgam_pending_settings", JSON.stringify(payload));
        setSaveLoading(true);
        const oauthPrepared = prepareOAuthNavigation();
        void beginOAuthNavigation(appState.oauthUrl, {
            onExternal: () => setSaveLoading(false),
            onFailure: (message) => {
                setSaveLoading(false);
                showToast(message, "error");
            },
            stillWaiting: () => appState.saving
        }, oauthPrepared);
        return;
    }

    showToast("برای ذخیره تنظیمات ابتدا حساب Google را متصل کنید.", "error");
}

async function beginOAuthNavigation(oauthUrl, options = {}, prepared = null) {
    const oauthPrepared = prepared || prepareOAuthNavigation();
    const navMode = await completeOAuthNavigation(oauthPrepared, oauthUrl);

    if (navMode === "none") {
        options.onFailure?.("خطا در باز کردن صفحه Google. دوباره تلاش کنید.");
        return navMode;
    }

    if (navMode === "external") {
        options.onExternal?.();
        return navMode;
    }

    window.setTimeout(() => {
        if (document.visibilityState === "visible" && (options.stillWaiting?.() ?? true)) {
            options.onFailure?.("باز کردن صفحه Google ناموفق بود. دوباره تلاش کنید.");
        }
    }, 2500);

    return navMode;
}

function updateSaveButton() {
    const btn = document.getElementById("saveSettings");
    const text = btn?.querySelector(".save-btn-text");
    if (!text || appState.saving) return;
    text.textContent = appState.connected ? "ذخیره تغییرات" : "اتصال به Google Calendar";
}

function setSaveLoading(loading, label = null) {
    const btn = document.getElementById("saveSettings");
    if (!btn) return;

    const text = btn.querySelector(".save-btn-text");
    const defaultLabel = appState.connected ? "ذخیره تغییرات" : "اتصال به Google Calendar";
    const loadingLabel = label || "در حال ذخیره…";

    appState.saving = loading;
    btn.disabled = loading;
    btn.classList.toggle("is-loading", loading);
    btn.setAttribute("aria-busy", loading ? "true" : "false");
    btn.setAttribute("aria-label", loading ? loadingLabel : defaultLabel);

    if (text) {
        text.textContent = loading ? loadingLabel : defaultLabel;
    }

    if (!loading) {
        updateSaveButton();
    }
}

function showApp() {
    const screen = document.getElementById("loading-screen");
    const app = document.getElementById("app-container");

    screen.classList.add("fade-out");
    setTimeout(() => {
        screen.hidden = true;
        app.hidden = false;
        requestAnimationFrame(() => app.classList.add("fade-in"));
    }, 280);
}

function showLoadingError(message) {
    const spinner = document.getElementById("loading-spinner");
    const title = document.getElementById("loading-title");
    const subtitle = document.querySelector(".loading-subtitle");
    const errorEl = document.getElementById("loading-error");

    if (spinner) spinner.hidden = true;
    if (subtitle) subtitle.hidden = true;
    if (title) title.textContent = "خطا در بارگذاری";
    if (errorEl) {
        errorEl.textContent = message;
        errorEl.hidden = false;
    }
}

let hamgamConfirmResolver = null;

function setupHamgamConfirmDialog() {
    const overlay = document.getElementById("hamgamConfirmOverlay");
    const cancelBtn = document.getElementById("hamgamConfirmCancel");
    const acceptBtn = document.getElementById("hamgamConfirmAccept");
    if (!overlay || !cancelBtn || !acceptBtn) {
        return;
    }

    const closeConfirm = (result) => {
        overlay.hidden = true;
        document.body.classList.remove("hamgam-confirm-open");
        const resolve = hamgamConfirmResolver;
        hamgamConfirmResolver = null;
        resolve?.(result);
    };

    cancelBtn.addEventListener("click", () => closeConfirm(false));
    acceptBtn.addEventListener("click", () => closeConfirm(true));
    overlay.addEventListener("click", (event) => {
        if (event.target === overlay) {
            closeConfirm(false);
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && hamgamConfirmResolver && !overlay.hidden) {
            closeConfirm(false);
        }
    });
}

function showHamgamConfirm(options = {}) {
    const overlay = document.getElementById("hamgamConfirmOverlay");
    const dialog = overlay?.querySelector(".hamgam-confirm-dialog");
    const titleEl = document.getElementById("hamgamConfirmTitle");
    const messageEl = document.getElementById("hamgamConfirmMessage");
    const acceptBtn = document.getElementById("hamgamConfirmAccept");
    const iconWrap = overlay?.querySelector(".hamgam-confirm-dialog__icon");
    if (!overlay || !titleEl || !messageEl || !acceptBtn) {
        return Promise.resolve(window.confirm(options.message || options.title || "ادامه می‌دهید؟"));
    }

    if (hamgamConfirmResolver) {
        hamgamConfirmResolver(false);
    }

    const variant = options.variant || (options.danger ? "danger" : "default");
    const isGmailVariant = variant === "gmail";
    const isReenableVariant = variant === "reenable" || isGmailVariant;

    titleEl.textContent = options.title || "تأیید عملیات";
    messageEl.textContent = options.message || "";
    acceptBtn.textContent = options.acceptLabel || "تأیید";
    acceptBtn.classList.toggle("hamgam-confirm-dialog__btn--danger", !!options.danger);
    acceptBtn.classList.toggle("hamgam-confirm-dialog__btn--reenable", isReenableVariant);
    acceptBtn.classList.toggle("hamgam-confirm-dialog__btn--primary", !options.danger && !isReenableVariant);

    if (dialog) {
        dialog.classList.toggle("hamgam-confirm-dialog--danger", variant === "danger");
        dialog.classList.toggle("hamgam-confirm-dialog--reenable", isReenableVariant);
    }

    if (iconWrap) {
        iconWrap.classList.toggle("hamgam-confirm-dialog__icon--danger", variant === "danger");
        iconWrap.classList.toggle("hamgam-confirm-dialog__icon--reenable", isReenableVariant);

        if (variant === "danger") {
            iconWrap.innerHTML = `<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M5 7h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                <path d="M10 11v5M14 11v5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                <path d="M6 7l1 12a1.5 1.5 0 001.5 1.5h8A1.5 1.5 0 0018 19l1-12" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M9 7V5.5A1.5 1.5 0 0110.5 4h3A1.5 1.5 0 0115 5.5V7" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
            </svg>`;
        } else if (isGmailVariant) {
            iconWrap.innerHTML = `<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="3.5" y="5.5" width="17" height="13" rx="2.2" stroke="currentColor" stroke-width="1.6"/>
                <path d="M4 8.5l8 5.5 8-5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        } else if (variant === "reenable") {
            iconWrap.innerHTML = `<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 4.5v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                <path d="M7.5 7.5A6.5 6.5 0 1012 5.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        } else {
            iconWrap.innerHTML = `<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/>
                <path d="M12 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <circle cx="12" cy="16.2" r="1" fill="currentColor"/>
            </svg>`;
        }
    }

    overlay.hidden = false;
    document.body.classList.add("hamgam-confirm-open");
    acceptBtn.focus();

    return new Promise((resolve) => {
        hamgamConfirmResolver = resolve;
    });
}

function showToast(message, type = "success") {
    const toast = document.getElementById("toast");
    if (!toast) return;

    toast.textContent = message;
    toast.className = `toast toast-${type} show`;
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => toast.classList.remove("show"), 3000);
}

function showWarningsIfAny(data) {
    if (!data?.warnings?.length) return;

    const message = data.warnings
        .map((warning) => warning.message || warning.code || "")
        .filter(Boolean)
        .join(" ");

    if (message) {
        showToast(message, "warning");
    }
}

function getFieldState() {
    const importFutureEl = document.querySelector('[data-field="importFutureVacations"]');
    const cancelOnDeleteEl = document.querySelector('[data-field="cancelAppointmentOnEventDelete"]');
    const cancelConflictEl = document.querySelector('[data-field="cancelConflictingAppointments"]');
    return {
        fullName: document.querySelector('[data-field="fullName"]').checked,
        datetime: document.querySelector('[data-field="datetime"]').checked,
        nationalId: document.querySelector('[data-field="nationalId"]').checked,
        phone: document.querySelector('[data-field="phone"]').checked,
        autoVacation: document.querySelector('[data-field="autoVacation"]').checked,
        importFutureVacations: importFutureEl ? importFutureEl.checked : false,
        cancelAppointmentOnEventDelete: cancelOnDeleteEl ? cancelOnDeleteEl.checked : true,
        cancelConflictingAppointments: cancelConflictEl ? cancelConflictEl.checked : true
    };
}

function applySettingsToForm(settings) {
    const targetColorId = String(settings.color_id || DEFAULT_COLOR_ID);

    document.querySelectorAll(".circle-opt").forEach(circle => {
        circle.classList.toggle("active", circle.dataset.color === targetColorId);
    });

    document.querySelector('[data-field="fullName"]').checked = !!settings.Patient_name;
    document.querySelector('[data-field="datetime"]').checked = !!settings.Patient_date_time;
    document.querySelector('[data-field="nationalId"]').checked = !!settings.Patient_national;
    document.querySelector('[data-field="phone"]').checked = !!settings.Patient_phone;
    document.querySelector('[data-field="autoVacation"]').checked = !!settings.auto_vacation;

    const importFutureEl = document.querySelector('[data-field="importFutureVacations"]');
    if (importFutureEl) {
        importFutureEl.checked = !!settings.import_future_vacations;
    }
    setImportFutureVacationsUsed(!!settings.import_future_vacations_used);
    const slotCount = Math.max(
        0,
        Number(settings.synced_vacation_count ?? settings.import_future_backfill_slot_count ?? 0)
    );
    setImportFutureBackfillUndoAvailable(!!settings.import_future_backfill_undo_available || slotCount > 0);
    setImportFutureBackfillSlotCount(slotCount);

    const cancelOnDeleteEl = document.querySelector('[data-field="cancelAppointmentOnEventDelete"]');
    if (cancelOnDeleteEl) {
        cancelOnDeleteEl.checked = !!settings.cancel_appointment_on_event_delete;
    }

    const cancelConflictEl = document.querySelector('[data-field="cancelConflictingAppointments"]');
    if (cancelConflictEl) {
        cancelConflictEl.checked = !!settings.cancel_conflicting_appointments;
    }

    if (settings.vacation_sync_centers) {
        appState.vacationCenterSelection = normalizeVacationCenterSelection(settings.vacation_sync_centers);
    }

    updateVacationSubPanel();
    if (appState.medicalCenters.length > 0) {
        applyDefaultVacationCenterSelection(appState.medicalCenters);
    } else {
        renderVacationCenters();
    }

    if (settings.google_account_email !== undefined) {
        updateGoogleAccountBanner(settings.google_account_email || null);
    }
}

function updateGoogleAccountBanner(email) {
    const group = document.getElementById("googleAccountGroup");
    const emailEl = document.getElementById("googleAccountEmail");
    if (!emailEl) return;

    if (email && appState.connected) {
        emailEl.textContent = email;
        if (group) group.hidden = false;
    } else {
        emailEl.textContent = "";
        if (group) group.hidden = true;
    }
}

function redirectToLauncher() {
    let navigated = false;

    const finishNavigation = () => {
        if (navigated) {
            return;
        }
        navigated = true;

        try {
            if (window.self !== window.top) {
                window.top.location.href = PAZIRESH24_LAUNCHER;
                return;
            }
        } catch {
            // cross-origin iframe — fall through
        }

        window.location.href = PAZIRESH24_LAUNCHER;
    };

    if (isEmbeddedInPaziresh24() && window.hamdast?.redirect?.dispatch) {
        try {
            window.hamdast.redirect.dispatch({ path: PAZIRESH24_LAUNCHER_PATH });
        } catch (dispatchError) {
            console.error("[Hamgam] launcher redirect dispatch failed:", dispatchError);
            finishNavigation();
            return;
        }

        window.setTimeout(finishNavigation, 450);
        return;
    }

    if (isEmbeddedInPaziresh24() && window.hamdast?.openLink) {
        try {
            window.hamdast.openLink({ url: PAZIRESH24_LAUNCHER });
        } catch (openLinkError) {
            console.error("[Hamgam] launcher openLink failed:", openLinkError);
            finishNavigation();
            return;
        }

        window.setTimeout(finishNavigation, 450);
        return;
    }

    finishNavigation();
}

/** باز کردن اپ تنظیمات داخل لانچر پذیرش۲۴ (iframe) — بدون خروج به تب جدید */
function openAppInLauncher() {
    if (isEmbeddedInPaziresh24() && window.hamdast?.redirect?.dispatch) {
        window.hamdast.redirect.dispatch({ path: PAZIRESH24_LAUNCHER_APP_PATH });
        return;
    }

    if (isEmbeddedInPaziresh24() && window.hamdast?.openLink) {
        window.hamdast.openLink({ url: PAZIRESH24_LAUNCHER_APP });
        return;
    }

    try {
        if (window.self !== window.top) {
            window.top.location.href = PAZIRESH24_LAUNCHER_APP;
            return;
        }
    } catch {
        // cross-origin iframe — fall through
    }

    window.location.href = PAZIRESH24_LAUNCHER_APP;
}

async function ensureFreshAccessToken() {
    if (window.hamdast) {
        await authenticateWithHamdast();
    }

    const token = localStorage.getItem("access_token");
    if (!token) {
        throw new Error("خطا در احراز هویت. صفحه را از پنل پذیرش۲۴ دوباره باز کنید.");
    }

    return token;
}

async function requestChangeGmailOAuthUrl(token) {
    const returnTo = isEmbeddedInPaziresh24() ? "launcher" : "settings";
    const response = await apiFetch(
        `${HAMGAM_API_PREFIX}/change-gmail.php`,
        token,
        { body: apiBodyWithToken(token, { return_to: returnTo }), withJson: true }
    );
    const data = await parseJsonResponse(response);

    if (response.status === 401 && window.hamdast) {
        const freshToken = await ensureFreshAccessToken();
        const retry = await apiFetch(
            `${HAMGAM_API_PREFIX}/change-gmail.php`,
            freshToken,
            { body: apiBodyWithToken(freshToken, { return_to: returnTo }), withJson: true }
        );
        const retryData = await parseJsonResponse(retry);
        if (!retry.ok || !retryData.oauth_url) {
            throw new Error(mapApiError(retryData.error) || "خطا در شروع تغییر حساب Google");
        }
        return retryData.oauth_url;
    }

    if (!response.ok || !data.oauth_url) {
        throw new Error(mapApiError(data.error) || "خطا در شروع تغییر حساب Google");
    }

    return data.oauth_url;
}

async function handleDisconnectGoogleClick() {
    if (appState.disconnectingGoogle || appState.changingGmail) return;

    if (!appState.connected) {
        showToast("اتصال Google برقرار نیست.", "error");
        return;
    }

    const confirmed = await showHamgamConfirm({
        title: "لغو اتصال Google",
        message: "اتصال Google Calendar قطع می‌شود. تنظیمات شما (رنگ، فیلدها و گزینه‌های مرخصی) حفظ می‌ماند و می‌توانید بعداً دوباره متصل شوید.",
        acceptLabel: "لغو اتصال",
        danger: true
    });
    if (!confirmed) {
        return;
    }

    const token = window.hamdast
        ? await ensureFreshAccessToken().catch(() => null)
        : localStorage.getItem("access_token");
    if (!token) {
        showToast("ابتدا وارد حساب پذیرش۲۴ شوید.", "error");
        return;
    }

    setDisconnectGoogleLoading(true);

    try {
        const response = await apiFetch(
            `${HAMGAM_API_PREFIX}/disconnect.php`,
            token,
            { body: apiBodyWithToken(token), withJson: true }
        );
        const data = await parseJsonResponse(response);

        if (response.status === 401 && window.hamdast) {
            const freshToken = await ensureFreshAccessToken();
            const retry = await apiFetch(
                `${HAMGAM_API_PREFIX}/disconnect.php`,
                freshToken,
                { body: apiBodyWithToken(freshToken), withJson: true }
            );
            const retryData = await parseJsonResponse(retry);
            if (!retry.ok || !retryData.ok) {
                throw new Error(mapApiError(retryData.error) || "لغو اتصال Google ناموفق بود.");
            }
            applyDisconnectResult(retryData);
            return;
        }

        if (!response.ok || !data.ok) {
            throw new Error(mapApiError(data.error) || "لغو اتصال Google ناموفق بود.");
        }

        applyDisconnectResult(data);
    } catch (error) {
        console.error("[Hamgam] disconnect google failed:", error);
        showToast(error.message || "لغو اتصال Google ناموفق بود.", "error");
    } finally {
        resetDisconnectGoogleButton();
    }
}

function applyDisconnectResult(data) {
    if (data.settings) {
        applySettingsToForm(data.settings);
    }

    applyConnectionState(false, null);
    appState.oauthUrl = data.oauth_url || null;
    prefetchOAuthUrl(appState.oauthUrl);
    updateLiveBadge();
    updateSaveButton();
    showToast("اتصال Google قطع شد.");
    redirectToLauncher();
}

async function handleChangeGmailClick() {
    if (appState.changingGmail) return;

    if (!appState.connected) {
        showToast("برای تغییر حساب ابتدا Google را متصل کنید.", "error");
        return;
    }

    const confirmed = await showHamgamConfirm({
        title: "تغییر حساب Google",
        message: "برای اتصال حساب Google دیگر به صفحه انتخاب حساب هدایت می‌شوید. تنظیمات همگام‌سازی و مرخصی شما حفظ می‌ماند.",
        acceptLabel: "تغییر حساب",
        variant: "gmail"
    });
    if (!confirmed) {
        return;
    }

    const oauthPrepared = prepareOAuthNavigation({ gmailChange: true });
    setChangeGmailLoading(true);

    try {
        const token = window.hamdast
            ? await ensureFreshAccessToken()
            : localStorage.getItem("access_token");

        if (!token) {
            throw new Error("خطا در احراز هویت. صفحه را از پنل پذیرش۲۴ دوباره باز کنید.");
        }

        const oauthUrl = await requestChangeGmailOAuthUrl(token);
        if (!oauthUrl) {
            throw new Error("خطا در دریافت آدرس Google.");
        }

        prefetchOAuthUrl(oauthUrl);
        markGmailChangePending();

        const navMode = await completeOAuthNavigation(oauthPrepared, oauthUrl, { gmailChange: true });

        if (navMode === "none") {
            clearGmailChangePending();
            throw new Error("خطا در باز کردن صفحه Google. دوباره تلاش کنید.");
        }

        if (navMode === "same" || navMode === "top") {
            closeOAuthPreparedPopup(oauthPrepared?.popup);
            return;
        }

        resetChangeGmailButton();
        startGmailChangeOutcomeWatch();

        if (navMode === "external") {
            showToast("صفحه انتخاب حساب Google در تب جدید باز شد. پس از تأیید به این صفحه برگردید.");
            return;
        }

        window.setTimeout(() => {
            if (document.visibilityState === "visible" && isGmailChangePending()) {
                stopGmailChangeOutcomeWatch();
                clearGmailChangePending();
                resetChangeGmailButton();
                showToast("باز کردن صفحه Google ناموفق بود. دوباره تلاش کنید.", "error");
            }
        }, 2500);
    } catch (error) {
        closeOAuthPreparedPopup(oauthPrepared?.popup);
        stopGmailChangeOutcomeWatch();
        clearGmailChangePending();
        resetChangeGmailButton();
        showToast(error.message || "خطا در شروع تغییر حساب. دوباره تلاش کنید.", "error");
    }
}

function updateLiveBadge() {
    const badge = document.getElementById("calendarLiveBadge");
    const summaryEl = document.getElementById("badgeSummaryText");
    const detailsBox = document.getElementById("badgeDetailsDropdown");
    const fields = getFieldState();

    const activeCircle = document.querySelector(".circle-opt.active");
    const selectedColorId = activeCircle ? activeCircle.dataset.color : DEFAULT_COLOR_ID;
    const colorData = googleColors[selectedColorId] || googleColors[DEFAULT_COLOR_ID];

    badge.style.backgroundColor = colorData.hex;
    badge.style.boxShadow = `0 4px 14px ${colorData.hex}40`;
    document.getElementById("colorLabel").textContent = colorData.name;
    document.getElementById("colorPickerSection").dataset.selectedColor = selectedColorId;

    summaryEl.textContent = fields.fullName
        ? `نام بیمار : ${PREVIEW_SAMPLES.patientName}`
        : "نوبت پذیرش 24";

    const items = [];
    if (fields.datetime) items.push({ label: "زمان نوبت", value: PREVIEW_SAMPLES.datetime });
    if (fields.nationalId) items.push({ label: "کد ملی", value: PREVIEW_SAMPLES.nationalId });
    if (fields.phone) items.push({ label: "شماره تلفن", value: PREVIEW_SAMPLES.phone });

    if (items.length === 0) {
        detailsBox.innerHTML = `<p class="detail-empty">توضیحات رویداد خالی است</p>`;
    } else {
        detailsBox.innerHTML = items.map((item, i) => `
            <div class="detail-item" style="animation-delay:${i * 0.06}s">
                <span class="label">${item.label}</span>
                <span class="value">${item.value}</span>
            </div>
        `).join("");
    }

    if (appState.previewOpen) {
        badge.setAttribute("aria-expanded", "true");
        detailsBox.classList.add("open");
    }
}

function toggleBadgeDetails() {
    appState.previewOpen = !appState.previewOpen;
    const badge = document.getElementById("calendarLiveBadge");
    const detailsBox = document.getElementById("badgeDetailsDropdown");

    badge.setAttribute("aria-expanded", appState.previewOpen ? "true" : "false");
    detailsBox.classList.toggle("open", appState.previewOpen);
}

function closePreviewDetails() {
    appState.previewOpen = false;
    document.getElementById("calendarLiveBadge").setAttribute("aria-expanded", "false");
    document.getElementById("badgeDetailsDropdown").classList.remove("open");
}

function handleColorSelect(el) {
    document.querySelectorAll(".circle-opt").forEach(c => c.classList.remove("active"));
    el.classList.add("active");
    updateLiveBadge();
}

async function openSettings() {
    const token = localStorage.getItem("access_token");
    if (!token) throw new Error("توکن یافت نشد");

    const response = await apiFetch(
        `${HAMGAM_API_PREFIX}/update.php`,
        token,
        { body: apiBodyWithToken(token) }
    );

    const data = await parseJsonResponse(response);
    if (!response.ok) {
        throw new Error(mapApiError(data.error) || "خطا در دریافت تنظیمات");
    }

    applySettingsToForm(data);
    updateLiveBadge();

    if (typeof data.connected === "boolean") {
        applyConnectionState(data.connected, data.google_account_email || null);
    } else if (data.google_account_email) {
        updateGoogleAccountBanner(data.google_account_email);
    }
}

async function applyPendingSettingsAfterOAuth() {
    const raw = localStorage.getItem("hamgam_pending_settings");
    if (!raw) return;

    localStorage.removeItem("hamgam_pending_settings");

    try {
        const settings = JSON.parse(raw);
        const token = localStorage.getItem("access_token");
        if (!token) return;

        const response = await apiFetch(
            `${HAMGAM_API_PREFIX}/updatesetting.php`,
            token,
            {
                body: apiBodyWithToken(token, { ...settings, returnJson: true }),
                withJson: true
            }
        );

        const data = await parseJsonResponse(response);
        if (!response.ok) {
            throw new Error(mapSaveErrorMessage(data, response));
        }

        showWarningsIfAny(data);
        showSaveToast(data, settings);

        redirectToLauncher();
    } catch (err) {
        console.error("[Hamgam] pending settings save failed:", err);
        showToast(err.message || "ذخیره تنظیمات ناموفق بود. دوباره تلاش کنید.", "error");
    }
}

function mapSaveErrorMessage(data, response) {
    const error = typeof data?.error === "string" ? data.error : "";
    const mapped = mapApiError(error);

    if (mapped) {
        return mapped;
    }
    if (response && !response.ok) {
        return `خطای سرور (${response.status}). چند لحظه بعد دوباره تلاش کنید.`;
    }

    return "ذخیره تنظیمات ناموفق بود. دوباره تلاش کنید.";
}

function showSaveToast(data, settings = null) {
    if (data?.backfill?.pending || data?.sync_pending) {
        showToast("تنظیمات ذخیره شد.");
        return;
    }

    if (data?.backfill?.ran && data.backfill.imported > 0) {
        applyBackfillStatusToUi(data.backfill);
        showToast(`تنظیمات ذخیره شد. ${data.backfill.imported} رویداد آینده به‌عنوان مرخصی ثبت شد.`);
        return;
    }

    if (data?.backfill?.ran && data.backfill.failed > 0) {
        showToast("تنظیمات ذخیره شد، اما برخی رویدادها ثبت نشدند.", "error");
        return;
    }

    if (settings?.autoVacation && settings?.importFutureVacations && data?.backfill?.ran) {
        setImportFutureVacationsUsed(true);
        showToast("تنظیمات ذخیره شد. رویداد شخصی جدیدی در ۳۰ روز آینده یافت نشد.");
        return;
    }

    showToast("تنظیمات ذخیره شد.");
}

async function update() {
    const settings = collectSettingsPayload();

    if (!validateVacationCentersBeforeSave(settings.autoVacation)) {
        return;
    }

    const loadingLabel = settings.autoVacation && settings.importFutureVacations
        ? "در حال ذخیره تنظیمات…"
        : null;
    setSaveLoading(true, loadingLabel);

    const loadingSafetyTimer = window.setTimeout(() => {
        if (appState.saving) {
            setSaveLoading(false);
            showToast("ارتباط با سرور طولانی شد. دوباره تلاش کنید.", "error");
        }
    }, 120000);

    try {
        const token = localStorage.getItem("access_token");
        const response = await apiFetch(
            `${HAMGAM_API_PREFIX}/updatesetting.php`,
            token,
            {
                body: apiBodyWithToken(token, { ...settings, returnJson: true }),
                withJson: true
            }
        );

        const data = await parseJsonResponse(response);
        if (!response.ok || data.ok !== true) {
            throw new Error(mapSaveErrorMessage(data, response));
        }

        showWarningsIfAny(data);

        if (data.settings) {
            applySettingsToForm(data.settings);
        }

        const waitingForBackfill = settings.importFutureVacations && data?.backfill?.pending === true;
        if (waitingForBackfill) {
            await waitForBackgroundSync({
                backfillPending: true,
                maxAttempts: 90,
                intervalMs: 2000
            });
            window.clearTimeout(loadingSafetyTimer);
        } else {
            window.clearTimeout(loadingSafetyTimer);
            setSaveLoading(false);
            showSaveToast(data, settings);
        }

        redirectToLauncher();
    } catch (err) {
        console.error(err);
        window.clearTimeout(loadingSafetyTimer);
        setSaveLoading(false);
        showToast(err.message || mapSaveErrorMessage(null, null), "error");
    }
}
