const BUTTON_URL = "/php/hamgam/button.php";
const LANDING_URL = "https://zamanak24.ir/";

const HAMDAST_SCOPES = [
    "provider.profile.read",
    "provider.appointment.webhook",
    "provider.appointment.read",
    "provider.appointment.write",
    "provider.management.write"
];

function showStartError(message) {
    const spinner = document.getElementById("startSpinner");
    const title = document.getElementById("startTitle");
    const msg = document.getElementById("startMessage");
    const err = document.getElementById("startError");
    const back = document.getElementById("startBack");

    if (spinner) spinner.hidden = true;
    if (title) title.textContent = "Could not start";
    if (msg) {
        msg.textContent =
            "Sign in to Paziresh24 in this browser, then open the Zamanak homepage and try again.";
    }
    if (err) {
        err.textContent = message;
        err.style.display = "block";
    }
    if (back) {
        back.href = LANDING_URL;
        back.hidden = false;
    }
}

(async function runAuthStart() {
    try {
        if (!window.hamdast?.getSessionToken) {
            throw new Error("Hamdast SDK is not available.");
        }

        window.hamdast.initialize({ app_key: "hamgam" });

        const sessionToken = await window.hamdast.getSessionToken({
            scope: HAMDAST_SCOPES
        });

        if (!sessionToken || typeof sessionToken !== "string") {
            throw new Error("Session token was not returned.");
        }

        const target = `${BUTTON_URL}?session_token=${encodeURIComponent(sessionToken)}`;
        window.location.replace(target);
    } catch (error) {
        console.error("[Hamgam start]", error);
        showStartError(error.message || "Authentication could not start.");
    }
})();
