<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
$token=$_POST['token']??'';$sid=(int)($_POST['session_id']??0);
if(!$token){echo json_encode(['error'=>'Token required']);exit;}
if(!isset($_FILES['video'])||$_FILES['video']['error']!==UPLOAD_ERR_OK){echo json_encode(['error'=>'No video uploaded','code'=>$_FILES['video']['error']??-1]);exit;}
$c=db_fetch_one("SELECT id FROM candidates WHERE unique_token=?",[$token],'s');
if(!$c){echo json_encode(['error'=>'Invalid token']);exit;}
$dir=__DIR__.'/../uploads/video/';
if(!is_dir($dir))mkdir($dir,0755,true);
$fname='session_'.$c['id'].'_'.time().'.webm';
$fpath=$dir.$fname;
$url=BASE_URL.'/uploads/video/'.$fname;
if(move_uploaded_file($_FILES['video']['tmp_name'],$fpath)){
  if($sid){db_execute("UPDATE interview_sessions SET recording_url=? WHERE id=?",[$url,$sid],"si");}else{db_execute("UPDATE interview_sessions SET recording_url=? WHERE candidate_id=? ORDER BY id DESC LIMIT 1",[$url,$c["id"]],"si");}
  echo json_encode(['url'=>$url,'filename'=>$fname]);
}else{echo json_encode(['error'=>'Upload failed - check permissions']);}
