# Faculty Information System (FacultyIS) v2.16.1

A hybrid **PHP (frontend/CRUD) + Flask (analytics API)** application for
managing faculty information, attendance, timetables, documents, and reports.

**Architecture:**

- **SQLite** — no database server, just a file in `data/`
- **PHP built-in server** — `php -S` on `127.0.0.1:8080`, no Nginx/Apache
- **uv** — Python dependency management via `pyproject.toml` + `uv.lock`
- **Portable** — copy the folder, run one command, it works offline
- **Fast** — seconds to start, not minutes

---

## Local Development

```bash
cp .env.example .env          # defaults work for local dev
chmod 600 .env                # protect secrets
echo 'DEV_MODE=1' >> .env     # accept insecure JWT default for dev
./start.sh                    # checks env → syncs deps → starts services
# visit http://127.0.0.1:8080
```

> **Warning:** The seed accounts below are for **local development only**.
> `DEV_MODE=1` enables the insecure default JWT secret.
> Never use these credentials or `DEV_MODE` in production.

<details>
<summary>Seed Accounts (development only)</summary>

| User ID   | Password      | Role    | Department        |
|-----------|---------------|---------|-------------------|
| admin1    | password123   | admin   | —                 |
| hod_cs    | password123   | hod     | Computer Science  |
| hod_ec    | password123   | hod     | Electronics       |
| fac_cs1   | password123   | faculty | Computer Science  |
| fac_cs2   | password123   | faculty | Computer Science  |
| fac_ec1   | password123   | faculty | Electronics       |
| fac_me1   | password123   | faculty | Mechanical        |

</details>

---

## Production Deployment

**Do not use `DEV_MODE` or default credentials in production.**

### Required configuration

1. **Generate a strong JWT secret:**

```bash
python3 -c "import secrets; print(secrets.token_urlsafe(48))"
```

2. **Create `.env` with production settings:**

```bash
cp .env.example .env
chmod 600 .env
```

Then edit `.env`:

```ini
JWT_SECRET=<paste the generated secret here>
# Do NOT set DEV_MODE — leave it unset or set to 0
JWT_TTL_SECONDS=3600
RATE_LIMIT_PER_MINUTE=30
LOG_LEVEL=WARNING
```

3. **Initialize the database:**

```bash
./start.sh            # first run creates the DB
# OR
./start.sh --reset    # reinitialize with fresh schema
```

4. **Create admin account via the application** — do not use seed data.

### Security hardening checklist

- [ ] `JWT_SECRET` is a strong random value (not the default)
- [ ] `DEV_MODE` is **unset** (not `1`, not `true`)
- [ ] `.env` permissions are `600`
- [ ] Seed accounts are **not** present in the database
- [ ] `data/` and `data/uploads/` are not world-readable
- [ ] The application runs behind a TLS-terminating proxy in production
- [ ] Firewall rules restrict access to Flask port (5000) — only PHP needs to reach it

---

## Architecture

```
Browser → PHP (:8080) ──session auth──→ CRUD writes to SQLite
                                │
                                └─ JWT → Flask (:5000) → analytical reads from SQLite
```

| Layer    | Technology                | Responsibility                                  |
|----------|---------------------------|-------------------------------------------------|
| Frontend | PHP built-in server       | UI, session auth, CRUD, file uploads, CSRF      |
| API      | Flask + Gunicorn (1 worker) | Reports, CSV export, attendance summaries      |
| Database | SQLite (WAL mode)         | Shared between PHP and Flask                     |
| Auth     | JWT (HS256, shared secret) | PHP issues tokens, Flask validates them          |

## Security Model

| Operation              | Faculty          | HOD                | Admin             |
|------------------------|------------------|--------------------|-------------------|
| Record attendance      | Own sections only| Own department     | All               |
| Delete attendance      | —                | Own department     | All               |
| Manage faculty         | —                | Own department     | All               |
| Delete documents       | Own uploads      | Own department     | All               |
| Change role            | —                | —                  | Admin-only        |
| Department CRUD        | —                | —                  | Admin-only        |
| Manage courses/sections| —                | Own department     | All               |
| Manage timetable       | —                | Own department     | All               |
| View workload report   | —                | Own department     | All               |
| **View faculty list**  | Own department   | **Own department** | All               |
| **View course list**   | Own department   | **Own department** | All               |
| **View attendance**    | Own sections     | **Own department** | All               |
| **View sections**      | Own sections     | **Own department** | All               |

### Session & JWT Revocation

- When a user's role or password is changed, `auth_version` is incremented in the database
- **PHP sessions:** On every request, `requireLogin()` compares the session's `auth_version` against the database. If stale, the session is destroyed and the user must re-authenticate
- **Flask JWTs:** On every request, `jwt_required()` compares the JWT's `auth_version` **and** `role`/`department_id` against the database. Any mismatch results in 401
- Deleted-user JWTs are rejected immediately (401)
- This ensures privilege changes take effect on the very next request, not just after token expiration

### Password Reset

- Reset tokens are **hashed** (SHA-256) before storage — raw tokens are never in the database
- The reset link is **never displayed** in the web response — only logged to `error_log` in `DEV_MODE`
- Response is identical whether the email exists or not (prevents enumeration)
- Rate-limited: 10 requests per hour per IP

### Rate Limiting

- Login: 5 attempts per 15 minutes per IP (clears on success)
- Password reset: 10 requests per hour per IP
- Flask API: configurable per-minute per-user (in-process)

## Environment Variables

| Variable              | Default                        | Description                          |
|-----------------------|--------------------------------|--------------------------------------|
| `JWT_SECRET`          | `dev-insecure-please-change-me`| Shared JWT signing secret            |
| `DEV_MODE`            | (not set)                      | Set to `1` to accept insecure default|
| `JWT_TTL_SECONDS`     | `3600`                         | Access token lifetime                |
| `RATE_LIMIT_PER_MINUTE` | `30`                         | Per-user rate limit (in-process)     |
| `LOG_LEVEL`           | `WARNING`                      | Flask log verbosity                  |
| `ACCESS_LOG`          | `false`                        | Enable Gunicorn access logs          |
| `FLASK_PORT`          | `5000`                         | Flask API port                       |
| `PHP_PORT`            | `8080`                         | PHP frontend port                    |

## Key Design Decisions

- **Authorization enforced server-side** — never rely on UI visibility alone
- **HOD scoped to own department** — HOD operations restricted by `department_id`
- **Object-level authorization on updates** — when updating by ID, both the existing object's department AND the requested new department are verified
- **Role changes are admin-only** — prevents HOD → admin privilege escalation
- **Last-admin protection** — cannot demote the final administrator
- **Password reset tokens are hashed** — raw tokens never stored in DB
- **Reset link never shown to user** — only logged for dev, emailed in production
- **Reset response is identical** — prevents account enumeration
- **Session & JWT staleness detection** — `auth_version` invalidates stale sessions and JWTs; role/department claims verified against DB
- **Login rate limiting** — 5 attempts / 15 min / IP; clears on success
- **Filename sanitization** — download Content-Disposition uses safe ASCII + RFC 5987 `filename*`
- **Flask refuses empty JWT secret** — even if started outside `start.sh`
- **`DEV_MODE` enforced at both levels** — `start.sh` AND Flask validate independently
- **Attendance IDOR fixed** — faculty can only record for own sections; HOD for own department
- **Report cache never bypasses authorization** — 401/403 responses are never served from cache
- **Export error handling** — 401/403 from Flask forwarded to client; 5xx/timeout → 502
- **Dropdown isolation** — non-admin users only see courses/departments in their own department
- **Read-side department isolation** — HOD/faculty list queries enforce department boundary on both read and write paths; `DepartmentScope` helper centralizes this logic
- **DepartmentScope used by all pages** — every PHP page calls `$scope->departments()`, `$scope->facultyList()`, etc. instead of manual if/else branches, eliminating regression risk
- **AuthorizationPolicy centralizes write-side auth** — `AuthorizationPolicy.php` provides `canCreate()`, `canEdit()`, `canDelete()`, `canManageRole()` etc., ensuring consistent object-level authorization
- **Audit-log department isolation** — HOD can only see audit logs for their own department; `audit_log.department_id` column populated for all entity actions
- **Dashboard department isolation** — HOD/faculty see only their own department's statistics; `DepartmentScope::dashboardStats()` provides scoped counts
- **DB consistency checks** — `check-consistency.sh` verifies department_id consistency across section→course, document→course, timetable→section, and audit_log records
- **Authorization policy documented** — `POLICY.md` is the single source of truth for all authorization decisions, checked by both PHP and Flask implementations
- **Audit logging uses REMOTE_ADDR** — X-Forwarded-For is not trusted without a proxy

## Status

- [x] Local dev architecture (SQLite + PHP built-in server + uv)
- [x] Hybrid PHP+Flask with shared JWT auth
- [x] Server-side authorization enforced on all POST handlers
- [x] Object-level authorization on updates (existing + new state verified)
- [x] HOD department scoping on faculty, courses, sections, attendance, timetable, documents
- [x] Role changes admin-only (no HOD → admin privilege escalation)
- [x] Last-admin demotion protection
- [x] Attendance IDOR fixed (faculty: own sections; HOD: own department)
- [x] Document delete authorization (admin: any; HOD: own dept; faculty: own uploads)
- [x] Password reset tokens hashed (SHA-256), never stored raw
- [x] Reset link never displayed in web response
- [x] Reset response identical regardless of email existence (no enumeration)
- [x] Rate limiting on login (5/15min) and password-reset (10/hour)
- [x] PHP session + JWT staleness detection via auth_version
- [x] JWT claim consistency (role + department_id verified against DB)
- [x] Deleted-user JWT rejection
- [x] Filename sanitization in Content-Disposition (RFC 5987)
- [x] Flask refuses empty JWT secret; DEV_MODE validated at both levels
- [x] HOD workload API scoped to own department
- [x] Report cache never bypasses 401/403 authorization
- [x] Export error handling distinguishes auth failures from service failures
- [x] Dropdown isolation for non-admin users
- [x] Read-side department isolation (faculty, courses, sections, attendance listings scoped by department)
- [x] DepartmentScope centralized helper for consistent authorization policy
- [x] DepartmentScope used by ALL pages (no manual if/else branches for read-side scoping)
- [x] AuthorizationPolicy centralizes write-side authorization (canCreate, canEdit, canDelete, canManageRole)
- [x] Audit-log department isolation (HOD sees only own department's audit logs)
- [x] Dashboard department isolation (HOD/faculty see own department's statistics only)
- [x] DB consistency check script (check-consistency.sh)
- [x] Authorization policy documented in POLICY.md
- [x] Dashboard & audit-log department isolation tests
- [x] Negative authorization tests (HOD → promote, HOD → delete course, cross-dept API)
- [x] Cross-department read isolation tests (HOD → faculty/course/attendance listing)
- [x] Deleted-user JWT lifecycle test (valid JWT → delete user → 401)
- [x] JWT includes department_id for Flask department scoping
- [x] Audit logging, CSRF protection, password hashing, upload isolation
- [x] Graceful shutdown, PID verification, safe SQLite backup
- [x] Offline-capable XLSX export (vendored SheetJS)
- [x] Clean release packaging (no .git, no .venv, no dev data)
- [ ] Predictive analytics — needs training data
- [ ] Email/SMS campaigns — needs SMTP/SMS provider
