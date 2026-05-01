-- ============================================
-- HireAI MySQL Schema
-- Run: mysql -u root -p < schema.sql
-- ============================================

CREATE DATABASE IF NOT EXISTS hireai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hireai;

CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    logo_url TEXT,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','hr','recruiter') DEFAULT 'hr',
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    created_by INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    job_role VARCHAR(255),
    description TEXT,
    share_token VARCHAR(64) UNIQUE,
    start_date DATE NULL,
    end_date DATE NULL,
    el_agent_id VARCHAR(150),
    passing_score INT DEFAULT 70,
    max_duration_minutes INT DEFAULT 15,
    num_questions INT DEFAULT 6,
    language ENUM('english','hinglish','hindi') DEFAULT 'english',
    status ENUM('draft','active','paused','completed') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    parameter VARCHAR(100) NOT NULL,
    parameter_label VARCHAR(200) NOT NULL,
    weight INT DEFAULT 15,
    max_marks INT DEFAULT 15,
    question_text TEXT NOT NULL,
    ideal_answer_hint TEXT,
    question_type ENUM('text','textarea','number','decimal','date','dropdown','multi_select','rating','file','audio','video','hyperlink') DEFAULT 'textarea',
    options_json JSON NULL,
    branch_rules_json JSON NULL,
    is_required TINYINT DEFAULT 1,
    order_no INT DEFAULT 1,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    campaign_id INT,
    name VARCHAR(255),
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255),
    city VARCHAR(100),
    experience_years DECIMAL(3,1) DEFAULT 0,
    current_ctc VARCHAR(50),
    expected_ctc VARCHAR(50),
    source VARCHAR(100) DEFAULT 'manual',
    salutation VARCHAR(20),
    dob DATE NULL,
    relocate VARCHAR(50),
    relocate_time VARCHAR(100),
    phone_code VARCHAR(10),
    college VARCHAR(255),
    engagement_type VARCHAR(100),
    english_level VARCHAR(100),
    industry VARCHAR(150),
    exp_type VARCHAR(100),
    exp_desc TEXT,
    current_salary VARCHAR(50),
    expected_salary VARCHAR(50),
    tenure VARCHAR(100),
    joining_date DATE NULL,
    flex_hours VARCHAR(50),
    laptop VARCHAR(50),
    internet VARCHAR(50),
    commute VARCHAR(100),
    tech_skills TEXT,
    soft_skills TEXT,
    resume_path TEXT,
    video_path TEXT,
    portfolio TEXT,
    ai_test_willing VARCHAR(50),
    referred_by_candidate_id INT NULL,
    unique_token VARCHAR(128) UNIQUE NOT NULL,
    status ENUM('pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold') DEFAULT 'pending',
    call_id VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS outreach_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    campaign_id INT NOT NULL,
    channel ENUM('whatsapp','sms','email','call') NOT NULL,
    status ENUM('sent','delivered','failed') DEFAULT 'sent',
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interview_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    campaign_id INT NOT NULL,
    el_conversation_id VARCHAR(150),
    full_transcript TEXT,
    recording_url TEXT,
    duration_seconds INT DEFAULT 0,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    status ENUM('initiated','in_progress','completed','failed') DEFAULT 'initiated'
);

CREATE TABLE IF NOT EXISTS scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    campaign_id INT NOT NULL,
    parameter VARCHAR(100),
    parameter_label VARCHAR(200),
    transcript TEXT,
    ai_score INT DEFAULT 0,
    max_marks INT DEFAULT 15,
    ai_reasoning TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interview_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT UNIQUE NOT NULL,
    campaign_id INT NOT NULL,
    total_score INT DEFAULT 0,
    max_score INT DEFAULT 100,
    pass_fail ENUM('pass','fail','pending') DEFAULT 'pending',
    ai_summary TEXT,
    recruiter_override_score INT NULL,
    recruiter_override_reason TEXT NULL,
    overridden_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interview_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    candidate_id INT NOT NULL,
    question_id INT NOT NULL,
    text_answer TEXT,
    audio_url TEXT,
    answer_mode ENUM('voice','text') DEFAULT 'text',
    time_taken INT DEFAULT 0,
    copy_count INT DEFAULT 0,
    cheat_flags JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add cheat_summary to interview_sessions if not exists
ALTER TABLE interview_sessions ADD COLUMN IF NOT EXISTS cheat_summary JSON;

ALTER TABLE questions ADD COLUMN IF NOT EXISTS question_type ENUM('text','textarea','number','decimal','date','dropdown','multi_select','rating','file','audio','video','hyperlink') DEFAULT 'textarea';
ALTER TABLE questions ADD COLUMN IF NOT EXISTS options_json JSON NULL;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS branch_rules_json JSON NULL;
ALTER TABLE questions ADD COLUMN IF NOT EXISTS is_required TINYINT DEFAULT 1;

ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS share_token VARCHAR(64) UNIQUE;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS start_date DATE NULL;
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS end_date DATE NULL;

ALTER TABLE candidates ADD COLUMN IF NOT EXISTS salutation VARCHAR(20);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS dob DATE NULL;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS relocate VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS relocate_time VARCHAR(100);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS phone_code VARCHAR(10);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS college VARCHAR(255);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS engagement_type VARCHAR(100);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS english_level VARCHAR(100);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS industry VARCHAR(150);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS exp_type VARCHAR(100);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS exp_desc TEXT;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS current_salary VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS expected_salary VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS tenure VARCHAR(100);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS joining_date DATE NULL;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS flex_hours VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS laptop VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS internet VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS commute VARCHAR(100);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS tech_skills TEXT;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS soft_skills TEXT;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS resume_path TEXT;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS video_path TEXT;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS portfolio TEXT;
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS ai_test_willing VARCHAR(50);
ALTER TABLE candidates ADD COLUMN IF NOT EXISTS referred_by_candidate_id INT NULL;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    user_id INT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    action VARCHAR(80) NOT NULL,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reminder_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    campaign_id INT NOT NULL,
    channel ENUM('whatsapp','sms','email') DEFAULT 'whatsapp',
    status ENUM('pending','sent','failed','cancelled') DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    attempts INT DEFAULT 0,
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS recruiter_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    user_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ─── SEED DATA ───────────────────────────────
INSERT IGNORE INTO organizations (id, name) VALUES (1, 'Avyukta Intellicall');

-- Password: Admin@123
INSERT IGNORE INTO users (id, org_id, name, email, password_hash, role) VALUES
(1, 1, 'Super Admin', 'admin@hireai.in', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin');

-- Campaign 1: AI Developer
INSERT IGNORE INTO campaigns (id, org_id, created_by, name, job_role, description, el_agent_id, passing_score, num_questions, status) VALUES
(1, 1, 1, 'AI Developer Hiring - Batch 1', 'AI Developer', 'Technical screening for AI/ML Developer roles', 'PASTE_YOUR_EL_AGENT_ID', 70, 6, 'active');

-- Questions for Campaign 1
INSERT IGNORE INTO questions (campaign_id, parameter, parameter_label, weight, max_marks, question_text, ideal_answer_hint, order_no) VALUES
(1,'english_communication','English Communication Skills',15,15,'Please explain a technical challenge you solved recently in simple English. You have 1 minute.','Clarity, structure, professional vocabulary, confidence',1),
(1,'ai_tools_usage','AI Tools Usage',15,15,'Which AI tools have you used for development? Name three and their best feature.','ChatGPT/Copilot/Claude, specific use cases, practical experience',2),
(1,'ai_prompting','AI Prompting',15,15,'Explain how you would write a prompt to generate Python code for a REST API.','Role assignment, context, specific requirements, output format',3),
(1,'ai_projects','AI Projects Done',20,20,'Describe one AI project you built from scratch. What was your role and the outcome?','Clear project, specific role, tech stack, quantifiable results',4),
(1,'machine_learning','Machine Learning',20,20,'Explain overfitting in ML and how you would prevent it in a real project.','Correct definition, regularization/dropout/cross-validation, examples',5),
(1,'api_db_integration','API & DB Integration (Python/SQL)',15,15,'How do you connect Python to MySQL and fetch data? Explain with an example.','mysql-connector/SQLAlchemy, connection, cursor, execute, fetchall',6);
