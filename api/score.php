<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
if(php_sapi_name()==='cli'){$candidate_id=(int)($argv[1]??0);$campaign_id=(int)($argv[2]??0);}
else{header('Content-Type: application/json');$candidate_id=(int)($_GET['candidate_id']??0);$campaign_id=(int)($_GET['campaign_id']??0);}
if(!$candidate_id||!$campaign_id){log_s("Missing args");exit(1);}
log_s("Scoring candidate $candidate_id campaign $campaign_id");
$candidate=db_fetch_one("SELECT c.*,camp.passing_score,camp.el_agent_id,camp.name as campaign_name,camp.job_role FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.id=? AND c.campaign_id=?",[$candidate_id,$campaign_id],'ii');
if(!$candidate){log_s("Not found");exit(1);}
$questions=db_fetch_all("SELECT * FROM questions WHERE campaign_id=? ORDER BY order_no",[$campaign_id],'i');
$answers=db_fetch_all("SELECT ia.*,q.question_text,q.parameter,q.parameter_label,q.max_marks,q.ideal_answer_hint FROM interview_answers ia JOIN questions q ON ia.question_id=q.id WHERE ia.candidate_id=? ORDER BY ia.id",[$candidate_id],'i');
$answer_by_question=[];
foreach($answers as $a){
  $qid=(int)$a['question_id'];
  if(!$qid)continue;
  $existing=$answer_by_question[$qid]??null;
  $current_has_text=trim((string)($a['text_answer']??''))!=='';
  $existing_has_text=trim((string)($existing['text_answer']??''))!=='';
  if(!$existing||($current_has_text&&!$existing_has_text))$answer_by_question[$qid]=$a;
}
function clean_answer_text($answer){
  return trim((string)($answer['text_answer']??''));
}
function has_gradable_answer($answer){
  if(!$answer)return false;
  $text=clean_answer_text($answer);
  if($text!=='' && !str_starts_with($text,'[Voice answer recorded but upload failed:'))return true;
  return trim((string)($answer['audio_url']??''))!=='';
}
function score_lookup_key($value){
  return strtolower(preg_replace('/[^a-z0-9]+/','_',trim((string)$value)));
}
function add_score_lookup(&$lookup,$key,$score){
  $key=score_lookup_key($key);
  if($key!=='')$lookup[$key]=$score;
}
$qa='';
foreach($questions as $idx=>$q){
  $answer=$answer_by_question[(int)$q['id']]??null;
  $answer_text=clean_answer_text($answer);
  $qa.="Question ID: {$q['id']}\n";
  $qa.="Parameter key: {$q['parameter']}\n";
  $qa.="Parameter label: {$q['parameter_label']}\n";
  $qa.="Max marks: {$q['max_marks']}\n";
  $qa.="Question: {$q['question_text']}\n";
  $qa.="Answer: ".($answer_text!==''?$answer_text:'[No gradable response recorded]')."\n";
  if(!empty($q['ideal_answer_hint']))$qa.="Scoring hints: {$q['ideal_answer_hint']}\n";
  $qa.="\n";
}
$params='';foreach($questions as $q)$params.="\n- question_id={$q['id']} | key={$q['parameter']} | label={$q['parameter_label']} | max={$q['max_marks']}";
$prompt="Score this interview for role: {$candidate['job_role']}.\n\nANSWERS:\n$qa\nPARAMETERS:$params\n\nCRITICAL SCORING RULES:\n1. Return EXACTLY one score object per question_id listed above. Total score objects must equal total questions.\n2. Use the exact question_id from the list — this is the primary identifier, not the parameter key.\n3. Multiple questions may share the same parameter key — score each question INDEPENDENTLY based on its own answer.\n4. The score object's parameter value must exactly match the provided key for that question_id.\n5. Questions marked [No gradable response recorded] must receive 0 marks.\n6. Each reasoning must be specific to THAT question's answer, not a generic response.\n7. Score only from the candidate answer and scoring hints.\n\nReturn ONLY valid JSON, no markdown:\n{\"scores\":[{\"question_id\":123,\"parameter\":\"exact_key\",\"parameter_label\":\"Label\",\"score\":N,\"max_marks\":N,\"reasoning\":\"specific to this answer\"}],\"summary\":\"2-3 sentences\"}";
$result=null;
$scoring_available=false;
$groq_key=defined('GROQ_API_KEY')?GROQ_API_KEY:'';
if($groq_key){
  log_s("Using Groq (llama-3.3-70b) for scoring");
  $ch=curl_init('https://api.groq.com/openai/v1/chat/completions');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['model'=>'llama-3.3-70b-versatile','max_tokens'=>4000,'temperature'=>0.1,'messages'=>[['role'=>'system','content'=>'You are an expert HR interviewer and scoring AI. Always respond with valid JSON only, no markdown, no extra text.'],['role'=>'user','content'=>$prompt]]]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$groq_key],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false]);
  $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($code===200){
    $data=json_decode($resp,true);$content=preg_replace('/```json|```/','',$data['choices'][0]['message']['content']??'');
    $result=json_decode(trim($content),true);
    if(is_array($result)&&isset($result['scores'])&&is_array($result['scores'])){
      $scoring_available=true;
      log_s("Groq done. Scores: ".count($result['scores']));
    }else{
      log_s("Groq returned invalid JSON: ".substr((string)$content,0,300));
      $result=null;
    }
  }
  else log_s("Groq error $code: ".substr((string)$resp,0,300));
}else{
  log_s("Groq key missing — check GROQ_API_KEY in .env");
}
if(!$result){
  log_s("Scoring unavailable; marking manual review");
  $scores=[];$total=$max=0;
  foreach($questions as $q){
    $qid=(int)$q['id'];$marks=(int)$q['max_marks'];$max+=$marks;
    $answer=$answer_by_question[$qid]??null;
    $reason=has_gradable_answer($answer)?'AI scoring unavailable — manual review required.':'No gradable response recorded.';
    $scores[]=['parameter'=>$q['parameter'],'parameter_label'=>$q['parameter_label'],'score'=>0,'max_marks'=>$marks,'reasoning'=>$reason];
  }
  $result=['scores'=>$scores,'total_score'=>$total,'max_total'=>$max,'pass_fail'=>'pending','summary'=>'AI scoring unavailable — manual review required.'];
}
$result_scores=[];
// Build lookup by question_id (primary) and parameter (fallback)
$score_lookup=[];
$score_lookup_by_qid=[];
foreach(($result['scores']??[]) as $s){
  $qid=(int)($s['question_id']??0);
  if($qid>0)$score_lookup_by_qid[$qid]=$s;
  add_score_lookup($score_lookup,$s['parameter']??'',$s);
  add_score_lookup($score_lookup,$s['parameter_label']??'',$s);
  add_score_lookup($score_lookup,$s['label']??'',$s);
  add_score_lookup($score_lookup,$s['question_id']??'',$s);
}
$total_score=0;$max_total=0;$answered_count=0;$required_count=0;$required_answered_count=0;
foreach($questions as $q){
  $qid=(int)$q['id'];$parameter=(string)$q['parameter'];$marks=(int)$q['max_marks'];
  $answer=$answer_by_question[$qid]??null;
  $has_answer=has_gradable_answer($answer);
  if(!empty($q['is_required'])){
    $required_count++;
    if($has_answer)$required_answered_count++;
  }
  if($has_answer)$answered_count++;
  // Prefer question_id match (handles duplicate parameters correctly)
  $raw=$score_lookup_by_qid[$qid]??$score_lookup[score_lookup_key($parameter)]??$score_lookup[score_lookup_key($q['parameter_label'])]??$score_lookup[score_lookup_key($qid)]??[];
  $score=(int)($raw['score']??0);
  $reasoning=$raw['reasoning']??'';
  if($has_answer&&$scoring_available&&!$raw){
    $reasoning='AI response did not include a matching score for this question.';
  }
  if(!$has_answer){
    $score=0;
    $reasoning='No gradable response recorded.';
  }
  $score=max(0,min($score,$marks));
  $result_scores[]=[
    'parameter'=>$parameter,
    'parameter_label'=>$q['parameter_label'],
    'score'=>$score,
    'max_marks'=>$marks,
    'reasoning'=>$reasoning,
  ];
  $total_score+=$score;
  $max_total+=$marks;
}
$pct_score=$max_total>0?round(($total_score/$max_total)*100):0;
$passing=(int)($candidate['passing_score']??70);
$required_answered=$required_count>0?$required_answered_count>=$required_count:$answered_count>=count($questions);
$pf=!$scoring_available?'pending':(($required_answered&&$pct_score>=$passing)?'pass':'fail');
$summary=$result['summary']??'';
if(!$required_answered){
  $summary=trim($summary."\nIncomplete interview: $required_answered_count of $required_count required questions have gradable responses.");
}
foreach($result_scores as $s){
  $qid_score=(int)($s['question_id']??0);
  $ex=$qid_score>0
    ?db_fetch_one("SELECT id FROM scores WHERE candidate_id=? AND question_id=?",[$candidate_id,$qid_score],'ii')
    :db_fetch_one("SELECT id FROM scores WHERE candidate_id=? AND parameter=? AND (question_id IS NULL OR question_id=0)",[$candidate_id,$s['parameter']],'is');
  if($ex)db_execute("UPDATE scores SET ai_score=?,max_marks=?,ai_reasoning=?,question_id=? WHERE id=?",[(int)$s['score'],(int)$s['max_marks'],$s['reasoning']??'',$qid_score,$ex['id']],'iisii');
  else db_execute("INSERT INTO scores (candidate_id,campaign_id,parameter,parameter_label,ai_score,max_marks,ai_reasoning,question_id) VALUES (?,?,?,?,?,?,?,?)",[$candidate_id,$campaign_id,$s['parameter'],$s['parameter_label'],(int)$s['score'],(int)$s['max_marks'],$s['reasoning']??'',$qid_score],'iissiiis');
}
$ex2=db_fetch_one("SELECT id FROM interview_results WHERE candidate_id=?",[$candidate_id],'i');
if($ex2)db_execute("UPDATE interview_results SET total_score=?,max_score=?,pass_fail=?,ai_summary=?,updated_at=NOW() WHERE candidate_id=?",[$total_score,$max_total,$pf,$summary,$candidate_id],'iissi');
else db_execute("INSERT INTO interview_results (candidate_id,campaign_id,total_score,max_score,pass_fail,ai_summary) VALUES (?,?,?,?,?,?)",[$candidate_id,$campaign_id,$total_score,$max_total,$pf,$summary],'iiiiss');
$new_status=$pf==='pending'?'on_hold':($pf==='pass'?'shortlisted':'rejected');
db_execute("UPDATE candidates SET status=? WHERE id=?",[$new_status,$candidate_id],'si');
log_s("Result saved: $total_score/$max_total $pf status->$new_status");
$name=$candidate['name']?:'Candidate';$role=$candidate['job_role'];$camp=$candidate['campaign_name'];
if($pf==='pending'){
  log_s("Manual review pending; skipping result WhatsApp.");
  if(php_sapi_name()!=='cli')echo json_encode(['status'=>'pending','score'=>$total_score,'max'=>$max_total,'pass_fail'=>$pf]);
  exit;
}
if($pct_score>=80){
  log_s("Score>=80 — triggering EL call");
  $agent_id=$candidate['el_agent_id']?:EL_AGENT_ID;
  if($agent_id&&$agent_id!=='PASTE_YOUR_EL_AGENT_ID'){
    $phone=preg_replace('/[^0-9]/','',$candidate['phone']??'');
    if(strlen($phone)==10)$phone='+91'.$phone;elseif(!str_starts_with($phone,'+'))$phone='+'.$phone;
    $payload=['agent_id'=>$agent_id,'agent_phone_number_id'=>EL_PHONE_NUMBER_ID,'to_number'=>$phone,
      'conversation_config_override'=>['agent'=>['first_message'=>"Hello $name! Congratulations on completing your interview for $role. You scored {$pct_score}% and have been shortlisted! Our recruiter will contact you soon."]]];
    $ch=curl_init('https://api.elevenlabs.io/v1/convai/sip-trunk/outbound-call');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Content-Type: application/json','xi-api-key: '.EL_API_KEY],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);
    $r=curl_exec($ch);$rc=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    log_s("EL call: $rc — $r");
  }
}
if($pf==='pass'){
  $wa="🎉 *Congratulations $name!*\n\nYour interview for *$role* at *$camp* has been reviewed.\n\n✅ *Result: Shortlisted* | Score: {$pct_score}%\n\nOur recruiter will contact you with next steps.\n\n*HireAI — Avyukta Intellicall*";
}else{
  $wa="📋 *Interview Update*\n\nHi $name, thank you for your interview for *$role* at *$camp*.\n\nWe will keep your profile and reach out if a suitable opportunity arises.\n\n*HireAI — Avyukta Intellicall*";
}
send_whatsapp($candidate['phone'],$wa, [
  'org_id' => $candidate['org_id'],
  'candidate_id' => $candidate_id,
  'campaign_id' => $campaign_id,
  'reason' => 'interview_result_notification',
]);
log_s("WhatsApp sent. Done.");
if(php_sapi_name()!=='cli')echo json_encode(['status'=>'done','score'=>$total_score,'max'=>$max_total,'pass_fail'=>$pf]);
function log_s($m){echo '['.date('H:i:s')."] $m\n";}
