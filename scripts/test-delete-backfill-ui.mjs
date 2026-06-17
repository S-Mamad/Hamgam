/**
 * Tests for delete synced backfill button visibility and state.
 * Run: node --test scripts/test-delete-backfill-ui.mjs
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { test, describe, beforeEach } from "node:test";
import assert from "node:assert/strict";
import { parseHTML } from "linkedom";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

function loadHarness() {
    const html = readFileSync(join(root, "index.html"), "utf8");
    const { document, window } = parseHTML(html);

    global.document = document;
    global.window = window;
    global.Event = window.Event;
    global.HTMLInputElement = window.HTMLInputElement;
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
        return {
            appState,
            setImportFutureVacationsUsed,
            setImportFutureBackfillSlotCount,
            applyImportFutureBackfillUndoUiState,
            updateVacationSubOptionsState,
            updateVacationSubPanel
        };
    `;

    return new Function(wrapped)();
}

describe("delete synced backfill button", () => {
    let api;

    beforeEach(() => {
        api = loadHarness();
        api.appState.medicalCenters = [];
        api.appState.vacationCenterSelection = { mode: "selected", centerIds: [] };
    });

    test("delete wrap lives outside vacation sub-panel (always reachable)", () => {
        const wrap = document.getElementById("deleteSyncedBackfillWrap");
        const panel = document.getElementById("vacationSubPanel");
        const subOptions = document.getElementById("vacationSubOptionsBlock");

        assert.ok(wrap, "delete wrap exists");
        assert.ok(panel.contains(subOptions), "sub options stay inside panel");
        assert.equal(panel.contains(wrap), false, "delete wrap is outside collapsed panel");
    });

    test("shows when 30-day import was used", () => {
        api.setImportFutureVacationsUsed(true);
        api.setImportFutureBackfillSlotCount(3);

        const wrap = document.getElementById("deleteSyncedBackfillWrap");
        assert.equal(wrap.hidden, false);
        assert.match(document.getElementById("deleteSyncedBackfillHint").textContent, /3 مرخصی/);
        assert.equal(document.getElementById("deleteSyncedBackfillBtn").querySelector(".vacation-sync-undo__btn-text").textContent, "حذف");
    });

    test("reset mode when used but no deletable slots", () => {
        api.setImportFutureVacationsUsed(true);
        api.setImportFutureBackfillSlotCount(0);

        assert.equal(document.getElementById("deleteSyncedBackfillBtn").querySelector(".vacation-sync-undo__btn-text").textContent, "فعال‌سازی مجدد");
        assert.ok(document.querySelector(".vacation-sync-undo--reset"));
    });

    test("hidden after import flag cleared", () => {
        api.setImportFutureVacationsUsed(true);
        api.setImportFutureVacationsUsed(false);

        assert.equal(document.getElementById("deleteSyncedBackfillWrap").hidden, true);
    });

    test("stays clickable when center selection is inactive", () => {
        api.appState.medicalCenters = [
            { medical_center_id: "c1", name: "A", is_active_booking: true },
            { medical_center_id: "c2", name: "B", is_active_booking: true }
        ];
        const toggle = document.querySelector('[data-field="autoVacation"]');
        toggle.checked = true;
        api.updateVacationSubPanel();

        api.setImportFutureVacationsUsed(true);
        api.setImportFutureBackfillSlotCount(2);
        api.updateVacationSubOptionsState();

        const block = document.getElementById("vacationSubOptionsBlock");
        assert.ok(block.classList.contains("vacation-sub-options-block--inactive"));

        const wrap = document.getElementById("deleteSyncedBackfillWrap");
        assert.equal(wrap.hidden, false);

        const btn = document.getElementById("deleteSyncedBackfillBtn");
        assert.equal(btn.disabled, false);
    });

    test("visible even when auto-vacation panel is closed", () => {
        api.setImportFutureVacationsUsed(true);
        api.setImportFutureBackfillSlotCount(1);

        const toggle = document.querySelector('[data-field="autoVacation"]');
        toggle.checked = false;
        api.updateVacationSubPanel();

        const panel = document.getElementById("vacationSubPanel");
        assert.equal(panel.classList.contains("open"), false);
        assert.equal(document.getElementById("deleteSyncedBackfillWrap").hidden, false);
    });
});
