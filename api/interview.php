<?php
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
$action=$_GET['action']??'';$method=$_SERVER['REQUEST_METHOD'];

if($action==='get_agents'&&$method==='GET'){
  $user=verify_jwt();if(!$user)json_response(['error'=>'Unauthorized'],401);
  $ch=curl_init('https://api.elevenlabs.io/v1/convai/agents?page_size=50');
  curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['xi-api-key: '.EL_API_KEY],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
  $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($code!==200)json_response(['error'=>'EL error: '.$resp],500);
  $data=json_decode($resp,true);$agents=[];
  foreach(($data['agents']??[])as $a)$agents[]=['agent_id'=>$a['agent_id'],'name'=>$a['name']??'Unnamed'];
  json_response(['agents'=>$agents]);
}

if($action==='start_call'&&$method==='GET'){
  $user=verify_jwt();if(!$user)json_response(['error'=>'Unauthorized'],401);
  $token=$_GET['token']??$_GET['t']??'';$candidate_id=(int)($_GET['candidate_id']??0);
  if($token){
    $c=db_fetch_one("SELECT c.*,camp.el_agent_id,camp.name as campaign_name,camp.job_role,camp.num_questions,camp.max_duration_minutes FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.unique_token=? AND c.org_id=?",[$token,$user['org_id']],'si');
  }else{
    $c=db_fetch_one("SELECT c.*,camp.el_agent_id,camp.name as campaign_name,camp.job_role,camp.num_questions,camp.max_duration_minutes FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.id=? AND c.org_id=?",[$candidate_id,$user['org_id']],'ii');
  }
  if(!$c)json_response(['error'=>'Candidate not found'],404);
  $agent_id=$c['el_agent_id']?:EL_AGENT_ID;
  if(!$agent_id||$agent_id==='PASTE_YOUR_EL_AGENT_ID')json_response(['error'=>'Agent not configured. Edit campaign and select an agent.'],400);
  json_response(trigger_el_outbound_call($c,$agent_id));
}

if($action==='bulk_start'&&$method==='POST'){
  $user=verify_jwt();if(!$user)json_response(['error'=>'Unauthorized'],401);
  $input=json_decode(file_get_contents('php://input'),true);
  $ids=array_map('intval',$input['candidate_ids']??[]);$done=$failed=0;
  foreach($ids as $id){
    $c=db_fetch_one("SELECT c.*,camp.el_agent_id,camp.name as campaign_name,camp.job_role,camp.num_questions,camp.max_duration_minutes FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.id=? AND c.org_id=?",[$id,$user['org_id']],'ii');
    if(!$c)continue;
    $r=trigger_el_outbound_call($c,$c['el_agent_id']?:EL_AGENT_ID);
    $r['success']?$done++:$failed++;sleep(1);
  }
  json_response(['started'=>$done,'failed'=>$failed,'message'=>"Started $done calls"]);
}

if($action==='create_session'&&$method==='GET'){
  $token=$_GET['t']??'';if(!$token)json_response(['error'=>'Token required'],400);
  $c=db_fetch_one("SELECT * FROM candidates WHERE unique_token=?",[$token],'s');
  if(!$c)json_response(['error'=>'Invalid token'],404);
  $existing=db_fetch_one("SELECT id FROM interview_sessions WHERE candidate_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1",[$c['id']],'i');
  if($existing)json_response(['session_id'=>$existing['id']]);
  $sid=db_insert("INSERT INTO interview_sessions (candidate_id,campaign_id,status,started_at) VALUES (?,?,'in_progress',NOW())",[$c['id'],$c['campaign_id']],'ii');
  db_execute("UPDATE candidates SET status='interview_started' WHERE id=? AND status='pending'",[$c['id']],'i');
  json_response(['session_id'=>$sid]);
}

if($action==='save_answer'&&$method==='POST'){
  $body=json_decode(file_get_contents('php://input'),true)??[];
  $token=$body['token']??'';$sid=(int)($body['session_id']??0);$ans=$body['answer']??[];$clog=$body['cheat_log']??[];
  if(!$token)json_response(['error'=>'Token required'],400);
  $c=db_fetch_one("SELECT * FROM candidates WHERE unique_token=?",[$token],'s');
  if(!$c)json_response(['error'=>'Invalid token'],404);
  db_execute("INSERT INTO interview_answers (session_id,candidate_id,question_id,text_answer,audio_url,answer_mode,time_taken,copy_count,cheat_flags,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())",
    [$sid,$c['id'],(int)($ans['question_id']??0),$ans['text_answer']??'',$ans['audio_url']??'',$ans['answer_mode']??'text',(int)($ans['time_taken']??0),(int)($ans['copy_count']??0),json_encode($clog)],
    'iiisssiis');
  json_response(['status'=>'saved']);
}

if($action==='complete_interview'&&$method==='POST'){
  $body=json_decode(file_get_contents('php://input'),true)??[];
  $token=$body['token']??'';$sid=(int)($body['session_id']??0);$answers=$body['answers']??[];$cheat=$body['cheat_summary']??[];
  if(!$token)json_response(['error'=>'Token required'],400);
  $c=db_fetch_one("SELECT c.*,camp.passing_score,camp.el_agent_id,camp.name as campaign_name,camp.job_role FROM candidates c JOIN campaigns camp ON c.campaign_id=camp.id WHERE c.unique_token=?",[$token],'s');
  if(!$c)json_response(['error'=>'Invalid token'],404);
  db_execute("UPDATE interview_sessions SET completed_at=NOW(),status='completed',cheat_summary=? WHERE id=?",
    [json_encode($cheat),$sid],'si');
  db_execute("UPDATE candidates SET status='interview_completed' WHERE id=?",[$c['id']],'i');
  $name=$c['name']?:'Candidate';
  $wa="🎉 *Interview Submitted!*\n\nHi $name,\n\nThank you for completing your interview for *{$c['job_role']}* at *{$c['campaign_name']}*.\n\nOur team will review your responses and reach out to you accordingly.\n\n*HireAI — Avyukta Intellicall*";
  send_whatsapp($c['phone'],$wa);
  $log="/tmp/score_{$c['id']}.log";
  exec("php ".escapeshellarg(__DIR__."/score.php")." {$c['id']} {$c['campaign_id']} > $log 2>&1 &");
  json_response(['status'=>'completed']);
}

if($method==='POST'&&($action==='webhook'||$action==='')){
  $body=json_decode(file_get_contents('php://input'),true)??[];
  $event=$body['type']??$body['event_type']??'';$conv_id=$body['conversation_id']??$body['call_id']??'';
  if(in_array($event,['conversation_ended','call_ended','post_call_transcription_complete'])){
    $tr_raw=$body['transcript']??$body['conversation']??$body['messages']??[];
    $rec=$body['recording_url']??$body['audio_url']??'';$dur=(int)($body['duration_seconds']??0);
    $tr='';
    if(is_array($tr_raw)){foreach($tr_raw as $m){$role=$m['role']??$m['speaker']??'unknown';$text=$m['content']??$m['text']??$m['message']??'';if($text)$tr.=ucfirst($role).": $text\n";}}
    elseif(is_string($tr_raw))$tr=$tr_raw;
    $session=null;
    if($conv_id){
      $session=db_fetch_one("SELECT * FROM interview_sessions WHERE el_conversation_id=?",[$conv_id],'s');
      if(!$session){$cand=db_fetch_one("SELECT * FROM candidates WHERE call_id=?",[$conv_id],'s');if($cand)$session=db_fetch_one("SELECT * FROM interview_sessions WHERE candidate_id=? ORDER BY id DESC LIMIT 1",[$cand['id']],'i');}
    }
    if(!$session&&!empty($body['session_id']))$session=db_fetch_one("SELECT * FROM interview_sessions WHERE id=?",[(int)$body['session_id']],'i');
    if($session){
      db_execute("UPDATE interview_sessions SET full_transcript=?,recording_url=?,duration_seconds=?,completed_at=NOW(),status='completed',el_conversation_id=? WHERE id=?",
        [$tr,$rec,$dur,$conv_id,$session['id']],'ssisi');
      db_execute("UPDATE candidates SET status='interview_completed' WHERE id=?",[$session['candidate_id']],'i');
      exec("php ".escapeshellarg(__DIR__."/score.php")." {$session['candidate_id']} {$session['campaign_id']} > /tmp/score_{$session['candidate_id']}.log 2>&1 &");
    }
  }
  json_response(['status'=>'ok']);
}

// ─── FIXED: was $$c['phone'] (double-dollar bug) ───────────────────────────
function trigger_el_outbound_call($c, $agent_id) {
  $phone = preg_replace('/[^0-9]/', '', $c['phone'] ?? ''); // FIXED: was $$c
  if (strlen($phone) == 10) $phone = '+91' . $phone;
  elseif (!str_starts_with($phone, '+')) $phone = '+' . $phone;
  $name = $c['name'] ?: 'Candidate';
  $payload = [
    'agent_id'              => $agent_id,
    'agent_phone_number_id' => EL_PHONE_NUMBER_ID,
    'to_number'             => $phone,
    'conversation_config_override' => [
      'agent' => [
        'prompt'        => ['prompt' => "AI interviewer for {$c['job_role']} at {$c['campaign_name']}. Candidate: $name. Ask {$c['num_questions']} questions."],
        'first_message' => "Hello $name! I am your AI interviewer for {$c['job_role']}. Ready to begin?",
      ],
    ],
    'dynamic_variables' => [
      'candidate_name' => $name,
      'job_role'       => $c['job_role'],
      'num_questions'  => (string)($c['num_questions'] ?? 6),
    ],
  ];
  $ch = curl_init('https://api.elevenlabs.io/v1/convai/sip-trunk/outbound-call');
  curl_setopt_array($ch, [
    CURLOPT_POST          => true,
    CURLOPT_POSTFIELDS    => json_encode($payload),
    CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'xi-api-key: ' . EL_API_KEY],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT       => 30,
    CURLOPT_SSL_VERIFYPEER => false,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $data     = json_decode($resp, true);
  $call_id  = $data['conversation_id'] ?? $data['call_id'] ?? '';
  if ($code >= 200 && $code < 300 && ($data['success'] ?? false)) {
    if ($call_id) {
      db_insert("INSERT INTO interview_sessions (candidate_id,campaign_id,el_conversation_id,status,started_at) VALUES (?,?,?,'initiated',NOW())", [$c['id'], $c['campaign_id'], $call_id], 'iis');
      db_execute("UPDATE candidates SET status='interview_started',call_id=? WHERE id=?", [$call_id, $c['id']], 'si');
    }
    return ['success' => true, 'call_id' => $call_id, 'message' => "Call started to $phone"];
  }
  return ['success' => false, 'error' => "EL error ($code): $resp"];
}
