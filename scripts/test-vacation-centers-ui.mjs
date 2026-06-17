/**
 * Automated tests for vacation medical-centers UI logic (script.js).
 * Run: node --test scripts/test-vacation-centers-ui.mjs
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { test, describe, beforeEach } from "node:test";
import assert from "node:assert/strict";
import { parseHTML } from "linkedom";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

const SAMPLE_CENTERS = [
    { medical_center_id: "online-1", name: "ویزیت آنلاین", is_active_booking: true },
    { medical_center_id: "center-2", name: "کلینیک تست", is_active_booking: true },
    { medical_center_id: "center-3", name: "مطب دوم", is_active_booking: false }
];

function loadVacationUiHarness() {
    const html = readFileSync(join(root, "index.html"), "utf8");
    const { document, window } = parseHTML(html);

    const toastMessages = [];
    const harness = {
        document,
        window,
        appState: {
            connected: true,
            medicalCenters: [],
            vacationCenterSelection: { mode: "selected", centerIds: [] },
            centersFetchState: "idle",
            centersFetchSeq: 0
        },
        toastMessages,
        renderCount: 0
    };

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
            getAllCenterIds,
            applyDefaultVacationCenterSelection,
            applySettingsToForm,
            buildVacationSyncCentersPayload,
            normalizeVacationCenterSelection,
            getVacationCenterSelection,
            hasVacationCenterSelection,
            setVacationCenterSelection,
            isAllCentersSelected,
            renderVacationCenters,
            handleVacationCenterCheckboxChange,
            validateVacationCentersBeforeSave,
            updateVacationSubPanel,
            showToast
        };
    `;

    const api = new Function(wrapped)();

    const originalShowToast = api.showToast;
    api.showToast = (message, type) => {
        toastMessages.push({ message, type });
        return originalShowToast(message, type);
    };

    const originalRender = api.renderVacationCenters;
    api.renderVacationCenters = (...args) => {
        harness.renderCount += 1;
        return originalRender(...args);
    };

    return { api, harness };
}

function openVacationPanel(api) {
    const toggle = document.querySelector('[data-field="autoVacation"]');
    toggle.checked = true;
    api.updateVacationSubPanel();
}

function setCenters(api, centers) {
    api.appState.medicalCenters = centers;
    api.appState.centersFetchState = centers.length ? "ready" : "empty";
}

describe("vacation centers UI", () => {
    let ctx;

    beforeEach(() => {
        ctx = loadVacationUiHarness();
        ctx.harness.renderCount = 0;
        ctx.harness.toastMessages = [];
    });

    test("default selection is empty when vacation panel opens", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        const selection = api.getVacationCenterSelection();
        assert.equal(selection.mode, "selected");
        assert.deepEqual(selection.centerIds, []);
        assert.equal(api.hasVacationCenterSelection(), false);
    });

    test("applyDefaultVacationCenterSelection keeps empty for new users", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        api.applyDefaultVacationCenterSelection(SAMPLE_CENTERS);

        assert.deepEqual(api.getVacationCenterSelection().centerIds, []);
    });

    test("applyDefaultVacationCenterSelection restores saved partial selection", () => {
        const { api } = ctx;
        api.appState.vacationCenterSelection = { mode: "selected", centerIds: ["center-2"] };
        api.applyDefaultVacationCenterSelection(SAMPLE_CENTERS);

        assert.deepEqual(api.getVacationCenterSelection().centerIds, ["center-2"]);
    });

    test("applyDefaultVacationCenterSelection expands legacy mode=all to every center", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        api.appState.vacationCenterSelection = { mode: "all", centerIds: [] };
        api.applyDefaultVacationCenterSelection(SAMPLE_CENTERS);

        assert.equal(api.isAllCentersSelected(), true);
        assert.deepEqual(api.getVacationCenterSelection().centerIds.sort(), api.getAllCenterIds(SAMPLE_CENTERS).sort());
        assert.equal(api.hasVacationCenterSelection(), true);
    });

    test("select all checkbox selects every center and collapses detail list", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);
        api.renderVacationCenters();

        const allInput = document.getElementById("vacationCenterSelectAll");
        assert.ok(allInput, "all-centers checkbox should exist");

        allInput.checked = true;
        allInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.equal(api.isAllCentersSelected(), true);
        assert.equal(api.hasVacationCenterSelection(), true);

        const detail = document.querySelector(".vacation-centers-detail");
        assert.ok(detail?.classList.contains("is-collapsed"), "detail list should collapse");
        assert.equal(document.querySelector(".vacation-centers-collapsed-summary"), null, "no extra summary banner");
    });

    test("deselecting all centers expands list and deactivates sub-options", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);
        api.setVacationCenterSelection({ mode: "selected", centerIds: api.getAllCenterIds(SAMPLE_CENTERS) });

        const allInput = document.getElementById("vacationCenterSelectAll");
        allInput.checked = false;
        allInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.equal(api.hasVacationCenterSelection(), false);
        assert.equal(document.querySelector(".vacation-centers-detail")?.classList.contains("is-collapsed"), false);

        const block = document.getElementById("vacationSubOptionsBlock");
        const hint = document.getElementById("vacationSubOptionsHint");
        assert.ok(block?.classList.contains("vacation-sub-options-block--inactive"));
        assert.equal(hint?.hidden, false);

        const importFuture = document.getElementById("importFutureVacations");
        assert.equal(importFuture.disabled, true);
    });

    test("selecting one center enables sub-options", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        const centerInput = document.getElementById("vacationCenter_center-2");
        assert.ok(centerInput);
        centerInput.checked = true;
        centerInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.equal(api.hasVacationCenterSelection(), true);
        assert.equal(document.getElementById("vacationSubOptionsBlock")?.classList.contains("vacation-sub-options-block--inactive"), false);
        assert.equal(document.getElementById("importFutureVacations").disabled, false);
    });

    test("validateVacationCentersBeforeSave blocks save without selection", () => {
        const { api, harness } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        const ok = api.validateVacationCentersBeforeSave(true);
        assert.equal(ok, false);

        const validation = document.getElementById("vacationCentersValidation");
        assert.equal(validation.hidden, false);
        assert.match(validation.textContent, /شما مرکز درمانیتو انتخاب نکردی/);
        assert.ok(document.getElementById("vacationCentersSection")?.classList.contains("has-validation-error"));
    });

    test("validateVacationCentersBeforeSave passes with at least one center", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        api.setVacationCenterSelection({ mode: "selected", centerIds: ["online-1"] });

        assert.equal(api.validateVacationCentersBeforeSave(true), true);
        assert.equal(document.getElementById("vacationCentersValidation").hidden, true);
    });

    test("validateVacationCentersBeforeSave skipped when auto vacation off", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        assert.equal(api.validateVacationCentersBeforeSave(false), true);
    });

    test("buildVacationSyncCentersPayload unchanged for partial and full selection", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);

        api.setVacationCenterSelection({ mode: "selected", centerIds: ["center-2"] });
        assert.deepEqual(api.buildVacationSyncCentersPayload(), {
            mode: "selected",
            centerIds: ["center-2"]
        });

        api.setVacationCenterSelection({ mode: "selected", centerIds: ["online-1", "center-2", "center-3"] });
        assert.deepEqual(api.buildVacationSyncCentersPayload(), {
            mode: "selected",
            centerIds: ["online-1", "center-2", "center-3"]
        });

        api.setVacationCenterSelection({ mode: "selected", centerIds: [] });
        assert.deepEqual(api.buildVacationSyncCentersPayload(), {
            mode: "selected",
            centerIds: []
        });
    });

    test("single center: no all-centers option, starts unchecked", () => {
        const { api } = ctx;
        const one = [SAMPLE_CENTERS[0]];
        setCenters(api, one);
        openVacationPanel(api);
        api.renderVacationCenters();

        assert.equal(document.getElementById("vacationCenterSelectAll"), null);
        const only = document.getElementById("vacationCenter_online-1");
        assert.ok(only);
        assert.equal(only.hasAttribute("checked"), false);
    });

    test("all-centers row uses native label toggle (no double-toggle handler)", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);
        api.setVacationCenterSelection({ mode: "selected", centerIds: api.getAllCenterIds(SAMPLE_CENTERS) });

        const script = readFileSync(join(root, "script.js"), "utf8");
        assert.equal(
            /vacation-center-item"\)\.forEach\(item =>/s.test(script),
            false,
            "row click handler removed to avoid label double-toggle"
        );

        const allInput = document.getElementById("vacationCenterSelectAll");
        allInput.checked = false;
        allInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.equal(api.isAllCentersSelected(), false);
        assert.equal(api.hasVacationCenterSelection(), false);
    });

    test("saved explicit all-center IDs still restore every center", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        api.appState.vacationCenterSelection = {
            mode: "selected",
            centerIds: ["online-1", "center-2", "center-3"]
        };
        openVacationPanel(api);
        api.applyDefaultVacationCenterSelection(SAMPLE_CENTERS);

        assert.equal(api.isAllCentersSelected(), true);
        assert.equal(api.getVacationCenterSelection().centerIds.includes("online-1"), true);
        assert.equal(document.getElementById("vacationCenterSelectAll")?.checked, true);
    });

    test("selecting all centers individually triggers collapse state", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);
        api.renderVacationCenters();

        for (const center of SAMPLE_CENTERS) {
            const input = document.getElementById(`vacationCenter_${center.medical_center_id}`);
            input.checked = true;
            input.dispatchEvent(new window.Event("change", { bubbles: true }));
        }

        assert.equal(api.isAllCentersSelected(), true);
        assert.ok(document.querySelector(".vacation-centers-detail")?.classList.contains("is-collapsed"));
        assert.equal(document.querySelector(".vacation-centers-collapsed-summary"), null, "no extra summary banner");
    });

    test("settings API with legacy mode=all selects every center after load", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);

        api.applySettingsToForm({
            color_id: "9",
            Patient_name: true,
            Patient_date_time: false,
            Patient_national: false,
            Patient_phone: false,
            auto_vacation: true,
            import_future_vacations: false,
            cancel_appointment_on_event_delete: true,
            cancel_conflicting_appointments: true,
            vacation_sync_centers: { mode: "all", center_ids: [] }
        });

        api.applyDefaultVacationCenterSelection(SAMPLE_CENTERS);

        assert.equal(api.isAllCentersSelected(), true);
        assert.equal(api.hasVacationCenterSelection(), true);
        assert.equal(api.getVacationCenterSelection().centerIds.includes("online-1"), true);
    });

    test("settings API with empty selected centers keeps online visit unchecked", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);

        api.applySettingsToForm({
            color_id: "9",
            Patient_name: true,
            Patient_date_time: false,
            Patient_national: false,
            Patient_phone: false,
            auto_vacation: true,
            import_future_vacations: false,
            cancel_appointment_on_event_delete: true,
            cancel_conflicting_appointments: true,
            vacation_sync_centers: { mode: "selected", center_ids: [] }
        });

        assert.equal(api.hasVacationCenterSelection(), false);
        assert.deepEqual(api.getVacationCenterSelection().centerIds, []);
    });

    test("deselecting last center disables sub-options again", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        const centerInput = document.getElementById("vacationCenter_center-2");
        centerInput.checked = true;
        centerInput.dispatchEvent(new window.Event("change", { bubbles: true }));
        assert.equal(document.getElementById("importFutureVacations").disabled, false);

        centerInput.checked = false;
        centerInput.dispatchEvent(new window.Event("change", { bubbles: true }));
        assert.equal(api.hasVacationCenterSelection(), false);
        assert.equal(document.getElementById("importFutureVacations").disabled, true);
    });

    test("selecting only online visit enables sub-options", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        const onlineInput = document.getElementById("vacationCenter_online-1");
        onlineInput.checked = true;
        onlineInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.deepEqual(api.getVacationCenterSelection().centerIds, ["online-1"]);
        assert.equal(api.hasVacationCenterSelection(), true);
        assert.equal(document.getElementById("importFutureVacations").disabled, false);
        assert.equal(document.getElementById("vacationCenterSelectAll")?.indeterminate, true);
    });

    test("partial selection of two centers keeps list expanded", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        for (const id of ["online-1", "center-2"]) {
            const input = document.getElementById(`vacationCenter_${id}`);
            input.checked = true;
            input.dispatchEvent(new window.Event("change", { bubbles: true }));
        }

        assert.equal(api.isAllCentersSelected(), false);
        assert.equal(api.hasVacationCenterSelection(), true);
        assert.equal(document.querySelector(".vacation-centers-detail")?.classList.contains("is-collapsed"), false);
        assert.equal(document.getElementById("vacationCenterSelectAll")?.indeterminate, true);
    });

    test("deselecting one center after select-all expands list", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);
        api.setVacationCenterSelection({ mode: "selected", centerIds: api.getAllCenterIds(SAMPLE_CENTERS) });

        const onlineInput = document.getElementById("vacationCenter_online-1");
        onlineInput.checked = false;
        onlineInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.equal(api.isAllCentersSelected(), false);
        assert.equal(api.hasVacationCenterSelection(), true);
        assert.deepEqual(api.getVacationCenterSelection().centerIds.sort(), ["center-2", "center-3"]);
        assert.equal(document.querySelector(".vacation-centers-detail")?.classList.contains("is-collapsed"), false);
        assert.equal(document.getElementById("vacationCenterSelectAll")?.checked, false);
        assert.equal(document.getElementById("vacationCenterSelectAll")?.indeterminate, true);
    });

    test("selecting center clears validation error", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        api.validateVacationCentersBeforeSave(true);
        assert.equal(document.getElementById("vacationCentersValidation").hidden, false);

        const centerInput = document.getElementById("vacationCenter_center-2");
        centerInput.checked = true;
        centerInput.dispatchEvent(new window.Event("change", { bubbles: true }));

        assert.equal(document.getElementById("vacationCentersValidation").hidden, true);
        assert.equal(document.getElementById("vacationCentersSection")?.classList.contains("has-validation-error"), false);
    });

    test("closing vacation panel does not require center selection on save", () => {
        const { api } = ctx;
        setCenters(api, SAMPLE_CENTERS);
        openVacationPanel(api);

        const toggle = document.querySelector('[data-field="autoVacation"]');
        toggle.checked = false;
        api.updateVacationSubPanel();

        assert.equal(api.validateVacationCentersBeforeSave(false), true);
    });
});
