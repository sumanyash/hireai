<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/helpers.php';
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
$token=$_POST['token']??'';$sid=(int)($_POST['session_id']??0);$qno=(int)($_POST['question_no']??0);
if(!$token){echo json_encode(['error'=>'Token required']);exit;}
if(!verify_session_ownership(null,$token,$sid)){http_response_code(403);echo json_encode(['error'=>'Forbidden']);exit;}
if(!isset($_FILES['audio'])||$_FILES['audio']['error']!==UPLOAD_ERR_OK){echo json_encode(['error'=>'No audio uploaded','code'=>$_FILES['audio']['error']??-1]);exit;}
if($_FILES['audio']['size'] > 10*1024*1024){http_response_code(413);echo json_encode(['error'=>'Audio too large. Max 10MB']);exit;}
$mime=detect_uploaded_mime($_FILES['audio']['tmp_name']);
$ext=upload_safe_extension($mime,'audio');
if(!$ext){http_response_code(415);echo json_encode(['error'=>'Invalid audio type']);exit;}
$c=db_fetch_one("SELECT id FROM candidates WHERE unique_token=?",[$token],'s');
if(!$c){http_response_code(403);echo json_encode(['error'=>'Forbidden']);exit;}
$dir=__DIR__.'/../uploads/audio/';
if(!is_dir($dir))mkdir($dir,0755,true);
$fname=str_replace('.','',uniqid('',true)).'.'.$ext;
$fpath=$dir.$fname;
$url=BASE_URL.'/uploads/audio/'.$fname;
if(move_uploaded_file($_FILES['audio']['tmp_name'],$fpath)){echo json_encode(['url'=>$url,'filename'=>$fname]);}
else{echo json_encode(['error'=>'Upload failed - check permissions']);}
