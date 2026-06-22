# مستند مهندسی Hamgam (Zamanak)

این سند نمای کلی معماری، جریان‌های اصلی و چک‌لیست استقرار را برای توسعه‌دهندگان توضیح می‌دهد.

**منابع مرتبط:**
- [DEPLOY.md](../DEPLOY.md) — راهنمای آپلود و تست روی production
- [EXTERNAL_INTEGRATIONS.md](./EXTERNAL_INTEGRATIONS.md) — OAuth2 اتصال providerهای خارجی
- [google-vacation/README.md](../google-vacation/README.md) — همگام‌سازی مرخصی از Google Calendar

---

## معماری کلی

```
پنل پذیرش۲۴ (Hamdast iframe)
        │
        ▼
index.html + script.js          ← UI تنظیمات
        │
        ▼
php/hamgam/*.php                ← API (auth, settings, OAuth, health)
        │
        ├── php/includes/       ← bootstrap, HttpClient, Database, ...
        ├── php/google-vacation/← VacationSyncService, WatchRegistrar
        └── php/webhook/*.php   ← نوبت (paziresh24) + تقویم (google-calendar)

php/storage/php-errors.log      ← لاگ سراسری
php/.env                        ← تنظیمات محیط (روی سرور)
```

**مسیرهای URL:**
- روی nginx: فرانت از `php/hamgam/*.php` مستقیم استفاده می‌کند.
- روی Apache با mod_rewrite: aliasهای `/hamgam/*` و `/webhook/*` در `.htaccess` روت.

**منبع حقیقت:** پوشه‌های `php/` و فایل‌های روت (`script.js`, `index.html`). پوشه `deploy/` خروجی build برای آپلود است (`scripts/build-deploy-package.ps1`).

---

## جریان auth → OAuth → webhook نوبت → vacation sync

### ۱. احراز هویت (auth)

1. `script.js` از Hamdast SDK توکن نشست می‌گیرد.
2. `POST php/hamgam/auth.php` با `hamdast_session_token`.
3. سرور توکن را با API پذیرش۲۴ تعویض می‌کند، `access_token` و وضعیت Google را برمی‌گرداند.
4. `HamgamConnectionService::syncAfterAuth` در پس‌زمینه: `center_id`، ثبت Watch تقویم.

### ۲. اتصال Google (OAuth)

1. اگر `connected=false`، فرانت به `oauth_url` هدایت می‌شود.
2. `google-oauth.php` callback را می‌گیرد، `refresh_token` ذخیره می‌کند، Watch ثبت می‌کند.
3. بازگشت به `/?oauth=success`.

### ۳. ذخیره تنظیمات

1. `POST updatesetting.php` — ذخیره تنظیمات UI + sync + backfill اختیاری مرخصی‌های آینده.
2. پاسخ: `{ ok, settings, backfill, warnings? }`.

### ۴. webhook نوبت

1. پذیرش۲۴ به `php/webhook/paziresh24-hamgam.php` POST می‌زند.
2. تأیید امضا (Svix) → یافتن پزشک → refresh توکن Google → ساخت ایونت‌ Calendar.
3. همیشه JSON برمی‌گرداند؛ skipها با `ok:true` و دلیل.

### ۵. vacation sync

1. تغییر تقویم → Google به `php/webhook/google-calendar.php` POST می‌زند (همیشه 200).
2. `VacationSyncService` با `syncToken` تغییرات را می‌گیرد.
3. اگر `auto_vacation=true` → مرخصی در پذیرش۲۴ ثبت/ویرایش/حذف می‌شود.
4. Cron `php/cron/renew-google-watches.php` هر ۶ ساعت Watch را تمدید می‌کند.

---

## چک‌لیست deploy و تست بعد از آپلود

### قبل از آپلود (لوکال)

```powershell
powershell -ExecutionPolicy Bypass -File scripts\pre-deploy-check.ps1
php php/tools/run-tests.php
```

### آپلود

فایل‌های تغییرکرده در `php/`، `script.js`، `index.html` و در صورت نیاز `deploy/` (بعد از `build-deploy-package.ps1`).

### بعد از آپلود (سرور) — همان ۵ تست DEPLOY.md

1. `GET .../php/hamgam/health.php` → JSON با `"status":"ok"`.
2. `POST .../php/hamgam/auth.php` → JSON (نه HTML خطا).
3. `POST .../php/webhook/paziresh24-hamgam.php` → JSON (مثلاً `skipped`).
4. باز کردن صفحه از پنل → ذخیره تنظیمات.
5. نوبت تست / مرخصی تست در Calendar.

همچنین:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\test-server.ps1
```

### عیب‌یابی

- لاگ: `php/storage/php-errors.log` — پیشوند `[channel][req=...]` برای ردیابی درخواست.
- health degraded → `.env` یا دیتابیس را چک کنید.

---

## تصمیم‌های آگاهانه

### WebhookVerifier فعلاً stub

`php/includes/WebhookVerifier.php` — `verifySvix` بدون secret واقعی `return true` می‌کند. تا زمانی که secret در `.env` تنظیم نشود، تأیید امضا غیرفعال است. **عمداً تغییر ندهید** مگر secret آماده باشد.

### HTTP_SSL_VERIFY روی بعضی هاست‌ها

روی cPanel/BitNinja گاهی `HTTP_SSL_VERIFY=false` لازم است تا cURL به APIهای خارجی وصل شود. این تنظیم production را عوض نکنید مگر مشکل SSL مشخص باشد.

### فرمت API پایدار

- خطا: `{ "error": "..." }` — `Response::jsonError`
- موفق: `{ "ok": true, ... }` یا فیلدهای auth/settings
- `warnings` فیلد اختیاری است؛ فرانت بدون آن همان رفتار قبلی را دارد.

### CORS

`Request::applyCors` از `CORS_ORIGINS` در `.env` استفاده می‌کند — برای iframe پذیرش۲۴ ضروری است.

---

## ساختار کلیدی includes

| فایل | نقش |
|------|-----|
| `bootstrap.php` | بارگذاری env، لاگ، timezone |
| `RequestContext.php` | شناسه درخواست برای لاگ |
| `HttpClient.php` | درخواست HTTP با retry محافظه‌کارانه (GET/DELETE) |
| `HamgamConnectionService.php` | sync بعد از auth |
| `ProviderIntegrationService.php` | OAuth2 اتصال providerهای خارجی (DrDr, ...) |
| `GoogleTokensRepository.php` | توکن‌ها و تنظیمات کاربر |
| `WebhookVerifier.php` | تأیید webhook (stub) |

---

## تست‌های لوکال

```bash
php php/tools/run-tests.php
php -c dev/php.ini php/tools/test-backfill-delete-fix.php
php -c dev/php.ini php/tools/test-backfill-lifecycle-e2e.php   # نیاز به .env.local
```

تست‌های HTTP در `php/tools/test-vacation-*.php` از مرورگر/curl با `?key=HAMDAST_API_KEY` اجرا می‌شوند.
