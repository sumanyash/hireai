# HireAI — Project Structure & Wireframe (A–Z)

**App name:** Avyukta Intellicall AI Hire  
**Domain:** hire.clouddialer.in  
**Stack:** PHP 8.2 · MySQL · nginx · Vanilla JS  
**Root:** `/var/www/hire/`

---

## 1. Directory Layout

```
/var/www/hire/
├── index.php                  # Login page + fallback router
├── dashboard.php              # Main dashboard
├── campaigns.php              # Campaign CRUD + questions + apply-form
├── candidates.php             # Candidate list, bulk import, export
├── candidate_detail.php       # Single candidate view (Q&A / recording / AI call)
├── analytics.php              # Charts & funnel stats
├── outreach.php               # WhatsApp / AI-call outreach
├── credits.php                # Credit wallet & transactions
├── admins.php                 # User management (super_admin only)
├── audit_logs.php             # Audit trail (super_admin only)
├── apply.php                  # PUBLIC — candidate application form
├── interview.php              # PUBLIC — in-browser AI interview
├── video_view.php             # Authenticated video playback
├── export_candidate.php       # Single PDF/CSV export
├── export_candidates.php      # Bulk CSV export (token-auth)
├── logout.php                 # Clears session
│
├── api/
│   ├── apply.php              # POST: submit application
│   ├── auth.php               # POST: login (JWT)
│   ├── candidates.php         # add / bulk_import / update / bulk_delete
│   ├── interview.php          # create_session / save_answer / complete_interview / webhook
│   ├── outreach.php           # send_single / bulk_send / custom_whatsapp_send / trigger_ai_call / bulk_ai_call
│   ├── score.php              # CLI/cron AI scoring (Gemini/Vertex → fallback Groq)
│   ├── scores.php             # GET scores / POST override
│   ├── credits.php            # summary / buy / settings
│   ├── reminders.php          # schedule / send_due
│   ├── call_webhook.php       # Inbound AI-call result webhook (Avya dialer)
│   ├── change_password.php    # POST: change own password
│   ├── upload_audio.php       # POST: upload audio answer
│   └── upload_video.php       # POST: upload video answer
│
├── includes/
│   ├── config.php             # Constants (DB, JWT, API keys via .env)
│   ├── db.php                 # MySQLi helpers (get_db, db_fetch_all, db_fetch_one, db_execute)
│   ├── functions.php          # JWT, CSRF, auth, credits, WhatsApp send, audit_log
│   ├── helpers.php            # normalize_phone, HMAC verify, login lock, upload validation, pagination
│   ├── auth_check.php         # Validates JWT session → $user array
│   ├── head.php               # <meta> + CSS vars + base styles + navbar CSS
│   ├── nav.php                # <nav> HTML + Change Password modal
│   └── footer.php             # Closing tags + toast HTML
│
├── scripts/
│   └── rescore_once.php       # CLI: re-run AI scoring for candidates (--on-hold flag)
│
├── uploads/
│   ├── audio/                 # Voice answers (.webm)
│   ├── video/                 # Session recordings (.webm)
│   ├── videos/                # Legacy video path
│   ├── resumes/               # Uploaded resumes
│   └── application/           # Application file uploads
│
├── schema.sql                 # Full DB schema + seed data
├── .env                       # Runtime secrets (never committed)
├── .env.example               # Env template
├── nginx.conf                 # nginx site config snippet
├── DEPLOY.md                  # Deployment notes
├── FIXES.txt                  # Bug fix history (2026-05-28 batch)
└── .gitignore
```

---

## 2. Database Schema

### `organizations`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| name | VARCHAR(255) | |
| logo_url | TEXT | |
| is_active | TINYINT | |

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| org_id | INT FK | |
| name | VARCHAR(255) | |
| email | VARCHAR UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt cost 12 |
| role | ENUM | super_admin / admin / hr / recruiter |
| is_active | TINYINT | |

### `campaigns`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| org_id, created_by | INT FK | |
| name, job_role | VARCHAR | |
| description | TEXT | |
| share_token | VARCHAR(64) UNIQUE | public apply URL |
| start_date, end_date | DATE | optional window |
| integration_type | ENUM | none / crm / google_sheet |
| integration_endpoint | TEXT | webhook URL |
| el_agent_id | VARCHAR(150) | ElevenLabs voice agent |
| passing_score | INT | default 70 |
| max_duration_minutes | INT | default 15 |
| num_questions | INT | default 6 |
| language | ENUM | english / hinglish / hindi |
| status | ENUM | draft / active / paused / completed |

### `questions`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| campaign_id | INT FK | |
| parameter, parameter_label | VARCHAR | scoring dimension |
| weight, max_marks | INT | |
| question_text, ideal_answer_hint | TEXT | |
| question_type | ENUM | text/textarea/number/decimal/date/dropdown/multi_select/rating/file/audio/video/hyperlink |
| options_json, branch_rules_json | JSON | for choice/branching |
| is_required | TINYINT | |
| order_no | INT | |

### `application_fields`
Custom fields for the public apply form per campaign.  
Types: text/textarea/number/decimal/date/dropdown/multi_select/checkbox/email/phone/url/file

### `candidates`
Full candidate profile (~40 fields): name, phone, email, city, experience, CTC, skills, resume_path, video_path, status, unique_token, referral fields, application_answers_json, etc.  
Status ENUM: `pending → outreach_sent → interview_started → interview_completed → shortlisted / rejected / on_hold`

### `interview_sessions`
One row per interview attempt. Stores el_conversation_id, transcript, recording_url, duration, cheat_summary JSON.

### `interview_answers`
Per-question answers: text_answer, audio_url, answer_mode (voice/text), time_taken, copy_count, cheat_flags JSON.

### `scores`
Per-parameter AI scores: parameter, ai_score, max_marks, ai_reasoning, transcript.

### `interview_results`
Aggregated result per candidate: total_score/max_score, pass_fail, ai_summary, recruiter_override_score.

### `outreach_log`
Records every outreach send: candidate_id, campaign_id, channel (whatsapp/sms/email/call), status.

### `ai_call_results`
Inbound webhook payload from Avya AI dialer: call_id, transcript, summary, sentiment, interview_score, grade, recommendation, raw_payload.

### `audit_logs`
Org-scoped action trail: entity_type, entity_id, action, details JSON, user_id.

### `reminder_jobs`
Scheduled reminder queue: candidate_id, channel, status (pending/sent/failed/cancelled), scheduled_at, attempts.

### `recruiter_notes`
Free-text notes attached to a candidate by a user.

### `credit_wallets`
One row per org: whatsapp_credits, sms_credits, email_credits, rcs_credits, low_balance_threshold, auto_recharge_enabled.

### `credit_transactions`
Purchase records: provider (razorpay/paypal/payoneer/manual), amount, credits_json, status.

### `credit_usage`
Debit log per message sent: channel, credits_used, balance_after, reason.

---

## 3. Authentication Flow

```
index.php (login form)
  → POST → verify email+password → make_jwt() → $_SESSION['token']
  → redirect /dashboard

auth_check.php (included on every protected page)
  → verify_jwt($_SESSION['token']) → returns $user array
  → {user_id, org_id, role, name, email}
  → on failure → redirect /

JWT payload: {user_id, org_id, role, exp: +24h}
CSRF: session-based token, verified on all state-changing POSTs
Login lock: file-based per IP+email, 5 attempts → 15 min lockout
```

---

## 4. Page-by-Page Wireframe

### 4.1 index.php — Login
```
┌─────────────────────────────────────┐
│         Avyukta Intellicall Logo    │
│                                     │
│  ┌──────────────────────────────┐   │
│  │  Email                       │   │
│  │  Password          [Show]    │   │
│  │  [Sign In →]                 │   │
│  │  Error message (if any)      │   │
│  └──────────────────────────────┘   │
└─────────────────────────────────────┘
```
- Dark navy bg (#0A1628)
- Server-side rate limiting (5 attempts → lock)
- Redirects to /dashboard on success

---

### 4.2 dashboard.php — Overview
```
┌── NAVBAR ──────────────────────────────────────────────┐
│ Dashboard | Campaigns | Candidates | Analytics | …     │
└────────────────────────────────────────────────────────┘
┌── STAT CARDS (6) ──────────────────────────────────────┐
│  Campaigns | Candidates | Completed | Shortlisted |    │
│  Pending   | Rejected   | Success % rate              │
└────────────────────────────────────────────────────────┘
┌── 14-Day Activity Chart ──┐ ┌── Campaign Cards (6) ──┐
│  Line chart (Chart.js)    │ │  Name, status, counts  │
└───────────────────────────┘ └────────────────────────┘
┌── Recent Activity (paginated 8/page) ──────────────────┐
│  Avatar | Name | Campaign | Status | Score | Time ago  │
│  [← Prev]  Page X/Y  [Next →]   (AJAX-loaded)          │
└────────────────────────────────────────────────────────┘
```

---

### 4.3 campaigns.php — Campaign Management
**Actions:** `list | new | edit | questions | apply_form`

**list view:**
```
┌── [+ New Campaign] ────────────────────────────────────┐
│ Table: Name | Job Role | Status | Candidates | Dates   │
│  per row: [Edit] [Apply Form] [Questions]              │
│           [Share Link] [WhatsApp] [Clone] [Delete]     │
│ Bulk: [Select All] [Bulk Delete]                       │
└────────────────────────────────────────────────────────┘
```

**new/edit view:**
```
Form: Campaign Name, Job Role, Description
      Start/End Date, Language, Passing Score
      Max Duration, Num Questions
      Integration Type (none/crm/google_sheet)
      ElevenLabs Agent ID
      [Save]
```

**questions view:**
```
Setup checklist: ① Details ② Questions ③ Apply Form ④ Activate
┌── Question list header ──────────────────────────────┐
│  Interview Questions (N)  Total Weight: X%           │
│  [per page select]  [+ Add Question ▶ opens modal]   │
└──────────────────────────────────────────────────────┘
┌── Question table (paginated) ────────────────────────┐
│  Q# | Type | Weight | Max | Question | Logic         │
│  per row: [✏ Edit → opens modal] [🗑 Delete]         │
└──────────────────────────────────────────────────────┘
[Empty state card + "Add First Question" button when 0 Qs]

Edit Question modal (.eq-overlay, triggered by ?edit_qid=X):
  Answer Type, Required, Weight, Max Marks, Order
  Question Text, AI Scoring Criteria, MCQ Choices (conditional)
  Advanced — Branching Rules (collapsible)
  [Save Changes]  [Cancel]  [Delete]

Add Question modal (.aq-overlay, JS-driven, no page reload):
  Same fields as edit modal
  [+ Add Question]  [Cancel]
  Body scroll locked while open; auto-focuses question text
┌── Campaign Journey sidebar (sticky right col 300px) ─┐
│  Progress ring, checklist steps, stats grid          │
└──────────────────────────────────────────────────────┘
```

**apply_form view:**
```
┌── Application Fields list ───────────────────────────┐
│  Order | Label | Type | Required | [Del]              │
│  [Add Template Fields] [Bulk Delete]                  │
└──────────────────────────────────────────────────────┘
Add Field form: label, field_key, type, placeholder, options
```

---

### 4.4 candidates.php — Candidate List
```
┌── Toolbar ───────────────────────────────────────────┐
│  [Campaign filter] [Status pills: All/Pending/…]     │
│  [Search]  [Columns ▾]  [Export CSV]  [+ Add]        │
└──────────────────────────────────────────────────────┘
┌── Status pills (count badges) ───────────────────────┐
│  All | Pending | Outreach Sent | Interview Started   │
│  Completed | Shortlisted | Rejected | On Hold        │
└──────────────────────────────────────────────────────┘
┌── Candidate table (paginated, toggleable columns) ───┐
│  ☐ | Avatar/Name | Phone | Campaign | Status         │
│      Score | City | Experience | Actions             │
│  [View] [Send WA] [Start Call]                       │
└──────────────────────────────────────────────────────┘
┌── Add Candidate Modal ───────────────────────────────┐
│  Tabs: [Single] [Bulk CSV]                           │
│  Single: Name*, Phone*, Email, Campaign              │
│  Bulk: CSV textarea or file upload                   │
└──────────────────────────────────────────────────────┘
```
- Column visibility persisted in localStorage (`hireai_candidate_table_state_v2`)
- Export uses HMAC-signed token (EXPORT_TOKEN_SECRET), not JWT

---

### 4.5 candidate_detail.php — Candidate Profile
```
┌── Header: Name, Phone, Campaign, Status badge ───────┐
│  [Change Status] [Edit] [Trigger Call] [Delete]      │
│  Score ring (total/100), Pass/Fail badge             │
│  Integrity banner (tab switches, paste events)       │
└──────────────────────────────────────────────────────┘
┌── Tabs ──────────────────────────────────────────────┐
│  [Q&A (N)]  [Recording]  [AI Call]                   │
└──────────────────────────────────────────────────────┘
Q&A tab:
  Per-question rows: parameter, score bar, transcript,
  audio player (inline), recruiter override input

Recording tab:
  Floating video player (recording_url or uploads/video/)
  [Open in new tab]

AI Call tab:
  Call result: score, grade, recommendation
  Transcript, summary, sentiment, strengths/improvements

Modals: [Call Modal] [Status Modal] [Edit Candidate Modal]
        [Delete Confirm]

Recruiter Notes section (bottom)
Schedule Reminder button → POST /api/reminders.php
```

---

### 4.6 analytics.php — Analytics
```
┌── HERO (dark gradient #0D1B2E→#2D1B69) ──────────────┐
│  "Analytics Dashboard"  [Campaign badge] [Period badge]│
│  Filters: [Campaign dropdown] [All|7d|30d|90d]        │
│  ┌── 4 KPI glassmorphism cards ──────────────────────┐│
│  │ Total Candidates | Avg Score | Completion % | SR% ││
│  └───────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────┘
┌── Candidate Funnel (5-step card) ────────────────────┐
│  Imported → Invited → Started → Completed → Shortlisted│
│  Each step: icon, count, % of total, progress bar     │
│  Conversion badge (green/amber) between steps         │
│  Chevron arrows between steps                         │
└──────────────────────────────────────────────────────┘
┌── Score Distribution ──┐  ┌── Completion Trend ──────┐
│  Bar chart (5 buckets) │  │  Line chart (14 days)    │
│  Color-coded red→green │  │  Purple gradient fill     │
│  High/Low/Avg badges   │  │  Dark tooltip on hover    │
└────────────────────────┘  └──────────────────────────┘
┌── AI Insights ─────────┐  ┌── Weakest Parameters ────┐
│  4 insight cards:      │  │  Horizontal bars per Q   │
│  Drop-off, Selection,  │  │  Color: red<40 amber<65  │
│  Score, Completion     │  │  green≥65 with sample cnt │
│  (green/amber/red/blue)│  └──────────────────────────┘
└────────────────────────┘
```

---

### 4.7 outreach.php — Outreach Console
```
┌── Campaign selector ─────────────────────────────────┐
┌── Candidate table (pending/outreach_sent filter) ────┐
│  ☐ | Name | Phone | Status | [Send WA] [AI Call]    │
│  [Select All] [Bulk Send WA] [Bulk AI Call]          │
└──────────────────────────────────────────────────────┘
┌── WhatsApp Composer ─────────────────────────────────┐
│  Message type, header, body, footer, button/section  │
│  [Send to Selected]                                  │
└──────────────────────────────────────────────────────┘
```
- WA API: wa.clouddialer.in (custom WA gateway)
- AI Call: ElevenLabs outbound via Avya dialer

---

### 4.8 credits.php — Credit Wallet
```
┌── Balance cards (4): WhatsApp | SMS | Email | RCS ───┐
│  Low balance warning banner                         │
┌── Buy Credits form ──────────────────────────────────┐
│  Channel selector, amount, [Razorpay / PayPal / …]  │
└──────────────────────────────────────────────────────┘
┌── Transaction History (paginated) ──────────────────┐
┌── Credit Usage Log (paginated) ─────────────────────┐
│  Candidate | Campaign | Channel | Used | Balance    │
└──────────────────────────────────────────────────────┘
```

---

### 4.9 admins.php — User Management (super_admin only)
```
┌── Users table ───────────────────────────────────────┐
│  Name | Email | Role | Active | [Toggle] [Reset PW]  │
└──────────────────────────────────────────────────────┘
┌── Create Admin form ─────────────────────────────────┐
│  Name, Email, Password, Role (admin/hr/recruiter)    │
│  [Create]                                            │
└──────────────────────────────────────────────────────┘
┌── Change Own Password section ──────────────────────┐
Actions: create_admin / toggle_active / reset_password /
         update_role / change_own_password
```

---

### 4.10 audit_logs.php — Audit Trail (super_admin only)
```
┌── Filters: Entity type | Action | Actor | Search ────┐
┌── Audit table (paginated) ───────────────────────────┐
│  Time | Actor | Entity | ID | Action | Details JSON  │
└──────────────────────────────────────────────────────┘
```

---

### 4.11 apply.php — PUBLIC Application Form
```
Access: /apply.php?campaign_id=X  OR  /apply.php?c=share_token
        Referral: ?ref=unique_token

9-step wizard:
  Step 1: Campaign selection (if no campaign_id)
  Step 2: Personal info (salutation, name, DOB, city)
  Step 3: Contact & location (phone, email, relocate)
  Step 4: Experience & employment (years, type, industry)
  Step 5: Compensation (current/expected CTC/salary)
  Step 6: Skills & preferences (tech skills, soft skills)
  Step 7: Logistics (laptop, internet, commute, flex hours)
  Step 8: Dynamic application fields (campaign-specific)
  Step 9: Resume/video upload, portfolio, AI test consent
  Submit → /api/apply.php → auto WhatsApp outreach
```

---

### 4.12 interview.php — PUBLIC AI Interview
```
Access: /interview.php?t=unique_token

Flow:
  1. Token validation → load candidate + campaign + questions
  2. Camera/mic permission request
  3. Create session (api/interview.php?action=create_session)
  4. Questions loop (branch logic supported):
     - Voice answer (MediaRecorder → upload_audio.php)
     - OR Text answer
     - Timer per question
     - Anti-cheat: tab-switch + paste logging (silent)
  5. Video recording (session-level, MediaRecorder → upload_video.php)
  6. Complete → POST complete_interview → score trigger (exec)
  7. Referral share screen

Guards: link_expires_at check, already-completed check,
        on_hold handled as "completed" screen
```

---

### 4.13 video_view.php — Recording Viewer
- Auth required, org-scoped check
- Renders `recording_url` from interview_sessions or falls back to uploads/video/session_X_*.webm
- `<video>` tag with controls

---

## 5. API Endpoints Reference

| Endpoint | Methods | Key Actions |
|----------|---------|-------------|
| `api/apply.php` | POST | Submit application, auto-send WA |
| `api/auth.php` | POST | `login` → JWT |
| `api/candidates.php` | POST | `add`, `bulk_import`, `update`, `bulk_delete` |
| `api/interview.php` | GET/POST | `create_session`, `save_answer`, `complete_interview`, `webhook` (ElevenLabs), `start_call`, `bulk_start`, `get_agents` |
| `api/outreach.php` | GET/POST | `send_single`, `bulk_send`, `custom_whatsapp_send`, `trigger_ai_call`, `bulk_ai_call`, `call_campaign`, `whatsapp_status`, `send_test` |
| `api/score.php` | CLI/GET | Score one candidate (Vertex AI → Gemini API → Groq fallback) |
| `api/scores.php` | GET/POST | `get` scores, `override` score |
| `api/credits.php` | GET/POST | `summary`, `buy`, `settings` |
| `api/reminders.php` | POST/GET | `schedule`, `send_due` |
| `api/call_webhook.php` | POST | Inbound Avya dialer result |
| `api/change_password.php` | POST | Change own password |
| `api/upload_audio.php` | POST | Save voice answer |
| `api/upload_video.php` | POST | Save session recording |

---

## 6. Includes Reference

| File | Purpose |
|------|---------|
| `config.php` | `define()` constants from .env: DB_*, JWT_SECRET, BASE_URL, API keys |
| `db.php` | `get_db()` singleton, `db_fetch_all()`, `db_fetch_one()`, `db_execute()` |
| `functions.php` | `make_jwt()`, `verify_jwt()`, `require_auth()`, `csrf_token()`, `verify_csrf_or_die()`, `audit_log()`, `ensure_credit_wallet()`, `deduct_credit()`, `add_credits()`, `send_whatsapp()`, `send_whatsapp_content()` |
| `helpers.php` | `normalize_phone()`, `verify_hmac_signature()`, `login_lock_*()`, `upload_safe_extension()`, `detect_uploaded_mime()`, `validate_integration_endpoint()`, `pagination_*()` |
| `auth_check.php` | Calls `require_auth()` → populates `$user`, redirects on failure |
| `head.php` | CSS vars, base styles, navbar CSS, Font Awesome, Inter font |
| `nav.php` | Top navigation bar HTML + Change Password modal + JS |
| `footer.php` | Toast HTML, closing body/html |

---

## 7. External Integrations

| Service | Purpose | Config Keys |
|---------|---------|-------------|
| ElevenLabs | AI voice interview agent (outbound + webhook) | `EL_API_KEY`, `EL_AGENT_ID`, `EL_PHONE_NUMBER_ID`, `INTERVIEW_WEBHOOK_SECRET` |
| Avya AI Dialer | Inbound call result webhook | `CALL_WEBHOOK_SECRET` |
| Vertex AI / Gemini | AI answer scoring (primary) | `VERTEX_AI_PROJECT`, `VERTEX_AI_LOCATION`, `VERTEX_AI_MODEL`, `GOOGLE_APPLICATION_CREDENTIALS` |
| Gemini API | AI scoring fallback | `GEMINI_API_KEY`, `GEMINI_MODEL` |
| Groq (LLaMA 3.3-70b) | AI scoring fallback #2 | Uses `OPENAI_API_KEY` env var |
| WhatsApp Gateway | Outreach messages | `WA_API_URL`, `WA_INSTANCE_ID`, `WA_TOKEN` |
| Razorpay | Credit purchases | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET` |
| PayPal | Credit purchases | `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` |
| Payoneer | Credit purchases | `PAYONEER_PROGRAM_ID` |

---

## 8. Role Permissions

| Feature | super_admin | admin | hr | recruiter |
|---------|-------------|-------|----|-----------|
| Create/edit campaigns | ✓ | ✓ | — | — |
| View campaigns | ✓ | ✓ | ✓ | ✓ |
| Add/import candidates | ✓ | ✓ | ✓ | ✓ |
| Bulk delete | ✓ | ✓ | ✓ | ✓ |
| Override scores | ✓ | ✓ | ✓ | ✓ |
| View credits | ✓ | ✓ | ✓ | ✓ |
| Buy credits | ✓ | ✓ | ✓ | ✓ |
| Manage admins | ✓ | — | — | — |
| View audit logs | ✓ | — | — | — |

---

## 9. Candidate Status Flow

```
pending
  ↓ (WhatsApp sent)
outreach_sent
  ↓ (candidate opens interview link)
interview_started
  ↓ (interview completed)
interview_completed
  ↓ (AI scoring)
shortlisted  ←→  rejected
       ↕
     on_hold  (scoring failed; rescored nightly via cron)
```

---

## 10. Scoring Pipeline

```
interview completed
  → exec("php api/score.php candidate_id campaign_id") in background
  → for each question with a gradable answer:
      1. Try Vertex AI (gemini-2.5-flash)
      2. Fallback: Gemini API (GEMINI_MODEL)
      3. Fallback: Groq LLaMA-3.3-70b
      → INSERT INTO scores (ai_score, ai_reasoning, transcript)
  → INSERT INTO interview_results (total_score, pass_fail, ai_summary)
  → UPDATE candidates SET status = shortlisted / rejected / on_hold
  → Send WhatsApp result notification

Cron: 2am daily → php scripts/rescore_once.php --on-hold --delay=20
      Logs to /tmp/rescore_onhold.log
```

---

## 11. Key Conventions

- All DB access via MySQLi prepared statements (db_fetch_all / db_fetch_one / db_execute)
- All state-changing POSTs require `verify_csrf_or_die()`
- API endpoints return JSON via `json_response()`
- Uploads validated by MIME sniffing (`detect_uploaded_mime`) + extension whitelist
- Phone numbers normalized with `normalize_phone()` (strips to digits, optional +91 prefix)
- Pagination: `pagination_page()`, `pagination_per_page()`, `pagination_html()`
- All user-facing strings HTML-escaped with `htmlspecialchars()`
- Credit deduction atomic via `WHERE balance >= ?` + `affected_rows` check
