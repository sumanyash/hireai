<?php
require_once __DIR__ . '/includes/auth_check.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: candidates.php'); exit; }

$c = db_fetch_one(
    "SELECT c.*, camp.name campaign_name, camp.id campaign_id, camp.job_role, camp.passing_score,
            COALESCE(ref.name, c.referred_by_name) AS referred_by_display_name, ref.phone AS referred_by_phone
     FROM candidates c LEFT JOIN campaigns camp ON c.campaign_id=camp.id
     LEFT JOIN candidates ref ON c.referred_by_candidate_id=ref.id
     WHERE c.id=? AND c.org_id=?",
    [$id, $user['org_id']], 'ii'
);
if (!$c) { header('Location: candidates.php'); exit; }

$session   = db_fetch_one("SELECT * FROM interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1", [$id], 'i');
$result    = db_fetch_one("SELECT * FROM interview_results  WHERE candidate_id=? ORDER BY id DESC LIMIT 1", [$id], 'i');
$scores    = db_fetch_all("SELECT * FROM scores WHERE candidate_id=? ORDER BY id", [$id], 'i');
$scoreByParameter = [];
foreach ($scores as $scoreRow) {
    $scoreByParameter[(string)($scoreRow['parameter'] ?? '')] = $scoreRow;
}
$notes_db  = db_fetch_all(
    "SELECT rn.*, u.name recruiter_name FROM recruiter_notes rn
     JOIN users u ON rn.user_id=u.id WHERE rn.candidate_id=? ORDER BY rn.created_at DESC",
    [$id], 'i'
);
$answers   = db_fetch_all(
    "SELECT ia.*, q.question_text, q.order_no AS question_number, q.parameter, q.parameter_label, q.max_marks
     FROM interview_answers ia LEFT JOIN questions q ON ia.question_id=q.id
     WHERE ia.candidate_id=? ORDER BY q.order_no ASC, ia.id ASC",
    [$id], 'i'
);
function answer_has_gradable_response($answer) {
    if (!$answer) return false;
    $text = trim((string)($answer['text_answer'] ?? ''));
    if ($text !== '' && !str_starts_with($text, '[Voice answer recorded but upload failed:')) return true;
    return trim((string)($answer['audio_url'] ?? '')) !== '';
}
$answerByQuestion = [];
foreach ($answers as $answerRow) {
    $qid = (int)($answerRow['question_id'] ?? 0);
    if (!$qid) continue;
    if (!isset($answerByQuestion[$qid]) || answer_has_gradable_response($answerRow)) {
        $answerByQuestion[$qid] = $answerRow;
    }
}
$questions = db_fetch_all(
    "SELECT *, order_no AS question_number FROM questions WHERE campaign_id=? ORDER BY order_no ASC",
    [$c['campaign_id']], 'i'
);
$ai_calls = db_fetch_all(
    "SELECT * FROM ai_call_results WHERE candidate_id=? ORDER BY received_at DESC LIMIT 5",
    [$id], 'i'
);
$ai_call = $ai_calls[0] ?? null;
$questionById = [];
foreach ($questions as $qRow) $questionById[(int)$qRow['id']] = $qRow;
$scoreByQuestion = [];
$scoreByQid = [];
foreach ($scores as $sRow) {
    $sqid = (int)($sRow['question_id'] ?? 0);
    if ($sqid > 0) $scoreByQid[$sqid] = $sRow;
}
foreach ($questions as $qRow) {
    $qid = (int)$qRow['id'];
    if (isset($scoreByQid[$qid])) {
        $scoreByQuestion[$qid] = $scoreByQid[$qid];
    } else {
        $param = (string)($qRow['parameter'] ?? '');
        if ($param !== '' && isset($scoreByParameter[$param])) {
            $scoreByQuestion[$qid] = $scoreByParameter[$param];
        }
    }
}
$displayScores = [];
foreach ($questions as $qRow) {
    $qid = (int)$qRow['id'];
    $scoreRow = $scoreByQuestion[$qid] ?? null;
    $aiScore = $scoreRow ? (int)($scoreRow['ai_score'] ?? 0) : 0;
    $reasoning = $scoreRow['ai_reasoning'] ?? '';
    if (!answer_has_gradable_response($answerByQuestion[$qid] ?? null)) {
        $aiScore = 0;
        $reasoning = 'No gradable response recorded.';
    }
    $displayScores[] = [
        'question_id' => $qid,
        'parameter' => (string)($qRow['parameter'] ?? ''),
        'parameter_label' => (string)($qRow['parameter_label'] ?? ''),
        'ai_score' => $aiScore,
        'max_marks' => (int)($qRow['max_marks'] ?? 0),
        'ai_reasoning' => $reasoning,
    ];
}
$displayTotalScore = array_sum(array_map(fn($row) => (int)($row['ai_score'] ?? 0), $displayScores));
$displayMaxScore   = array_sum(array_map(fn($row) => (int)($row['max_marks'] ?? 0), $displayScores));
$campaigns = db_fetch_all("SELECT id,name,job_role FROM campaigns WHERE org_id=? ORDER BY name", [$user['org_id']], 'i');

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    if (isset($_POST['add_note'])) {
        db_insert("INSERT INTO recruiter_notes (candidate_id,user_id,note) VALUES (?,?,?)",
            [$id, $user['user_id'], $_POST['note']], 'iis');
        header("Location: candidate_detail.php?id=$id&toast=note_added"); exit;
    }
    if (isset($_POST['update_status'])) {
        db_execute("UPDATE candidates SET status=?, updated_at=NOW() WHERE id=?",
            [$_POST['status'], $id], 'si');
        header("Location: candidate_detail.php?id=$id&toast=status_updated"); exit;
    }
    if (isset($_POST['override_score'])) {
        db_execute(
            "UPDATE interview_results SET recruiter_override_score=?,recruiter_override_reason=?,overridden_by=? WHERE candidate_id=?",
            [(int)$_POST['override_score'], $_POST['reason'], $user['user_id'], $id], 'isii'
        );
        header("Location: candidate_detail.php?id=$id&toast=score_updated"); exit;
    }
    if (isset($_POST['save_manual_scores'])) {
        $manual_scores = $_POST['manual_scores'] ?? [];
        $manual_reason = trim((string)($_POST['manual_reason'] ?? 'Manual recruiter review'));
        $existing_scores = db_fetch_all("SELECT * FROM scores WHERE candidate_id=?", [$id], 'i');
        $existing_by_parameter = [];
        $existing_by_qid = [];
        foreach ($existing_scores as $row) {
            $existing_by_parameter[(string)($row['parameter'] ?? '')] = $row;
            $qid = (int)($row['question_id'] ?? 0);
            if ($qid > 0) $existing_by_qid[$qid] = $row;
        }

        foreach ($manual_scores as $question_id => $manual_score) {
            $question_id = (int)$question_id;
            if (!isset($questionById[$question_id])) continue;
            $question = $questionById[$question_id];
            $parameter = (string)($question['parameter'] ?? '');
            if ($parameter === '') continue;
            $max_marks = max(0, (int)($question['max_marks'] ?? 0));
            $score_val = max(0, min((int)$manual_score, $max_marks));
            $existing_score = $existing_by_qid[$question_id] ?? $existing_by_parameter[$parameter] ?? null;
            if ($existing_score) {
                db_execute(
                    "UPDATE scores SET question_id=?, ai_score=?, max_marks=?, ai_reasoning=? WHERE id=? AND candidate_id=?",
                    [$question_id, $score_val, $max_marks, 'Manual review: ' . $manual_reason, (int)$existing_score['id'], $id], 'iiisii'
                );
            } else {
                db_insert(
                    "INSERT INTO scores (candidate_id,question_id,campaign_id,parameter,parameter_label,ai_score,max_marks,ai_reasoning) VALUES (?,?,?,?,?,?,?,?)",
                    [$id, $question_id, $c['campaign_id'], $parameter, (string)($question['parameter_label'] ?? ''), $score_val, $max_marks, 'Manual review: ' . $manual_reason],
                    'iiissiis'
                );
            }
        }

        $totals = db_fetch_one(
            "SELECT COALESCE(SUM(ai_score),0) total_score, COALESCE(SUM(max_marks),0) max_score FROM scores WHERE candidate_id=?",
            [$id], 'i'
        ) ?: ['total_score' => 0, 'max_score' => 0];
        $total_score = (int)$totals['total_score'];
        $max_score = max(1, (int)$totals['max_score']);
        $pct_score = round($total_score / $max_score * 100);
        $pf_new = $pct_score >= (int)($c['passing_score'] ?? 70) ? 'pass' : 'fail';
        $summary = "Manual score review by {$user['name']}: {$manual_reason}";

        if ($result) {
            db_execute(
                "UPDATE interview_results SET total_score=?, max_score=?, pass_fail=?, ai_summary=?, recruiter_override_score=NULL, recruiter_override_reason=NULL, overridden_by=?, updated_at=NOW() WHERE candidate_id=?",
                [$total_score, $max_score, $pf_new, $summary, $user['user_id'], $id], 'iissii'
            );
        } else {
            db_insert(
                "INSERT INTO interview_results (candidate_id,campaign_id,total_score,max_score,pass_fail,ai_summary,overridden_by) VALUES (?,?,?,?,?,?,?)",
                [$id, $c['campaign_id'], $total_score, $max_score, $pf_new, $summary, $user['user_id']], 'iiiissi'
            );
        }

        $new_status = $pf_new === 'pass' ? 'shortlisted' : 'rejected';
        db_execute("UPDATE candidates SET status=?, updated_at=NOW() WHERE id=?", [$new_status, $id], 'si');
        header("Location: candidate_detail.php?id=$id&toast=manual_scores_saved"); exit;
    }
}

$display_score = !is_null($result['recruiter_override_score'] ?? null)
    ? (int)$result['recruiter_override_score']
    : ($result ? (int)$result['total_score'] : ($displayTotalScore > 0 ? $displayTotalScore : null));
$display_max   = $result ? (int)($result['max_score'] ?? 100) : ($displayMaxScore ?: 100);
$pct           = ($display_score !== null && $display_max > 0) ? min(100, round($display_score / $display_max * 100)) : 0;
$ringCirc      = round(2 * M_PI * 46, 2);
$ringOffset    = round($ringCirc * (1 - $pct / 100), 2);
$avatarColors  = ['135deg,#6366F1,#8B5CF6','135deg,#3B82F6,#6366F1','135deg,#0EA5E9,#3B82F6','135deg,#10B981,#059669','135deg,#F59E0B,#EF4444'];
$avatarGrad    = $avatarColors[ord(strtolower($c['name'][0] ?? 'a')) % count($avatarColors)];
$nameParts     = array_filter(explode(' ', trim($c['name'])));
$initials      = strtoupper(substr($c['name'], 0, 1));
if (count($nameParts) > 1) $initials .= strtoupper(substr(end($nameParts), 0, 1));
$pf            = $result['pass_fail'] ?? null;
$scoreColor    = $pf === 'pass' ? '#10B981' : ($pf === 'fail' ? '#EF4444' : '#94A3B8');
$scoreBg       = $pf === 'pass' ? '#ECFDF5' : ($pf === 'fail' ? '#FEF2F2' : '#F8FAFC');
$recUrl        = $session['recording_url'] ?? $session['video_url'] ?? $session['audio_url'] ?? null;
if (!$recUrl) {
    $videoFiles = glob(__DIR__ . '/uploads/video/session_' . (int)$id . '_*.webm') ?: [];
    if ($videoFiles) {
        rsort($videoFiles);
        $recUrl = BASE_URL . '/uploads/video/' . basename($videoFiles[0]);
    }
}
$breakdown     = !empty($result['score_breakdown']) ? json_decode($result['score_breakdown'], true) : null;
$cheat         = !empty($session['cheat_summary'])  ? json_decode($session['cheat_summary'],  true) : null;
$quickTabSwitches = (int)($cheat['tab_switches'] ?? 0);
$quickCopyPaste   = (int)($cheat['copy_paste'] ?? 0);
$quickFlagTotal   = $quickTabSwitches + $quickCopyPaste;
$interviewLink = defined('INTERVIEW_URL') ? INTERVIEW_URL . '?t=' . htmlspecialchars($c['unique_token'] ?? '') : '';
$toast         = $_GET['toast'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title><?= htmlspecialchars($c['name']) ?> — HireAI</title>
<?php include __DIR__ . '/includes/head.php'; ?>
<style>
/* TOAST */
.toast{position:fixed;bottom:28px;right:28px;z-index:9999;padding:14px 22px;border-radius:14px;font-size:14px;font-weight:600;color:#fff;display:flex;align-items:center;gap:10px;box-shadow:0 8px 40px rgba(0,0,0,.25);animation:toastIn .3s cubic-bezier(.4,0,.2,1);max-width:360px;pointer-events:none}
.toast-success{background:linear-gradient(135deg,#059669,#10B981)}
.toast-error{background:linear-gradient(135deg,#DC2626,#EF4444)}
.toast-info{background:linear-gradient(135deg,#1D4ED8,#3B82F6)}
@keyframes toastIn{from{opacity:0;transform:translateY(20px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes toastOut{to{opacity:0;transform:translateY(20px) scale(.95)}}

/* LAYOUT */
.detail-grid{display:grid;grid-template-columns:310px 1fr;gap:20px;align-items:start;grid-auto-rows:min-content}
.detail-grid>div:last-child{position:sticky;top:86px;max-height:calc(100vh - 106px);overflow-y:auto;padding-right:2px}
@media(max-width:1024px){.detail-grid{grid-template-columns:1fr}.detail-grid>div:last-child{position:static;max-height:none;overflow-y:visible}}

/* INFO */
.info-row{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid #F1F5F9}
.info-row:last-child{border-bottom:none}
.info-key{font-size:11px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;width:90px;flex-shrink:0;padding-top:2px}
.info-val{font-size:14px;font-weight:500;color:var(--text);word-break:break-all}

/* SCORE RING */
.ring-wrap{position:relative;width:120px;height:120px;margin:0 auto 14px}
.ring-track{fill:none;stroke:#F1F5F9;stroke-width:8}
.ring-fill{fill:none;stroke-width:8;stroke-linecap:round;transform-origin:center;transform:rotate(-90deg);transition:stroke-dashoffset 1.2s cubic-bezier(.4,0,.2,1)}
.ring-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.ring-score{font-size:30px;font-weight:900;letter-spacing:-1.5px;line-height:1}
.ring-max{font-size:11px;font-weight:700;color:var(--gray);letter-spacing:.3px;opacity:.7}

/* SCORE BARS */
.sbar-wrap{background:#E2E8F0;border-radius:99px;height:7px;overflow:hidden;margin-top:4px}
.sbar-fill{height:7px;border-radius:99px;transition:width 1.1s cubic-bezier(.4,0,.2,1)}

/* TABS */
.tabs{display:flex;gap:2px;background:#F1F5F9;padding:4px;border-radius:12px;margin-bottom:20px}
.tab-btn{flex:1;padding:9px 10px;border-radius:9px;border:none;background:transparent;font-size:13px;font-weight:600;color:var(--gray2);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:5px}
.tab-btn.active{background:#fff;color:var(--text);box-shadow:0 1px 6px rgba(0,0,0,.1)}
.tab-panel{display:none}
.tab-panel.active{display:block}
.tab-badge{background:var(--blue);color:#fff;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:800}
.tab-badge-orange{background:var(--orange)}

/* Q&A */
.qa-item{background:#F8FAFC;border-radius:14px;padding:16px;margin-bottom:10px;border:1.5px solid #F1F5F9;transition:all .2s}
.qa-item:hover{border-color:rgba(37,99,235,.2);box-shadow:0 4px 18px rgba(37,99,235,.07)}
.qa-q{font-size:13px;font-weight:700;color:var(--text2);margin-bottom:10px;display:flex;align-items:flex-start;gap:8px}
.q-num{background:linear-gradient(135deg,var(--blue),var(--accent));color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.qa-a{font-size:14px;color:var(--text);line-height:1.7;padding:12px 14px;background:#fff;border-radius:10px;border:1.5px solid var(--light)}
.qa-meta{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;align-items:center}
.qa-tag{font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:#EFF6FF;color:#1E40AF;display:inline-flex;align-items:center;gap:4px}
.qa-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:10px}
.qa-head .qa-q{margin-bottom:0;flex:1}
.qa-score-box{display:flex;align-items:center;gap:6px;background:#fff;border:1.5px solid #E2E8F0;border-radius:11px;padding:7px 9px;flex-shrink:0}
.qa-score-box input{width:58px;padding:5px 7px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;font-weight:900;text-align:center;color:var(--text)}
.qa-score-box span{font-size:12px;font-weight:800;color:var(--gray)}
.qa-score-reason{font-size:11px;color:var(--gray);line-height:1.45;margin-top:8px;background:#F8FAFC;border-radius:8px;padding:7px 9px}
.floating-rec{position:fixed;right:28px;bottom:28px;width:360px;min-width:260px;max-width:720px;height:260px;min-height:180px;max-height:80vh;background:#0F172A;border:1.5px solid #1E293B;border-radius:16px;z-index:80;box-shadow:0 18px 50px rgba(15,23,42,.32);resize:both;overflow:auto;display:none}
.floating-rec.show{display:block}
.floating-rec-head{cursor:move;display:flex;align-items:center;justify-content:space-between;gap:8px;color:#fff;font-size:12px;font-weight:800;padding:10px 12px;border-bottom:1px solid #1E293B;user-select:none}
.floating-rec-actions{display:flex;gap:6px;align-items:center}
.floating-rec-actions a,.floating-rec-actions button{border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.08);color:#DBEAFE;border-radius:8px;padding:5px 7px;font-size:11px;line-height:1;cursor:pointer;text-decoration:none}
.floating-rec-body{height:calc(100% - 42px);padding:10px;display:flex;align-items:center;justify-content:center}
.floating-rec video{width:100%;height:100%;object-fit:contain;background:#000;border-radius:10px}
.floating-rec audio{width:100%}
.rec-mini-btn{display:inline-flex;align-items:center;gap:6px;border:1.5px solid #BFDBFE;background:#EFF6FF;color:#1D4ED8;border-radius:10px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none}
.qa-save-bar{position:sticky;bottom:12px;z-index:6;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border:1.5px solid #E2E8F0;border-radius:16px;padding:12px;display:grid;grid-template-columns:1fr auto;gap:10px;box-shadow:0 10px 32px rgba(15,23,42,.12);margin-top:16px}
@media(max-width:720px){.qa-head{flex-direction:column}.qa-score-box{width:100%;justify-content:space-between}.qa-save-bar{grid-template-columns:1fr}.floating-rec{left:12px;right:12px;bottom:12px;width:auto}}

/* Q&A Sort Bar */
.qa-sort-bar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:14px;padding:8px 12px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px}
.qa-sort-bar>span{font-size:11px;font-weight:700;color:var(--gray2);text-transform:uppercase;letter-spacing:.5px;margin-right:2px;white-space:nowrap}
.qa-sort-btn{padding:4px 11px;border:1.5px solid #E2E8F0;border-radius:20px;background:#fff;font-size:11px;font-weight:700;color:var(--gray2);cursor:pointer;transition:all .18s}
.qa-sort-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.qa-sort-btn:hover:not(.active){background:#EFF6FF;border-color:#BFDBFE;color:var(--accent)}
/* Per-answer Analyze button */
.qa-analyze-btn{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:5px 12px;border:1.5px solid #DDD6FE;border-radius:8px;background:#F5F3FF;color:#7C3AED;font-size:12px;font-weight:700;cursor:pointer;transition:all .18s;white-space:nowrap}
.qa-analyze-btn:hover:not(:disabled){background:#EDE9FE;border-color:#A78BFA}
.qa-analyze-btn:disabled{opacity:.55;cursor:not-allowed}
/* INLINE AUDIO — no new tab */
.qa-audio-wrap{margin-top:10px;background:#EFF6FF;border-radius:10px;padding:10px 12px;border:1.5px solid #DBEAFE}
.qa-audio-wrap audio{width:100%;height:34px;display:block;border-radius:6px;accent-color:#1D4ED8}

/* VIDEO */
.video-frame{background:#060E1D;border-radius:14px;overflow:hidden;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center}
.video-frame video,.video-frame audio{width:100%;max-height:100%}
.no-media{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:rgba(255,255,255,.3);padding:40px;text-align:center}
.no-media i{font-size:36px}

/* HERO CARD */
.hero-card{background:#fff;border-radius:18px;box-shadow:var(--card-shadow);border:1px solid rgba(0,0,0,.04);margin-bottom:20px;overflow:hidden}
.hero-accent{height:4px;background:linear-gradient(90deg,#6366F1,#3B82F6,#10B981)}
.hero-body{display:flex;align-items:center;gap:16px;padding:18px 22px;flex-wrap:wrap}
.hero-left{display:flex;align-items:center;gap:14px;flex:1;min-width:0}
.hero-avatar{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#fff;flex-shrink:0;letter-spacing:-1px;box-shadow:0 4px 14px rgba(0,0,0,.18)}
.hero-name{font-size:20px;font-weight:800;color:var(--text);letter-spacing:-.4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:340px}
.hero-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}
.hero-meta span{display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--gray);font-weight:500}
.hero-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex-shrink:0}
.ha-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 13px;border-radius:10px;font-size:12px;font-weight:700;border:none;cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap;line-height:1}
.ha-btn-primary{background:linear-gradient(135deg,#2563EB,#3B82F6);color:#fff}
.ha-btn-primary:hover{background:linear-gradient(135deg,#1D4ED8,#2563EB);box-shadow:0 4px 12px rgba(37,99,235,.3)}
.ha-btn-green{background:linear-gradient(135deg,#059669,#10B981);color:#fff}
.ha-btn-green:hover{box-shadow:0 4px 12px rgba(5,150,105,.3)}
.ha-btn-indigo{background:linear-gradient(135deg,#4F46E5,#6366F1);color:#fff}
.ha-btn-indigo:hover{box-shadow:0 4px 12px rgba(79,70,229,.3)}
.ha-btn-ghost{background:transparent;color:var(--gray2);border:1.5px solid #E2E8F0;padding:8px 11px}
.ha-btn-ghost:hover{background:#F8FAFC;color:var(--text)}
.ha-btn-danger{background:transparent;color:#EF4444;border:1.5px solid #FECACA;padding:8px 11px}
.ha-btn-danger:hover{background:#FEF2F2}
.ha-sep{width:1px;height:26px;background:#E2E8F0}
@media(max-width:800px){.hero-body{padding:14px 16px;gap:12px}.hero-right{width:100%;justify-content:flex-start}}

/* NOTES */
.note-item{padding:12px 14px;background:#F8FAFC;border-radius:10px;margin-bottom:8px;border-left:3px solid var(--blue)}
.note-text{font-size:14px;color:var(--text);line-height:1.5}
.note-meta{font-size:11px;color:var(--gray);margin-top:5px;display:flex;gap:12px}

/* INTEGRITY */
.int-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:12px 0 14px}
.int-stat{background:#F8FAFC;border:1.5px solid #E2E8F0;border-radius:11px;padding:9px 6px;text-align:center;transition:border-color .2s}
.int-stat-val{font-size:20px;font-weight:900;line-height:1;margin-bottom:3px}
.int-stat-label{font-size:9.5px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.4px}
.int-flag-v2{display:flex;align-items:flex-start;gap:9px;padding:10px 11px;border-radius:11px;margin-bottom:7px;border-left:3px solid;border-top:1px solid transparent;border-right:1px solid transparent;border-bottom:1px solid transparent}
.int-flag-v2.int-flag-high{background:#FEF2F2;border-left-color:#EF4444;border-top-color:#FECACA;border-right-color:#FECACA;border-bottom-color:#FECACA}
.int-flag-v2.int-flag-medium{background:#FFFBEB;border-left-color:#F59E0B;border-top-color:#FDE68A;border-right-color:#FDE68A;border-bottom-color:#FDE68A}
.int-flag-icon{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;margin-top:1px}
.int-flag-high .int-flag-icon{background:#FEE2E2;color:#DC2626}
.int-flag-medium .int-flag-icon{background:#FEF3C7;color:#B45309}
.int-flag-body{flex:1;min-width:0}
.int-flag-title{font-size:12px;font-weight:800;color:var(--text);margin-bottom:2px;line-height:1.2}
.int-flag-desc{font-size:11px;color:#64748B;line-height:1.4}
.int-flag-count{font-size:14px;font-weight:900;padding:2px 8px;border-radius:7px;min-width:26px;text-align:center;flex-shrink:0;align-self:center}
.int-flag-high .int-flag-count{color:#DC2626;background:#FEE2E2}
.int-flag-medium .int-flag-count{color:#B45309;background:#FEF3C7}
.int-clean-state{display:flex;align-items:center;gap:12px;padding:14px 12px;background:#ECFDF5;border-radius:12px;border:1.5px solid #A7F3D0}
.int-risk-header{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:11px;margin-bottom:12px;border:1.5px solid}
.int-risk-label{font-size:13px;font-weight:800;line-height:1.2}
.int-risk-sub{font-size:11px;opacity:.75;margin-top:1px}
.int-perq-section{margin-top:12px;padding-top:12px;border-top:1px solid #F1F5F9}
.int-perq-heading{font-size:10.5px;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;display:flex;align-items:center;gap:5px;cursor:pointer;user-select:none}
.int-perq-heading:hover{color:var(--text2)}
.int-perq-list{max-height:220px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#CBD5E1 transparent;transition:max-height .3s ease}
.int-perq-list.collapsed{max-height:0;overflow:hidden}
.int-perq-item{display:flex;align-items:center;gap:8px;margin-bottom:6px;padding:7px 10px;background:#F8FAFC;border-radius:9px;border:1px solid #F1F5F9}
.int-perq-qnum{font-size:11px;font-weight:900;background:linear-gradient(135deg,var(--blue),var(--accent));color:#fff;padding:2px 8px;border-radius:6px;flex-shrink:0;letter-spacing:.3px}
.int-perq-summary{font-size:11px;color:#475569;line-height:1.4;flex:1}
.int-session-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;padding-top:12px;border-top:1px solid #F1F5F9}
.int-meta-chip{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:600;color:#64748B;background:#F1F5F9;border-radius:6px;padding:3px 8px}
.risk-pill-v2{font-size:10.5px;font-weight:800;border-radius:999px;padding:3px 10px;border:1.5px solid;display:inline-flex;align-items:center;gap:4px;white-space:nowrap}

/* CONFIRM MODAL */
.confirm-overlay{display:none;position:fixed;inset:0;background:rgba(8,15,30,.75);backdrop-filter:blur(10px);z-index:3000;align-items:center;justify-content:center;padding:20px}
.confirm-overlay.active{display:flex;animation:fadeIn .2s}
.confirm-box{background:#fff;border-radius:20px;padding:36px 32px;max-width:420px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:slideUp .25s cubic-bezier(.4,0,.2,1)}
.confirm-icon{font-size:52px;margin-bottom:16px}
.confirm-title{font-size:20px;font-weight:800;color:var(--text);margin-bottom:8px}
.confirm-msg{font-size:14px;color:var(--gray2);margin-bottom:28px;line-height:1.6}
.confirm-btns{display:flex;gap:12px;justify-content:center}

/* KEYBOARD HINT */
.kb-hint{font-size:10px;background:#F1F5F9;color:var(--gray);padding:2px 7px;border-radius:5px;font-family:monospace;margin-left:4px}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="main-content">

<?php
$isTerminated       = !empty($cheat['terminated']);
$terminationReason  = $cheat['termination_reason'] ?? 'face_not_detected';
?>
<?php if ($isTerminated): ?>
<div style="background:#FEF2F2;border:2px solid #FECACA;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;align-items:flex-start;gap:14px">
  <div style="font-size:28px;flex-shrink:0">🚫</div>
  <div>
    <div style="font-size:15px;font-weight:800;color:#DC2626;margin-bottom:4px">Interview Terminated — Integrity Violation</div>
    <div style="font-size:13px;color:#991B1B;line-height:1.6">
      This candidate's interview was <strong>automatically terminated</strong> because their face was not detected on consecutive checks.
      Reason recorded: <strong><?= htmlspecialchars(str_replace('_',' ',$terminationReason)) ?></strong>.
      The session has been completed and flagged in the integrity report.
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($toast): ?>
<div class="toast toast-success" id="toastEl">
  <i class="fa-solid fa-circle-check"></i>
  <?= match($toast) {
    'note_added'     => 'Note added successfully',
    'status_updated' => 'Status updated',
    'score_updated'  => 'Score override saved',
    'manual_scores_saved' => 'Manual score review saved',
    default          => 'Changes saved'
  } ?>
</div>
<?php endif; ?>

<!-- BREADCRUMB -->
<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--gray);margin-bottom:14px">
  <a href="dashboard" style="color:var(--gray)"><i class="fa-solid fa-gauge-high fa-xs"></i> Dashboard</a>
  <i class="fa-solid fa-chevron-right fa-xs"></i>
  <a href="candidates" style="color:var(--gray)">Candidates</a>
  <i class="fa-solid fa-chevron-right fa-xs"></i>
  <span style="color:var(--text);font-weight:600"><?= htmlspecialchars($c['name']) ?></span>
</div>

<!-- HERO HEADER -->
<div class="hero-card animate-in">
  <div class="hero-accent"></div>
  <div class="hero-body">
    <div class="hero-left">
      <div class="hero-avatar" style="background:linear-gradient(<?= $avatarGrad ?>)"><?= htmlspecialchars($initials) ?></div>
      <div style="min-width:0;flex:1">
        <div class="hero-name"><?= htmlspecialchars($c['name']) ?></div>
        <div class="hero-meta">
          <?php if (!empty($c['phone'])): ?>
          <span><i class="fa-solid fa-phone fa-xs"></i> <?= htmlspecialchars($c['phone']) ?></span>
          <?php endif; ?>
          <?php if (!empty($c['email'])): ?>
          <span><i class="fa-solid fa-envelope fa-xs"></i> <?= htmlspecialchars($c['email']) ?></span>
          <?php endif; ?>
          <?php if (!empty($c['campaign_name'])): ?>
          <span><i class="fa-solid fa-briefcase fa-xs"></i> <?= htmlspecialchars($c['campaign_name']) ?></span>
          <?php endif; ?>
          <?php if (!empty($c['city'])): ?>
          <span><i class="fa-solid fa-location-dot fa-xs"></i> <?= htmlspecialchars($c['city']) ?></span>
          <?php endif; ?>
          <span style="color:<?= $quickFlagTotal ? '#B45309' : '#64748B' ?>">
            <i class="fa-solid fa-shield-halved fa-xs"></i>
            <?= $quickFlagTotal ? $quickTabSwitches . ' tab · ' . $quickCopyPaste . ' paste' : 'Clean integrity' ?>
          </span>
        </div>
      </div>
    </div>
    <div class="hero-right">
      <span class="badge badge-<?= $c['status'] ?>" style="font-size:12px;padding:5px 14px;flex-shrink:0">
        <?= ucfirst(str_replace('_', ' ', $c['status'])) ?>
      </span>
      <?php if (!empty($c['phone'])): ?>
      <a href="tel:<?= htmlspecialchars($c['phone']) ?>" class="ha-btn ha-btn-green">
        <i class="fa-solid fa-phone fa-xs"></i> Call
      </a>
      <?php endif; ?>
      <?php if ($interviewLink): ?>
      <button onclick="copyLink()" class="ha-btn ha-btn-indigo">
        <i class="fa-solid fa-link fa-xs"></i> Copy Link
      </button>
      <?php endif; ?>
      <?php if (defined('DIALER_API_KEY') && DIALER_API_KEY): ?>
      <button onclick="openCallModal()" class="ha-btn" style="background:linear-gradient(135deg,#059669,#10B981);color:#fff;border-color:transparent" title="Trigger AI Call">
        <i class="fa-solid fa-phone fa-xs"></i> AI Call
      </button>
      <?php endif; ?>
      <button onclick="openStatusModal()" class="ha-btn ha-btn-primary">
        <i class="fa-solid fa-pen fa-xs"></i> Status
      </button>
      <button onclick="openEditModal()" class="ha-btn ha-btn-ghost">
        <i class="fa-solid fa-user-pen fa-xs"></i> Edit
      </button>
      <div class="ha-sep"></div>
      <button onclick="scheduleReminder()" class="ha-btn ha-btn-ghost" title="Set reminder">
        <i class="fa-solid fa-bell fa-xs"></i>
      </button>
      <?php
        $hasVoiceNoTranscript = false;
        foreach ($answers as $ans) {
            if (trim((string)($ans['audio_url'] ?? '')) !== '' && trim((string)($ans['text_answer'] ?? '')) === '') {
                $hasVoiceNoTranscript = true; break;
            }
        }
      ?>
      <?php if ($hasVoiceNoTranscript): ?>
      <button onclick="rescoreCandidate()" class="ha-btn" style="background:linear-gradient(135deg,#7C3AED,#6366F1);color:#fff;border-color:transparent" title="Transcribe voice answers and re-score">
        <i class="fa-solid fa-microphone fa-xs"></i> Score Voice
      </button>
      <?php endif; ?>
      <a href="export_candidate.php?id=<?= $c['id'] ?>" target="_blank" class="ha-btn ha-btn-ghost" title="Export PDF">
        <i class="fa-solid fa-file-export fa-xs"></i>
      </a>
      <button onclick="confirmDelete(<?= $c['id'] ?>,'<?= addslashes(htmlspecialchars($c['name'])) ?>')"
        class="ha-btn ha-btn-danger" title="Delete candidate">
        <i class="fa-solid fa-trash fa-xs"></i>
      </button>
    </div>
  </div>
</div>

<div class="detail-grid">

<!-- ═══ LEFT SIDEBAR ═══ -->
<div>

  <!-- SCORE CARD -->
  <div class="card animate-in" style="text-align:center;padding:26px 20px">
    <?php if ($display_score !== null): ?>
    <div class="ring-wrap">
      <svg width="120" height="120" viewBox="0 0 108 108" aria-hidden="true">
        <circle class="ring-track" cx="54" cy="54" r="46"/>
        <circle class="ring-fill" cx="54" cy="54" r="46"
          stroke="<?= $scoreColor ?>"
          stroke-dasharray="<?= $ringCirc ?>"
          stroke-dashoffset="<?= $ringCirc ?>"
          data-offset="<?= $ringOffset ?>"/>
      </svg>
      <div class="ring-center">
        <div class="ring-score" style="color:<?= $scoreColor ?>"><?= $display_score ?></div>
        <div class="ring-max">/ <?= $display_max ?></div>
      </div>
    </div>

    <div style="display:inline-flex;align-items:center;gap:6px;padding:5px 18px;border-radius:20px;
                background:<?= $scoreBg ?>;border:1.5px solid <?= $scoreColor ?>40;
                font-size:13px;font-weight:800;color:<?= $scoreColor ?>;margin-bottom:12px">
      <?= $pf === 'pass'
        ? '<i class="fa-solid fa-circle-check"></i> PASSED'
        : ($pf === 'pending'
          ? '<i class="fa-regular fa-clock"></i> REVIEW PENDING'
          : '<i class="fa-solid fa-circle-xmark"></i> FAILED') ?>
    </div>
    <?php else: ?>
    <div style="padding:24px 0">
      <i class="fa-regular fa-clock fa-2x" style="color:var(--gray);margin-bottom:10px;display:block;opacity:.5"></i>
      <div style="font-size:14px;font-weight:600;color:var(--gray)">Score Pending</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- CANDIDATE INFO -->
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-id-card" style="color:var(--blue)"></i> Info</h3></div>
    <?php
    $fields = ['phone'=>'Phone','email'=>'Email','city'=>'City','experience_years'=>'Exp','current_ctc'=>'CTC','expected_ctc'=>'Expected','source'=>'Source','job_role'=>'Role','campaign_name'=>'Campaign'];
    foreach ($fields as $k => $l): if (!empty($c[$k])): ?>
    <div class="info-row">
      <div class="info-key"><?= $l ?></div>
      <div class="info-val"><?= htmlspecialchars($c[$k]) ?><?= $k === 'experience_years' ? ' yrs' : '' ?></div>
    </div>
    <?php endif; endforeach; ?>
    <div class="info-row">
      <div class="info-key">Applied</div>
      <div class="info-val"><?= $c['created_at'] ? date('d M Y', strtotime($c['created_at'])) : '—' ?></div>
    </div>
    <?php if (!empty($c['referred_by_display_name']) || !empty($c['referred_medium'])): ?>
    <div class="info-row">
      <div class="info-key">Referred</div>
      <div class="info-val">
        <?= htmlspecialchars($c['referred_by_display_name'] ?? '—') ?>
        <?php if (!empty($c['referred_by_phone'])): ?><br><small style="color:var(--gray)"><?= htmlspecialchars($c['referred_by_phone']) ?></small><?php endif; ?>
        <?php if (!empty($c['referred_medium'])): ?><br><small style="color:var(--blue)">Medium: <?= htmlspecialchars($c['referred_medium']) ?></small><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php $application_answers = json_decode($c['application_answers_json'] ?? '{}', true) ?: []; ?>
  <?php if (!empty($application_answers)): ?>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-clipboard-list" style="color:var(--purple)"></i> Apply Form</h3></div>
    <?php foreach ($application_answers as $ans):
      $value    = $ans['value'] ?? '';
      $ansType  = $ans['type']  ?? '';
      $ansKey   = strtolower((string)($ans['key'] ?? ''));
      if (is_array($value)) $value = implode(', ', array_filter($value));
      // File fields: fall back to resume_path / photo_path columns if dynamic value is empty
      if ($ansType === 'file' && trim((string)$value) === '') {
        if ((str_contains($ansKey, 'resume') || str_contains($ansKey, 'cv')) && !empty($c['resume_path'])) {
          $value = $c['resume_path'];
        } elseif (str_contains($ansKey, 'photo') && !empty($c['photo_path'])) {
          $value = $c['photo_path'];
        }
      }
      if (trim((string)$value) === '') $value = '—';
      $isFilePath = $ansType === 'file' && str_starts_with((string)$value, 'uploads/');
    ?>
    <div class="info-row">
      <div class="info-key"><?= htmlspecialchars($ans['label'] ?? $ans['key'] ?? 'Field') ?></div>
      <div class="info-val">
        <?php if ($isFilePath):
          $fileUrl   = BASE_URL . '/' . $value;
          $origName  = $ans['original_name'] ?? basename((string)$value);
          $fileExt   = strtolower(pathinfo((string)$value, PATHINFO_EXTENSION));
          $isImg     = in_array($fileExt, ['jpg','jpeg','png','gif','webp']);
          $isPdf     = $fileExt === 'pdf';
        ?>
          <?php if ($isImg): ?>
            <a href="<?= htmlspecialchars($fileUrl) ?>" target="_blank">
              <img src="<?= htmlspecialchars($fileUrl) ?>" style="max-width:120px;max-height:80px;border-radius:6px;border:1px solid #E2E8F0;display:block">
            </a>
          <?php else: ?>
            <a href="<?= htmlspecialchars($fileUrl) ?>" target="_blank"
               style="display:inline-flex;align-items:center;gap:7px;color:var(--accent);font-weight:600;font-size:13px;text-decoration:none;background:#EFF6FF;padding:6px 12px;border-radius:8px;border:1px solid #DBEAFE">
              <i class="fa-solid <?= $isPdf ? 'fa-file-pdf' : 'fa-file-word' ?>" style="color:<?= $isPdf ? '#EF4444' : '#1D4ED8' ?>"></i>
              <?= htmlspecialchars($origName) ?>
              <i class="fa-solid fa-arrow-up-right-from-square fa-xs" style="opacity:.5"></i>
            </a>
          <?php endif; ?>
        <?php else: ?>
          <?= nl2br(htmlspecialchars((string)$value)) ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- INTEGRITY -->
  <?php
  $hasSession = !empty($session);

  // cheat_summary has reliable top-level counts from the frontend
  $rawTab  = (int)($cheat['tab_switches'] ?? 0);
  $rawCopy = (int)($cheat['copy_paste']   ?? 0);

  // cheat_flags is a CUMULATIVE global log sent with every answer.
  // The last answer's log contains all events for the full interview, each tagged with a question number.
  // "Window focus lost" and "Time expired" are noise — excluded.
  $allEvents = [];
  if (!empty($answers)) {
    $lastAns = $answers[array_key_last($answers)];
    $decoded = json_decode((string)($lastAns['cheat_flags'] ?? ''), true);
    if (is_array($decoded)) $allEvents = $decoded;
  }

  $rawFace = 0;
  $byQuestion = []; // [qNum => [tab, paste, face]]
  foreach ($allEvents as $evt) {
    $msg  = trim((string)($evt['msg'] ?? ''));
    $qNum = (int)($evt['question'] ?? 0);
    $ml   = strtolower($msg);
    if ($msg === '' || str_contains($ml, 'time expired') || str_contains($ml, 'window focus lost')) continue;

    if (!isset($byQuestion[$qNum])) $byQuestion[$qNum] = ['tab' => 0, 'paste' => 0, 'face' => 0];

    if (str_contains($ml, 'tab switch') || str_contains($ml, 'visibilitychange')) {
      $byQuestion[$qNum]['tab']++;
    } elseif (str_contains($ml, 'paste') || str_contains($ml, 'ctrl+v')) {
      $byQuestion[$qNum]['paste']++;
    } elseif (str_contains($ml, 'low light') || str_contains($ml, 'face not visible')) {
      $byQuestion[$qNum]['face']++;
    }
  }
  // Count questions affected by face/light issues (not raw event spam from 4s polling)
  $rawFace = count(array_filter($byQuestion, fn($c) => $c['face'] > 0));

  // Build per-question summary rows — only for questions that had real events
  $perQuestionFlags = [];
  ksort($byQuestion);
  foreach ($byQuestion as $qNum => $counts) {
    if ($counts['tab'] + $counts['paste'] + $counts['face'] === 0) continue;
    $parts = [];
    if ($counts['paste']) $parts[] = $counts['paste'] . ' paste' . ($counts['paste'] > 1 ? 's' : '');
    if ($counts['tab'])   $parts[] = $counts['tab']   . ' tab switch' . ($counts['tab'] > 1 ? 'es' : '');
    if ($counts['face'])  $parts[] = $counts['face']  . ' face/light issue' . ($counts['face'] > 1 ? 's' : '');
    $perQuestionFlags[] = [
      'num'     => $qNum,
      'summary' => implode(', ', $parts),
      'high'    => $counts['paste'] > 0,
    ];
  }

  $totalFlagCount = $rawTab + $rawCopy;

  // Overall risk — based only on real signals
  if ($isTerminated) {
    $riskLevel = 'critical';
    $riskLabel = 'Terminated — Face Not Detected';
    $riskSub   = 'Interview was automatically terminated due to face detection failure';
    $riskColor = '#BE123C'; $riskBg = '#FFF1F2'; $riskBd = '#FECDD3'; $riskIconClr = '#F43F5E';
    $riskIcon  = 'fa-ban';
  } elseif ($totalFlagCount === 0 && $rawFace === 0 && empty($perQuestionFlags)) {
    $riskLevel = 'clean';
    $riskLabel = 'Clean Interview';   $riskSub = 'No suspicious activity detected';
    $riskColor = '#065F46'; $riskBg = '#ECFDF5'; $riskBd = '#A7F3D0'; $riskIconClr = '#10B981';
    $riskIcon  = 'fa-shield-check';
  } elseif ($rawCopy >= 3 || $rawTab >= 10) {
    $riskLevel = 'critical';
    $riskLabel = 'Critical Risk';     $riskSub = 'Significant integrity violations detected';
    $riskColor = '#BE123C'; $riskBg = '#FFF1F2'; $riskBd = '#FECDD3'; $riskIconClr = '#F43F5E';
    $riskIcon  = 'fa-triangle-exclamation';
  } elseif ($rawCopy > 0 || $rawTab >= 4) {
    $riskLevel = 'high';
    $riskLabel = 'High Risk';         $riskSub = 'Suspicious activity detected — review recommended';
    $riskColor = '#991B1B'; $riskBg = '#FEF2F2'; $riskBd = '#FECACA'; $riskIconClr = '#EF4444';
    $riskIcon  = 'fa-triangle-exclamation';
  } elseif ($totalFlagCount > 0 || $rawFace > 0) {
    $riskLevel = 'medium';
    $riskLabel = 'Medium Risk';       $riskSub = 'Minor flags noted — may warrant a closer look';
    $riskColor = '#92400E'; $riskBg = '#FFFBEB'; $riskBd = '#FDE68A'; $riskIconClr = '#F59E0B';
    $riskIcon  = 'fa-exclamation-circle';
  } else {
    $riskLevel = 'clean';
    $riskLabel = 'Clean Interview';   $riskSub = 'No suspicious activity detected';
    $riskColor = '#065F46'; $riskBg = '#ECFDF5'; $riskBd = '#A7F3D0'; $riskIconClr = '#10B981';
    $riskIcon  = 'fa-shield-check';
  }

  $flagDefs = [];
  if ($rawTab > 0)  $flagDefs[] = ['Tab / Window Switches',  'Candidate navigated away from the interview window.',                    'medium', 'fa-window-restore', $rawTab];
  if ($rawCopy > 0) $flagDefs[] = ['Copy / Paste Activity',  'Paste shortcuts triggered — may have used external reference material.', 'high',   'fa-paste',          $rawCopy];
  if ($rawFace > 0) $flagDefs[] = ['Face / Lighting Issues', 'Camera detected poor visibility or low light conditions.',               'medium', 'fa-face-frown',      $rawFace];
  ?>
  <div class="card animate-in" id="integritySection">
    <div class="card-header">
      <h3><i class="fa-solid fa-shield-halved" style="color:var(--orange)"></i> Integrity</h3>
      <?php if ($riskLevel !== 'clean'): ?>
      <span class="risk-pill-v2" style="color:<?= $riskColor ?>;background:<?= $riskBg ?>;border-color:<?= $riskBd ?>">
        <i class="fa-solid <?= $riskIcon ?>" style="color:<?= $riskIconClr ?>"></i> <?= $riskLabel ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($riskLevel !== 'clean'): ?>
    <div class="int-risk-header" style="background:<?= $riskBg ?>;border-color:<?= $riskBd ?>">
      <i class="fa-solid <?= $riskIcon ?>" style="color:<?= $riskIconClr ?>;font-size:16px;flex-shrink:0"></i>
      <div>
        <div class="int-risk-label" style="color:<?= $riskColor ?>"><?= $riskLabel ?></div>
        <div class="int-risk-sub"   style="color:<?= $riskColor ?>"><?= $riskSub ?></div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($hasSession): ?>
    <div class="int-stat-grid">
      <div class="int-stat" style="<?= $rawTab > 0  ? 'border-color:#FDE68A;background:#FFFBEB' : '' ?>">
        <div class="int-stat-val" style="color:<?= $rawTab > 0  ? '#B45309' : '#94A3B8' ?>"><?= $rawTab ?></div>
        <div class="int-stat-label">Tab Switches</div>
      </div>
      <div class="int-stat" style="<?= $rawCopy > 0 ? 'border-color:#FECACA;background:#FEF2F2' : '' ?>">
        <div class="int-stat-val" style="color:<?= $rawCopy > 0 ? '#DC2626' : '#94A3B8' ?>"><?= $rawCopy ?></div>
        <div class="int-stat-label">Copy / Paste</div>
      </div>
      <div class="int-stat" style="<?= $rawFace > 0 ? 'border-color:#FDE68A;background:#FFFBEB' : '' ?>">
        <div class="int-stat-val" style="color:<?= $rawFace > 0 ? '#B45309' : '#94A3B8' ?>"><?= $rawFace ?></div>
        <div class="int-stat-label">Face / Light</div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($riskLevel === 'clean'): ?>
    <div class="int-clean-state">
      <i class="fa-solid fa-shield-check" style="color:#10B981;font-size:22px;flex-shrink:0"></i>
      <div>
        <div style="font-size:13px;font-weight:700;color:#065F46">No suspicious activity detected</div>
        <div style="font-size:11px;color:#047857;margin-top:2px">Interview passed all integrity checks</div>
      </div>
    </div>
    <?php else: ?>

    <?php foreach ($flagDefs as [$ftitle, $fdesc, $fsev, $ficon, $fcount]): ?>
    <div class="int-flag-v2 int-flag-<?= $fsev ?>">
      <div class="int-flag-icon"><i class="fa-solid <?= $ficon ?>"></i></div>
      <div class="int-flag-body">
        <div class="int-flag-title"><?= $ftitle ?></div>
        <div class="int-flag-desc"><?= $fdesc ?></div>
      </div>
      <div class="int-flag-count"><?= $fcount ?>×</div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($perQuestionFlags)): ?>
    <div class="int-perq-section">
      <div class="int-perq-heading" onclick="togglePerQ(this)">
        <i class="fa-solid fa-list-check fa-xs"></i> Per-Question Events
        <span style="font-size:10px;background:#F1F5F9;padding:1px 7px;border-radius:99px;color:var(--gray)"><?= count($perQuestionFlags) ?></span>
        <i class="fa-solid fa-chevron-down fa-xs" style="margin-left:auto;transition:transform .25s" id="perq-chevron"></i>
      </div>
      <div class="int-perq-list" id="perq-list">
      <?php foreach ($perQuestionFlags as $pq): ?>
      <div class="int-perq-item">
        <span class="int-perq-qnum">Q<?= (int)$pq['num'] ?></span>
        <span class="int-perq-summary" style="<?= $pq['high'] ? 'color:#DC2626;font-weight:600' : '' ?>">
          <?= htmlspecialchars($pq['summary']) ?>
        </span>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($session && (!empty($session['duration_seconds']) || !empty($session['started_at']))): ?>
    <div class="int-session-meta">
      <?php if (!empty($session['duration_seconds'])): ?>
      <span class="int-meta-chip"><i class="fa-solid fa-stopwatch fa-xs"></i> <?= round((int)$session['duration_seconds'] / 60, 1) ?> min</span>
      <?php endif; ?>
      <?php if (!empty($session['started_at'])): ?>
      <span class="int-meta-chip"><i class="fa-regular fa-clock fa-xs"></i> <?= date('d M, h:i A', strtotime($session['started_at'])) ?></span>
      <?php endif; ?>
      <?php if (!empty($session['completed_at'])): ?>
      <span class="int-meta-chip" style="color:#059669"><i class="fa-solid fa-circle-check fa-xs"></i> Completed</span>
      <?php endif; ?>
    </div>
    <?php elseif (!$hasSession): ?>
    <div style="text-align:center;padding:16px 0 8px;color:var(--gray)">
      <i class="fa-regular fa-clock fa-lg" style="opacity:.35;display:block;margin-bottom:6px"></i>
      <div style="font-size:12px;font-weight:600">Interview not yet taken</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- INTERVIEW LINK -->
  <?php if ($interviewLink): ?>
  <div class="card animate-in">
    <div class="card-header"><h3><i class="fa-solid fa-link" style="color:var(--accent)"></i> Interview Link</h3></div>
    <div style="background:#EFF6FF;border-radius:10px;padding:10px 12px;font-size:12px;word-break:break-all;color:var(--blue);margin-bottom:8px">
      <?= htmlspecialchars($interviewLink) ?>
    </div>
    <button onclick="copyLink()" class="btn-sm" style="width:100%;justify-content:center">
      <i class="fa-solid fa-copy fa-xs"></i> Copy Link
    </button>
  </div>
  <?php endif; ?>

</div><!-- /sidebar -->

<!-- ═══ RIGHT MAIN ═══ -->
<div>

  <?php
  // Integrity quick-banner — shown above tabs for instant visibility
  $quickTotal = (int)($cheat['total_flags'] ?? ($quickTabSwitches + $quickCopyPaste));
  $realFlags  = $quickTabSwitches + $quickCopyPaste;
  if ($realFlags > 0):
    $bColor = $quickCopyPaste > 0 ? '#FEF2F2' : '#FFFBEB';
    $bBorder= $quickCopyPaste > 0 ? '#FECACA' : '#FDE68A';
    $bText  = $quickCopyPaste > 0 ? '#991B1B'  : '#92400E';
    $bIcon  = $quickCopyPaste > 0 ? 'fa-triangle-exclamation' : 'fa-eye';
  ?>
  <div style="background:<?= $bColor ?>;border:1px solid <?= $bBorder ?>;border-radius:12px;padding:10px 16px;margin-bottom:12px;display:flex;align-items:center;gap:10px;cursor:pointer" onclick="document.getElementById('integritySection').scrollIntoView({behavior:'smooth'})">
    <i class="fa-solid <?= $bIcon ?>" style="color:<?= $bText ?>;font-size:16px;flex-shrink:0"></i>
    <div style="flex:1;min-width:0">
      <span style="font-size:13px;font-weight:700;color:<?= $bText ?>">Integrity Flags Detected</span>
      <span style="font-size:12px;color:<?= $bText ?>;opacity:.8;margin-left:6px">
        <?= $quickTabSwitches > 0 ? $quickTabSwitches . ' tab switch' . ($quickTabSwitches > 1 ? 'es' : '') : '' ?>
        <?= ($quickTabSwitches > 0 && $quickCopyPaste > 0) ? ' · ' : '' ?>
        <?= $quickCopyPaste > 0 ? $quickCopyPaste . ' paste event' . ($quickCopyPaste > 1 ? 's' : '') : '' ?>
      </span>
    </div>
    <span style="font-size:11px;color:<?= $bText ?>;opacity:.6">Click to view ↓</span>
  </div>
  <?php endif; ?>

  <!-- TABS -->
  <div class="tabs animate-in">
    <button class="tab-btn active" onclick="switchTab('qa',this)" data-tab-btn="qa">
      <i class="fa-solid fa-comments fa-sm"></i> Q&A
      <?php if (count($answers)): ?><span class="tab-badge"><?= count($answers) ?></span><?php endif; ?>
      <span class="kb-hint">1</span>
    </button>
    <button class="tab-btn" onclick="switchTab('recording',this)" data-tab-btn="recording">
      <i class="fa-solid fa-video fa-sm"></i> Recording <span class="kb-hint">2</span>
    </button>
    <?php if (defined('DIALER_API_KEY') && DIALER_API_KEY): ?>
    <button class="tab-btn" onclick="switchTab('aicall',this)" data-tab-btn="aicall" style="<?= $ai_call ? '' : 'opacity:.6' ?>">
      <i class="fa-solid fa-phone-volume fa-sm" style="color:#059669"></i> AI Call
      <?php if ($ai_call): ?><span class="tab-badge" style="background:#059669"><?= $ai_call['interview_score'] ?? '—' ?></span><?php endif; ?>
      <span class="kb-hint">3</span>
    </button>
    <?php endif; ?>
  </div>

  <!-- TAB: RECORDING -->
  <div class="tab-panel" id="tab-recording">
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-video" style="color:var(--purple)"></i> Interview Recording</h3>
        <?php if ($recUrl): ?>
        <a href="<?= htmlspecialchars($recUrl) ?>" download class="btn-sm">
          <i class="fa-solid fa-download fa-xs"></i> Download
        </a>
        <?php endif; ?>
      </div>
      <div class="video-frame">
        <?php
        $ext = strtolower(pathinfo(strtok($recUrl ?? '', '?'), PATHINFO_EXTENSION));
        if ($recUrl && in_array($ext, ['mp4','webm','mov','mkv'])): ?>
        <video id="rec-video" controls preload="auto" style="width:100%;height:100%;object-fit:contain"
               onloadedmetadata="fixWebmDuration(this)">
          <source src="<?= htmlspecialchars($recUrl) ?>">
        </video>
        <?php elseif ($recUrl && in_array($ext, ['mp3','wav','ogg','m4a','webm'])): ?>
        <div style="width:100%;padding:32px;text-align:center">
          <i class="fa-solid fa-waveform-lines fa-3x" style="color:var(--accent);margin-bottom:16px;display:block"></i>
          <audio controls style="width:100%;border-radius:8px">
            <source src="<?= htmlspecialchars($recUrl) ?>">
          </audio>
        </div>
        <?php elseif ($recUrl): ?>
        <div style="width:100%;padding:32px;text-align:center">
          <audio controls style="width:100%;border-radius:8px">
            <source src="<?= htmlspecialchars($recUrl) ?>">
          </audio>
        </div>
        <?php else: ?>
        <div class="no-media" style="text-align:center;padding:40px;color:rgba(255,255,255,.4)">
          <i class="fa-solid fa-video-slash"></i>
          <div style="font-weight:600;font-size:15px;color:rgba(255,255,255,.6);margin-top:12px">Recording Unavailable</div>
          <div style="font-size:12px;margin-top:4px">Video upload may have failed or interview was incomplete</div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($session): ?>
      <div style="display:flex;gap:16px;margin-top:12px;flex-wrap:wrap">
        <?php if (!empty($session['started_at'])): ?>
        <span style="font-size:12px;color:var(--gray)">
          <i class="fa-regular fa-clock fa-xs"></i> Started: <?= date('d M Y, h:i A', strtotime($session['started_at'])) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($session['completed_at'])): ?>
        <span style="font-size:12px;color:var(--gray)">
          <i class="fa-solid fa-check fa-xs" style="color:var(--green)"></i> Done: <?= date('d M Y, h:i A', strtotime($session['completed_at'])) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($session['duration_seconds'])): ?>
        <span style="font-size:12px;color:var(--gray)">
          <i class="fa-solid fa-stopwatch fa-xs"></i> <?= round($session['duration_seconds'] / 60, 1) ?> min
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: Q&A -->
  <div class="tab-panel active" id="tab-qa">
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-comments" style="color:var(--green)"></i> Interview Q&A</h3>
        <span style="font-size:12px;color:var(--gray);background:#F1F5F9;padding:3px 10px;border-radius:20px">
          <?= count($answers) ?> answers
        </span>
      </div>
      <?php if ($recUrl): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <button type="button" class="rec-mini-btn" onclick="toggleFloatingRecording(true)">
          <i class="fa-solid fa-up-right-and-down-left-from-center"></i> Open floating recording
        </button>
        <a class="rec-mini-btn" href="video_view.php?id=<?= $id ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-arrow-up-right-from-square"></i> New tab
        </a>
      </div>
      <?php endif; ?>

      <?php if (!empty($answers)): ?>
      <form method="POST">
      <?= csrf_input() ?>
      <?php endif; ?>
      <?php if (!empty($answers)): ?>
      <div class="qa-sort-bar">
        <span>Sort:</span>
        <button type="button" class="qa-sort-btn active" onclick="sortQA('order',this)"><i class="fa-solid fa-list-ol fa-xs"></i> Question #</button>
        <button type="button" class="qa-sort-btn" onclick="sortQA('score_desc',this)"><i class="fa-solid fa-arrow-down-wide-short fa-xs"></i> Score High→Low</button>
        <button type="button" class="qa-sort-btn" onclick="sortQA('score_asc',this)"><i class="fa-solid fa-arrow-up-wide-short fa-xs"></i> Score Low→High</button>
      </div>
      <div id="qa-list">
      <?php foreach ($answers as $i => $a):
        $qText   = $a['question_text'] ?? 'Question ' . ($i + 1);
        $ansText = $a['text_answer'] ?? '';
        $hasAudio= !empty($a['audio_url']);
        $isUploadFailureText = str_starts_with(trim((string)$ansText), '[Voice answer recorded but upload failed:');
        $missingVoiceFile = !$hasAudio && ($a['answer_mode'] ?? '') === 'voice';
        $hasGradable = answer_has_gradable_response($a);
        $tt      = (int)($a['time_taken'] ?? 0);
        $cp      = (int)($a['copy_count'] ?? 0);
        $qaScore = $scoreByQuestion[(int)($a['question_id'] ?? 0)] ?? $scoreByParameter[(string)($a['parameter'] ?? '')] ?? null;
        $qaScoreVal = $qaScore ? (int)$qaScore['ai_score'] : 0;
        $qaMax = $qaScore ? (int)$qaScore['max_marks'] : (int)($a['max_marks'] ?? 0);
      ?>
      <div class="qa-item" id="qa-item-<?= (int)($a['id'] ?? 0) ?>" data-order="<?= $i + 1 ?>" data-score="<?= $qaScoreVal ?>">
        <div class="qa-head">
          <div class="qa-q">
            <div class="q-num"><?= $a['question_number'] ?? ($i + 1) ?></div>
            <?= htmlspecialchars($qText) ?>
          </div>
          <div class="qa-score-box">
            <input type="number" name="manual_scores[<?= (int)($a['question_id'] ?? 0) ?>]" value="<?= $qaScoreVal ?>" min="0" max="<?= $qaMax ?>">
            <span>/<?= $qaMax ?></span>
          </div>
        </div>
        <?php if ($ansText && !$isUploadFailureText): ?>
        <div class="qa-a"><?= nl2br(htmlspecialchars($ansText)) ?></div>
        <?php elseif ($hasAudio): ?>
        <div class="qa-a" style="background:#F5F3FF;border-color:#DDD6FE;color:var(--purple)">
          <i class="fa-solid fa-microphone fa-xs"></i> Voice response recorded
        </div>
        <?php elseif ($missingVoiceFile): ?>
        <div class="qa-a" style="background:#FFF7ED;border-color:#FED7AA;color:#C2410C">
          <i class="fa-solid fa-triangle-exclamation fa-xs"></i> Voice mode was used, but no playable audio file was saved for this answer.
        </div>
        <?php else: ?>
        <div class="qa-a" style="color:var(--gray);font-style:italic">No response recorded</div>
        <?php endif; ?>

        <?php if ($hasAudio): ?>
        <!-- ✅ INLINE AUDIO — plays right here, no new tab -->
        <div class="qa-audio-wrap">
          <div style="font-size:11px;font-weight:600;color:#1E40AF;margin-bottom:6px;display:flex;align-items:center;gap:5px">
            <i class="fa-solid fa-microphone fa-xs"></i> Voice Answer
          </div>
          <audio controls preload="none" style="width:100%;border-radius:6px">
            <source src="<?= htmlspecialchars($a['audio_url']) ?>">
            Your browser does not support audio.
          </audio>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:12px">
            <a href="<?= htmlspecialchars($a['audio_url']) ?>" target="_blank" rel="noopener" style="color:var(--blue);font-weight:700;text-decoration:none">
              <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i> Open voice note
            </a>
            <a href="<?= htmlspecialchars($a['audio_url']) ?>" download style="color:var(--purple);font-weight:700;text-decoration:none">
              <i class="fa-solid fa-download fa-xs"></i> Download
            </a>
          </div>
          <?php if (!$ansText || $isUploadFailureText): ?>
          <button type="button" class="qa-analyze-btn" id="analyze-btn-<?= (int)($a['id'] ?? 0) ?>"
            onclick="analyzeVoice(<?= (int)($a['id'] ?? 0) ?>, <?= $id ?>, <?= (int)($c['campaign_id'] ?? 0) ?>)">
            <i class="fa-solid fa-wand-magic-sparkles fa-xs"></i> Analyze with AI
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="qa-meta">
          <span class="qa-tag">
            <i class="fa-solid fa-<?= ($a['answer_mode'] ?? '') === 'voice' ? 'microphone' : 'keyboard' ?> fa-xs"></i>
            <?= ucfirst($a['answer_mode'] ?? 'text') ?>
          </span>
          <?php if ($tt > 0): ?>
          <span class="qa-tag" style="background:#ECFDF5;color:#065F46">
            <i class="fa-solid fa-stopwatch fa-xs"></i> <?= $tt ?>s
          </span>
          <?php endif; ?>
          <?php if ($cp > 0): ?>
          <span class="qa-tag" style="background:#FEE2E2;color:#991B1B">
            <i class="fa-solid fa-copy fa-xs"></i> <?= $cp ?> copies
          </span>
          <?php endif; ?>
        </div>
        <?php if ($qaScore && !empty($qaScore['ai_reasoning'])):
          $reasonText = str_replace(
            ['AI scoring unavailable — manual review required.', 'AI scoring unavailable — manual review recommended.'],
            'Pending AI score. Please review manually.',
            $qaScore['ai_reasoning']
          );
        ?>
        <div class="qa-score-reason"><?= htmlspecialchars($reasonText) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div><!-- #qa-list -->
      <div class="qa-save-bar">
        <input type="text" name="manual_reason" class="form-control" placeholder="Manual review note, e.g. voice answer reviewed with recording">
        <button type="submit" name="save_manual_scores" class="btn-primary" style="padding:10px 18px;white-space:nowrap">
          <i class="fa-solid fa-floppy-disk"></i> Save Manual Marks
        </button>
      </div>
      </form>
      <?php
      elseif (!empty($questions)): foreach ($questions as $i => $q): ?>
      <div class="qa-item">
        <div class="qa-q">
          <div class="q-num"><?= $q['question_number'] ?? ($i + 1) ?></div>
          <?= htmlspecialchars($q['question_text'] ?? '') ?>
        </div>
        <div style="font-size:13px;color:var(--gray);font-style:italic;padding:8px 10px;background:#fff;border-radius:8px">
          <i class="fa-regular fa-hourglass fa-xs"></i> Awaiting response...
        </div>
      </div>
      <?php endforeach;
      else: ?>
      <div style="text-align:center;padding:40px;color:var(--gray)">
        <i class="fa-regular fa-comment-dots fa-2x" style="margin-bottom:12px;display:block;opacity:.3"></i>
        <div style="font-weight:600">No answers yet</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: AI CALL RESULTS -->
  <?php if (defined('DIALER_API_KEY') && DIALER_API_KEY): ?>
  <div class="tab-panel" id="tab-aicall">
  <?php if (!$ai_call): ?>
    <div class="card animate-in" style="text-align:center;padding:48px 24px">
      <i class="fa-solid fa-phone-volume fa-3x" style="color:#D1FAE5;margin-bottom:16px;display:block"></i>
      <div style="font-size:16px;font-weight:700;margin-bottom:8px">No AI Call results yet</div>
      <div style="font-size:13px;color:var(--gray)">Click <strong>AI Call</strong> to trigger a second-round call. Results will appear here automatically after the call ends.</div>
    </div>
  <?php else:
    // Grade colours
    $gradeColor = match(strtoupper($ai_call['interview_grade'] ?? '')) {
      'A+','A' => ['#ECFDF5','#065F46','#10B981'],
      'B'      => ['#EFF6FF','#1E40AF','#3B82F6'],
      'C'      => ['#FFFBEB','#92400E','#F59E0B'],
      default  => ['#FEF2F2','#991B1B','#EF4444'],
    };
    $recColor = match(strtolower($ai_call['interview_recommendation'] ?? '')) {
      'advance'  => '#059669',
      'consider' => '#D97706',
      default    => '#DC2626',
    };
    $recIcon = match(strtolower($ai_call['interview_recommendation'] ?? '')) {
      'advance'  => 'fa-circle-check',
      'consider' => 'fa-circle-question',
      default    => 'fa-circle-xmark',
    };
    $strengths   = json_decode($ai_call['strengths'] ?? '[]', true) ?: [];
    $improvements= json_decode($ai_call['improvements'] ?? '[]', true) ?: [];
    $transcript  = json_decode($ai_call['transcript'] ?? '[]', true);
    $isJsonTrans = is_array($transcript);
  ?>
    <!-- Score card -->
    <div class="card animate-in" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray);margin-bottom:4px">AI Call Score — Round 2</div>
          <div style="font-size:12px;color:var(--gray)"><?= date('d M Y, h:i A', strtotime($ai_call['received_at'])) ?> &middot; <?= $ai_call['duration_sec'] ? floor($ai_call['duration_sec']/60).'m '.($ai_call['duration_sec']%60).'s' : '—' ?> &middot; <?= ucfirst($ai_call['sentiment'] ?? '—') ?></div>
        </div>
        <!-- Grade badge -->
        <div style="background:<?= $gradeColor[0] ?>;border:2px solid <?= $gradeColor[2] ?>;border-radius:14px;padding:10px 20px;text-align:center;min-width:80px">
          <div style="font-size:28px;font-weight:900;color:<?= $gradeColor[1] ?>;line-height:1"><?= htmlspecialchars($ai_call['interview_grade'] ?? '—') ?></div>
          <div style="font-size:10px;font-weight:700;color:<?= $gradeColor[2] ?>;text-transform:uppercase;letter-spacing:.5px"><?= ($ai_call['interview_score'] !== null ? $ai_call['interview_score'].'/100' : '—') ?></div>
        </div>
      </div>

      <!-- Recommendation -->
      <div style="display:flex;align-items:center;gap:10px;padding:12px 16px;background:<?= $gradeColor[0] ?>;border-radius:10px;margin-bottom:16px">
        <i class="fa-solid <?= $recIcon ?>" style="color:<?= $recColor ?>;font-size:20px"></i>
        <div>
          <div style="font-size:13px;font-weight:700;color:<?= $recColor ?>">Recommendation: <?= htmlspecialchars($ai_call['interview_recommendation'] ?? '—') ?></div>
          <?php if ($ai_call['score_reasoning']): ?>
          <div style="font-size:12px;color:var(--gray2);margin-top:3px"><?= htmlspecialchars($ai_call['score_reasoning']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Summary -->
      <?php if ($ai_call['summary']): ?>
      <div style="font-size:13px;color:var(--text);line-height:1.6;padding:12px 16px;background:#F8FAFC;border-radius:10px;margin-bottom:16px;border-left:3px solid <?= $gradeColor[2] ?>">
        <?= htmlspecialchars($ai_call['summary']) ?>
      </div>
      <?php endif; ?>

      <!-- Strengths / Improvements -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div style="background:#F0FDF4;border-radius:10px;padding:14px">
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#065F46;margin-bottom:8px"><i class="fa-solid fa-thumbs-up fa-xs"></i> Strengths</div>
          <?php if ($strengths): foreach ($strengths as $s): ?>
          <div style="font-size:12px;color:#065F46;padding:4px 0;border-bottom:1px solid #D1FAE5;display:flex;align-items:flex-start;gap:6px">
            <i class="fa-solid fa-check fa-xs" style="margin-top:3px;flex-shrink:0"></i><?= htmlspecialchars($s) ?>
          </div>
          <?php endforeach; else: ?>
          <div style="font-size:12px;color:var(--gray)">—</div>
          <?php endif; ?>
        </div>
        <div style="background:#FEF2F2;border-radius:10px;padding:14px">
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#991B1B;margin-bottom:8px"><i class="fa-solid fa-triangle-exclamation fa-xs"></i> Improvements</div>
          <?php if ($improvements): foreach ($improvements as $imp): ?>
          <div style="font-size:12px;color:#991B1B;padding:4px 0;border-bottom:1px solid #FECACA;display:flex;align-items:flex-start;gap:6px">
            <i class="fa-solid fa-arrow-up fa-xs" style="margin-top:3px;flex-shrink:0"></i><?= htmlspecialchars($imp) ?>
          </div>
          <?php endforeach; else: ?>
          <div style="font-size:12px;color:var(--gray)">—</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Transcript -->
    <?php if ($ai_call['transcript']): ?>
    <div class="card animate-in">
      <div class="card-header">
        <h3><i class="fa-solid fa-comments" style="color:var(--blue)"></i> Call Transcript</h3>
      </div>
      <div style="max-height:420px;overflow-y:auto;display:flex;flex-direction:column;gap:10px;padding-top:4px">
      <?php if ($isJsonTrans): foreach ($transcript as $turn):
        $isAgent = strtolower($turn['role'] ?? $turn['speaker'] ?? '') !== 'user';
        $speaker = $isAgent ? 'AI Agent' : ($c['name'] ?: 'Candidate');
        $text    = $turn['content'] ?? $turn['text'] ?? $turn['message'] ?? '';
      ?>
        <div style="display:flex;gap:10px;align-items:flex-start;<?= $isAgent ? '' : 'flex-direction:row-reverse' ?>">
          <div style="width:30px;height:30px;border-radius:50%;background:<?= $isAgent ? 'linear-gradient(135deg,#6366F1,#8B5CF6)' : 'linear-gradient(135deg,#0EA5E9,#3B82F6)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fa-solid <?= $isAgent ? 'fa-robot' : 'fa-user' ?> fa-xs" style="color:#fff"></i>
          </div>
          <div style="background:<?= $isAgent ? '#F1F5F9' : '#EFF6FF' ?>;border-radius:10px;padding:10px 14px;max-width:75%;font-size:13px;line-height:1.5;color:var(--text)">
            <div style="font-size:10px;font-weight:700;color:var(--gray);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px"><?= htmlspecialchars($speaker) ?></div>
            <?= htmlspecialchars($text) ?>
          </div>
        </div>
      <?php endforeach; else: ?>
        <pre style="font-size:12px;line-height:1.6;color:var(--text);white-space:pre-wrap;margin:0"><?= htmlspecialchars((string)$ai_call['transcript']) ?></pre>
      <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- History of past calls -->
    <?php if (count($ai_calls) > 1): ?>
    <div class="card animate-in" style="margin-top:16px">
      <div class="card-header"><h3><i class="fa-solid fa-clock-rotate-left" style="color:var(--gray)"></i> Previous Calls</h3></div>
      <?php foreach (array_slice($ai_calls, 1) as $past):
        $pg = strtoupper($past['interview_grade'] ?? '?');
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F1F5F9;font-size:12px;color:var(--gray2)">
        <span><?= date('d M Y, h:i A', strtotime($past['received_at'])) ?></span>
        <span><?= $past['duration_sec'] ? floor($past['duration_sec']/60).'m' : '—' ?></span>
        <span style="font-weight:700"><?= ($past['interview_score'] ?? '—') ?>/100</span>
        <span style="font-weight:700"><?= $pg ?> — <?= htmlspecialchars($past['interview_recommendation'] ?? '—') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /right -->
</div><!-- /detail-grid -->

<?php if ($recUrl): ?>
<div class="floating-rec" id="floatingRecording">
  <div class="floating-rec-head" id="floatingRecHandle">
    <span><i class="fa-solid fa-grip-lines"></i> Recording</span>
    <div class="floating-rec-actions">
      <a href="video_view.php?id=<?= $id ?>" target="_blank" rel="noopener" title="Open in new tab"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
      <a href="<?= htmlspecialchars($recUrl) ?>" download title="Download"><i class="fa-solid fa-download"></i></a>
      <button type="button" onclick="toggleFloatingRecording(false)" title="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="floating-rec-body">
    <?php
    $floatExt = strtolower(pathinfo(strtok($recUrl, '?'), PATHINFO_EXTENSION));
    if (in_array($floatExt, ['mp4','webm','mov','mkv'])): ?>
      <video controls preload="auto" onloadedmetadata="fixWebmDuration(this)"><source src="<?= htmlspecialchars($recUrl) ?>"></video>
    <?php else: ?>
      <audio controls preload="auto"><source src="<?= htmlspecialchars($recUrl) ?>"></audio>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- AI CALL MODAL -->
<?php if (defined('DIALER_API_KEY') && DIALER_API_KEY): ?>
<div class="modal-overlay" id="callModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <h3><i class="fa-solid fa-phone" style="color:#10B981"></i> Trigger AI Call</h3>
      <button class="modal-close" onclick="closeModal('callModal')">✕</button>
    </div>
    <div style="padding:0 20px 20px">
      <div class="form-group">
        <label class="form-label">Phone Number</label>
        <input class="form-control" id="callPhone" value="<?= htmlspecialchars($c['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Candidate Name</label>
        <input class="form-control" id="callName" value="<?= htmlspecialchars($c['name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Caller ID <span style="font-size:11px;color:var(--gray)">(outbound number shown to candidate)</span></label>
        <input class="form-control" id="callCallerId" value="<?= htmlspecialchars(DIALER_CALLER_ID) ?>">
      </div>
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;margin-bottom:16px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray);margin-bottom:8px">Context passed to AI</div>
        <div style="font-size:12px;color:var(--text2);display:grid;gap:4px">
          <div><b>Role:</b> <?= htmlspecialchars($c['job_role'] ?? '—') ?></div>
          <div><b>Campaign:</b> <?= htmlspecialchars($c['campaign_name'] ?? '—') ?></div>
          <div style="word-break:break-all"><b>Interview Link:</b> <?= htmlspecialchars(defined('INTERVIEW_URL') ? INTERVIEW_URL . '?t=' . ($c['unique_token'] ?? '') : '—') ?></div>
        </div>
      </div>
      <div id="callResult" style="display:none;margin-bottom:12px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button onclick="closeModal('callModal')" class="btn-sm">Cancel</button>
        <button onclick="triggerCall()" id="callBtn" class="btn-primary" style="background:#10B981;border-color:#10B981">
          <i class="fa-solid fa-phone fa-xs"></i> Call Now
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- STATUS MODAL -->
<div class="modal-overlay" id="statusModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Update Candidate Status</h3>
      <button class="modal-close" onclick="closeModal('statusModal')">✕</button>
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select class="form-control" id="newStatus">
        <?php foreach (['pending','outreach_sent','interview_started','interview_completed','shortlisted','rejected','on_hold'] as $s): ?>
        <option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>>
          <?= ucfirst(str_replace('_', ' ', $s)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Notes (optional)</label>
      <textarea class="form-control" id="statusNotes" placeholder="Any comments..."><?= htmlspecialchars($c['notes'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn-outline" onclick="closeModal('statusModal')">Cancel</button>
      <button class="btn-primary" onclick="saveStatus()">
        <i class="fa-solid fa-floppy-disk fa-sm"></i> Save
      </button>
    </div>
  </div>
</div>

<!-- EDIT CANDIDATE MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Edit Candidate Details</h3>
      <button class="modal-close" onclick="closeModal('editModal')">✕</button>
    </div>
    <div class="grid-2">
      <div class="form-group">
        <label class="form-label">Campaign</label>
        <select class="form-control" id="editCampaign">
          <?php foreach ($campaigns as $camp): ?>
          <option value="<?= $camp['id'] ?>" <?= (int)$c['campaign_id'] === (int)$camp['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($camp['name']) ?><?= !empty($camp['job_role']) ? ' - ' . htmlspecialchars($camp['job_role']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Name</label><input class="form-control" id="editName" value="<?= htmlspecialchars($c['name'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Phone</label><input class="form-control" id="editPhone" value="<?= htmlspecialchars($c['phone'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Email</label><input class="form-control" id="editEmail" value="<?= htmlspecialchars($c['email'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">City</label><input class="form-control" id="editCity" value="<?= htmlspecialchars($c['city'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Experience Years</label><input class="form-control" id="editExp" value="<?= htmlspecialchars($c['experience_years'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Current CTC</label><input class="form-control" id="editCtc" value="<?= htmlspecialchars($c['current_ctc'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Expected CTC</label><input class="form-control" id="editExpected" value="<?= htmlspecialchars($c['expected_ctc'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Source</label><input class="form-control" id="editSource" value="<?= htmlspecialchars($c['source'] ?? '') ?>"></div>
      <div class="form-group"><label class="form-label">Referral Name</label><input class="form-control" id="editReferralName" value="<?= htmlspecialchars($c['referred_by_display_name'] ?? '') ?>"></div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="btn-outline" onclick="closeModal('editModal')">Cancel</button>
      <button class="btn-primary" onclick="saveCandidateDetails()"><i class="fa-solid fa-floppy-disk fa-sm"></i> Save</button>
    </div>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="confirm-overlay" id="deleteModal">
  <div class="confirm-box">
    <div class="confirm-icon">🗑️</div>
    <div class="confirm-title">Delete Candidate?</div>
    <div class="confirm-msg">
      This will permanently delete <strong id="delName"></strong> and all their interview data.<br>
      <span style="color:#EF4444;font-weight:700">This cannot be undone.</span>
    </div>
    <div class="confirm-btns">
      <button class="btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn-danger" id="confirmDeleteBtn">
        <i class="fa-solid fa-trash fa-sm"></i> Delete Permanently
      </button>
    </div>
  </div>
</div>

<script>
// ── TABS ─────────────────────────────────────────────────────
const tabBtns   = document.querySelectorAll('.tab-btn');
const tabPanels = document.querySelectorAll('.tab-panel');

function switchTab(name, btn) {
  tabPanels.forEach(p => p.classList.remove('active'));
  tabBtns.forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  btn.classList.add('active');
  // Pause all audio/video when switching tabs (prevents background playback)
  document.querySelectorAll('audio, video').forEach(m => m.pause());
}

function toggleFloatingRecording(show) {
  const box = document.getElementById('floatingRecording');
  if (!box) return;
  box.classList.toggle('show', !!show);
  if (show) {
    const v = box.querySelector('video');
    if (v) fixWebmDuration(v);
  } else {
    box.querySelectorAll('audio, video').forEach(m => m.pause());
  }
}

(() => {
  const box = document.getElementById('floatingRecording');
  const handle = document.getElementById('floatingRecHandle');
  if (!box || !handle) return;
  let dragging = false, startX = 0, startY = 0, startLeft = 0, startTop = 0;
  handle.addEventListener('pointerdown', e => {
    if (e.target.closest('a,button')) return;
    dragging = true;
    const rect = box.getBoundingClientRect();
    startX = e.clientX; startY = e.clientY; startLeft = rect.left; startTop = rect.top;
    box.style.left = startLeft + 'px';
    box.style.top = startTop + 'px';
    box.style.right = 'auto';
    box.style.bottom = 'auto';
    handle.setPointerCapture(e.pointerId);
  });
  handle.addEventListener('pointermove', e => {
    if (!dragging) return;
    const nextLeft = Math.max(8, Math.min(window.innerWidth - box.offsetWidth - 8, startLeft + e.clientX - startX));
    const nextTop = Math.max(8, Math.min(window.innerHeight - 80, startTop + e.clientY - startY));
    box.style.left = nextLeft + 'px';
    box.style.top = nextTop + 'px';
  });
  handle.addEventListener('pointerup', () => dragging = false);
})();

// ── RING + SCORE BARS ANIMATE ────────────────────────────────
window.addEventListener('load', () => {
  setTimeout(() => {
    document.querySelectorAll('.ring-fill').forEach(el => {
      el.style.strokeDashoffset = el.dataset.offset;
    });
    document.querySelectorAll('.sbar-fill').forEach(el => {
      el.style.width = el.dataset.w + '%';
    });
  }, 300);
});

// ── TOAST (from PHP redirect) ────────────────────────────────
const toastEl = document.getElementById('toastEl');
if (toastEl) {
  setTimeout(() => {
    toastEl.style.animation = 'toastOut .3s forwards';
    setTimeout(() => toastEl.remove(), 300);
  }, 3500);
}

// ── TOAST HELPER ─────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const icons = { success: 'circle-check', error: 'circle-xmark', info: 'circle-info' };
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.innerHTML = `<i class="fa-solid fa-${icons[type] || 'circle-check'}"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => {
    t.style.animation = 'toastOut .3s forwards';
    setTimeout(() => t.remove(), 300);
  }, 3000);
}

// ── MODALS ────────────────────────────────────────────────────
function openCallModal() { document.getElementById('callModal')?.classList.add('active'); }

async function triggerCall() {
  const btn = document.getElementById('callBtn');
  const res = document.getElementById('callResult');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Calling…';
  res.style.display = 'none';
  try {
    const r = await fetch('/api/outreach.php?action=trigger_ai_call', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        candidate_id: <?= $id ?>,
        caller_id: document.getElementById('callCallerId').value.trim(),
      })
    });
    const d = await r.json();
    res.style.display = 'block';
    if (d.success) {
      res.innerHTML = '<div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:8px;padding:10px 14px;font-size:13px;color:#065F46"><i class="fa-solid fa-circle-check"></i> Call triggered! Status: <b>' + (d.status||'ringing') + '</b><br><span style="font-size:11px;opacity:.7">Call ID: ' + (d.call_id||'—') + '</span></div>';
      btn.innerHTML = '<i class="fa-solid fa-check fa-xs"></i> Called';
      btn.style.background = '#059669';
    } else {
      res.innerHTML = '<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#991B1B"><i class="fa-solid fa-triangle-exclamation"></i> ' + (d.error||'Failed') + '</div>';
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-phone fa-xs"></i> Retry';
    }
  } catch(e) {
    res.style.display = 'block';
    res.innerHTML = '<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;color:#991B1B">Network error — try again</div>';
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-phone fa-xs"></i> Call Now';
  }
}

function openStatusModal() { document.getElementById('statusModal').classList.add('active'); }
function openEditModal() { document.getElementById('editModal').classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.modal-overlay, .confirm-overlay').forEach(m => {
  m.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('active');
  });
});

// ── DELETE ────────────────────────────────────────────────────
let _deleteId = null;
function confirmDelete(id, name) {
  _deleteId = id;
  document.getElementById('delName').textContent = name;
  document.getElementById('deleteModal').classList.add('active');
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Deleting...';
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', candidate_id: _deleteId })
    });
    const d = await r.json();
    if (d.success) {
      showToast('Candidate deleted successfully', 'success');
      setTimeout(() => location.href = 'candidates.php', 900);
    } else {
      showToast('Error: ' + (d.error || 'Delete failed'), 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash fa-sm"></i> Delete Permanently';
    }
  } catch (e) {
    showToast('Network error. Try again.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-trash fa-sm"></i> Delete Permanently';
  }
});

// ── STATUS UPDATE ─────────────────────────────────────────────
async function saveStatus() {
  const status = document.getElementById('newStatus').value;
  const notes  = document.getElementById('statusNotes').value;
  const btn    = document.querySelector('#statusModal .btn-primary');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Saving...';
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_status', candidate_id: <?= $c['id'] ?>, status, notes })
    });
    const d = await r.json();
    if (d.success) {
      closeModal('statusModal');
      showToast('Status updated!', 'success');
      setTimeout(() => location.reload(), 700);
    } else {
      showToast('Error: ' + (d.error || 'Failed'), 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk fa-sm"></i> Save';
    }
  } catch (e) {
    showToast('Network error', 'error');
  }
}

async function saveCandidateDetails() {
  const btn = document.querySelector('#editModal .btn-primary');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Saving...';
  try {
    const r = await fetch('/api/candidates.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'update',
        candidate_id: <?= $c['id'] ?>,
        campaign_id: parseInt(document.getElementById('editCampaign').value, 10),
        name: document.getElementById('editName').value.trim(),
        phone: document.getElementById('editPhone').value.trim(),
        email: document.getElementById('editEmail').value.trim(),
        city: document.getElementById('editCity').value.trim(),
        experience_years: document.getElementById('editExp').value.trim(),
        current_ctc: document.getElementById('editCtc').value.trim(),
        expected_ctc: document.getElementById('editExpected').value.trim(),
        source: document.getElementById('editSource').value.trim(),
        referred_by_name: document.getElementById('editReferralName').value.trim()
      })
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Update failed');
    showToast('Candidate updated', 'success');
    setTimeout(() => location.reload(), 700);
  } catch (e) {
    showToast(e.message || 'Update failed', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk fa-sm"></i> Save';
  }
}

async function scheduleReminder() {
  showToast('Scheduling reminder...', 'info');
  try {
    const r = await fetch('/api/reminders.php?action=schedule', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ candidate_id: <?= $c['id'] ?>, hours: 24 })
    });
    const d = await r.json();
    if (d.success) showToast(d.message || 'Reminder scheduled', 'success');
    else showToast('Error: ' + (d.error || 'Reminder failed'), 'error');
  } catch (e) {
    showToast('Network error', 'error');
  }
}

// ── COPY LINK ─────────────────────────────────────────────────
function fixWebmDuration(video) {
  if (!isNaN(video.duration) && video.duration !== Infinity && video.duration > 0) return;
  // WebM files from MediaRecorder lack duration metadata; seeking to a huge value
  // forces the browser to scan the file and calculate the real duration.
  video.currentTime = 1e101;
  video.addEventListener('timeupdate', function handler() {
    video.removeEventListener('timeupdate', handler);
    video.currentTime = 0;
  }, { once: true });
}

async function rescoreCandidate() {
  const btn = document.querySelector('[onclick="rescoreCandidate()"]');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Queuing…'; }
  try {
    const r = await fetch(`/api/score.php?async=1&candidate_id=<?= $id ?>&campaign_id=<?= $c['campaign_id'] ?>`);
    const d = await r.json();
    if (d.status === 'queued') {
      if (btn) { btn.innerHTML = '<i class="fa-solid fa-check fa-xs"></i> Queued'; }
      showToast('Scoring started in background — reloading in 12s…', 'success');
      setTimeout(() => location.reload(), 12000);
    } else {
      showToast(d.error || 'Failed to queue scoring', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-microphone fa-xs"></i> Score Voice'; }
    }
  } catch(e) {
    showToast('Network error — please retry', 'error');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-microphone fa-xs"></i> Score Voice'; }
  }
}

// ── SORT Q&A ─────────────────────────────────────────────────
function sortQA(mode, btn) {
  const list = document.getElementById('qa-list');
  if (!list) return;
  const items = Array.from(list.querySelectorAll(':scope > .qa-item'));
  if (mode === 'order') {
    items.sort((a, b) => parseInt(a.dataset.order || 0) - parseInt(b.dataset.order || 0));
  } else if (mode === 'score_desc') {
    items.sort((a, b) => parseInt(b.dataset.score || 0) - parseInt(a.dataset.score || 0));
  } else if (mode === 'score_asc') {
    items.sort((a, b) => parseInt(a.dataset.score || 0) - parseInt(b.dataset.score || 0));
  }
  items.forEach(item => list.appendChild(item));
  document.querySelectorAll('.qa-sort-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
}

// ── PER-VOICE AI ANALYSIS ─────────────────────────────────────
async function analyzeVoice(answerId, candidateId, campaignId) {
  const btn = document.getElementById('analyze-btn-' + answerId);
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i> Transcribing…'; }
  try {
    const r = await fetch(`/api/score.php?action=transcribe_one&answer_id=${answerId}&candidate_id=${candidateId}&campaign_id=${campaignId}`);
    const d = await r.json();
    if (d.success && d.transcript) {
      const item = btn ? btn.closest('.qa-item') : null;
      if (item) {
        const qaA = item.querySelector('.qa-a');
        if (qaA) {
          qaA.style.background = '#F0FDF4';
          qaA.style.borderColor = '#BBF7D0';
          qaA.style.color = '#166534';
          qaA.innerHTML = '<i class="fa-solid fa-check-circle fa-xs" style="margin-right:5px"></i><strong>Transcript:</strong> ' + d.transcript.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
        }
      }
      // Fire async background rescore — returns immediately, no browser timeout risk
      fetch(`/api/score.php?async=1&candidate_id=${candidateId}&campaign_id=${campaignId}`).catch(() => {});
      if (btn) { btn.innerHTML = '<i class="fa-solid fa-check fa-xs"></i> Transcript saved'; btn.style.background='#D1FAE5'; btn.style.color='#065F46'; btn.style.borderColor='#6EE7B7'; }
      showToast('Transcript saved — AI scoring running in background. Reloading in 12s…', 'success');
      setTimeout(() => location.reload(), 12000);
    } else {
      showToast(d.error || 'Transcription failed — check AI keys and audio file path', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles fa-xs"></i> Analyze with AI'; }
    }
  } catch(e) {
    showToast('Connection took too long — please click Analyze again', 'error');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles fa-xs"></i> Analyze with AI'; }
  }
}

function copyLink() {
  const link = '<?= $interviewLink ?>';
  if (!link) return;
  navigator.clipboard.writeText(link)
    .then(() => showToast('Interview link copied!', 'info'))
    .catch(() => {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = link; document.body.appendChild(ta);
      ta.select(); document.execCommand('copy');
      ta.remove(); showToast('Link copied!', 'info');
    });
}

// Keep review playback sane: starting one recording/audio pauses all others.
document.querySelectorAll('audio, video').forEach(media => {
  media.addEventListener('play', () => {
    document.querySelectorAll('audio, video').forEach(other => {
      if (other !== media && !other.paused) other.pause();
    });
  });
});

// ── PER-Q TOGGLE ─────────────────────────────────────────────
function togglePerQ(heading) {
  const list = document.getElementById('perq-list');
  const chev = document.getElementById('perq-chevron');
  if (!list) return;
  list.classList.toggle('collapsed');
  if (chev) chev.style.transform = list.classList.contains('collapsed') ? 'rotate(-90deg)' : '';
}

// ── KEYBOARD SHORTCUTS ────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.target.matches('input, textarea, select')) return;
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active, .confirm-overlay.active')
      .forEach(m => m.classList.remove('active'));
  }
  if (e.key === '1') switchTab('qa',        document.querySelector('[data-tab-btn=qa]'));
  if (e.key === '2') switchTab('recording', document.querySelector('[data-tab-btn=recording]'));
  const aiBtn = document.querySelector('[data-tab-btn=aicall]');
  if (e.key === '3' && aiBtn) switchTab('aicall', aiBtn);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
