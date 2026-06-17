const HAMGAM_API = window.location.origin;

const googleColors = {
    "11": { name: "قرمز", hex: "#DA5234" },
    "1": { name: "آبی آسمانی", hex: "#3b82f6" },
    "10": { name: "سبز", hex: "#489160" },
    "9": { name: "آبی ارغوانی", hex: "#3f51b5" },
    "5": { name: "زرد", hex: "#E7BA51" }
};

function updateLiveBadge() {
    const badge = document.getElementById("calendarLiveBadge");
    const detailsBox = document.getElementById("badgeDetailsDropdown");

    const nameCheck = document.querySelector('[data-field="fullName"]').checked;
    const dateCheck = document.querySelector('[data-field="datetime"]').checked;
    const nationalCheck = document.querySelector('[data-field="nationalId"]').checked;
    const phoneCheck = document.querySelector('[data-field="phone"]').checked;

    const activeCircle = document.querySelector('.circle-opt.active');
    let selectedColorId = activeCircle ? activeCircle.dataset.color : "1";
    let colorData = googleColors[selectedColorId] || googleColors["1"];

    badge.style.backgroundColor = colorData.hex;
    document.getElementById("colorLabel").innerText = colorData.name;

    if (nameCheck) {
        badge.innerHTML = `<span>نام بیمار : محمد محمدی</span> <span style="font-size:10px; opacity: 0.8; margin-right: 5px;">▾</span>`;
    } else {
        badge.innerHTML = `<span>نوبت پذیرش 24</span> <span style="font-size:10px; opacity: 0.8; margin-right: 5px;">▾</span>`;
    }

    let detailsHTML = "";
    if (dateCheck) detailsHTML += `<div class="detail-item"><span class="label">زمان نوبت:</span><span class="value">شنبه - ۱۴:۳۰</span></div>`;
    if (nationalCheck) detailsHTML += `<div class="detail-item"><span class="label">کد ملی:</span><span class="value">4423456789</span></div>`;
    if (phoneCheck) detailsHTML += `<div class="detail-item"><span class="label">شماره تلفن:</span><span class="value">۰۹۱۲۳۴۵۶۷۸۹</span></div>`;

    if (detailsHTML === "") {
        detailsHTML = `<div class="detail-item" style="color:#94a3b8; font-style:italic; font-size: 11px;">توضیحات رویداد خالی است</div>`;
    }

    detailsBox.innerHTML = detailsHTML;
}

function toggleBadgeDetails() {
    const detailsBox = document.getElementById("badgeDetailsDropdown");
    detailsBox.classList.toggle("open");
}

function handleColorSelect(el) {
    document.querySelectorAll('.circle-opt').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById("colorPickerSection").dataset.selectedColor = el.dataset.color;
    updateLiveBadge();
}

async function openSettings() {
    const loader = document.getElementById("save-loader");
    if (loader) loader.style.display = "flex";

    try {
        const response = await fetch(`${HAMGAM_API}/hamgam/update`, {
            method: "POST",
            headers: {
                access_token: localStorage.getItem("access_token")
            }
        });

        const settings = await response.json();

        const targetColorId = settings.color_id || "1";
        document.querySelectorAll('.circle-opt').forEach(circle => {
            if (circle.dataset.color === targetColorId.toString()) {
                circle.classList.add('active');
            } else {
                circle.classList.remove('active');
            }
        });
        document.getElementById("colorPickerSection").dataset.selectedColor = targetColorId;

        document.querySelector('[data-field="fullName"]').checked = settings.Patient_name;
        document.querySelector('[data-field="datetime"]').checked = settings.Patient_date_time;
        document.querySelector('[data-field="nationalId"]').checked = settings.Patient_national;
        document.querySelector('[data-field="phone"]').checked = settings.Patient_phone;

    } catch (err) {
        console.error("خطا در لود:", err);
    } finally {
        if (loader) loader.style.display = "none";
        updateLiveBadge();
    }
}

async function update() {
    const saveBtn = document.getElementById("saveSettings");
    const loader = document.getElementById("saveloader");
    const colorSection = document.getElementById("colorPickerSection");

    let colorId = colorSection.dataset.selectedColor || "1";

    const settings = {
        colorId,
        fullName: document.querySelector('[data-field="fullName"]').checked,
        datetime: document.querySelector('[data-field="datetime"]').checked,
        nationalId: document.querySelector('[data-field="nationalId"]').checked,
        phone: document.querySelector('[data-field="phone"]').checked
    };

    if (loader) loader.style.display = "flex";
    if (saveBtn) saveBtn.style.display = "none";

    try {
        const response = await fetch(`${HAMGAM_API}/hamgam/updatesetting`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "access_token": localStorage.getItem("access_token")
            },
            body: JSON.stringify(settings)
        });
        console.log(await response.text());
    } catch (err) {
        console.error(err);
    } finally {
        window.location.href = "https://www.paziresh24.com/_/hamgam/launcher/";
        if (loader) loader.style.display = "none";
        if (saveBtn) saveBtn.style.display = "block";
    }
}
