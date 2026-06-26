# HireAI — Session Instructions (READ THIS FIRST)

> **Read this file at the start of every prompt before touching any code.**  
> Update the "Fixes Applied" and "Known Issues" sections after every change.

---

## Quick Reference

- **Root:** `/var/www/hire/`
- **DB:** MySQL `hireai` via MySQLi (see `includes/db.php`)
- **Auth:** JWT in `$_SESSION['token']` · CSRF tokens on all POSTs · Login lock in flat file
- **AI scoring:** Vertex AI → Gemini API → Groq (fallback chain, see `api/score.php`)
- **Face detection:** Gemini Vision via `api/check_face.php` (brightness pre-filter client-side)
- **Outreach:** WhatsApp via `WA_API_URL` (wa.clouddialer.in) · Avya Dialer (`DIALER_API_URL`) for AI calls
- **Credits:** `credit_wallets` table, deduction is atomic via `WHERE balance >= ?` + `affected_rows`
- **Export token:** Uses `EXPORT_TOKEN_SECRET` (separate from `JWT_SECRET`)
- **Public pages (no auth):** `apply.php`, `interview.php`, `forgot_password.php`, `reset_password.php`, `api/apply.php`, `api/check_duplicate.php`, `api/check_face.php`, `api/call_webhook.php`
- **super_admin only:** `admins.php`, `audit_logs.php`, `training.php`
- **Self-contained pages:** `apply.php` and `interview.php` do NOT include `head.php` — they have their own `<head>` with inline styles

---

## Architecture Rules (never break these)

1. **All DB writes** use prepared statements through `db_execute()` — never string-interpolate into SQL.
2. **All POST handlers** call `verify_csrf_or_die()` (except public API endpoints and webhooks).
3. **API endpoints** return JSON only via `json_response($data, $code)` from `functions.php`.
4. **File uploads** must go through `detect_uploaded_mime()` + `upload_safe_extension()` from `helpers.php`.
5. **Phone numbers** normalized with `normalize_phone()` from `helpers.php` (canonical version).
6. **Credit deductions** must use the atomic `WHERE balance >= ?` pattern in `functions.php:deduct_credit()`.
7. **Audit logging** required for all admin mutations: `audit_log($org_id, $user_id, $entity, $id, $action, $details)`.
8. **Candidate delete** must use a DB transaction and include `ai_call_results` in the cascade.
9. **Scoring (api/score.php)** runs via CLI only in production; verify JWT when accessed over HTTP.
10. **Webhook endpoints** (`call_webhook.php`, `api/interview.php?action=webhook`) use `verify_hmac_signature()` not JWT.
11. **Standard apply form fields** are controlled by `campaigns.apply_form_config` JSON column — NOT stored in `application_fields` table. Custom campaign-specific fields go in `application_fields`.
12. **Mobile inputs** must use `font-size:16px` minimum to prevent iOS Safari auto-zoom on focus.
13. **Face checks** always default to `face:true` on network/API error — never block an interview due to a check_face.php failure.

---

## Fixes Applied (chronological)

### 2026-05-28 — Batch fix (27 items)

| # | Category | File(s) | What was fixed |
|---|----------|---------|----------------|
| 1 | CRITICAL | `.env` | Added missing `INTERVIEW_WEBHOOK_SECRET` — webhooks were 401ing |
| 2 | CRITICAL | `nginx.conf` | Standardized all fastcgi_pass to `php8.2-fpm.sock` (was 8.3, causing 502) |
| 3 | CRITICAL | `api/score.php` | Added `verify_jwt()` check for HTTP access; CLI path still open |
| 4 | SECURITY | `nginx.conf` | Blocked `/.git` and all hidden dirs via `location ~ /\.` |
| 5 | SECURITY | `nginx.conf` | Blocked `.phpORG`, `.bak`, `.bak3`, `.sh`, `.sql`, `.log`, `.HTML` extensions |
| 6 | SECURITY | `api/interview.php` | Fixed shell injection in `exec()` — IDs cast to int, path uses `escapeshellarg()` |
| 7 | SECURITY | `candidates.php`, `export_candidates.php` | Export tokens now signed/verified with `EXPORT_TOKEN_SECRET` not `JWT_SECRET` |
| 8 | SECURITY | `api/outreach.php` | Phone numbers in `error_log()` masked to `XXXX****XX` format |
| 9 | DATA | `api/candidates.php` | Delete/bulk_delete wrapped in `begin_transaction/commit/rollback` |
| 10 | DATA | `api/candidates.php` | `ai_call_results` now included in delete cascade |
| 11 | DATA | `includes/functions.php` | `deduct_credit()` rewritten with atomic `WHERE balance >= ?` + `affected_rows` |
| 12 | DATA | `interview.php` | `on_hold` added to link-expiry exclusion + already-completed check |
| 13 | DATA | `api/outreach.php` | `custom_whatsapp_send` now updates status to `outreach_sent` on success |
| 14 | PERF | `api/candidates.php` | Duplicate check replaced: was PHP loop over all rows → now single indexed SQL |
| 15 | PERF | `candidates.php` | Status pill counts: was loading all rows → now single GROUP BY query |
| 16 | PERF | `includes/functions.php` | Removed `CREATE TABLE IF NOT EXISTS` DDL from `ensure_credit_wallet()` |
| 17 | DB | `schema.sql` + live DB | Added missing indexes (8 tables) |
| 18 | DB | `users` table + `schema.sql` | Added `admin` to role ENUM (was missing) |
| 19 | SCHEMA | `schema.sql` | Added missing `ai_call_results` table definition |
| 20 | CODE | `api/score.php` | Moved `log_s()` to top of file; removed duplicate at bottom |
| 21 | CODE | `includes/helpers.php` | Canonical `normalize_phone()` moved here; removed duplicate in `candidates.php` |
| 22 | CODE | `includes/functions.php` | Dead `score_candidate()` (OpenAI) wrapped in block comment |
| 23 | NGINX | `nginx.conf` | Added security headers: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy |
| 24 | NGINX | `nginx.conf` | Blocked PHP execution in `/uploads/` directory |
| 25 | NGINX | `nginx.conf` | `client_max_body_size` reduced from 150M → 25M |
| 26 | AUTO | `scripts/rescore_once.php`, crontab | Added `--on-hold` flag; cron at 2am daily |
| 27 | OPS | live DB | Rescored 15 stuck `on_hold` candidates via Groq |

---

### 2026-05-28 — Voice AI Analysis + Sort feature

| # | Category | File(s) | What was fixed/added |
|---|----------|---------|---------------------|
| 28 | FEATURE | `api/score.php` | `transcribe_one` action: transcribes single voice answer via Groq Whisper |
| 29 | FEATURE | `candidate_detail.php` | "Analyze with AI" button on voice answers without transcript |
| 30 | FEATURE | `candidate_detail.php` | Q&A Sort bar: Question # / Score High→Low / Score Low→High |
| 31 | FEATURE | `api/score.php` | `transcribe_voice_gemini()` + unified `transcribe_voice()` with Groq fallback |
| 32 | REFACTOR | `api/score.php` | Extracted `resolve_audio_local_path()` helper |
| 33 | FIX | `api/score.php` | `?async=1` action — fire-and-forget background scoring |
| 34 | FIX | `candidate_detail.php` | `analyzeVoice()` uses async scoring; eliminates network timeout |
| 35 | FIX | `candidate_detail.php` | `rescoreCandidate()` switched to `?async=1` |
| 36 | NOTE | `api/interview.php` | `complete_interview` already auto-runs score.php — voice answers auto-transcribed |
| 37 | REMOVED | `campaigns.php` | Removed integration_type / integration_endpoint from UI (DB columns kept) |
| 38 | FEATURE | `jd_builder.php`, `api/generate_campaign.php`, `api/save_from_jd.php` | AI Campaign Builder from JD |
| 39 | FIX | `apply.php`, `api/generate_campaign.php`, `api/save_from_jd.php` | Duplicate fields bug — expanded standard field exclusion list |
| 40 | FIX | `apply.php`, `api/apply.php` | Dynamic apply form enabled; `$is_dynamic_apply` was hardcoded false |
| 41 | FIX | `apply.php`, `api/apply.php` | Draft/paused campaigns now load correctly with preview banner |

---

### 2026-05-28 — Apply Form Config Architecture Rewrite

| # | Category | File(s) | What was fixed |
|---|----------|---------|----------------|
| 42 | FEATURE | `campaigns.php` | Standard Fields Toggle UI (40 fields, 9 sections, ON/OFF checkboxes) |
| 43 | FEATURE | `campaigns.php` | `save_apply_form_config` saves JSON to `campaigns.apply_form_config` (not application_fields table) |
| 44 | NOTE | `apply.php` | No changes needed — dynamic form already reads application_fields |
| 45 | FEATURE | `api/generate_campaign.php` | Mixed question types: Q1-4 MCQ, Q5-7 short_answer, Q8-10 voice_note |
| 46 | FEATURE | `api/save_from_jd.php` | Saves `options_json` for MCQ; prepends "Correct: X." to ideal_answer_hint |
| 47 | FEATURE | `jd_builder.php` | Question type selector badge, MCQ options UI, correct-answer input |
| 48 | FEATURE | `interview.php` | `dropdown` type renders as radio MCQ cards (not `<select>`) |
| 49 | BUG | `jd_builder.php` | MCQ hint replaced by correct-answer — fixed with named input selectors |
| 50 | BUG | `jd_builder.php` | `renumberQuestions()` didn't update `qopts-N` IDs → MCQ options saved empty |
| 51 | BUG | `jd_builder.php` | `getElementById('qopts-N')` → card-scoped `querySelector('.q-mcq-opts')` |
| 52 | BUG | `api/apply.php` | Null campaign bypassed status guard; removed hardcoded `id=1` fallback |
| 53 | BUG | `campaigns.php` | `save_apply_form_config` ran 80 queries without transaction — wrapped in transaction |
| 54 | BUG | `api/score.php` | `async=1` used user-supplied campaign_id; now uses DB-validated value + escapeshellarg |
| 55 | ARCH | `campaigns.php`, `apply.php` | Standard fields moved from application_fields table → campaigns.apply_form_config JSON |
| 56 | FIX | `campaigns.php` | `save_apply_form_config` rewritten to use JSON column |
| 57 | FIX | `campaigns.php` | Toggle state reads from JSON column |
| 58 | FIX | `apply.php` | `$is_dynamic_apply` fixed: wizard always used when std config exists |
| 59 | FEATURE | `apply.php` | `is_std_on($key)` PHP helper for conditional field rendering |
| 60 | FIX | `apply.php` | JS validators use `el(id)` helper to skip non-rendered fields |

---

### 2026-05-30 — UI Polish: Campaign Questions + Analytics Redesign

| # | Category | File(s) | What was changed |
|---|----------|---------|-----------------|
| 61 | FIX | `campaigns.php` | Edit Question modal — background scroll locked while open |
| 62 | UI | `campaigns.php` | Edit Question modal enlarged (max-width 700px) |
| 63 | UI | `campaigns.php` | Add Question inline form compacted |
| 64 | BUG | `campaigns.php` | Campaign Journey sidebar restored to right column (stray `</div>` fix) |
| 65 | FEATURE | `campaigns.php` | Add Question converted to modal (.aq-overlay) with body scroll lock |
| 66 | UI | `campaigns.php` | "ElevenLabs Agent" → "Your AI Agent" label (ElevenLabs later removed; Avya Dialer used instead) |
| 67 | REDESIGN | `analytics.php` | Full enterprise analytics page redesign (dark hero, glassmorphism KPIs, gradient charts) |

---

### 2026-06-01 — Date Picker, Duplicate Check, Campaign Deactivate, JD Builder Fixes

| # | Category | File(s) | What was fixed/added |
|---|----------|---------|---------------------|
| 68 | BUG | `apply.php` | Date picker (flatpickr) not working — flatpickr CSS+JS added directly to `<head>` (head.php not included) |
| 69 | FEATURE | `api/check_duplicate.php` | New endpoint: real-time phone/email duplicate check for apply form |
| 70 | FIX | `apply.php` | Duplicate phone/email → blocks Continue button with error; `nextSection()` made async |
| 71 | FEATURE | `campaigns.php` | Campaign deactivate: active → paused (Deactivate button on active campaigns) |
| 72 | FIX | `campaigns.php` | Campaign activation no longer blocked by missing custom fields; `$has_apply` checks apply_form_config OR defaults to true |
| 73 | FIX | `api/generate_campaign.php` | `maxOutputTokens`: 4096 → 16384 (was truncating 10-question JSON) |
| 74 | FIX | `api/generate_campaign.php` | Campaign name example changed to "Role Name at Company Name" (was getting "– 2024" suffix) |
| 75 | FIX | `jd_builder.php` | Branding: "Gemini" → "Avyukta AI" |
| 76 | FIX | `jd_builder.php` | Min 10 questions validation (was `< 1`) |
| 77 | FIX | `api/save_from_jd.php` | Auto-saves default apply_form_config after campaign creation (prevents activation-blocked bug) |
| 78 | FIX | `api/generate_campaign.php` | Prompt updated: exactly 10 questions (4 MCQ Q1-4, 3 short_answer Q5-7, 3 voice_note Q8-10) |

---

### 2026-06-01 — Face Detection + Interview Termination

| # | Category | File(s) | What was added |
|---|----------|---------|----------------|
| 79 | FEATURE | `api/check_face.php` | New endpoint: Gemini Vision face detection (POST token+image+question_no) |
| 80 | FEATURE | `interview.php` | Face Gate modal: identity verification before interview starts |
| 81 | FEATURE | `interview.php` | Client-side brightness pre-filter: canvas centre 50%×60% sample; <25 = dark → reject without API call |
| 82 | FEATURE | `interview.php` | `startFaceGateCheck()` — retries every 2.5-3s; 3 network failures → fallback pass |
| 83 | FEATURE | `interview.php` | `checkFaceOrTerminate(qNo, isRecheck)` — called after each question |
| 84 | FEATURE | `interview.php` | `showFaceWarning(qNo)` — 15s countdown then recheck (two-strike system) |
| 85 | FEATURE | `interview.php` | `terminateInterview(reason)` — shows #termination-screen, posts cheat_summary.terminated=true |
| 86 | FEATURE | `interview.php` | Camera track disconnect → immediate termination |
| 87 | FEATURE | `api/interview.php` | `complete_interview` saves cheat_summary JSON to `interview_sessions` |
| 88 | FEATURE | `candidates.php` | Red "🚫 Terminated" pill shown next to status badge when terminated |
| 89 | FEATURE | `candidate_detail.php` | Red termination banner + Integrity = Critical Risk + "Terminated — Face Not Detected" label |

---

### 2026-06-02 — Interview UI Overhaul + Voice Recording

| # | Category | File(s) | What was changed |
|---|----------|---------|-----------------|
| 90 | REDESIGN | `interview.php` | White/bright theme: `--bg:#F0F4F8`, `--surface:#FFFFFF`, `--text:#0F172A` |
| 91 | UI | `interview.php` | Permission screen: full-page two-column layout (`.perm-left` / `.perm-right`) |
| 92 | FIX | `interview.php` | Voice recording: removed 3s auto-start; now "Tap the button to start recording" |
| 93 | UI | `interview.php` | Completion screen: green "Close This Tab" button + guidance text |
| 94 | UI | `interview.php` | Header: org logo from `o.logo_url` or fallback `avyukta.in` logo |

---

### 2026-06-03 — DOB Validation + Terminated Badge + Disclaimer

| # | Category | File(s) | What was added |
|---|----------|---------|----------------|
| 95 | FEATURE | `apply.php` | DOB validation: must be ≥18 years old (maxDate = today − 18 years via flatpickr + JS) |
| 96 | UI | `interview.php` | Disclaimer for Test Participants section added to permission screen right panel (4 bullet points: Silent Mode, Wi-Fi, no calls, use laptop) |

---

### 2026-06-08 — Mobile Responsive Fixes

| # | Category | File(s) | What was fixed |
|---|----------|---------|----------------|
| 97 | BUG | `interview.php` | iOS auto-zoom on input focus: all inputs/textareas changed to `font-size:16px` (< 16px triggers zoom) |
| 98 | FIX | `interview.php` | `min-width:0` added to `.dynamic-answer` and all inputs to prevent flex overflow clipping |
| 99 | FIX | `interview.php` | `height:100dvh` (dynamic viewport height) — adjusts when mobile keyboard opens |
| 100 | FIX | `interview.php` | `visualViewport` resize listener — resizes body, scrolls focused input into view on keyboard open |
| 101 | FIX | `interview.php` | `-webkit-overflow-scrolling:touch` on `.main-scroll` for smooth iOS scrolling |
| 102 | UI | `interview.php` | Camera sidebar reduced: 200px → 140px (≤680px), 110px (≤480px) |
| 103 | UI | `interview.php` | New breakpoints at 480px and 380px: compact header, smaller card padding, touch-friendly tap targets |
| 104 | UI | `interview.php` | Permission screen responsive: 540px and 380px breakpoints for `.perm-left`/`.perm-right` padding, title size, grid columns |
| 105 | BUG | `apply.php` | Phone field layout: `.phone-grid` CSS class with responsive columns (210px → 130px on ≤540px, 110px on ≤380px) |
| 106 | FIX | `apply.php` | Country code combobox: `ccDisplayLabel()` shows compact "🇮🏼 +91" on mobile (≤540px), full label on desktop |
| 107 | FIX | `apply.php` | `text-overflow:ellipsis` on `.cc-input` prevents overflow of long country names |
| 108 | FIX | `apply.php` | `resize` event listener re-applies compact/full label on orientation change |

---

### 2026-06-08 — Training Guide + Nav Restriction

| # | Category | File(s) | What was changed |
|---|----------|---------|-----------------|
| 109 | FEATURE | `training.php` (NEW) | Complete in-app User Guide page — 17 sections covering all platform features |
| 110 | UI | `training.php` | Two-column layout: 232px sticky sidebar (scroll-spy, Font Awesome icons, grouped sections, print button) + scrollable content area |
| 111 | UI | `training.php` | Hero: dark blue→purple gradient with eyebrow badge and feature pill tags |
| 112 | UI | `training.php` | CSS component system: `card`, `tile` (feature tiles), `step` (numbered), `info` (color-coded), `wf-card`, `faq` (accordion), `flow` (pill chains), `tbl` |
| 113 | UI | `training.php` | Content: Platform Overview (3-col tiles), Quick Start (5 steps), Role Permissions table, Campaigns, AI Builder, Apply Form, Candidates, Outreach, Interview Flow, Face Detection, Results, AI Scoring, Analytics, User Mgmt, Credits, 3 Workflow guides, 9 FAQs |
| 114 | ROUTE | `index.php` | Added `'training' => 'training.php'` route |
| 115 | ACCESS | `includes/nav.php` | Guide link moved inside `super_admin` block — only Super Admin can see Guide, Audit Logs, and Admins |

---

### 2026-06-12 — Org-wide Duplicate Check Fix

| # | Category | File(s) | What was fixed |
|---|----------|---------|----------------|
| 116 | BUG | `api/candidates.php` | `candidate_duplicate_check()` now checks org-wide (all campaigns) instead of per-campaign; returns campaign name for better error message |
| 117 | BUG | `api/candidates.php` | Merged `candidate_duplicate_exists` + `candidate_duplicate_exists_for_update` into single `candidate_duplicate_check()` with optional `exclude_id`; all call sites updated to pass `org_id` |
| 118 | BUG | `api/check_duplicate.php` | Real-time apply form check now checks org-wide via JOIN on `campaigns.org_id` (was campaign-scoped only) |
| 119 | BUG | `api/apply.php` | Public form submission duplicate check now queries all candidates in org (was `WHERE campaign_id=?`); error message improved |

---

## Known Issues (open)

| # | Severity | Description | Fix needed |
|---|----------|-------------|------------|
| 1 | LOW | `schema.sql` still shows `num_questions INT DEFAULT 6` — live DB already has 10 as default for new campaigns | Update DEFAULT in schema.sql |

---

## How to Update This File

After every fix or change:

1. Add a row to the **Fixes Applied** table with date, category, file, and one-line description.
2. If a known issue is resolved, remove its row from **Known Issues**.
3. If a new issue is discovered but not yet fixed, add it to **Known Issues**.
4. If a new architectural rule is established, add it to **Architecture Rules**.

---

## File Size Reference

| File | Lines | Notes |
|------|-------|-------|
| `apply.php` | ~2765 | 9-step wizard + 2-step dynamic form; self-contained (no head.php) |
| `interview.php` | ~1721 | Full interview engine; self-contained; white theme; face detection |
| `campaigns.php` | ~2123 | Campaign CRUD + questions + apply-form config + deactivate |
| `candidates.php` | ~1474 | Paginated list + terminated badge |
| `candidate_detail.php` | ~1753 | Q&A + recording + termination banner + async scoring |
| `jd_builder.php` | ~600 | AI campaign builder UI |
| `analytics.php` | ~532 | Redesigned enterprise analytics |
| `api/score.php` | ~413 | Scoring pipeline (Vertex→Gemini→Groq) + async + transcription |
| `api/apply.php` | ~477 | Application submission |
| `training.php` | ~580 | In-app User Guide (super_admin only); 17 sections, sidebar nav, scroll-spy |
| `admins.php` | ~462 | User management |

---

## .env Keys That Must Exist

```
DB_HOST, DB_USER, DB_PASS, DB_NAME
JWT_SECRET                    # session JWTs
EXPORT_TOKEN_SECRET           # CSV export tokens (separate from JWT_SECRET)
INTERVIEW_WEBHOOK_SECRET      # interview session webhook HMAC
CALL_WEBHOOK_SECRET           # Avya dialer webhook HMAC
BASE_URL                      # e.g. https://hire.clouddialer.in
# EL_API_KEY, EL_AGENT_ID, EL_PHONE_NUMBER_ID  ← ElevenLabs disabled; Avya Dialer used instead
GEMINI_API_KEY, GEMINI_MODEL
VERTEX_AI_PROJECT, VERTEX_AI_LOCATION, VERTEX_AI_MODEL
GOOGLE_APPLICATION_CREDENTIALS
WA_API_URL, WA_INSTANCE_ID, WA_TOKEN
RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET
```
