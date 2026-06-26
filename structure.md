# HireAI — Project Structure & Wireframe (A–Z)

**App name:** Avyukta Intellicall AI Hire  
**Domain:** hire.clouddialer.in  
**Stack:** PHP 8.2 · MySQL · nginx · Vanilla JS  
**Root:** `/var/www/hire/`

---

## 1. Directory Layout

```
/var/www/hire/
├── index.php                  # Login page + router (also routes forgot/reset_password)
├── dashboard.php              # Main dashboard
├── campaigns.php              # Campaign CRUD + questions + apply-form + deactivate
├── candidates.php             # Candidate list (shows Terminated badge), bulk import, export
├── candidate_detail.php       # Single candidate view + termination banner + integrity section
├── analytics.php              # Charts & funnel stats
├── outreach.php               # WhatsApp / AI-call outreach
├── credits.php                # Credit wallet & transactions
├── admins.php                 # User management (super_admin only)
├── audit_logs.php             # Audit trail (super_admin only)
├── jd_builder.php             # AI Campaign Builder from Job Description (Avyukta AI)
├── training.php               # In-app User Guide (super_admin only); 17 sections, sidebar + scroll-spy
├── apply.php                  # PUBLIC — candidate application form (mobile-responsive)
├── interview.php              # PUBLIC — in-browser AI interview (white theme, mobile-responsive)
├── forgot_password.php        # PUBLIC — password reset request form
├── reset_password.php         # PUBLIC — password reset with token
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
│   ├── score.php              # CLI/cron AI scoring (Vertex AI → Gemini API → Groq fallback)
│   ├── scores.php             # GET scores / POST override
│   ├── credits.php            # summary / buy / settings
│   ├── reminders.php          # schedule / send_due
│   ├── call_webhook.php       # Inbound AI-call result webhook (Avya dialer)
│   ├── change_password.php    # POST: change own password
│   ├── upload_audio.php       # POST: upload audio answer
│   ├── upload_video.php       # POST: upload session recording
│   ├── check_duplicate.php    # GET: real-time phone/email duplicate check (apply form)
│   ├── check_face.php         # POST: Gemini Vision face-presence check (interview)
│   ├── generate_campaign.php  # POST: AI campaign generation from JD text (Avyukta AI)
│   └── save_from_jd.php       # POST: save AI-generated campaign + questions + apply config
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
│   └── rescore_once.php       # CLI: re-run AI scoring for on-hold candidates
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
├── instruction.md             # Dev session instructions (read first)
├── structure.md               # This file
└── .gitignore
```

---

## 2. Database Schema

### `organizations`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| name | VARCHAR(255) | |
| logo_url | TEXT | Used in interview.php header; falls back to avyukta.in logo |
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
| integration_type | ENUM | none / crm / google_sheet (UI removed, column kept) |
| integration_endpoint | TEXT | (UI removed, column kept) |
| el_agent_id | VARCHAR(150) | legacy ElevenLabs agent ID (unused — Avya Dialer handles all AI calls) |
| passing_score | INT | default 70 |
| max_duration_minutes | INT | default 15 |
| num_questions | INT | default 10 (AI builder always generates exactly 10) |
| apply_form_config | JSON | Enabled standard field keys; NULL = all fields on by default |
| language | ENUM | english / hinglish / hindi |
| status | ENUM | draft / active / **paused** / completed (paused = deactivated) |

### `questions`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| campaign_id | INT FK | |
| parameter, parameter_label | VARCHAR | scoring dimension |
| weight, max_marks | INT | must sum to 100 across campaign |
| question_text, ideal_answer_hint | TEXT | |
| question_type | ENUM | text/textarea/number/decimal/date/dropdown/multi_select/rating/file/audio/video/hyperlink |
| options_json | JSON | for MCQ/dropdown choices |
| branch_rules_json | JSON | conditional branching |
| is_required | TINYINT | |
| order_no | INT | |

### `application_fields`
Custom fields for the public apply form per campaign.  
Types: text/textarea/number/decimal/date/dropdown/multi_select/checkbox/email/phone/url/file  
Standard fields are **not** stored here — they are controlled by `campaigns.apply_form_config` JSON.

### `candidates`
Full candidate profile (~40 fields): name, phone, email, city, experience, CTC, skills, resume_path, video_path, status, unique_token, referral fields, application_answers_json, etc.  
Status ENUM: `pending → outreach_sent → interview_started → interview_completed → shortlisted / rejected / on_hold`  
Terminated candidates: `cheat_summary.terminated === true` in their interview session; shown with red "🚫 Terminated" badge in candidates.php and detail banner in candidate_detail.php.

### `interview_sessions`
One row per interview attempt. Stores el_conversation_id, transcript, recording_url, duration, **cheat_summary JSON**.

**cheat_summary JSON fields:**
```json
{
  "terminated": true,          // face detection failure → interview forcibly ended
  "face_away": 3,              // number of times face was not detected mid-interview
  "tab_switches": 2,           // number of times tab/window was hidden
  "copy_paste_count": 1        // pastes over 20 chars threshold
}
```

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

Password reset flow:
  forgot_password.php → sends email with token → reset_password.php
  (both are public pages, routed via index.php)
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
│  │  Forgot password? link       │   │
│  │  Error message (if any)      │   │
│  └──────────────────────────────┘   │
└─────────────────────────────────────┘
```
- Dark navy bg (#0A1628)
- Server-side rate limiting (5 attempts → lock)
- Routes `/forgot_password` and `/reset_password` to public pages
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
**Actions:** `list | new | edit | questions | apply_form | activate | deactivate | clone | delete`

**list view:**
```
┌── [+ New Campaign]  [AI Builder 🤖] ───────────────────┐
│ Table: Name | Job Role | Status | Candidates | Dates   │
│  per row: [Edit] [Apply Form] [Questions]              │
│           [Share Link] [WhatsApp] [Clone] [Delete]     │
│           [Activate] (draft/paused) OR                 │
│           [Deactivate] (active only → sets to paused)  │
│ Bulk: [Select All] [Bulk Delete]                       │
└────────────────────────────────────────────────────────┘
```
**Status flow:** draft → active ↔ paused (deactivate sets active→paused)

**apply_form view (standard fields):**
```
┌── Standard Fields (40 fields, 9 sections) ───────────────────┐
│  Each section has ON/OFF toggle checkboxes per field          │
│  Config saved as JSON to campaigns.apply_form_config column   │
│  Custom fields (application_fields table) shown below         │
└──────────────────────────────────────────────────────────────┘
```
- Activation requires: campaign details + questions. Apply form NOT required (standard fields exist by default).
- `campaign_setup_state()`: `$has_apply` = true if `apply_form_config` set OR no config saved yet (defaults to all-on)

**questions view:**
```
Setup checklist: ① Details ② Questions ③ Apply Form ④ Activate
┌── Question list + [+ Add Question] modal ────────────────────┐
│  Q# | Type badge | Weight | Question text | [Edit] [Delete]  │
└──────────────────────────────────────────────────────────────┘
Edit/Add modal: type, weight, text, hint, MCQ options
```

---

### 4.4 jd_builder.php — AI Campaign Builder
```
Step 1: Paste Job Description text (30–15000 chars)
        [Generate Campaign with Avyukta AI ✨]
        → POST /api/generate_campaign.php
        → Gemini generates: name, role, description, 10 questions, custom fields

Step 2: Preview & Edit
        Campaign details editable inline
        10 question cards (4 MCQ + 3 short_answer + 3 voice_note)
        Each card: type selector badge, weight input, question text, hint
        MCQ cards: 4 options textarea + correct answer input
        Custom application fields list
        [✓ Create Campaign] → POST /api/save_from_jd.php
```
- Branding: "Avyukta AI is generating your campaign configuration"
- Minimum 10 questions enforced client and server side
- `save_from_jd.php` auto-saves default `apply_form_config` (all standard fields on)
- AI model: Gemini 2.0 Flash / Vertex AI, `maxOutputTokens: 16384`

---

### 4.5 candidates.php — Candidate List
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
│      🚫 Terminated pill (if cheat_summary.terminated)│
│      Score | City | Experience | Actions             │
│  [View] [Send WA] [Start Call]                       │
└──────────────────────────────────────────────────────┘
```
- Column visibility persisted in localStorage (`hireai_candidate_table_state_v2`)
- Export uses HMAC-signed token (EXPORT_TOKEN_SECRET), not JWT
- Terminated badge: red pill shown next to status when `cheat_summary.terminated === true`

---

### 4.6 candidate_detail.php — Candidate Profile
```
┌── Header: Name, Phone, Campaign, Status badge ───────┐
│  [Change Status] [Edit] [Trigger Call] [Delete]      │
│  Score ring (total/100), Pass/Fail badge             │
│  ⛔ Red termination banner (if terminated)            │
│  Integrity section: "Terminated — Face Not Detected" │
│    → Critical Risk (forced), tab switches, pastes    │
└──────────────────────────────────────────────────────┘
┌── Tabs ──────────────────────────────────────────────┐
│  [Q&A (N)]  [Recording]  [AI Call]                   │
└──────────────────────────────────────────────────────┘
Q&A tab:
  Sort bar: Question # | Score High→Low | Score Low→High
  Per-question rows: parameter, score bar, transcript,
  audio player (inline), "Analyze with AI" button (voice),
  recruiter override input

Recording tab:
  Floating video player (recording_url or uploads/video/)

AI Call tab:
  Call result: score, grade, recommendation, transcript
```

---

### 4.7 analytics.php — Analytics
```
┌── HERO (dark gradient #0D1B2E→#2D1B69) ──────────────┐
│  "Analytics Dashboard"  [Campaign badge] [Period badge]│
│  Filters: [Campaign dropdown] [All|7d|30d|90d]        │
│  ┌── 4 KPI glassmorphism cards ──────────────────────┐│
│  │ Total Candidates | Avg Score | Completion % | SR% ││
│  └───────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────┘
┌── Candidate Funnel (5-step) ──────────────────────────┐
│  Imported→Invited→Started→Completed→Shortlisted       │
└──────────────────────────────────────────────────────┘
┌── Score Distribution ──┐  ┌── Completion Trend ──────┐
│  Bar chart (5 buckets) │  │  Line chart (14 days)    │
└────────────────────────┘  └──────────────────────────┘
┌── AI Insights (4 cards)─┐  ┌── Weakest Parameters ──┐
│  Drop-off/Selection/    │  │  Horizontal bars        │
│  Score/Completion       │  │  red<40 amber<65 green≥65│
└─────────────────────────┘  └────────────────────────┘
```

---

### 4.8 apply.php — PUBLIC Application Form
```
Access: /apply.php?campaign_id=X  OR  /apply.php?c=share_token
        Referral: ?ref=unique_token
        Draft/paused campaigns: shows preview/warning banner

Two form modes:
  A) 9-step wizard — when campaign has apply_form_config (standard fields)
     Step 1: Personal info (salutation, name, DOB ≥18 years, city)
     Step 2: Contact (country code + phone, email) — duplicate check on blur
     Step 3: Education / experience
     Step 4: Compensation (current/expected CTC)
     Step 5: Skills & preferences
     Step 6: Logistics (laptop, internet, commute, flex hours)
     Step 7: Campaign-specific custom fields
     Step 8: Resume/video upload, documents
     Step 9: Declaration + submit

  B) 2-step dynamic form — JD-builder campaigns with custom fields only

Phone field (mobile-responsive):
  Country code combobox: 210px on desktop, 130px on mobile (≤540px)
  Shows compact "🇮🏼 +91" on mobile, full "🇮🏼 +91 India" on desktop
  Real-time duplicate phone/email check → GET /api/check_duplicate.php
  Duplicate found → blocks Continue button with error message

DOB validation: must be ≥18 years (maxDate = today − 18 years)
  flatpickr date picker inline in apply.php <head> (not via head.php)
```

---

### 4.9 interview.php — PUBLIC AI Interview
```
Access: /interview.php?t=unique_token

Theme: White/bright (--bg:#F0F4F8, --surface:#FFFFFF, --text:#0F172A)
Header: Org logo (o.logo_url) or fallback to avyukta.in logo

Flow:
  1. Token validation → load candidate + campaign + questions
  2. Permission screen (full-page two-column layout):
     LEFT: camera/mic permission request, consent checkbox, [Start] button
     RIGHT:
       📋 Interview Instructions (7 points)
       ⚠️  Disclaimer for Test Participants (4 points):
           - Phone on Silent Mode
           - Stable Wi-Fi connection
           - Avoid incoming calls
           - Best on laptop/desktop

  3. Face Gate Modal (#face-gate):
     - Shows live camera feed
     - Client-side brightness pre-filter (canvas centre 50%×60% sample; <25 → dark → reject)
     - Sends frame to /api/check_face.php (Gemini Vision: "Is there a human face?")
     - Retries every 2.5-3s; 3 network failures → fallback (assume face present)
     - [Start Interview] button enabled only when face confirmed

  4. Interview questions loop:
     - Voice answer: tap mic button to start recording (NO auto-start)
       MediaRecorder → /api/upload_audio.php
     - Text answer: textarea / MCQ cards / input
     - Timer: 3 min per question (configurable)
     - Anti-cheat: tab-switch + paste (>20 chars) logging (silent, no UI)
     - Face check after every question:
         • checkFaceOrTerminate() → check_face.php
         • showFaceWarning() → 15s countdown → recheck
         • 2 consecutive failures → terminateInterview()
         • Camera disconnected → immediate terminateInterview()

  5. Termination (if triggered):
     - #termination-screen shown
     - POST complete_interview with cheat_summary.terminated=true
     - Recording and camera stopped

  6. Completion screen:
     - Green "Close This Tab" button (window.close())
     - Guidance: "you can close this tab"

  7. Video recording: session-level MediaRecorder → /api/upload_video.php

Mobile responsive:
  - font-size:16px on all inputs/textareas (prevents iOS auto-zoom)
  - visualViewport resize listener → adjusts body height when keyboard opens
  - Camera sidebar: 140px (≤680px), 110px (≤480px)
  - -webkit-overflow-scrolling:touch on main scroll
  - scrollIntoView on input focus
  - height:100dvh (dynamic viewport height)
```

---

### 4.10 api/check_face.php — Face Detection API
```
POST body: { token, image (base64 JPEG), question_no }
Auth: validates candidate token against active interview sessions
Process:
  1. Decode base64 image
  2. Call Gemini Vision: "Is there a human face clearly visible and centered?"
  3. Returns { face: true } or { face: false }
  4. On API error → returns { face: true } (never blocks interview on failure)
Supports both: Gemini API key path and Vertex AI service account path
```

---

### 4.11 api/check_duplicate.php — Duplicate Check API
```
GET params: campaign_id + phone OR email
Returns: { exists: true/false, field: "phone"|"email" }
No auth required (called from public apply form)
Checks candidates table for existing phone/email within same campaign
```

---

### 4.12 api/generate_campaign.php — AI Campaign Generation
```
POST body: { jd_text }
Auth: JWT required (admin/hr only)
Calls Gemini (API key or Vertex AI service account)
maxOutputTokens: 16384

Returns exactly 10 questions:
  Q1–Q4: question_type="mcq"         → mapped to DB type "dropdown"
  Q5–Q7: question_type="short_answer" → mapped to DB type "textarea"
  Q8–Q10: question_type="voice_note"  → mapped to DB type "audio"

Weights auto-normalized to sum=100
Campaign name format: "Role Name at Company Name" (max 60 chars)
```

---

### 4.13 api/save_from_jd.php — Save AI Campaign
```
POST body: { campaign_name, job_role, description, passing_score,
             language, questions[], application_fields[] }
Auth: JWT required
Validates: minimum 10 questions
Actions:
  1. INSERT campaign
  2. INSERT questions (with options_json for MCQ)
  3. INSERT application_fields (custom fields only)
  4. Auto-saves default apply_form_config (all 30 standard fields enabled)
     → prevents "activation blocked" on new JD-builder campaigns
```

---

### 4.14 training.php — User Guide (super_admin only)
```
Access: /training  (super_admin role required)

Layout: Two-column — 232px sticky sidebar + scrollable content area

Sidebar:
  Top: "User Guide" title + "HireAI — Avyukta Intellicall" subtitle
  Grouped nav with Font Awesome icons:
    GETTING STARTED: Platform Overview | Quick Start | User Roles
    CORE FEATURES:   Campaigns | AI Builder | Apply Form | Candidates | Outreach
    INTERVIEW:       Interview Flow | Face Detection | Reviewing Results | AI Scoring
    ADMIN:           Analytics | User Management | Credits
    REFERENCE:       Workflows | FAQ
  Bottom: Print / Save as PDF button
  Scroll-spy: active nav item updates as user scrolls content

Hero:
  Dark gradient (navy → indigo → purple): #1E3A8A → #312E81 → #4C1D95
  Eyebrow badge + H1 "HireAI — Complete User Guide"
  Feature pill tags: Campaign Management | AI Builder | AI Interviews | Auto Scoring | WhatsApp Outreach

CSS component system:
  .card         — white bordered card with shadow
  .tile         — feature tile with colored icon box + title + desc
  .step         — numbered blue circle + title + desc + inline note (tip/warn/danger)
  .info         — color-coded info box (blue/green/orange/red)
  .wf-card      — workflow card with CSS counter steps
  .faq          — accordion (toggleFaq())
  .flow         — pill chain with arrows (status flow diagrams)
  .tbl          — styled table (role permissions, feature comparison)

17 content sections:
  1. Platform Overview — "What is HireAI?" card + 6 feature tiles (3-col grid)
  2. Quick Start — 5 numbered steps
  3. User Roles — role permissions table (Super Admin / Admin / Recruiter)
  4. Campaigns — status flow diagram + 5 steps
  5. AI Builder — green info + 4 steps + generated-output table
  6. Apply Form — 2-col tiles + 3 steps
  7. Candidates — status flow diagram + 4 steps + termination info box
  8. Outreach — 2-col tiles (WhatsApp / AI Call) + 2 steps
  9. Interview Flow — blue info + 4 steps + candidate briefing card
  10. Face Detection — 2-col cards (checked/termination) + admin result card
  11. Reviewing Results — 5 steps
  12. AI Scoring — pipeline card (5 numbered inline steps) + tip info
  13. Analytics — 4 feature tiles
  14. User Management — 3 steps + blue recommendation info
  15. Credits — feature table + orange low-balance warning
  16. Workflows — 3 wf-cards: Launch Drive / Import & Send / Review & Shortlist
  17. FAQ — 9 accordion items
```

---

### 4.15 video_view.php — Recording Viewer
- Auth required, org-scoped check
- Renders `recording_url` from interview_sessions or fallback uploads/video/session_X_*.webm
- `<video>` tag with controls

---

## 5. API Endpoints Reference

| Endpoint | Methods | Key Actions |
|----------|---------|-------------|
| `api/apply.php` | POST | Submit application, auto-send WA |
| `api/auth.php` | POST | `login` → JWT |
| `api/candidates.php` | POST | `add`, `bulk_import`, `update`, `bulk_delete` |
| `api/interview.php` | GET/POST | `create_session`, `save_answer`, `complete_interview` (saves cheat_summary), `webhook`, `start_call`, `bulk_start`, `get_agents` |
| `api/outreach.php` | GET/POST | `send_single`, `bulk_send`, `custom_whatsapp_send`, `trigger_ai_call`, `bulk_ai_call`, `call_campaign`, `whatsapp_status`, `send_test` |
| `api/score.php` | CLI/GET | Score one candidate; `?async=1` for fire-and-forget; `?action=transcribe_one` for single voice transcription |
| `api/scores.php` | GET/POST | `get` scores, `override` score |
| `api/credits.php` | GET/POST | `summary`, `buy`, `settings` |
| `api/reminders.php` | POST/GET | `schedule`, `send_due` |
| `api/call_webhook.php` | POST | Inbound Avya dialer result (HMAC-verified) |
| `api/change_password.php` | POST | Change own password |
| `api/upload_audio.php` | POST | Save voice answer |
| `api/upload_video.php` | POST | Save session recording |
| `api/check_duplicate.php` | GET | Real-time phone/email duplicate check |
| `api/check_face.php` | POST | Gemini Vision face-presence check |
| `api/generate_campaign.php` | POST | AI campaign generation from JD text |
| `api/save_from_jd.php` | POST | Save AI-generated campaign to DB |

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
| `nav.php` | Top navigation bar HTML + Change Password modal + JS; Guide/Audit Logs/Admins shown only for super_admin |
| `footer.php` | Toast HTML, closing body/html |

Note: `apply.php` and `interview.php` do **not** include `head.php` — they manage their own `<head>` with inline styles/scripts.

---

## 7. External Integrations

| Service | Purpose | Config Keys |
|---------|---------|-------------|
| ~~ElevenLabs~~ | Disabled — replaced by Avya Dialer | ~~`EL_API_KEY`, `EL_AGENT_ID`, `EL_PHONE_NUMBER_ID`~~ |
| Avya AI Dialer | Outbound AI calls + inbound result webhook | `DIALER_API_KEY`, `DIALER_CALLER_ID`, `DIALER_API_URL`, `CALL_WEBHOOK_SECRET` |
| Vertex AI / Gemini | AI scoring + face detection + JD generation (primary) | `VERTEX_AI_PROJECT`, `VERTEX_AI_LOCATION`, `VERTEX_AI_MODEL`, `GOOGLE_APPLICATION_CREDENTIALS` |
| Gemini API | AI scoring + face detection fallback | `GEMINI_API_KEY`, `GEMINI_MODEL` |
| Groq (LLaMA 3.3-70b) | AI scoring fallback #2 + Whisper transcription | Uses `OPENAI_API_KEY` env var |
| WhatsApp Gateway | Outreach messages | `WA_API_URL`, `WA_INSTANCE_ID`, `WA_TOKEN` |
| Razorpay | Credit purchases | `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET` |
| PayPal | Credit purchases | `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` |
| Payoneer | Credit purchases | `PAYONEER_PROGRAM_ID` |

---

## 8. Role Permissions

| Feature | super_admin | admin | hr | recruiter |
|---------|-------------|-------|----|-----------|
| Create/edit/deactivate campaigns | ✓ | ✓ | — | — |
| View campaigns | ✓ | ✓ | ✓ | ✓ |
| Use JD AI Builder | ✓ | ✓ | — | — |
| Add/import candidates | ✓ | ✓ | ✓ | ✓ |
| Bulk delete | ✓ | ✓ | ✓ | ✓ |
| Override scores | ✓ | ✓ | ✓ | ✓ |
| View credits | ✓ | ✓ | ✓ | ✓ |
| Buy credits | ✓ | ✓ | ✓ | ✓ |
| Manage admins | ✓ | — | — | — |
| View audit logs | ✓ | — | — | — |
| View User Guide (training.php) | ✓ | — | — | — |

---

## 9. Candidate Status Flow

```
pending
  ↓ (WhatsApp sent)
outreach_sent
  ↓ (candidate opens interview link)
interview_started
  ↓ (interview completed OR terminated)
interview_completed  [+ cheat_summary.terminated=true if terminated]
  ↓ (AI scoring)
shortlisted  ←→  rejected
       ↕
     on_hold  (scoring failed; rescored nightly via cron)
```

---

## 10. Interview Integrity System

```
Client-side tracking (silent — no UI feedback to candidate):
  • Tab/window switch → tabSwitchCount++, logged to cheat_summary
  • Paste > 20 chars → copyCount++, paste alert modal shown
  • Ctrl+V keydown → also counted
  • Context menu blocked (right-click)

Face detection (backend via Gemini Vision):
  Pre-interview:
    Face Gate Modal → check_face.php → must confirm face before starting

  During interview (after each question):
    1. Client-side brightness check (canvas centre sample < 25 = dark → fail)
    2. checkFaceOrTerminate() → POST check_face.php
    3. First failure: showFaceWarning() (15s countdown) → recheck
    4. Second consecutive failure: terminateInterview()
    5. Camera disconnect: immediate terminateInterview()

Termination:
    terminateInterview(reason):
      - Shows #termination-screen
      - POST complete_interview with cheat_summary.terminated=true
      - Stops recording + camera tracks
      - cheat_summary saved to interview_sessions table

Visibility in admin:
    candidates.php: red "🚫 Terminated" pill next to status badge
    candidate_detail.php: red banner at top, Integrity = Critical Risk
```

---

## 11. Scoring Pipeline

```
interview completed
  → exec("php api/score.php candidate_id campaign_id") in background
  → for each question with a gradable answer:
      1. Try Vertex AI (gemini-2.5-flash)
      2. Fallback: Gemini API (GEMINI_MODEL)
      3. Fallback: Groq LLaMA-3.3-70b
      Voice answers: transcribed first (Gemini→Groq Whisper fallback)
      → INSERT INTO scores (ai_score, ai_reasoning, transcript)
  → INSERT INTO interview_results (total_score, pass_fail, ai_summary)
  → UPDATE candidates SET status = shortlisted / rejected / on_hold
  → Send WhatsApp result notification

Async mode: GET /api/score.php?async=1 → queues in background, returns immediately
Cron: 2am daily → php scripts/rescore_once.php --on-hold --delay=20
      Logs to /tmp/rescore_onhold.log
```

---

## 12. Key Conventions

- All DB access via MySQLi prepared statements (db_fetch_all / db_fetch_one / db_execute)
- All state-changing POSTs require `verify_csrf_or_die()`
- API endpoints return JSON via `json_response()`
- Uploads validated by MIME sniffing (`detect_uploaded_mime`) + extension whitelist
- Phone numbers normalized with `normalize_phone()` (strips to digits, optional +91 prefix)
- Pagination: `pagination_page()`, `pagination_per_page()`, `pagination_html()`
- All user-facing strings HTML-escaped with `htmlspecialchars()`
- Credit deduction atomic via `WHERE balance >= ?` + `affected_rows` check
- `apply.php` and `interview.php` are self-contained (no head.php/footer.php includes)
- Standard apply form fields controlled via `campaigns.apply_form_config` JSON (not application_fields table)
- Interview inputs use `font-size:16px` to prevent iOS Safari auto-zoom on focus
