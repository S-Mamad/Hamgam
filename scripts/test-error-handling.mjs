/**
 * Tests for API error mapping and save toast logic (script.js).
 * Run: node --test scripts/test-error-handling.mjs
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { test, describe } from "node:test";
import assert from "node:assert/strict";
import { parseHTML } from "linkedom";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

function loadErrorHarness() {
    const html = readFileSync(join(root, "index.html"), "utf8");
    const { document, window } = parseHTML(html);

    const toastMessages = [];
    global.document = document;
    global.window = window;
    global.localStorage = {
        _data: { access_token: "test-token" },
        getItem(k) { return this._data[k] ?? null; },
        setItem(k, v) { this._data[k] = v; }
    };

    const script = readFileSync(join(root, "script.js"), "utf8")
        .replace('document.addEventListener("DOMContentLoaded", initApp);', "")
        .replace(/^const HAMGAM_API = .*$/m, 'const HAMGAM_API = "http://localhost";')
        .replace(/^const HAMGAM_API_PREFIX = .*$/m, 'const HAMGAM_API_PREFIX = "http://localhost/php/hamgam";');

    const wrapped = `
        ${script}
        return { mapApiError, showSaveToast, showToast, appState };
    `;

    const api = new Function(wrapped)();
    const originalShowToast = api.showToast;
    api.showToast = (message, type = "success") => {
        toastMessages.push({ message, type });
        return originalShowToast(message, type);
    };

    return { api, toastMessages };
}

describe("mapApiError", () => {
    test("maps known English API errors to Persian", () => {
        const { api } = loadErrorHarness();
        assert.equal(
            api.mapApiError("Google account not connected"),
            "حساب Google متصل نیست. ابتدا اتصال Google را برقرار کنید."
        );
        assert.equal(
            api.mapApiError("Authentication failed"),
            "خطا در احراز هویت. صفحه را از پنل پذیرش۲۴ مجدداً باز کنید."
        );
    });

    test("passes through unknown errors", () => {
        const { api } = loadErrorHarness();
        assert.equal(api.mapApiError("Custom server message"), "Custom server message");
    });

    test("returns null for empty error", () => {
        const { api } = loadErrorHarness();
        assert.equal(api.mapApiError(""), null);
    });
});

describe("showSaveToast", () => {
    test("shows pending message when backfill is async", () => {
        const { api } = loadErrorHarness();
        api.showSaveToast({ backfill: { ran: true, pending: true } });
        const toast = document.getElementById("toast");
        assert.match(toast.textContent, /همگام‌سازی/);
    });

    test("does not show no-events message while backfill pending", () => {
        const { api } = loadErrorHarness();
        api.showSaveToast(
            { backfill: { ran: true, pending: true }, sync_pending: true },
            { autoVacation: true, importFutureVacations: true }
        );
        assert.doesNotMatch(document.getElementById("toast").textContent, /یافت نشد/);
    });

    test("shows imported count for completed backfill", () => {
        const { api } = loadErrorHarness();
        api.showSaveToast({ backfill: { ran: true, imported: 5, failed: 0 } });
        assert.match(document.getElementById("toast").textContent, /5/);
    });

    test("shows error styling when backfill had failures", () => {
        const { api } = loadErrorHarness();
        api.showSaveToast({ backfill: { ran: true, imported: 0, failed: 2 } });
        assert.match(document.getElementById("toast").className, /toast-error/);
    });
});
