# External Provider Integrations (OAuth2 Account Linking)

Production-ready OAuth2 Authorization Code Flow for connecting Paziresh24 doctors to external providers (DrDr, ...).

## Architecture

```
Doctor UI (Paziresh24 panel)
        │
        ├─ GET  /integrations/:provider/connect   → redirect to provider authorize URL
        ├─ GET  /integrations/:provider/callback  → exchange code, store encrypted tokens
        ├─ GET  /integrations/:provider/status    → connection status (no tokens)
        └─ POST /integrations/:provider/disconnect
        │
        ▼
ProviderIntegrationService
        ├── OAuthStateSigner (HMAC state, CSRF protection)
        ├── TokenEncryption (AES-256-GCM at rest)
        └── DoctorExternalConnectionsRepository
```

## Security guarantees

- Standard OAuth2 Authorization Code Flow only (no OTP scraping, no session hijacking).
- `state` is HMAC-signed and includes `doctor_id`, `provider`, `nonce`, `iat` (15 min TTL).
- Doctor identity is validated from a real Paziresh24 JWT (`Authorization: Bearer` or trusted header).
- Tokens are encrypted with AES-256-GCM before DB storage.
- Tokens and authorization codes are never logged or returned in API responses.
- Callback endpoint is rate-limited (default: 30 req/min/IP/provider).

## Flow for developers

### 1. Doctor clicks "Connect to DrDr"

Frontend option A — JSON (recommended):

```http
GET /integrations/drdr/connect?format=json
Authorization: Bearer {paziresh24_access_token}
```

Response:

```json
{
  "ok": true,
  "provider": "drdr",
  "oauth_url": "https://provider.example.com/oauth/authorize?..."
}
```

Then redirect the browser to `oauth_url`.

Frontend option B — direct redirect:

```http
GET /integrations/drdr/connect
Authorization: Bearer {paziresh24_access_token}
```

Server responds with HTTP redirect to provider authorization page.

### 2. Provider callback

Provider redirects browser to:

```
GET /integrations/drdr/callback?code=...&state=...
```

Server:

1. Rate-limits request
2. Verifies HMAC `state` → extracts `doctor_id`
3. `POST {token_url}` with `grant_type=authorization_code`
4. Encrypts and upserts tokens in `doctor_external_connections`
5. Redirects to settings: `/?integration=success&provider=drdr`

### 3. Using tokens internally

```php
$accessToken = ProviderIntegrationService::getValidAccessToken($doctorId, 'drdr');
if ($accessToken === null) {
    // not connected or refresh failed
}
// Call provider API with $accessToken — never expose to client
```

### 4. Check connection status

```http
GET /integrations/drdr/status
Authorization: Bearer {paziresh24_access_token}
```

```json
{
  "ok": true,
  "provider": "drdr",
  "connected": true,
  "expires_at": 1718888888,
  "has_refresh_token": true
}
```

### 5. Disconnect

```http
POST /integrations/drdr/disconnect
Authorization: Bearer {paziresh24_access_token}
```

```json
{
  "ok": true,
  "provider": "drdr",
  "disconnected": true
}
```

## Database

Table: `doctor_external_connections`

| Column | Type | Notes |
|--------|------|-------|
| id | PK | auto increment |
| doctor_id | indexed | Paziresh24 user id |
| provider | string | e.g. `drdr` |
| access_token | TEXT | AES-256-GCM encrypted |
| refresh_token | TEXT nullable | encrypted |
| expires_at | timestamp/int | unix time |
| created_at | timestamp | |
| updated_at | timestamp | |

SQL files:

- `php/sql/mysql_doctor_external_connections.sql`
- `php/sql/doctor_external_connections.sql` (SQLite)

Migration runs automatically via `Database::connection()`.

## Adding a new provider

1. Register OAuth app with provider; set redirect URI to  
   `https://{your-domain}/integrations/{slug}/callback`
2. Add slug to `IntegrationProviderConfig::$knownProviders`
3. Add env vars:

```env
INTEGRATION_{SLUG}_CLIENT_ID=...
INTEGRATION_{SLUG}_CLIENT_SECRET=...
INTEGRATION_{SLUG}_AUTH_URL=...
INTEGRATION_{SLUG}_TOKEN_URL=...
INTEGRATION_{SLUG}_REDIRECT_URI=...
INTEGRATION_{SLUG}_SCOPE=...
```

4. Deploy `.env` and test connect → callback → status.

## Required env vars

```env
TOKEN_ENCRYPTION_KEY=base64-encoded-32-byte-key
INTEGRATION_OAUTH_STATE_SECRET=long-random-string
INTEGRATION_CALLBACK_RATE_LIMIT=30
```

Generate encryption key:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

## Logging

Only structured metadata is logged:

```
[integrations/oauth][req=abc123] doctor_id=42 provider=drdr status=success reason=callback_success
```

Never logged: `access_token`, `refresh_token`, `authorization_code`.

## Local test

```bash
php -c dev/php.ini php/tools/test-provider-integration.php
```
