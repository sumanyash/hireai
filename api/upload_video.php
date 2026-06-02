<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/helpers.php';
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
$token=$_POST['token']??'';$sid=(int)($_POST['session_id']??0);
$is_partial=(bool)($_POST['is_partial']??false);
if(!$token){echo json_encode(['error'=>'Token required']);exit;}
if(!verify_session_ownership(null,$token,$sid)){http_response_code(403);echo json_encode(['error'=>'Forbidden']);exit;}
if(!isset($_FILES['video'])||$_FILES['video']['error']!==UPLOAD_ERR_OK){echo json_encode(['error'=>'No video uploaded','code'=>$_FILES['video']['error']??-1]);exit;}
if($_FILES['video']['size'] > 100*1024*1024){http_response_code(413);echo json_encode(['error'=>'Video too large. Max 100MB']);exit;}
$mime=detect_uploaded_mime($_FILES['video']['tmp_name']);
$ext=upload_safe_extension($mime,'video');
if(!$ext){http_response_code(415);echo json_encode(['error'=>'Invalid video type']);exit;}
$c=db_fetch_one("SELECT id FROM candidates WHERE unique_token=?",[$token],'s');
if(!$c){http_response_code(403);echo json_encode(['error'=>'Forbidden']);exit;}
$dir=__DIR__.'/../uploads/video/';
if(!is_dir($dir))mkdir($dir,0755,true);
$fname=str_replace('.','',uniqid('',true)).'.'.$ext;
$fpath=$dir.$fname;
$url=BASE_URL.'/uploads/video/'.$fname;
if(move_uploaded_file($_FILES['video']['tmp_name'],$fpath)){
    // Partial (checkpoint) upload: only store if no recording_url saved yet.
    // Final upload (is_partial=false): always overwrite — it is the complete recording.
    if($is_partial){
        $existing=db_fetch_one("SELECT recording_url FROM interview_sessions WHERE id=? AND candidate_id=?",[$sid,$c['id']],'ii');
        if(empty($existing['recording_url'])){
            db_execute("UPDATE interview_sessions SET recording_url=? WHERE id=? AND candidate_id=?",[$url,$sid,$c['id']],'sii');
        } else {
            // A previous checkpoint or complete recording exists — delete the new partial to save disk
            @unlink($fpath);
            echo json_encode(['url'=>$existing['recording_url'],'skipped'=>true]);exit;
        }
    } else {
        // Final upload — always wins over any previous partial checkpoint
        // Clean up old partial file if it exists and differs from this new file
        $old=db_fetch_one("SELECT recording_url FROM interview_sessions WHERE id=? AND candidate_id=?",[$sid,$c['id']],'ii');
        if(!empty($old['recording_url'])){
            $old_path=str_replace(BASE_URL,__DIR__.'/..',$old['recording_url']);
            if(file_exists($old_path)&&$old_path!==$fpath) @unlink($old_path);
        }
        db_execute("UPDATE interview_sessions SET recording_url=? WHERE id=? AND candidate_id=?",[$url,$sid,$c['id']],'sii');
    }
    echo json_encode(['url'=>$url,'filename'=>$fname,'partial'=>$is_partial]);
}else{echo json_encode(['error'=>'Upload failed - check permissions']);}
