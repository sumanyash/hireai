USE hireai;

-- Add missing columns to interview_sessions
ALTER TABLE interview_sessions 
ADD COLUMN IF NOT EXISTS cheat_summary JSON,
ADD COLUMN IF NOT EXISTS video_url TEXT,
ADD COLUMN IF NOT EXISTS total_questions INT DEFAULT 0;

-- Create interview_questions table (new system)
CREATE TABLE IF NOT EXISTS interview_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    question_number INT DEFAULT 1,
    question_text TEXT NOT NULL,
    parameter VARCHAR(100),
    parameter_label VARCHAR(200),
    weight INT DEFAULT 15,
    max_marks INT DEFAULT 15,
    ideal_answer_hint TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add cheat_summary to interview_sessions if not exists
ALTER TABLE interview_sessions 
ADD COLUMN IF NOT EXISTS cheat_summary JSON;

-- Add score_breakdown to interview_results
ALTER TABLE interview_results 
ADD COLUMN IF NOT EXISTS score_breakdown JSON;

-- Add video_url to interview_sessions
ALTER TABLE interview_sessions
ADD COLUMN IF NOT EXISTS video_url TEXT;

-- Sync interview_questions from questions table
INSERT IGNORE INTO interview_questions (campaign_id, question_number, question_text, parameter, parameter_label, weight, max_marks, ideal_answer_hint)
SELECT campaign_id, order_no, question_text, parameter, parameter_label, weight, max_marks, ideal_answer_hint
FROM questions;

SELECT 'DB upgrade complete!' as status;
