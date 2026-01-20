## Backend – Laravel API

This directory contains the **Laravel API** for the hybrid email productivity app:

- Authentication (email/password, Google Sign‑In)
- Gmail integration (read, send, modify, snooze)
- Hybrid search (fuzzy + semantic)
- Kanban email workflow (columns, snooze, done, etc.)
- Token management (access + refresh tokens)

---

## 1. Prerequisites

- **PHP** 8.2+
- **Composer**
- **MySQL** 8.x (or compatible)
- **Node.js** 18+ (optional, only if you want to run Laravel Mix/Vite locally for assets)
- **OpenSSL** (for HTTPS / OAuth callbacks in dev if needed)

---

## 2. Setup & Installation

```bash
cd final-project-be

# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate
```

### 2.1 Configure Database

Edit `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

Then run migrations:

```bash
php artisan migrate
```

---

## 3. Environment Variables

Key variables used by this project (non‑exhaustive):

```dotenv
APP_NAME="Hybrid Email"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Google OAuth / Gmail
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=${APP_URL}/api/auth/google/callback

# OpenAI (semantic search / embeddings)
OPENAI_API_KEY=your_openai_api_key
OPENAI_MODEL=gpt-4o-mini
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
```

> **Note**: In production, set `APP_URL` to your HTTPS backend URL and configure proper DB/OpenAI/Google credentials.

---

## 4. Running the API

### 4.1 Development Server

```bash
php artisan serve
```

The API will be available at `http://localhost:8000` (by default).

### 4.2 Queues & Background Jobs

For semantic embeddings and other async work:

```bash
# Use database or redis queue (configure in config/queue.php and .env)
php artisan queue:work
```

### 4.3 Embedding Backfill Command

To generate semantic embeddings for recent emails:

```bash
php artisan emails:embed --limit=100
```

This command:
- Finds connected Gmail providers
- Fetches recent INBOX emails
- Generates embeddings only for emails that don't have them yet

---

## 5. API Endpoints (Overview)

Base URL (dev): `http://localhost:8000/api`

### 5.1 Authentication

- `POST /api/register`
  - Body: `{ name, email, password }`
  - Response: `{ accessToken, accessTokenExpiresAt, refreshToken }`

- `POST /api/login`
  - Body: `{ email, password }`
  - Response: `{ accessToken, accessTokenExpiresAt, refreshToken }`

- `POST /api/google-signin`
  - Body: `{ code }` (OAuth authorization code from Google)
  - Response: `{ accessToken, accessTokenExpiresAt, refreshToken, emailProviderConnected, isNewUser }`

- `GET /api/me` (auth required)
  - Returns authenticated user info.

- `POST /api/logout` (auth required)
  - Revokes current access token and refresh token (cookie).

> **Automatic refresh**: The frontend never calls `/api/refresh` directly. Instead, the `bearer.auth` middleware refreshes access tokens on any protected request when it detects an expired access token and a valid refresh token cookie.

#### Example: Login (email/password)

```bash
curl -X POST 'http://localhost:8000/api/login' \
  -H 'Content-Type: application/json' \
  -d '{"email":"demo@example.com","password":"password"}'
```

#### Example: Use access token

```bash
curl 'http://localhost:8000/api/mailboxes' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

#### Example: Logout (revokes tokens + clears cookie)

```bash
curl -X POST 'http://localhost:8000/api/logout' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -b 'refresh_token=<REFRESH_COOKIE_VALUE>'
```

### 5.2 Gmail OAuth / Provider

- `GET /api/email-provider/status`
  - Returns whether Gmail is connected for the current user.

- `GET /api/auth/google/authorize`
  - Returns a Google OAuth URL to connect Gmail (used after login).

- `GET /api/auth/google/callback`
  - Google redirects here after the user authorizes Gmail.

- `POST /api/auth/google/disconnect`
  - Disconnects Gmail and revokes provider tokens.

### 5.3 Email

- `GET /api/mailboxes`
- `GET /api/mailboxes/{mailboxId}/emails`
  - Query params: `page`, `limit`, `unread_only`, `has_attachments`
- `GET /api/emails/{emailId}`
- `POST /api/emails/send`
- `POST /api/emails/{emailId}/reply`
- `POST /api/emails/{emailId}/forward`
- `POST /api/emails/{emailId}/modify`
  - Body: `{ read?: bool, starred?: bool, delete?: bool }`
- `GET /api/emails/{emailId}/attachments/{attachmentId}`

### 5.4 Search

- `GET /api/search/fuzzy`
  - Query params: `query`, `page`, `limit`
  - Returns emails with `relevance_score` (0–100).

#### Example: Fuzzy search

```bash
curl 'http://localhost:8000/api/search/fuzzy?query=invoice&page=1&limit=15' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

- `POST /api/search/semantic`
  - Body: `{ query, limit?, threshold?, page? }`
  - Returns emails with:
    - `similarity_score` (0–1)
    - `relevance_score` (0–100, derived from similarity)
    - `meta.took_ms`, `meta.model`

#### Example: Semantic search

```bash
curl -X POST 'http://localhost:8000/api/search/semantic' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{"query":"email about billing issue","limit":15,"page":1}'
```

- `GET /api/search/suggestions`
  - Query params: `query`, `limit`
  - Returns sender/keyword suggestions for type‑ahead.

### 5.5 Workflow & Kanban

- `GET /api/workflow/states`
- `POST /api/workflow/states/{emailId}`
  - Body: `{ column_id }` (e.g. `inbox`, `todo`, `in_progress`, `done`, `snoozed`)
- `POST /api/workflow/initialize/{emailId}`
- `POST /api/workflow/snooze/{emailId}`
  - Body: `{ quick_option | snooze_until }`
- `POST /api/workflow/unsnooze/{emailId}`
- `GET /api/emails/{emailId}/summary`

### 5.6 Kanban Configuration

- `GET /api/kanban/columns`
- `POST /api/kanban/columns`
- `PUT /api/kanban/columns/{columnId}`
- `DELETE /api/kanban/columns/{columnId}`
- `POST /api/kanban/columns/reorder`
- `GET /api/kanban/gmail-labels`

---

## 6. Google OAuth Setup

1. **Create a Google Cloud project**
   - Enable **Gmail API** and **Google People API** (if needed).
2. **Create OAuth 2.0 Client ID**
   - Application type: **Web application**
   - Authorized redirect URI:  
     `http://localhost:8000/api/auth/google/callback` (dev)
3. **Configure `.env`**
   - Set:
     - `GOOGLE_CLIENT_ID`
     - `GOOGLE_CLIENT_SECRET`
     - `GOOGLE_REDIRECT_URI` (optional; defaults to `${APP_URL}/api/auth/google/callback`)
4. **Frontend Google OAuth**
   - Frontend uses `VITE_GOOGLE_CLIENT_ID` for the Google OAuth client.
   - Ensure the same client ID is used on both frontend and backend.

---

## 7. Token Storage & Security

### 7.1 Token Model

- **Access Token**
  - Random 64‑char string stored in `auth_tokens.access_token`
  - Short‑lived (e.g. 15 minutes).
  - Sent as `Authorization: Bearer <token>` from frontend (stored **in memory only**).

- **Refresh Token**
  - Long‑lived token stored in `auth_tokens.refresh_token`.
  - Sent **only** as an **httpOnly, secure cookie**: `refresh_token`.
  - Not accessible to JavaScript (protects against XSS).

### 7.2 Automatic Refresh (Middleware)

`App\Http\Middleware\BearerTokenAuth`:

- For each protected `/api/*` request:
  - Reads Bearer token from `Authorization` header.
  - If missing, tries to restore from `refresh_token` cookie:
    - If valid, generates a new access token and updates DB.
  - If access token expired but refresh token still valid:
    - Rotates access token and updates DB.
  - If both invalid/expired:
    - Returns `401 Unauthorized`.
- When a token is refreshed/restored:
  - Adds headers:
    - `X-New-Access-Token`
    - `X-Access-Token-Expires-At`

The frontend Axios interceptor reads these headers and updates the in‑memory access token.

### 7.3 Logout

`POST /api/logout`:

- Revokes the current access token and refresh token in DB.
- Clears the `refresh_token` cookie.

---

## 8. CORS & Security Considerations

`config/cors.php`:

- `allowed_origins` is currently `['*']` for development convenience.
- `supports_credentials` is `true` to allow cookies (refresh token).
- `exposed_headers` includes:
  - `X-New-Access-Token`
  - `X-Access-Token-Expires-At`

**Production recommendation**:

- Restrict `allowed_origins` to your frontend domain(s).
- Keep `supports_credentials = true` so httpOnly cookies work.

---

## 9. Troubleshooting

- **401 after refresh / page reload**
  - Ensure backend is running with correct `APP_URL` and cookies domain.
  - Check that browser sends `refresh_token` cookie (same‑site and secure flags).

- **Google OAuth fails**
  - Verify redirect URI matches exactly in Google Cloud Console and `.env`.
  - Check Gmail API is enabled in Google Cloud.

- **Embeddings not generated**
  - Ensure `OPENAI_API_KEY` is set and queue worker is running (if using jobs).
  - Run `php artisan emails:embed --limit=100` manually to test.
