import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import vm from "node:vm";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const script = readFileSync(join(root, "script.js"), "utf8");

const calls = [];
const context = {
    window: {
        self: {},
        top: {},
        location: { href: "" },
        hamdast: {
            redirect: {
                dispatch(obj) {
                    calls.push({ type: "dispatch", ...obj });
                }
            },
            openLink(obj) {
                calls.push({ type: "openLink", ...obj });
            }
        }
    },
    console,
    document: {}
};

context.window.self = context.window;
context.window.top = { location: { href: "" } };

const sandbox = vm.createContext(context);
const wrapped = `
const PAZIRESH24_LAUNCHER = "https://www.paziresh24.com/_/hamgam/launcher/";
const PAZIRESH24_LAUNCHER_PATH = "/_/hamgam/launcher/";

function isEmbeddedInPaziresh24() {
    try {
        return window.self !== window.top;
    } catch {
        return false;
    }
}

function redirectToLauncher() {
    if (isEmbeddedInPaziresh24() && window.hamdast?.redirect?.dispatch) {
        window.hamdast.redirect.dispatch({ path: PAZIRESH24_LAUNCHER_PATH });
        return;
    }

    if (isEmbeddedInPaziresh24() && window.hamdast?.openLink) {
        window.hamdast.openLink({ url: PAZIRESH24_LAUNCHER });
        return;
    }

    try {
        if (window.self !== window.top) {
            window.top.location.href = PAZIRESH24_LAUNCHER;
            return;
        }
    } catch {
    }

    window.location.href = PAZIRESH24_LAUNCHER;
}
`;

vm.runInContext(wrapped, sandbox);

function assert(condition, message) {
    if (!condition) {
        console.error("FAIL", message);
        process.exit(1);
    }
    console.log("OK  ", message);
}

// iframe + hamdast: in-page navigation
context.window.self = context.window;
context.window.top = { location: { href: "https://www.paziresh24.com/panel" } };
calls.length = 0;
vm.runInContext("redirectToLauncher();", sandbox);
assert(calls.length === 1, "iframe uses hamdast redirect dispatch");
assert(calls[0].type === "dispatch", "prefers redirect.dispatch in iframe");
assert(calls[0].path === "/_/hamgam/launcher/", "launcher path is in-page route");

// top-level fallback
context.window.self = context.window;
context.window.top = context.window;
calls.length = 0;
context.window.location.href = "";
vm.runInContext("redirectToLauncher();", sandbox);
assert(calls.length === 0, "top-level does not use hamdast SDK");
assert(
    context.window.location.href === "https://www.paziresh24.com/_/hamgam/launcher/",
    "top-level falls back to launcher URL"
);

console.log("\nAll redirectToLauncher checks passed.");
