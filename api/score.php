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
  return clean_answer_text($answer)!=='';
}
$qa='';
if(!empty($answers)){
  foreach($answers as $a){
    $answer_text=clean_answer_text($a);
    $qa.="Parameter: {$a['parameter_label']}\nQuestion: {$a['question_text']}\nAnswer: ".($answer_text!==''?$answer_text:'[No gradable response recorded]')."\n";
    if($a['ideal_answer_hint'])$qa.="Hints: {$a['ideal_answer_hint']}\n";
    $qa.="\n";
  }
}else{
  $sess=db_fetch_one("SELECT full_transcript FROM interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1",[$candidate_id],'i');
  $qa=$sess['full_transcript']??'No answers.';
}
$params='';foreach($questions as $q)$params.="\n- {$q['parameter_label']} (max: {$q['max_marks']})";
$prompt="Score this interview for role: {$candidate['job_role']}.\n\nANSWERS:\n$qa\nPARAMETERS:$params\n\nImportant scoring rule: questions marked [No gradable response recorded] must receive 0 marks. Do not infer answers for blank responses.\n\nReturn ONLY valid JSON:\n{\"scores\":[{\"parameter\":\"key\",\"parameter_label\":\"Label\",\"score\":N,\"max_marks\":N,\"reasoning\":\"brief\"}],\"total_score\":N,\"max_total\":N,\"pass_fail\":\"pass or fail\",\"summary\":\"2-3 sentences\"}";
$result=null;
$okey=defined('OPENAI_API_KEY')?OPENAI_API_KEY:(defined('OPENAI_KEY')?OPENAI_KEY:'');
if($okey){
  $ch=curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','max_tokens'=>1000,'temperature'=>0.2,'messages'=>[['role'=>'user','content'=>$prompt]]]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$okey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>false]);
  $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($code===200){$data=json_decode($resp,true);$content=preg_replace('/```json|```/','',$data['choices'][0]['message']['content']??'');$result=json_decode(trim($content),true);log_s("OpenAI done. Total: ".($result['total_score']??'N/A'));}
  else log_s("OpenAI error $code");
}
if(!$result){
  log_s("Fallback scoring");
  $scores=[];$total=$max=0;
  foreach($questions as $q){
    $qid=(int)$q['id'];$marks=(int)$q['max_marks'];$max+=$marks;
    $answer=$answer_by_question[$qid]??null;
    $reason=has_gradable_answer($answer)?'AI scoring unavailable — manual review required.':'No gradable response recorded.';
    $scores[]=['parameter'=>$q['parameter'],'parameter_label'=>$q['parameter_label'],'score'=>0,'max_marks'=>$marks,'reasoning'=>$reason];
  }
  $pct=$max>0?round(($total/$max)*100):0;$pass=(int)($candidate['passing_score']??70);
  $result=['scores'=>$scores,'total_score'=>$total,'max_total'=>$max,'pass_fail'=>$pct>=$pass?'pass':'fail','summary'=>'AI scoring unavailable — manual review recommended.'];
}
$result_scores=[];
$score_by_parameter=[];
foreach(($result['scores']??[]) as $s){
  $score_by_parameter[(string)($s['parameter']??'')]=$s;
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
  $raw=$score_by_parameter[$parameter]??[];
  $score=(int)($raw['score']??0);
  $reasoning=$raw['reasoning']??'';
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
$pf=($required_answered&&$pct_score>=$passing)?'pass':'fail';
$summary=$result['summary']??'';
if(!$required_answered){
  $summary=trim($summary."\nIncomplete interview: $required_answered_count of $required_count required questions have gradable responses.");
}
foreach($result_scores as $s){
  $ex=db_fetch_one("SELECT id FROM scores WHERE candidate_id=? AND parameter=?",[$candidate_id,$s['parameter']],'is');
  if($ex)db_execute("UPDATE scores SET ai_score=?,max_marks=?,ai_reasoning=? WHERE id=?",[(int)$s['score'],(int)$s['max_marks'],$s['reasoning']??'',$ex['id']],'iisi');
  else db_execute("INSERT INTO scores (candidate_id,campaign_id,parameter,parameter_label,ai_score,max_marks,ai_reasoning) VALUES (?,?,?,?,?,?,?)",[$candidate_id,$campaign_id,$s['parameter'],$s['parameter_label'],(int)$s['score'],(int)$s['max_marks'],$s['reasoning']??''],'iissiis');
}
$ex2=db_fetch_one("SELECT id FROM interview_results WHERE candidate_id=?",[$candidate_id],'i');
if($ex2)db_execute("UPDATE interview_results SET total_score=?,max_score=?,pass_fail=?,ai_summary=?,updated_at=NOW() WHERE candidate_id=?",[$total_score,$max_total,$pf,$summary,$candidate_id],'iissi');
else db_execute("INSERT INTO interview_results (candidate_id,campaign_id,total_score,max_score,pass_fail,ai_summary) VALUES (?,?,?,?,?,?)",[$candidate_id,$campaign_id,$total_score,$max_total,$pf,$summary],'iiiiss');
$new_status=$pf==='pass'?'shortlisted':'rejected';
db_execute("UPDATE candidates SET status=? WHERE id=?",[$new_status,$candidate_id],'si');
log_s("Result saved: $total_score/$max_total $pf status->$new_status");
$name=$candidate['name']?:'Candidate';$role=$candidate['job_role'];$camp=$candidate['campaign_name'];
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
