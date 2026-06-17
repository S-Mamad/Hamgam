# Google Calendar → Paziresh24 Vacation Sync

ماژول ثبت خودکار مرخصی در پذیرش۲۴ از رویدادهای Google Calendar.

## نصب

### ۱. Migration دیتابیس

**MySQL (production):**
```bash
# در phpMyAdmin اجرا کنید:
php/sql/mysql_google_vacation_migration.sql
```

**SQLite (local):**
```bash
sqlite3 php/storage/database.sqlite < php/sql/google_vacation_migration.sql
```

### ۲. متغیرهای `.env`

```env
GOOGLE_CALENDAR_WATCH_URL=https://www.googleapis.com/calendar/v3/calendars/primary/events/watch
GOOGLE_CALENDAR_EVENTS_LIST_URL=https://www.googleapis.com/calendar/v3/calendars/primary/events
GOOGLE_CALENDAR_CHANNELS_STOP_URL=https://www.googleapis.com/calendar/v3/channels/stop
GOOGLE_CALENDAR_WEBHOOK_URL=https://hamgam.zamanak24.ir/webhook/google-calendar
PAZIRESH24_VACATION_URL=https://apigw.paziresh24.com/open-platform/v1/booking/vacations
```

روی nginx بدون mod_rewrite:
```env
GOOGLE_CALENDAR_WEBHOOK_URL=https://hamgam.zamanak24.ir/php/webhook/google-calendar.php
```

### ۳. Webhook URL

| هاست | URL |
|------|-----|
| Apache + rewrite | `https://hamgam.zamanak24.ir/webhook/google-calendar` |
| nginx / مستقیم | `https://hamgam.zamanak24.ir/php/webhook/google-calendar.php` |

## جریان کار

1. **OAuth موفق** → `WatchRegistrar` یک Watch روی تقویم `primary` ثبت می‌کند
2. **تغییر در تقویم** → Google به webhook POST می‌زند
3. **پردازش** → `VacationSyncService` تغییرات را با `syncToken` می‌گیرد
4. اگر `auto_vacation=true` → مرخصی در پذیرش۲۴ ثبت می‌شود

## Cron تمدید Watch

Watch گوگل حدود ۷ روز اعتبار دارد. هر ۶ ساعت اجرا کنید:

```cron
0 */6 * * * php /path/to/php/cron/renew-google-watches.php
```

## لاگ

پیشوند: `[google-vacation]` در `php/storage/php-errors.log`

## v1 محدودیت‌ها

- ویرایش رویداد گوگل (تغییر تاریخ/ساعت) → `PUT updateVacation` در پذیرش۲۴ (با `old_from`/`old_to` از DB)
- حذف رویداد گوگل → `DELETE deleteVacation` در پذیرش۲۴
- فقط تغییر عنوان (بدون تغییر زمان) → فقط به‌روزرسانی DB، بدون درخواست API
- رویدادهای Hamgam (عنوان شامل «پذیرش 24») → skip
- `center_id` → UUID مرکز درمانی از `GET /booking/medical-centers` (فیلد `data[].id`)
