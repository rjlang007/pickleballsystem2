<?php
// FILE: admin/api/pending_reservations.php — Nav badge polling
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../config/app.php';
require_once __DIR__.'/../../config/security.php';
requireAdmin();
header('Content-Type: application/json');header('Cache-Control: no-store');
$db=getDB();
$count=(int)$db->query("SELECT COUNT(*) FROM falcon.reservations WHERE status='pending'")->fetchColumn();
$prev=$db->query("SELECT r.id,r.slot_date,r.slot_time,r.party_size,u.username,u.full_name,c.name AS court_name FROM falcon.reservations r JOIN falcon.users u ON u.id=r.user_id JOIN falcon.courts c ON c.id=r.court_id WHERE r.status='pending' ORDER BY r.slot_date ASC,r.slot_time ASC LIMIT 5")->fetchAll();
$out=array_map(fn($p)=>['slot_date'=>$p['slot_date'],'slot_date_fmt'=>date('M d',strtotime($p['slot_date'])),'slot_time'=>$p['slot_time'],'slot_time_fmt'=>date('h:i A',strtotime($p['slot_time'])),'party_size'=>$p['party_size'],'username'=>$p['username'],'full_name'=>$p['full_name'],'court_name'=>$p['court_name']],$prev);
echo json_encode(['count'=>$count,'preview'=>$out]);