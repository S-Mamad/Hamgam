# راهنمای استقرار Hamgam روی hamgam.zamanak24.ir

## ۱. تنظیم ساب‌دامین در cPanel

1. **Subdomains** → `hamgam` را بسازید (Document Root معمولاً `public_html/hamgam` می‌شود).
2. Document Root باید **ریشه پروژه** باشد — جایی که `index.html` و `.htaccess` روت قرار دارند.

## ۲. آپلود فایل‌ها (همان ساختار فعلی)

کل پوشه پروژه را با همین ساختار آپلود کنید:

```
hamgam/                    ← Document Root ساب‌دامین
├── .htaccess
├── index.html
├── script.js
├── style.css
├── php/
│   ├── .env               ← روی سرور (commit نشود)
│   ├── .htaccess
│   ├── hamgam/
│   ├── includes/
│   ├── webhook/
│   ├── storage/           ← قابل نوشتن (755 یا 775)
│   └── sql/
└── setting-calendar/      ← اختیاری
```

**آپلود نکنید:**
- `php/.env.local`
- `php/storage/*.sqlite`
- `php/storage/php-errors.log`
- `.git/`, `.vscode/`, `dev/`

## ۳. فایل `.env`

- مسیر روی سرور: **`php/.env`**
- نمونه: `cp .env.example .env` (از داخل پوشه `php`)
- مقادیر مهم برای ساب‌دامین:

```env
APP_BASE_URL=https://hamgam.zamanak24.ir
GOOGLE_OAUTH_CALLBACK_URI=https://hamgam.zamanak24.ir/php/hamgam/google-oauth.php
REDIRECT_SETTINGS=https://hamgam.zamanak24.ir/
CORS_ORIGINS=https://hamgam.zamanak24.ir,https://www.paziresh24.com,https://hamdast.paziresh24.com
HTTP_SSL_VERIFY=false
# اگر refresh_token از n8n است:
# GOOGLE_CLIENT_ID=919694018739-dm4m0vgr76dgor65i1prm7dkt4g53vcu
# GOOGLE_CLIENT_SECRET=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
```

**دیتابیس cPanel:** `DB_NAME` و `DB_USER` هر دو باید پیشوند cPanel داشته باشند، مثلاً:
`eavlxtce_hamgam_db` و `eavlxtce_hamgam_user`

## ۴. دیتابیس MySQL

جدول `google_tokens` را در phpMyAdmin بسازید:

- اسکریپت: `php/sql/mysql_google_tokens.sql`
- مقادیر `DB_*` در `.env` را با اطلاعات cPanel پر کنید.

## ۵. تنظیمات خارج از پروژه (بعد از آپلود)

### هاست nginx / BitNinja (`.htaccess` کار نمی‌کند)

روی این نوع هاست مسیرهای `/hamgam/button` جواب **404** می‌دهند. از مسیر مستقیم `php/` استفاده کنید:

| سرویس | URL |
|--------|-----|
| **دکمه خارجی (Hamdast)** | `https://hamgam.zamanak24.ir/php/hamgam/button.php` |
| **Google OAuth redirect** | `https://hamgam.zamanak24.ir/php/hamgam/google-oauth.php` |
| **وب‌هوک Hamdast** | `https://hamgam.zamanak24.ir/php/webhook/paziresh24-hamgam.php` |
| **صفحه تنظیمات (Hamdast)** | `https://hamgam.zamanak24.ir/` |

در `php/.env` هم `GOOGLE_OAUTH_CALLBACK_URI` باید همان مسیر `php/hamgam/google-oauth.php` باشد.

### هاست Apache با mod_rewrite (اختیاری)

اگر `/hamgam/button` بدون 404 باز شد:

| سرویس | URL |
|--------|-----|
| **دکمه خارجی** | `https://hamgam.zamanak24.ir/hamgam/button` |
| **Google OAuth redirect** | `https://hamgam.zamanak24.ir/hamgam/google-oauth` |
| **وب‌هوک** | `https://hamgam.zamanak24.ir/webhook/paziresh24-hamgam` |

## ۶. Google Cloud Console

در **APIs & Services → Credentials → OAuth 2.0 Client**:

- **Authorized redirect URIs:** `https://hamgam.zamanak24.ir/php/hamgam/google-oauth.php`  
  (یا `/hamgam/google-oauth` اگر mod_rewrite فعال است)

## ۷. تست بعد از آپلود

1. `https://hamgam.zamanak24.ir/php/hamgam/health.php` → باید JSON با `"status":"ok"` برگرداند.
2. `POST https://hamgam.zamanak24.ir/php/hamgam/auth.php` با body تست → باید JSON باشد (نه HTML با `<br />`).
3. **وب‌هوک نوبت (پنل Hamdast):**  
   `POST https://hamgam.zamanak24.ir/php/webhook/paziresh24-hamgam.php`  
   باید JSON برگرداند (مثلاً `{"ok":true,"skipped":"not_connected"}`).
4. `https://hamgam.zamanak24.ir/` → از پنل پذیرش۲۴ باز شود و وارد تنظیمات شوید.
5. اولین بار → Google OAuth → برگشت به `https://hamgam.zamanak24.ir/?oauth=success`
6. نوبت تست → ایونت‌ در Google Calendar

### SQL چک توکن‌ها

```sql
SELECT
  paziresh24_user_id,
  google_refresh_token IS NOT NULL AS has_google_refresh,
  hamdast_access_token IS NOT NULL AS has_hamdast,
  center_id,
  google_channel_id IS NOT NULL AS has_watch
FROM google_tokens;
```

### curl تست وب‌هوک نوبت

```powershell
curl.exe -X POST "https://hamgam.zamanak24.ir/php/webhook/paziresh24-hamgam.php" `
  -H "Content-Type: application/json" `
  -d "{\"event\":\"provider.appointment\",\"data\":{\"book_id\":\"test-id\",\"doctor_user_id\":1792050,\"book_date\":\"2026-06-10\",\"book_time\":\"16:00\",\"center_name\":\"Test\",\"patient_name\":\"Ali\",\"patient_family\":\"Test\",\"patient_cell\":\"09120000000\",\"patient_national_code\":\"1234567890\"}}"
```

### Google Calendar Watch webhook

- URL در `.env`: `GOOGLE_CALENDAR_WEBHOOK_URL=https://hamgam.zamanak24.ir/php/webhook/google-calendar.php`
- Cron تمدید watch: `0 */6 * * * php /path/to/php/cron/renew-google-watches.php`

### قبل از هر آپلود (لوکال)

```powershell
powershell -ExecutionPolicy Bypass -File scripts\strip-php-bom.ps1
powershell -ExecutionPolicy Bypass -File scripts\pre-deploy-check.ps1
```

## ۸. دیپلوی خودکار (GitHub + cPanel Git)

بعد از راه‌اندازی، با هر `git push` به GitHub، cPanel خودکار کد را pull و deploy می‌کند.

### پیش‌نیازها

- حساب GitHub
- **Git Version Control** فعال در cPanel
- فایل `php/.env` یک‌بار روی سرور ساخته شده (بخش ۳) — دیپلوی خودکار آن را overwrite **نمی‌کند**

### فایل‌های deploy در repo

| فایل | نقش |
|------|-----|
| `.cpanel.yml` | بعد از pull، `scripts/cpanel-deploy.sh` را اجرا می‌کند |
| `scripts/build-deploy-package.sh` | ساخت پکیج deploy (معادل bash از `build-deploy-package.ps1`) |
| `scripts/cpanel-deploy.sh` | sync به Document Root + حفظ `php/.env` و لاگ‌ها |

### مرحله A — Git و GitHub (یک‌بار)

در ریشه پروژه (PowerShell):

```powershell
cd "f:\VS Code File\New folder\Zamanak"
git init
git add .
git commit -m "Initial commit: Hamgam app"
```

سپس در GitHub یک repo **خصوصی** بسازید (مثلاً `Zamanak`) و push کنید:

```powershell
git branch -M main
git remote add origin https://github.com/YOUR_USER/Zamanak.git
git push -u origin main
```

> `php/.env` و `deploy/` در `.gitignore` هستند و commit نمی‌شوند.

### مرحله B — cPanel Git Version Control (یک‌بار)

1. cPanel → **Git Version Control** → **Create**
2. **Clone URL:** `https://github.com/YOUR_USER/Zamanak.git`
3. **Repository Path:** مثلاً `repositories/zamanak` (خارج از `public_html`)
4. برای repo خصوصی:
   - **Deploy Key** در GitHub → Settings → Deploy keys، یا
   - **Personal Access Token** هنگام clone در cPanel

### مرحله C — تنظیم Deployment Path (یک‌بار)

1. همان repo → **Manage** → تب **Pull or Deploy**
2. **Deployment Path:** `public_html/hamgam` (Document Root ساب‌دامین)
3. یک‌بار **Deploy HEAD Commit** را دستی بزنید
4. log را بررسی کنید — باید `Deploy complete` ببینید
5. **Webhook URL** را کپی کنید

### مرحله D — GitHub Webhook (یک‌بار)

1. GitHub repo → **Settings** → **Webhooks** → **Add webhook**
2. **Payload URL:** URL از cPanel
3. **Content type:** `application/json`
4. **Events:** Just the push event
5. اگر cPanel Secret داد، در GitHub وارد کنید

### جریان روزمره

```powershell
# اختیاری — قبل از push
powershell -ExecutionPolicy Bypass -File scripts\pre-deploy-check.ps1

git add .
git commit -m "توضیح تغییر"
git push origin main
# → cPanel ظرف چند ثانیه خودکار deploy می‌کند
```

تأیید بعد از deploy:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\test-server.ps1
```

### رفتار deploy خودکار

| موضوع | رفتار |
|--------|--------|
| `php/.env` | هرگز از repo deploy نمی‌شود؛ روی سرور حفظ می‌شود |
| `php/storage/php-errors.log` | backup/restore می‌شود |
| `php/storage/` | بعد از sync، `chmod 775` (یا `755`) |
| شاخه | فقط شاخه‌ای که webhook روی آن تنظیم شده (معمولاً `main`) |

### عیب‌یابی deploy خودکار

- **log cPanel Git:** Manage → Pull/Deploy → آخرین deploy log
- **`DEPLOYPATH is not set`:** Deployment Path در cPanel تنظیم نشده
- **`rsync: command not found`:** با پشتیبانی هاست تماس بگیرید (معمولاً روی cPanel موجود است)
- **health check WARNING:** چند ثانیه صبر کنید یا `test-server.ps1` را دستی اجرا کنید
- **اولین deploy بدون `.env`:** یک‌بار `php/.env` را روی سرور بسازید (بخش ۳)

## ۹. عیب‌یابی

### خطای «Unexpected token '<'» یا Fatal error در auth

یعنی فایل PHP قبل از `<?php` کاراکتر اضافه (BOM) دارد. فایل `php/hamgam/auth.php` باید **UTF-8 بدون BOM** باشد.

- در VS Code: نوار پایین → Encoding → **Save with Encoding → UTF-8**
- بعد از آپلود دوباره تست:  
  `POST https://hamgam.zamanak24.ir/php/hamgam/auth.php`  
  باید JSON برگرداند، نه HTML با `<br />`.

### دکمه می‌زند و می‌رود صفحه اصلی پذیرش۲۴

یعنی `button.php` خطا خورده. لاگ را ببینید:

- `php/storage/php-errors.log`
- یا Error Log در cPanel

پیام‌های رایج:
- `invalid or missing session_token` → token نرسیده یا encode نشده
- `session_token exchange failed` → `HAMDAST_API_KEY` اشتباه
- `user id not found` → API پذیرش۲۴ جواب نداد
- `SQLSTATE` → `DB_USER` / `DB_PASS` / `DB_NAME` اشتباه

### نکته فرانت

`script.js` از `window.location.origin` استفاده می‌کند؛ با ساب‌دامین بدون تغییر کد کار می‌کند.
