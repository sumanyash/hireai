# Statement of Work — HireAI Phase 2
## AI-Powered Training, Onboarding & Employee Readiness Platform

**Prepared by:** Avyukta Intellicall  
**Product:** HireAI — hire.clouddialer.in  
**Document Type:** Statement of Work (SOW)  
**Phase:** 2 — AI Training & Onboarding Module  
**Version:** 1.0  

---

## Executive Summary

Phase 1 of HireAI delivers a complete AI-powered hiring pipeline: campaign creation, AI-driven interviews, automated scoring, candidate management, WhatsApp outreach, face detection, and analytics. The platform successfully covers the "Find & Screen" stage of the talent lifecycle.

Phase 2 extends HireAI beyond hiring into the **post-hire journey** — transforming the platform into a complete **AI Hiring + AI Training + AI Employee Readiness** system. Once a candidate is hired through HireAI, the company can immediately launch a structured, AI-delivered training program tailored to the employee's role, powered by the company's own knowledge base, and ending with a formal assessment that determines whether the employee is ready for deployment.

---

## Phase 1 — What Has Been Built (Baseline)

| Module | Status | Key Technical Details |
|--------|--------|----------------------|
| Campaign Management | ✓ Done | CRUD + AI Builder (JD → 10 questions in 30s) |
| Candidate Apply Form | ✓ Done | 9-step wizard, mobile-responsive, duplicate check |
| AI Interview Engine | ✓ Done | Voice/Text/MCQ, face detection, tab-switch tracking |
| AI Auto-Scoring | ✓ Done | Vertex AI → Gemini → Groq fallback chain |
| WhatsApp Outreach | ✓ Done | Single + bulk, credit-gated |
| Candidate Management | ✓ Done | Import CSV, status tracking, export, terminated badge |
| Analytics Dashboard | ✓ Done | Funnel, score distribution, weakest questions |
| Face Detection & Integrity | ✓ Done | Gemini Vision, two-strike termination |
| Credit System | ✓ Done | Wallet per org, atomic deduction, Razorpay |
| User Roles & Audit | ✓ Done | super_admin / admin / hr / recruiter + audit trail |
| User Guide (training.php) | ✓ Done | In-app guide, 17 sections, super_admin only |

**Stack:** PHP 8.2 · MySQL · nginx · Vanilla JS · Gemini API · Vertex AI · Groq · Avya Dialer · WhatsApp Gateway

---

## Phase 2 — Scope of Work

### Module 1: Knowledge Base Management

**Description:** Companies upload their own training material. The AI engine processes this content to generate structured training programs.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 1.1 | Multi-format knowledge base upload: PDF, DOCX, TXT, MP4, MP3, URLs | P0 |
| 1.2 | File chunking and vector embedding via Gemini Embeddings API | P0 |
| 1.3 | Org-scoped knowledge base — each company's data is isolated | P0 |
| 1.4 | Knowledge base versioning — update content without breaking active training | P1 |
| 1.5 | Content tagging by role (Support / Sales / Technical / HR / Custom) | P0 |
| 1.6 | Knowledge base preview and search in admin portal | P1 |
| 1.7 | Auto-extraction of key topics, SOPs, FAQs from uploaded documents | P0 |
| 1.8 | Video upload processing — transcript extraction from training videos | P1 |

**New DB Tables:** `knowledge_bases`, `kb_documents`, `kb_chunks` (vector store or MySQL FULLTEXT)

**New Files:**
- `knowledge_base.php` — admin upload and management UI
- `api/kb_upload.php` — file upload + processing trigger
- `api/kb_process.php` — CLI: chunk, embed, store
- `api/kb_query.php` — RAG query endpoint (used by AI trainer)

---

### Module 2: Training Program Builder

**Description:** Admins or HR managers create structured training programs from the uploaded knowledge base, define duration, and assign to hired employees.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 2.1 | Create training programs: name, role, duration (7 / 15 / 30 / custom days) | P0 |
| 2.2 | AI auto-generates daily lesson plan from knowledge base for given role + duration | P0 |
| 2.3 | Each day = one or more modules; each module has: topic, content, estimated time, quiz | P0 |
| 2.4 | Manual module editing: reorder, delete, add custom modules | P1 |
| 2.5 | Assign training program to a hired candidate (link to existing candidate record) | P0 |
| 2.6 | Schedule training start date; auto-unlock daily modules by schedule | P0 |
| 2.7 | Clone a training program for reuse across hires of same role | P1 |
| 2.8 | Training program status: Draft / Active / Archived | P0 |

**New DB Tables:** `training_programs`, `training_modules`, `training_enrollments`

**New Files:**
- `training_builder.php` — program creation UI (admin)
- `api/training_programs.php` — CRUD for programs and modules
- `api/training_assign.php` — assign program to candidate/employee

---

### Module 3: AI Avatar Trainer (Delivery Engine)

**Description:** The core Phase 2 experience. The employee opens their training portal and is taught by an on-screen AI trainer (avatar) that speaks, explains concepts, answers questions, and guides them through each module.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 3.1 | Browser-based training interface — no app required | P0 |
| 3.2 | AI Avatar: animated on-screen presenter (male or female, selectable per org) | P0 |
| 3.3 | Avatar speaks the module content via Text-to-Speech (TTS service — ElevenLabs removed; use Gemini TTS or alternative) | P0 |
| 3.4 | Avatar lip-sync or animated talking head — using a third-party avatar API (e.g. D-ID, HeyGen, Simli, or CSS-animated 2D character as fallback) | P1 |
| 3.5 | Module content displayed alongside avatar: slides / text panels / diagrams | P0 |
| 3.6 | Employee can ask the AI trainer questions via voice or text at any point in the module | P0 |
| 3.7 | AI trainer answers from the knowledge base using RAG (Retrieval-Augmented Generation) via Gemini | P0 |
| 3.8 | "Explain again" / "Give me an example" / "Simplify this" — contextual learning commands | P1 |
| 3.9 | AI trainer summarizes each module before ending: key takeaways | P0 |
| 3.10 | Progress saved automatically — resume from where employee left off | P0 |
| 3.11 | Module completion locked until minimum time spent + quiz passed | P1 |
| 3.12 | Multilingual support — select training language; AI responds in same language | P1 |

**AI Stack for Module 3:**
- Content generation: Gemini 2.0 Flash (via existing Vertex AI / API key)
- Voice narration: Gemini TTS or alternative TTS service (ElevenLabs removed)
- RAG queries: Gemini Embeddings + cosine similarity search
- Avatar: D-ID API (primary) or CSS-animated 2D character (fallback)

**New Files:**
- `training_session.php` — employee-facing training interface (self-contained, like interview.php)
- `api/training_chat.php` — Q&A with AI trainer (RAG + Gemini)
- `api/training_tts.php` — generate audio for module content via TTS service (TBD)
- `api/training_progress.php` — save/load progress, mark module complete

---

### Module 4: Interactive Learning Scenarios

**Description:** Beyond passive listening — employees practice real situations they will face on the job.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 4.1 | Simulated customer conversations — AI plays a customer, employee responds | P0 |
| 4.2 | Role-play scenarios auto-generated from knowledge base (e.g. "Handle an angry customer call") | P0 |
| 4.3 | Sales objection handling practice — AI provides objections, scores responses | P0 |
| 4.4 | Support ticket simulation — AI generates a ticket, employee resolves it | P1 |
| 4.5 | Scenario difficulty progression — easy to hard as training advances | P1 |
| 4.6 | AI feedback after each scenario: what was good, what needs improvement | P0 |
| 4.7 | Voice-mode available for all scenarios (employee speaks, AI listens) | P1 |
| 4.8 | Scenario library — company can add custom role-play scripts | P1 |

**New Files:**
- `api/training_scenario.php` — scenario generation + evaluation
- (scenarios rendered within `training_session.php`)

---

### Module 5: Assessment Engine

**Description:** Quizzes after each module and a comprehensive final assessment before deployment clearance.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 5.1 | Auto-generated quiz after each training module (5–10 questions: MCQ + short answer) | P0 |
| 5.2 | Questions generated by AI from the module content via Gemini | P0 |
| 5.3 | Per-module quiz: must pass (configurable threshold, default 70%) to unlock next module | P0 |
| 5.4 | Retry allowed on module quizzes (configurable: 1–3 attempts) | P1 |
| 5.5 | Final assessment after all modules completed: comprehensive test across all topics | P0 |
| 5.6 | Final assessment includes practical scenario evaluation (not just MCQ) | P1 |
| 5.7 | Auto-scoring: MCQ is instant; short-answer scored by Gemini AI | P0 |
| 5.8 | Assessment result: score, pass/fail, breakdown by topic | P0 |
| 5.9 | Training Completion Certificate — auto-generated PDF with name, program, score, date | P1 |
| 5.10 | Certificate download and email delivery | P1 |
| 5.11 | Manager notified when employee completes final assessment | P0 |

**New DB Tables:** `training_assessments`, `training_assessment_answers`, `training_certificates`

**New Files:**
- `api/training_assessment.php` — generate + submit + score assessments
- `api/training_certificate.php` — generate PDF certificate (TCPDF or similar)

---

### Module 6: Probation Management

**Description:** Training progress and assessment scores feed into a probation period tracker that helps managers make deployment decisions.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 6.1 | Training progress automatically linked to the candidate's probation period | P0 |
| 6.2 | Probation dashboard per employee: days completed, modules done, quiz scores, final score | P0 |
| 6.3 | AI-generated probation readiness recommendation: "Ready for Deployment" / "Needs More Time" / "Requires Re-training" | P0 |
| 6.4 | Manager can override the AI recommendation with a manual decision | P0 |
| 6.5 | Probation period report — exportable PDF/CSV for each employee | P1 |
| 6.6 | Alert manager if employee falls behind schedule (e.g. hasn't logged in for 2 days) | P1 |
| 6.7 | HR can extend probation with one click; new training milestones auto-recalculated | P1 |

**New DB Tables:** `probation_records`

**New Files:**
- `probation.php` — manager view of all probation employees
- `api/probation.php` — update status, generate report

---

### Module 7: Learning Analytics & Manager Dashboard

**Description:** Full visibility into training performance for managers, HR, and super admins.

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 7.1 | Training Analytics page: completion %, avg quiz score, time spent, drop-off points | P0 |
| 7.2 | Per-employee training timeline view | P0 |
| 7.3 | Module-level analytics: which modules employees struggle with most | P0 |
| 7.4 | Cohort comparison: employees hired for same role, same campaign, ranked by training score | P1 |
| 7.5 | Engagement metrics: login frequency, time on platform, Q&A interactions | P1 |
| 7.6 | AI Skill Gap Report per employee: weak topics identified, improvement suggestions | P1 |
| 7.7 | Export analytics as CSV or PDF report | P1 |
| 7.8 | Training ROI metrics (configurable): time to productivity estimate | P2 |

**New Files:**
- `training_analytics.php` — manager analytics UI
- `api/training_analytics.php` — data endpoint

---

### Module 8: Gamification

**Description:** Keep employees motivated and engaged through training.

| # | Requirement | Priority |
|---|-------------|----------|
| 8.1 | Points system: earn XP for completing modules, quizzes, scenarios | P1 |
| 8.2 | Badges: "Fast Learner", "Quiz Master", "First Login", "Day 7 Streak", etc. | P1 |
| 8.3 | Leaderboard: top employees by training score within org or campaign | P1 |
| 8.4 | Training streak: consecutive login days | P1 |
| 8.5 | Badges displayed on employee profile and completion certificate | P2 |

---

### Module 9: AI Knowledge Assistant (24/7 Helpdesk)

**Description:** Employees can ask any work-related question at any time and get an instant AI answer from the company knowledge base.

| # | Requirement | Priority |
|---|-------------|----------|
| 9.1 | Persistent chat widget available on employee training portal | P1 |
| 9.2 | Powered by RAG: queries Gemini against org's knowledge base | P1 |
| 9.3 | Context-aware: knows the employee's role and current training module | P1 |
| 9.4 | Escalation: if AI can't answer confidently, flags the question for manager review | P2 |
| 9.5 | Q&A history stored per employee for manager review | P2 |

---

### Module 10: Compliance & Policy Training

**Description:** Mandatory training modules that all employees must complete regardless of role.

| # | Requirement | Priority |
|---|-------------|----------|
| 10.1 | Org-level mandatory modules: Code of Conduct, POSH, Data Privacy, IT Policy | P1 |
| 10.2 | Policy acknowledgment: employee reads + digitally signs policy | P1 |
| 10.3 | Audit trail of acknowledgments with timestamp | P1 |
| 10.4 | Automated reminder if employee hasn't completed mandatory modules | P2 |

---

### Module 11: Versant-Style Communication Assessment

**Description:** An AI-powered spoken English / communication proficiency test modeled after Versant. Evaluates pronunciation, fluency, vocabulary, grammar, and coherence — critical for BPO, call center, sales, and support roles. Can be used as a **pre-hire screening step (Phase 1 extension)** OR as a **post-training certification check (Phase 2)**.

**Why this matters:** Avyukta Intellicall places agents in voice-based roles. A candidate may score well on written interview questions but struggle with spoken communication. The Versant module catches this before deployment.

**Test Structure (5 sections):**

| Section | What happens | How it's evaluated |
|---------|-------------|-------------------|
| **Reading Aloud** | Employee reads 3 passages displayed on screen → mic records | Pronunciation accuracy, pace, clarity |
| **Sentence Repeat** | AI speaks a sentence via TTS → employee repeats it | Listening comprehension + pronunciation |
| **Word Fluency** | Say as many words as possible in a category in 30 seconds | Vocabulary breadth, spontaneous speech |
| **Quick Questions** | 5 open-ended questions → voice answer (30s each) | Grammar, vocabulary, coherence, confidence |
| **Story Retelling** | AI reads a short story → employee retells it in own words | Comprehension, structured speech, fluency |

**Scoring Model:**

| Dimension | Score (0–20) | What AI evaluates |
|-----------|-------------|-------------------|
| Pronunciation | 0–20 | Phoneme accuracy, accent clarity, word stress |
| Fluency | 0–20 | Natural pace, minimal hesitation, no long pauses |
| Vocabulary | 0–20 | Word variety, appropriate word choice |
| Grammar | 0–20 | Sentence structure correctness |
| Coherence | 0–20 | Logical flow, completeness of thought |
| **Total** | **0–100** | Overall Communication Score |

**CEFR Mapping:**

| Score | Level | Meaning |
|-------|-------|---------|
| 85–100 | C1–C2 | Advanced / Proficient |
| 65–84 | B2 | Upper-Intermediate |
| 45–64 | B1 | Intermediate |
| 25–44 | A2 | Elementary |
| 0–24 | A1 | Beginner |

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 11.1 | 5-section test interface: Reading Aloud / Sentence Repeat / Word Fluency / Quick Q&A / Story Retell | P0 |
| 11.2 | Browser-based voice recording (MediaRecorder, same as interview.php) | P0 |
| 11.3 | TTS playback for Sentence Repeat and Story sections (TTS service TBD — ElevenLabs removed) | P0 |
| 11.4 | Groq Whisper transcription of all recorded responses | P0 |
| 11.5 | Gemini AI evaluation: score each section on 5 dimensions (0–20 each) with reasoning | P0 |
| 11.6 | Overall communication score (0–100) + CEFR level + AI summary | P0 |
| 11.7 | Radar chart showing scores across 5 dimensions on results page | P1 |
| 11.8 | Admin sets minimum passing score per campaign/training program | P0 |
| 11.9 | Dual use: usable inside Phase 1 interview (as a question type) OR as standalone Phase 2 test | P1 |
| 11.10 | Test language configurable: English / Hindi / Hinglish | P1 |
| 11.11 | Custom passage pool: admin can upload their own reading passages relevant to the role | P1 |
| 11.12 | Anti-cheat: face detection active during Versant test (reuses Phase 1 check_face.php) | P1 |
| 11.13 | Result stored per candidate with section-by-section breakdown + audio recordings | P0 |
| 11.14 | Manager can playback individual audio recordings per section | P1 |

**New DB Tables:** `versant_tests`, `versant_results`

```
versant_tests: id, org_id, name, language, passing_score, custom_passages_json, created_by
versant_results: id, candidate_id, enrollment_id (nullable), test_id, pronunciation_score,
                 fluency_score, vocabulary_score, grammar_score, coherence_score,
                 total_score, cefr_level, ai_summary, recordings_json, completed_at
```

**New Files:**
- `versant.php` — admin: manage Versant tests, view results
- `versant_test.php` — PUBLIC (token): employee-facing Versant test interface (self-contained)
- `api/versant.php` — submit answers, trigger scoring, fetch results
- `api/versant_score.php` — CLI: Whisper transcription + Gemini evaluation per section

**Integration with Phase 1:** The `questions` table already has a `question_type` ENUM. Adding `versant` as a new type allows a Versant section to be embedded directly inside an AI interview campaign — making it usable as a pre-hire filter before even calling a candidate.

---

### Module 12: Typing Speed & Accuracy Test

**Description:** A real-time typing assessment that measures WPM (words per minute), accuracy, and consistency. Essential for support agents, data entry, back-office, and any role where keyboard speed matters. Fully client-side scoring — no AI API cost.

**Test Modes:**

| Mode | Duration | Best For |
|------|----------|---------|
| Quick Test | 1 minute | Initial screening |
| Standard Test | 3 minutes | Training certification |
| Extended Test | 5 minutes | High-accuracy roles (data entry) |
| Custom | Admin-set | Any duration |

**Paragraph Types:**

| Type | Description |
|------|-------------|
| General English | Common sentences, varied vocabulary |
| Customer Support | Sample customer emails, ticket responses |
| Sales Scripts | Product descriptions, objection responses |
| Technical | IT terms, support documentation |
| Role-specific | Admin uploads custom paragraphs from their KB |

**Scoring Model:**

| Metric | Formula | Display |
|--------|---------|---------|
| Gross WPM | Total chars typed ÷ 5 ÷ minutes | Raw speed |
| Net WPM | Gross WPM − (errors ÷ minutes) | Final speed after penalty |
| Accuracy % | (Correct chars ÷ Total chars) × 100 | Precision |
| Consistency | Standard deviation of WPM across 30s intervals | Stamina |
| **Composite Score** | (Net WPM × 0.6) + (Accuracy × 0.4) normalised to 0–100 | Final score for threshold check |

**WPM Benchmarks:**

| Net WPM | Level | Suitable For |
|---------|-------|-------------|
| 60+ | Excellent | All roles |
| 45–59 | Good | Most support / back-office roles |
| 30–44 | Average | Basic data entry |
| Below 30 | Needs improvement | Flag for typing training |

**Functional Requirements:**

| # | Requirement | Priority |
|---|-------------|----------|
| 12.1 | Real-time typing interface: paragraph displayed, employee types below | P0 |
| 12.2 | Character-level live highlighting: correct = green, incorrect = red, pending = gray | P0 |
| 12.3 | Live counters: WPM, Accuracy %, time remaining | P0 |
| 12.4 | Auto-submit when timer ends; manual submit also allowed | P0 |
| 12.5 | Paragraph randomly selected from pool each attempt (prevent memorization) | P0 |
| 12.6 | Test mode selector: 1 min / 3 min / 5 min / custom (admin-configured) | P0 |
| 12.7 | Paragraph type filter by role (admin sets which types apply to this test) | P1 |
| 12.8 | Admin uploads custom paragraphs (role-specific, from company KB) | P1 |
| 12.9 | Score breakdown: Gross WPM, Net WPM, Accuracy %, Consistency, Composite Score | P0 |
| 12.10 | Visual results: speed gauge, accuracy ring, keystroke heatmap | P1 |
| 12.11 | Configurable minimum Net WPM and Accuracy % thresholds per campaign/program | P0 |
| 12.12 | Anti-cheat: paste blocked, browser tab switch ends test immediately | P0 |
| 12.13 | Retry limit: admin sets max attempts (default 2) | P1 |
| 12.14 | Best score recorded across attempts; improvement tracked over training period | P1 |
| 12.15 | Dual use: standalone pre-hire filter (Phase 1 interview) OR training certification (Phase 2) | P1 |
| 12.16 | Fully client-side scoring — no API calls, instant results | P0 |
| 12.17 | Mobile: show "Best experienced on desktop" notice (same pattern as interview.php disclaimer) | P0 |

**New DB Tables:** `typing_tests`, `typing_results`

```
typing_tests: id, org_id, name, duration_seconds, min_wpm, min_accuracy,
              paragraph_types_json, max_attempts, created_by
typing_results: id, candidate_id, enrollment_id (nullable), test_id,
                gross_wpm, net_wpm, accuracy_pct, consistency_score,
                composite_score, chars_typed, errors, attempt_number,
                paragraph_used, keystrokes_json, completed_at
```

**New Files:**
- `typing_test.php` — PUBLIC (token): typing test interface (self-contained, like interview.php)
- `api/typing_test.php` — save result, fetch best score, admin CRUD for test configs

**Integration with Phase 1:** Same as Versant — a `typing_test` question type can be added to the `questions.question_type` ENUM, allowing typing tests to be embedded as a step inside an interview campaign.

**Combined Pre-Hire Use (Phase 1 extension):**
```
Campaign settings → tick "Include Typing Test"
  → After voice/text/MCQ questions → typing test begins automatically
  → Score contributes to overall interview score (configurable weight)
  → Recruiter sees: Interview Score + Typing WPM + Communication Score on candidate card
```

---

## User Flows

### Flow A: Admin Sets Up Training (Company Side)

```
Admin logs in → Clicks "Training" in nav
  → Uploads Knowledge Base (PDF/SOP/video)
    → AI processes & chunks documents
  → Creates Training Program
    → Selects role + duration (e.g. "Sales — 15 Days")
    → AI generates daily lesson plan
    → Admin reviews/edits modules
    → Saves as Active
  → Assigns to hired candidate
    → Selects candidate from Candidates list
    → Sets training start date
    → Employee receives WhatsApp/email invite with training link
```

### Flow B: Employee Takes Training

```
Employee opens training link (unique token, like interview link)
  → Welcome screen: program name, duration, schedule
  → Day 1 unlocked
    → Module 1 opens
    → AI Avatar appears on screen, starts speaking module content
    → Employee reads along / listens
    → Employee can type/speak questions → AI answers from KB
    → Module ends → Key takeaways summary
    → Quiz (5 MCQ) → must score 70%+ to proceed
    → Module 2 unlocked
  → Scenario Practice session
    → AI plays customer/manager role
    → Employee responds
    → AI scores and gives feedback
  → Day 1 complete → Progress saved
  → Day 2 unlocked next calendar day
  → ...continues until all modules done...
  → Final Assessment
    → Comprehensive test across all topics
    → Practical scenario evaluation
    → Score displayed + pass/fail
  → Certificate generated and downloadable
  → Manager notified
```

### Flow C: Manager Reviews Progress

```
Manager logs in → Training Analytics
  → Sees all enrolled employees
  → Filters by program / status
  → Clicks employee → training timeline, scores, quiz performance
  → Views Probation Dashboard
    → AI readiness recommendation
    → Override or approve
    → Download probation report
```

---

## System Architecture

### New Components (Phase 2)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         PHASE 2 ARCHITECTURE                            │
├──────────────────────────┬──────────────────────────────────────────────┤
│   ADMIN PORTAL           │   EMPLOYEE PORTAL                            │
│   (extends Phase 1)      │   (new, self-contained)                      │
│                          │                                              │
│  knowledge_base.php       │  training_session.php                        │
│  training_builder.php     │   ├── AI Avatar (D-ID / CSS animated)        │
│  training_analytics.php   │   ├── TTS narration (service TBD)             │
│  probation.php            │   ├── Chat Q&A (RAG + Gemini)                │
│                          │   ├── Scenario simulations                   │
│                          │   └── Quiz / Assessment                      │
├──────────────────────────┴──────────────────────────────────────────────┤
│                         AI ENGINE LAYER                                 │
│                                                                         │
│  Content Generation:  Gemini 2.0 Flash (lesson plans, quizzes, feedback)│
│  RAG Query:           Gemini Embeddings + MySQL FULLTEXT / pgvector      │
│  Voice:               TTS service TBD (ElevenLabs removed)              │
│  Avatar:              D-ID API (primary) / CSS 2D fallback              │
│  Transcription:       Groq Whisper (already integrated)                 │
│  Scoring:             Gemini AI (same pipeline as Phase 1 interview)    │
├─────────────────────────────────────────────────────────────────────────┤
│                         DATA LAYER (new tables)                         │
│                                                                         │
│  knowledge_bases          training_programs       training_enrollments  │
│  kb_documents             training_modules        training_progress     │
│  kb_chunks                training_schedules      training_assessments  │
│  training_certificates    probation_records       training_badges       │
│  versant_tests            versant_results         typing_tests          │
│  typing_results                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Database Schema — New Tables

### `knowledge_bases`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| org_id | INT FK | org-scoped |
| name | VARCHAR(255) | e.g. "Sales Handbook Q1 2026" |
| role_tag | VARCHAR(100) | sales / support / technical / all |
| status | ENUM | processing / ready / error |
| created_at | TIMESTAMP | |

### `kb_documents`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| kb_id | INT FK | |
| filename | VARCHAR(255) | |
| file_path | TEXT | storage path |
| file_type | ENUM | pdf / docx / txt / mp4 / mp3 / url |
| processing_status | ENUM | pending / done / error |
| page_count | INT | |
| extracted_text | LONGTEXT | raw extracted content |

### `kb_chunks`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| doc_id | INT FK | |
| kb_id | INT FK | |
| chunk_text | TEXT | 300–500 token chunk |
| embedding_json | MEDIUMTEXT | Gemini embedding vector (JSON array) |
| chunk_index | INT | position in document |

### `training_programs`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| org_id | INT FK | |
| kb_id | INT FK | source knowledge base |
| name | VARCHAR(255) | |
| role_tag | VARCHAR(100) | |
| duration_days | INT | 7 / 15 / 30 / custom |
| passing_score | INT | default 70 |
| max_quiz_attempts | INT | default 2 |
| avatar_gender | ENUM | male / female |
| language | ENUM | english / hindi / hinglish |
| status | ENUM | draft / active / archived |
| created_by | INT FK | user_id |

### `training_modules`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| program_id | INT FK | |
| day_number | INT | which day this module belongs to |
| order_no | INT | within the day |
| title | VARCHAR(255) | |
| content_text | LONGTEXT | AI-generated lesson content |
| tts_audio_url | TEXT | cached TTS audio |
| estimated_minutes | INT | |
| module_type | ENUM | lesson / scenario / quiz / assessment |
| scenario_json | JSON | for scenario-type modules |
| quiz_json | JSON | auto-generated quiz questions |

### `training_enrollments`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| candidate_id | INT FK | links to existing candidates table |
| program_id | INT FK | |
| enrolled_by | INT FK | user_id of assigning HR |
| start_date | DATE | |
| expected_end_date | DATE | |
| status | ENUM | not_started / in_progress / completed / failed |
| final_score | INT | |
| certificate_url | TEXT | |
| unique_token | VARCHAR(64) | training access token (like interview token) |

### `training_progress`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| enrollment_id | INT FK | |
| module_id | INT FK | |
| status | ENUM | locked / unlocked / in_progress / completed |
| quiz_score | INT | |
| quiz_attempts | INT | |
| time_spent_seconds | INT | |
| completed_at | TIMESTAMP | |

### `training_assessments`
Per-question answers for quizzes and final assessments (same pattern as `interview_answers` in Phase 1).

### `probation_records`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| enrollment_id | INT FK | |
| probation_days | INT | |
| start_date, end_date | DATE | |
| ai_recommendation | ENUM | ready / needs_more_time / re_training |
| manager_decision | ENUM | approved / extended / terminated |
| decision_by | INT FK | |
| decision_notes | TEXT | |

### `training_certificates`
Stores generated certificate metadata: enrollment_id, issued_at, pdf_url, score, badge_list.

### `versant_tests`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| org_id | INT FK | |
| name | VARCHAR(255) | |
| language | ENUM | english / hindi / hinglish |
| passing_score | INT | default 65 |
| custom_passages_json | JSON | admin-uploaded reading passages |
| created_by | INT FK | |

### `versant_results`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| candidate_id | INT FK | |
| enrollment_id | INT FK | NULL for Phase 1 pre-hire use |
| test_id | INT FK | |
| pronunciation_score | INT | 0–20 |
| fluency_score | INT | 0–20 |
| vocabulary_score | INT | 0–20 |
| grammar_score | INT | 0–20 |
| coherence_score | INT | 0–20 |
| total_score | INT | 0–100 |
| cefr_level | VARCHAR(3) | A1 / A2 / B1 / B2 / C1 / C2 |
| ai_summary | TEXT | Gemini-generated feedback |
| recordings_json | JSON | {section: audio_url} per section |
| completed_at | TIMESTAMP | |

### `typing_tests`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| org_id | INT FK | |
| name | VARCHAR(255) | |
| duration_seconds | INT | 60 / 180 / 300 / custom |
| min_wpm | INT | threshold for pass |
| min_accuracy | INT | threshold (%) for pass |
| paragraph_types_json | JSON | enabled types for this test |
| max_attempts | INT | default 2 |
| created_by | INT FK | |

### `typing_results`
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK | |
| candidate_id | INT FK | |
| enrollment_id | INT FK | NULL for Phase 1 pre-hire use |
| test_id | INT FK | |
| gross_wpm | INT | |
| net_wpm | INT | errors penalized |
| accuracy_pct | DECIMAL(5,2) | |
| consistency_score | INT | 0–100 (low deviation = high score) |
| composite_score | INT | 0–100 weighted final |
| chars_typed | INT | |
| errors | INT | |
| attempt_number | INT | |
| paragraph_used | TEXT | the paragraph shown |
| completed_at | TIMESTAMP | |

---

## API Endpoints — Phase 2

| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/kb_upload.php` | POST | Upload knowledge base document |
| `api/kb_process.php` | CLI | Chunk, embed, and index documents |
| `api/kb_query.php` | POST | RAG query: find relevant chunks for a question |
| `api/training_programs.php` | GET/POST | CRUD for training programs + modules |
| `api/training_assign.php` | POST | Assign program to candidate; generate token |
| `api/training_chat.php` | POST | Employee Q&A with AI trainer (RAG + Gemini) |
| `api/training_tts.php` | POST | Generate TTS audio for a module (cache in uploads/) |
| `api/training_progress.php` | GET/POST | Load / save module progress |
| `api/training_scenario.php` | POST | Generate scenario turn + evaluate response |
| `api/training_assessment.php` | GET/POST | Generate quiz / submit answers / score |
| `api/training_certificate.php` | POST | Generate PDF certificate |
| `api/training_analytics.php` | GET | Aggregate analytics for manager dashboard |
| `api/probation.php` | GET/POST | Probation status, recommendations, reports |
| `api/versant.php` | GET/POST | Submit Versant answers, fetch results, admin CRUD |
| `api/versant_score.php` | CLI | Whisper transcription + Gemini scoring per section |
| `api/typing_test.php` | GET/POST | Save typing result, fetch best score, admin CRUD |

---

## New Pages — Phase 2

| File | Access | Description |
|------|--------|-------------|
| `knowledge_base.php` | admin+ | Upload and manage knowledge bases |
| `training_builder.php` | admin+ | Create and edit training programs (module editor) |
| `training_programs.php` | admin+ | List all programs: status, assigned count, completion % |
| `training_analytics.php` | all roles | Training performance dashboard |
| `probation.php` | admin+ | Probation tracker for all enrolled employees |
| `training_session.php` | PUBLIC (token) | Employee training interface (self-contained, like interview.php) |
| `training_complete.php` | PUBLIC | Completion screen + certificate download |
| `versant.php` | admin+ | Manage Versant test configs + view all candidate results |
| `versant_test.php` | PUBLIC (token) | Employee-facing Versant communication test (self-contained) |
| `typing_test.php` | PUBLIC (token) | Employee-facing typing speed test (self-contained) |

---

## Phase 2 — Navigation Updates

New nav items for Phase 2 (shown based on role):

```
Dashboard | Campaigns | Candidates | Analytics | Outreach | Credits |
Training ▾ (dropdown)
  ├── Knowledge Base
  ├── Training Programs
  ├── Training Analytics
  ├── Probation Tracker
  ├── Versant Tests (Communication)
  └── Typing Tests
Audit Logs | Guide | Admins | Super Admin
```

Or as individual top-level items if dropdown is not preferred.

---

## Integration Points with Phase 1

| Phase 1 Component | Phase 2 Extension |
|-------------------|-------------------|
| `candidates` table | Add `employee_status` ENUM column: hired / in_training / trained / deployed |
| Candidate status flow | Extended: shortlisted → **hired → in_training → training_complete → deployed** |
| Credit system | New credit types: `training_session_credits`, `tts_credits`, `avatar_credits` |
| WhatsApp outreach | Send training invite + daily reminder + completion notification |
| AI scoring pipeline (api/score.php) | Reused for scoring short-answer training assessments + Versant evaluation |
| Groq Whisper | Reused for Versant speech transcription per section |
| TTS Service (TBD) | AI trainer voice narration — ElevenLabs removed; Gemini TTS or alternative to be selected |
| Gemini API / Vertex AI | Extended for RAG, content generation, scenario evaluation |
| Audit logs | All training mutations logged to existing `audit_logs` table |
| Analytics page | Training tab added to existing analytics.php |
| Export system | Training reports follow same HMAC-token export pattern |

---

## Technology Stack — Phase 2 Additions

| Component | Technology | Notes |
|-----------|-----------|-------|
| AI Content Generation | Gemini 2.0 Flash (already in .env) | Lesson plans, quizzes, feedback |
| Vector/Semantic Search | Gemini Embeddings API + MySQL FULLTEXT | RAG for Q&A |
| Voice Narration | TTS service TBD (ElevenLabs removed) | AI trainer voice + Versant TTS prompts |
| Avatar | D-ID API (primary) OR CSS 2D animated character | Talking trainer on screen |
| Document Parsing | Apache Tika / pdftotext / php-docx-reader | Extract text from PDF/DOCX |
| PDF Certificate | TCPDF or mPDF (PHP library) | Generate training + Versant certificates |
| Speech Transcription | Groq Whisper (already integrated) | Versant section recordings |
| Communication Scoring | Gemini AI (already integrated) | Versant 5-dimension evaluation |
| Typing Engine | Vanilla JS (no external lib) | Pure client-side: WPM counter, live highlighting, scoring |

**New .env keys required:**
```
DID_API_KEY               # D-ID avatar API
GEMINI_EMBEDDING_MODEL    # e.g. text-embedding-004
TIKA_SERVER_URL           # optional: Apache Tika for doc parsing
```

---

## Milestone & Timeline

| Milestone | Deliverable | Estimated Duration |
|-----------|-------------|-------------------|
| M1 | Knowledge Base module: upload, parse, chunk, embed, search | 3 weeks |
| M2 | Training Program Builder: create, AI-generate modules, assign | 2 weeks |
| M3 | Employee Training Portal: layout, module rendering, TTS narration | 3 weeks |
| M4 | AI Avatar integration (D-ID or CSS fallback) | 2 weeks |
| M5 | Interactive Q&A with AI trainer (RAG + Gemini) | 2 weeks |
| M6 | Scenario & Role-play engine | 2 weeks |
| M7 | Assessment engine: auto-quiz generation, scoring, certification | 2 weeks |
| M8 | Probation Management module | 1 week |
| M9 | Training Analytics dashboard | 1 week |
| M10 | Gamification (badges, leaderboard, XP) | 1 week |
| M11 | AI Knowledge Assistant (24/7 chat widget) | 1 week |
| M12 | Compliance & Policy training module | 1 week |
| M13 | Versant communication test: 5-section interface, Whisper + Gemini scoring, CEFR output | 2 weeks |
| M14 | Typing test: real-time WPM engine, paragraph pool, role-specific modes, client-side scoring | 1 week |
| M15 | WhatsApp integration: training invites + reminders | 1 week |
| M16 | QA, load testing, security audit | 2 weeks |
| **Total** | **Phase 2 Complete** | **~27 weeks** |

---

## Priorities (MoSCoW)

### Must Have (Phase 2.0 — MVP)
- Knowledge base upload and AI processing
- Training program builder with AI-generated modules
- Employee training portal (AI avatar + TTS narration)
- Q&A with AI trainer (RAG)
- Module-level quiz (auto-generated)
- Final assessment + pass/fail
- Probation tracker
- Training analytics dashboard
- **Typing Test** — Net WPM, accuracy %, real-time feedback, configurable thresholds
- **Versant Communication Test** — 5-section spoken English assessment, CEFR score

### Should Have (Phase 2.1)
- Scenario & role-play engine
- Completion certificate (PDF)
- WhatsApp reminders during training
- Multilingual training
- Manager override on probation decisions

### Could Have (Phase 2.2)
- Gamification (XP, badges, leaderboard)
- AI Knowledge Assistant (24/7 chat)
- Compliance & Policy module
- Cohort comparison analytics
- AI Skill Gap Report

### Won't Have (Phase 3 scope)
- Native mobile app
- Zoom/video call integration with human trainer
- HR payroll integration
- Learning Management System (LMS) certification imports

---

## Risk & Mitigation

| Risk | Impact | Mitigation |
|------|--------|-----------|
| D-ID avatar API latency | High | CSS-animated 2D avatar fallback ready at launch |
| Large document processing time | Medium | Async CLI processing (like api/score.php pattern); show "processing" status |
| Gemini embedding API cost at scale | Medium | Cache all embeddings; only re-embed on KB update |
| Employee token security | High | Same unique_token pattern as interview.php — one-time link, expiry configurable |
| TTS audio costs | Medium | Cache generated audio per module; never re-generate same content |
| Knowledge base data privacy | High | Org-scoped isolation at DB level; same as existing org_id pattern |

---

## Phase 2 — Credit System Extension

| Credit Type | Used For | Estimated Cost |
|-------------|----------|---------------|
| `training_tts` | Per module audio generation (TTS service TBD) | Per 1000 characters |
| `training_avatar` | Per D-ID avatar video render | Per 30-second clip |
| `training_session` | Per day of training access | Per employee per day |
| `training_whatsapp` | WhatsApp reminders during training | Per message (existing WA credits) |

---

## Definition of Done (Phase 2)

- [ ] Company uploads a knowledge base and AI generates a 7-day training program in under 2 minutes
- [ ] Employee opens training link, sees AI avatar, hears content narrated, asks a question and gets an answer from the KB
- [ ] Employee completes a module quiz with AI scoring
- [ ] Employee completes final assessment and receives a downloadable certificate
- [ ] Manager sees probation dashboard with AI readiness recommendation
- [ ] Training analytics shows completion %, avg score, time spent per module
- [ ] All new pages are mobile-responsive
- [ ] All API endpoints use prepared statements, CSRF (admin), and JWT validation
- [ ] All training mutations are audit-logged
- [ ] Credit deduction for training sessions is atomic (same pattern as Phase 1)

---

## Summary: Phase 1 → Phase 2 Transformation

```
PHASE 1 (Complete):                     PHASE 2 (This SOW):
═══════════════════                     ══════════════════
Find candidates         →               Train hired employees
AI Interview            →               AI Training Sessions
Auto-scoring            →               Auto-assessment + certification
Face detection          →               Engagement tracking during training
Campaign management     →               Training program management
WhatsApp outreach       →               Training invites + progress reminders
Analytics (hiring)      →               Analytics (learning + probation)
Candidate portal        →               Employee training portal
────────────────────────────────────────────────────────────────
New in Phase 2:
  Versant Test    → Spoken English / Communication Score (0–100 + CEFR)
  Typing Test     → Net WPM + Accuracy + Composite Score (client-side)
  Both usable in Phase 1 (pre-hire screening) AND Phase 2 (training certification)
────────────────────────────────────────────────────────────────────────
RESULT: HireAI becomes a complete AI Talent Lifecycle Platform:
  Hire → Screen (Versant + Typing) → Train → Certify → Deploy
```

---

*Document version 1.0 — HireAI Phase 2 SOW*  
*Avyukta Intellicall — hire.clouddialer.in*
