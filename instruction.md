# HireAI — Session Instructions (READ THIS FIRST)

> **Read this file at the start of every prompt before touching any code.**  
> Update the "Fixes Applied" and "Known Issues" sections after every change.

---

## Quick Reference

- **Root:** `/var/www/hire/`
- **DB:** MySQL `hireai` via MySQLi (see `includes/db.php`)
- **Auth:** JWT in `$_SESSION['token']` · CSRF tokens on all POSTs · Login lock in flat file
- **AI scoring:** Vertex AI → Gemini API → Groq (fallback chain, see `api/score.php`)
- **Outreach:** WhatsApp via `WA_API_URL` (wa.clouddialer.in) · ElevenLabs for AI calls
- **Credits:** `credit_wallets` table, deduction is atomic via `WHERE balance >= ?` + `affected_rows`
- **Export token:** Uses `EXPORT_TOKEN_SECRET` (separate from `JWT_SECRET`)
- **Public pages (no auth):** `apply.php`, `interview.php`, `api/apply.php`, `api/call_webhook.php`
- **super_admin only:** `admins.php`, `audit_logs.php`

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
| 18 | DB | `users` table + `schema.sql` | Added `admin` to role ENUM (was missing, only super_admin/hr/recruiter existed) |
| 19 | SCHEMA | `schema.sql` | Added missing `ai_call_results` table definition |
| 20 | CODE | `api/score.php` | Moved `log_s()` to top of file; removed duplicate at bottom |
| 21 | CODE | `includes/helpers.php` | Canonical `normalize_phone()` moved here; removed duplicate in `candidates.php` |
| 22 | CODE | `includes/functions.php` | Dead `score_candidate()` (OpenAI) wrapped in block comment |
| 23 | NGINX | `nginx.conf` | Added security headers: X-Frame-Options, X-Content-Type-Options, X-XSS-Protection, Referrer-Policy |
| 24 | NGINX | `nginx.conf` | Blocked PHP execution in `/uploads/` directory |
| 25 | NGINX | `nginx.conf` | `client_max_body_size` reduced from 150M → 25M to match PHP `upload_max_filesize` |
| 26 | AUTO | `scripts/rescore_once.php`, crontab | Added `--on-hold` flag; cron at 2am daily, logs to `/tmp/rescore_onhold.log` |
| 27 | OPS | live DB | Rescored 15 stuck `on_hold` candidates via Groq (Vertex AI was 404) |

---

### 2026-05-28 — Voice AI Analysis + Sort feature

| # | Category | File(s) | What was fixed/added |
|---|----------|---------|---------------------|
| 28 | FEATURE | `api/score.php` | Added `transcribe_one` action: `GET ?action=transcribe_one&answer_id=X&candidate_id=Y` — transcribes a single voice answer via Groq Whisper, saves transcript to `interview_answers.text_answer`, returns `{success, transcript}`. Requires JWT auth + org ownership check. |
| 29 | FEATURE | `candidate_detail.php` | Per-answer **"Analyze with AI"** button on every voice note that has no transcript yet (`!$ansText`). Clicking: (1) transcribes via `transcribe_one`, (2) shows transcript inline in the answer card, (3) auto-triggers full rescore, (4) reloads page. |
| 30 | FEATURE | `candidate_detail.php` | **Q&A Sort bar** above the answer list: "Question #" (default), "Score High→Low", "Score Low→High". Client-side sort using `data-order` and `data-score` on each `.qa-item`. Sort state resets on page reload. |
| 31 | FEATURE | `api/score.php` | Added `transcribe_voice_gemini()` using Vertex AI service account (same credentials as scoring). Added unified `transcribe_voice()` — tries Gemini first, falls back to Groq Whisper. All transcription call sites (loop + transcribe_one action) now use `transcribe_voice()`. Groq remains as fallback. |
| 32 | REFACTOR | `api/score.php` | Extracted `resolve_audio_local_path()` helper — shared by Groq and Gemini transcription functions. |
| 33 | FIX | `api/score.php` | Added `?async=1` action: fires `exec("php score.php $cid $cmp &")` and returns `{status:queued}` immediately. Prevents browser/nginx timeout on long scoring operations. Requires JWT + org ownership check. |
| 34 | FIX | `candidate_detail.php` | `analyzeVoice()` now uses async scoring (fire-and-forget fetch `?async=1`) instead of waiting for full rescore. Page reloads after 12s. Eliminates "Network error" caused by chained 60-90s requests. |
| 35 | FIX | `candidate_detail.php` | `rescoreCandidate()` (Score Voice button) switched to `?async=1` — returns in <1s, reloads after 12s. |
| 41 | FIX | `apply.php`, `api/apply.php` | **Draft campaign apply form fix**: apply.php was only loading campaigns with `status='active'` — draft/paused campaigns returned null → no fields → legacy 9-step form. Changed queries to load `status IN ('active','draft','paused')`. Draft campaigns show a yellow preview banner; paused shows red. `api/apply.php` blocks actual submission for non-active campaigns. |
| 40 | FIX | `apply.php`, `api/apply.php` | **Dynamic apply form enabled**: `$is_dynamic_apply` was hardcoded `false` — changed to `!empty($application_fields)`. Now any campaign with configured application_fields gets its own clean 2-step form (all fields → declaration). Legacy 9-step form remains for campaigns with no fields. Also: injected mandatory phone/email/name inputs when missing from campaign fields; fixed `api/apply.php` to make DOB, joining_date, expected_salary optional (only validate format if submitted — was always required before). |
| 39 | FIX | `apply.php`, `api/generate_campaign.php`, `api/save_from_jd.php` | **Duplicate fields bug**: apply.php's `$default_apply_keys` was missing common aliases (`full_name`, `experience_years`, `current_ctc`, etc.) so AI-generated fields duplicated standard form steps. Fixed: (1) expanded `$default_apply_keys` with all common variants; (2) updated AI prompt to never generate standard fields; (3) `save_from_jd.php` now server-side rejects any field_key in the standard list. Also cleaned campaign 21's application_fields — removed 6 duplicate fields, kept 3 role-specific ones. |
| 38 | FEATURE | `jd_builder.php`, `api/generate_campaign.php`, `api/save_from_jd.php` | **AI Campaign Builder from JD**: Admin pastes Job Description → Gemini generates campaign name, job role, description, 6 voice interview questions (weights summing to 100), and 6-9 application form fields. Phase 2 preview lets admin inline-edit everything before saving. One click creates campaign + questions + form in DB. Route: `/jd_builder`. Cost: ~₹0.06/generation (Gemini 2.5 Flash). |
| 37 | REMOVED | `campaigns.php` | Permanently removed "Where should applications sync?" (integration_type dropdown) and "Connection URL / Sheet GID" (integration_endpoint input) from new/edit campaign form. Hidden inputs send `none`/empty to keep DB writes valid. Setup checklist CRM step removed, `integration_pending` hardcoded `false`, pending alert and activate-button message removed. Backend DB columns kept untouched. |
| 36 | NOTE | `api/interview.php` | New interview completion (`complete_interview` action) ALREADY auto-runs score.php via `exec` in background. With Gemini transcription now in score.php, voice answers are transcribed+scored automatically for all new interviews — no admin action needed. Old candidates still have manual buttons. |

---

### 2026-05-28 — Apply Form Config + Mixed Question Types

| # | Category | File(s) | What was fixed/added |
|---|----------|---------|---------------------|
| 42 | FEATURE | `campaigns.php` | **Standard Fields Toggle UI**: Apply Form view now shows all 40 standard fields (salutation, name, phone, email, DOB, city, relocate, experience, compensation, availability, work readiness, documents, consent) organized in 9 sections, each with an ON/OFF toggle checkbox. Replaces the old "Add Complete Apply Form" one-shot button. |
| 43 | FEATURE | `campaigns.php` | **`save_apply_form_config` POST action**: Upserts standard fields in `application_fields` table based on toggle state — INSERT if enabled+not-exists, UPDATE `is_active=1` if enabled+exists, `is_active=0` if disabled. Custom fields (non-standard) are never touched. Fields default to ON when no config exists yet. |
| 44 | NOTE | `apply.php` | No changes needed — the existing dynamic form already reads `application_fields` for the campaign and renders exactly those fields. The toggle UI + save handler is the only change needed. |
| 45 | FEATURE | `api/generate_campaign.php` | **Mixed question types in JD AI generation**: Updated prompt to enforce Q1=MCQ, Q2=MCQ, Q3=short_answer, Q4=short_answer, Q5=voice_note, Q6=voice_note. Each question now has a `question_type` field (`mcq`/`short_answer`/`voice_note`). MCQ questions include `options` array (4 choices) and `correct_answer`. Response normalization maps: `mcq→dropdown`, `short_answer→textarea`, `voice_note→audio`. |
| 46 | FEATURE | `api/save_from_jd.php` | Saves `options_json` for MCQ (dropdown) questions. Prepends `"Correct: X."` to `ideal_answer_hint` so AI scorer knows the right answer. INSERT now includes `options_json` column (type string `'issiissssi'`). |
| 47 | FEATURE | `jd_builder.php` | Question cards now show a **Type selector** (MCQ/Short Answer/Voice Note) with color-coded badges. MCQ cards show expandable options textarea (4 lines) and correct-answer input. `collectData()` reads raw_type, options[], correct_answer from DOM and sends them to `save_from_jd.php`. |
| 48 | FEATURE | `interview.php` | `dropdown` question type now renders as **A/B/C/D radio-button MCQ cards** (using existing `choice-list`/`choice-item` styles) instead of a `<select>` dropdown, when options are available. Falls back to `<select>` if no options exist. |

---

### 2026-05-29 — Code Review Bug Fixes (6 items)

| # | Severity | File(s) | What was fixed |
|---|----------|---------|----------------|
| 49 | BUG | `jd_builder.php` | **MCQ hint silently replaced by correct-answer**: `collectData()` used `metas[metas.length-1]` to read the hint input, but `.q-mcq-opts` always in DOM adds two extra `.q-meta-input` elements (options textarea + correct-answer input) making `metas[5]` = correct-answer. Fixed: added `name="q-param"`, `name="q-label"`, `name="q-hint"` to meta inputs; `collectData()` now uses named `querySelector` (`input[name="q-hint"]`) instead of positional index. |
| 50 | BUG | `jd_builder.php` | **`renumberQuestions()` didn't update `qopts-N` IDs**: after removing a question, card indices shift but `qopts-N` div IDs stayed at original values, causing `getElementById('qopts-'+newIndex)` to return null → MCQ options and correct_answer saved empty. Fixed: `renumberQuestions()` now also updates `optsDiv.id = 'qopts-'+i` and the `onchange="onQTypeChange(this,N)"` attribute on the type select. |
| 51 | BUG | `jd_builder.php` | **`onQTypeChange` and `collectData` used `getElementById('qopts-N')` which is brittle**: switched both to card-scoped `card.querySelector('.q-mcq-opts')` — immune to ID drift even if numbering is momentarily inconsistent. |
| 52 | BUG | `api/apply.php` | **Null campaign bypassed status guard**: when `campaign_id=0` and no active campaign existed, code fell back to hardcoded `id=1`; if campaign 1 didn't exist `$campaign` was null, the status check `if ($campaign && ...)` was skipped, and INSERT proceeded with `org_id=1`. Fixed: removed the `id=1` hardcoded fallback; now fails with `apply_fail('No active campaign...')` immediately if no active campaign exists or if the given `campaign_id` resolves to null. |
| 53 | BUG | `campaigns.php` | **`save_apply_form_config` ran 80 queries without a transaction**: a mid-loop DB error left `application_fields` partially updated. Fixed: wrapped the entire loop in `$db->begin_transaction()` / `commit()` / `rollback()` with error redirect on failure. |
| 54 | BUG | `api/score.php` | **`async=1` passed user-supplied `$_GET['campaign_id']` to `exec()` instead of the DB-validated value**: an org user could supply any campaign_id to trigger bulk background processes. Fixed: SELECT now fetches `campaign_id` from the candidates row; `$db_campaign_id = (int)$cand_chk['campaign_id']` is used for exec; also added `escapeshellarg()` wrappers around both `$candidate_id` and `$db_campaign_id` in the exec string. |

---

### 2026-05-30 — Apply Form Config Architecture Rewrite + Multi Bug Fix

| # | Category | File(s) | What was fixed |
|---|----------|---------|----------------|
| 55 | ARCH | `campaigns.php`, `apply.php` | **Wrong approach for std fields**: Previous implementation stored standard fields in `application_fields` table → caused duplicate rows (74 instead of ~40), broke `$is_dynamic_apply` → killed the 9-step wizard, made unchecking ineffective (dupes survived disable). |
| 56 | FIX | `campaigns.php` | **`save_apply_form_config` rewritten**: Now stores enabled field keys as JSON in `campaigns.apply_form_config` (column added via `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`). Also DELETES all standard field rows from `application_fields` for the campaign (cleanup). No more application_fields mutations for standard fields. |
| 57 | FIX | `campaigns.php` apply_form view | **Toggle state reads from JSON column**: `$active_std_keys` and `$std_never_saved` now derived from `$campaign['apply_form_config']` (JSON parse), not from `$application_fields` rows. |
| 58 | FIX | `apply.php` | **`$is_dynamic_apply` fixed**: Was `!empty($application_fields)` which was true whenever standard fields were wrongly in the table → 2-step form. Now: `!$_has_std_form_cfg && !empty($custom_application_fields)` — 9-step wizard always used when std field config exists; 2-step form only for JD-builder campaigns with custom fields and no std config. |
| 59 | FEATURE | `apply.php` | **`is_std_on($key)` PHP helper**: Returns `true` if the field key is enabled in `campaigns.apply_form_config`, or `true` by default if no config is saved. Each standard field in sections 1-8 of the 9-step wizard is now wrapped in `<?php if (is_std_on('key')): ?>...<?php endif; ?>`. |
| 60 | FIX | `apply.php` | **JS validators fixed with `el(id)` helper**: Added `function el(id) { return !!document.getElementById(id); }`. All section validators (1-8) now check `el('fieldId') &&` before validating required fields — prevents "required" errors for fields that are not rendered by PHP (toggled off). |

---

### 2026-05-30 — UI Polish: Campaign Questions + Analytics Redesign

| # | Category | File(s) | What was changed |
|---|----------|---------|-----------------|
| 61 | FIX | `campaigns.php` | **Edit Question modal — background scroll locked**: Added `document.body.style.overflow='hidden'` on modal open and restored on close/Escape. Background page no longer scrolls while modal is visible. |
| 62 | UI | `campaigns.php` | **Edit Question modal enlarged**: `max-width` 620px → 700px, border-radius 20px → 22px, internal padding increased to 28px in head/body/footer. |
| 63 | UI | `campaigns.php` | **Add Question inline form compacted**: `qf-body` padding 20px → 16px 20px, grid gap 14px → 12px, all row margins 14px → 12px, question text textarea rows 3 → 2. Reduces page scroll length. |
| 64 | BUG | `campaigns.php` | **Campaign Journey sidebar restored to right column**: Stray `</div>` was closing the `.journey-grid` container before the `<aside class="journey-card">`, causing it to render full-width below content. Removed the extra `</div>` so the aside is correctly the second grid column. |
| 65 | FEATURE | `campaigns.php` | **Add Question converted to modal**: Removed inline `qf-card` form. Added big purple "+ Add Question" button in the questions table header. Added empty-state card when no questions exist. Built `.aq-overlay/.aq-modal` JS-driven modal (720px, same style as edit modal) with `openAddModal()` / `closeAddModal()`, body scroll lock, Escape key, and auto-focus on question text field. |
| 66 | UI | `campaigns.php` | **"ElevenLabs Agent" → "Your AI Agent"**: Renamed label on the Edit Campaign form. |
| 67 | REDESIGN | `analytics.php` | **Full enterprise analytics page redesign**: Dark gradient hero banner (`#0D1B2E→#2D1B69`) with 4 glassmorphism KPI cards (Total Candidates, Avg Score, Completion Rate, Selection Rate). Campaign/time filters styled for dark theme. Horizontal funnel with per-step conversion badges (green/amber), chevron arrows, color-coded icons. Chart.js charts enlarged to 280px with gradient fills, dark tooltips, and bold tick labels. AI Insights panel with contextual color-coded cards (green/amber/red/blue) based on actual data thresholds. Weakest Parameters with gradient bar fills and sample counts. Fully responsive. |

---

## Known Issues (open)

| # | Severity | Description | Fix needed |
|---|----------|-------------|------------|
| 1 | MEDIUM | Vertex AI returning 404 — `gemini-2.0-flash-001` not found in project `symbolic-surf-471213-m4` | Update `VERTEX_AI_MODEL` in `.env` to a valid model, or re-enable Vertex AI API in Google Cloud Console. Scoring currently falls back to Groq successfully. |

---

## How to Update This File

After every fix or change:

1. Add a row to the **Fixes Applied** table with date, category, file, and one-line description.
2. If a known issue is resolved, remove its row from **Known Issues**.
3. If a new issue is discovered but not yet fixed, add it to **Known Issues**.
4. If a new architectural rule is established, add it to **Architecture Rules**.

---

## File Size Reference (for context on what's large)

| File | Size | Notes |
|------|------|-------|
| `apply.php` | ~100 KB | 9-step multi-page public form |
| `campaigns.php` | ~98 KB | All campaign CRUD + question builder |
| `candidate_detail.php` | ~89 KB | Detail view with inline audio/video |
| `candidates.php` | ~74 KB | Paginated list with column toggles |
| `interview.php` | ~60 KB | Full in-browser interview engine |
| `outreach.php` | ~26 KB | WhatsApp + AI call console |
| `credits.php` | ~17 KB | Wallet + transactions |
| `admins.php` | ~18 KB | User management |
| `includes/functions.php` | medium | Core utilities |
| `includes/helpers.php` | medium | Security + login lock + pagination |

---

## .env Keys That Must Exist

```
DB_HOST, DB_USER, DB_PASS, DB_NAME
JWT_SECRET                    # session JWTs
EXPORT_TOKEN_SECRET           # CSV export tokens (separate from JWT_SECRET)
INTERVIEW_WEBHOOK_SECRET      # ElevenLabs webhook HMAC
CALL_WEBHOOK_SECRET           # Avya dialer webhook HMAC
BASE_URL                      # e.g. https://hire.clouddialer.in
EL_API_KEY, EL_AGENT_ID, EL_PHONE_NUMBER_ID
GEMINI_API_KEY, GEMINI_MODEL
VERTEX_AI_PROJECT, VERTEX_AI_LOCATION, VERTEX_AI_MODEL
GOOGLE_APPLICATION_CREDENTIALS
WA_API_URL, WA_INSTANCE_ID, WA_TOKEN
RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET
```
