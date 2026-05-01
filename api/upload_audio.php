<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/db.php';
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
$token=$_POST['token']??'';$qno=(int)($_POST['question_no']??0);
if(!$token){echo json_encode(['error'=>'Token required']);exit;}
if(!isset($_FILES['audio'])||$_FILES['audio']['error']!==UPLOAD_ERR_OK){echo json_encode(['error'=>'No audio uploaded','code'=>$_FILES['audio']['error']??-1]);exit;}
if($_FILES['audio']['size'] > 10*1024*1024){http_response_code(413);echo json_encode(['error'=>'Audio too large. Max 10MB']);exit;}
$allowed=['audio/webm','audio/ogg','audio/mpeg','audio/wav','audio/x-wav'];
$mime=mime_content_type($_FILES['audio']['tmp_name']);
if(!in_array($mime,$allowed,true)){http_response_code(415);echo json_encode(['error'=>'Invalid audio type']);exit;}
$c=db_fetch_one("SELECT id FROM candidates WHERE unique_token=?",[$token],'s');
if(!$c){echo json_encode(['error'=>'Invalid token']);exit;}
$dir=__DIR__.'/../uploads/audio/';
if(!is_dir($dir))mkdir($dir,0755,true);
$ext=match($mime){'audio/mpeg'=>'mp3','audio/wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg',default=>'webm'};
$fname='cand'.$c['id'].'_q'.$qno.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
$fpath=$dir.$fname;
$url=BASE_URL.'/uploads/audio/'.$fname;
if(move_uploaded_file($_FILES['audio']['tmp_name'],$fpath)){echo json_encode(['url'=>$url,'filename'=>$fname]);}
else{echo json_encode(['error'=>'Upload failed - check permissions']);}
