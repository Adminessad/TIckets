<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { http_response_code(200); exit('OK'); }

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=essadmx_javiercp_tickets_essad;charset=utf8mb4',
        'essadmx_javiertickets',
        'TU_PASSWORD_AQUI',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'DB Error: ' . $e->getMessage()]));
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (strpos($path, '/api/offices') !== false && $method == 'GET') {
    exit(json_encode(['data' => $pdo->query('SELECT id, name FROM offices ORDER BY name')->fetchAll()]));
}

if (strpos($path, '/api/categories') !== false && $method == 'GET') {
    exit(json_encode(['data' => $pdo->query('SELECT id, name, type FROM ticket_categories ORDER BY type, name')->fetchAll()]));
}

if (strpos($path, '/api/login') !== false && $method == 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$input['email'] ?? '']);
    $user = $stmt->fetch();
    if ($user && password_verify($input['password'] ?? '', $user['password'])) {
        exit(json_encode(['token' => base64_encode($user['id'].':'.time().':'.$user['role']), 'user' => ['id'=>$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']]]));
    }
    http_response_code(401);
    exit(json_encode(['error' => 'Credenciales invalidas']));
}

if (strpos($path, '/api/tickets') !== false && $method == 'POST' && strpos($path,'admin') === false && strpos($path,'comment') === false) {
    if (empty($input['user_email']) || empty($input['user_name']) || empty($input['description'])) {
        http_response_code(422); exit(json_encode(['error' => 'Campos requeridos faltantes']));
    }
    $year = date('Y');
    $count = $pdo->query("SELECT COUNT(*) FROM tickets WHERE YEAR(created_at) = $year")->fetchColumn();
    $folio = 'IT-'.$year.'-'.str_pad($count+1, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO tickets (folio,user_email,user_name,office_id,category_id,priority,description) VALUES (?,?,?,?,?,?,?)')->execute([$folio,$input['user_email'],$input['user_name'],$input['office_id'],$input['category_id'],$input['priority']??'media',$input['description']]);
    exit(json_encode(['success'=>true,'folio'=>$folio,'message'=>'Ticket creado exitosamente']));
}

if (strpos($path, '/api/dashboard') !== false && $method == 'GET') {
    $stmt = $pdo->query('SELECT t.id,t.folio,t.user_name,t.user_email,t.priority,t.status,t.description,t.created_at,t.closed_at,o.name as office_name,c.name as category_name,c.type as category_type,u.name as assigned_name FROM tickets t LEFT JOIN offices o ON t.office_id=o.id LEFT JOIN ticket_categories c ON t.category_id=c.id LEFT JOIN users u ON t.assigned_to=u.id ORDER BY t.created_at DESC');
    exit(json_encode(['tickets' => $stmt->fetchAll()]));
}

if (preg_match('/\/api\/tickets\/(\d+)$/', $path, $m) && $method == 'GET') {
    $stmt = $pdo->prepare('SELECT t.*,o.name as office_name,c.name as category_name,u.name as assigned_name FROM tickets t LEFT JOIN offices o ON t.office_id=o.id LEFT JOIN ticket_categories c ON t.category_id=c.id LEFT JOIN users u ON t.assigned_to=u.id WHERE t.id=?');
    $stmt->execute([$m[1]]);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $comments = $pdo->prepare('SELECT * FROM ticket_comments WHERE ticket_id=? ORDER BY created_at ASC');
        $comments->execute([$m[1]]);
        $ticket['comments'] = $comments->fetchAll();
        exit(json_encode(['ticket' => $ticket]));
    }
    http_response_code(404); exit(json_encode(['error'=>'Ticket no encontrado']));
}

if (preg_match('/\/api\/tickets\/(\d+)\/comment/', $path, $m) && $method == 'POST') {
    $pdo->prepare('INSERT INTO ticket_comments (ticket_id,author_email,author_name,is_internal,message) VALUES (?,?,?,?,?)')->execute([$m[1],$input['author_email']??'',$input['author_name']??'TI Essad',$input['is_internal']??false,$input['message']??'']);
    exit(json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]));
}

if (preg_match('/\/api\/tickets\/(\d+)$/', $path, $m) && $method == 'PATCH') {
    $fields=[]; $vals=[];
    foreach(['status','priority','assigned_to'] as $f) { if(isset($input[$f])){ $fields[]="$f=?"; $vals[]=$input[$f]; } }
    if($fields){ if(isset($input['status'])&&$input['status']=='cerrado'){ $fields[]='closed_at=NOW()'; } $vals[]=$m[1]; $pdo->prepare('UPDATE tickets SET '.implode(',',$fields).' WHERE id=?')->execute($vals); }
    exit(json_encode(['success'=>true]));
}

if (strpos($path, '/api/metrics') !== false && $method == 'GET') {
    exit(json_encode([
        'by_office' => $pdo->query('SELECT o.name,COUNT(t.id) as total FROM tickets t LEFT JOIN offices o ON t.office_id=o.id GROUP BY o.name ORDER BY total DESC')->fetchAll(),
        'by_status' => $pdo->query('SELECT status,COUNT(*) as total FROM tickets GROUP BY status')->fetchAll(),
        'by_category' => $pdo->query('SELECT c.name,COUNT(t.id) as total FROM tickets t LEFT JOIN ticket_categories c ON t.category_id=c.id GROUP BY c.name ORDER BY total DESC')->fetchAll(),
        'avg_resolution_hours' => round($pdo->query('SELECT AVG(TIMESTAMPDIFF(HOUR,created_at,closed_at)) FROM tickets WHERE closed_at IS NOT NULL')->fetchColumn(),2),
        'sla_vencido' => $pdo->query("SELECT COUNT(*) FROM tickets WHERE sla_status='vencido'")->fetchColumn(),
        'total' => $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn()
    ]));
}

if (strpos($path, '/api/admin/users') !== false && $method == 'GET') {
    exit(json_encode(['users' => $pdo->query('SELECT id,name,email,role,created_at FROM users ORDER BY role,name')->fetchAll()]));
}

if (strpos($path, '/api/admin/users') !== false && $method == 'POST') {
    $pdo->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)')->execute([$input['name'],$input['email'],password_hash('123456',PASSWORD_DEFAULT),$input['role']??'agente']);
    exit(json_encode(['success'=>true]));
}

if (preg_match('/\/api\/admin\/users\/(\d+)/', $path, $m) && $method == 'DELETE') {
    $pdo->prepare('DELETE FROM users WHERE id=? AND role!="admin"')->execute([$m[1]]);
    exit(json_encode(['success'=>true]));
}

if (strpos($path, '/api/track') !== false && $method == 'POST') {
    $stmt = $pdo->prepare('SELECT t.*,o.name as office_name,c.name as category_name FROM tickets t LEFT JOIN offices o ON t.office_id=o.id LEFT JOIN ticket_categories c ON t.category_id=c.id WHERE t.folio=? AND t.user_email=?');
    $stmt->execute([$input['folio']??'',$input['email']??'']);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $comments = $pdo->prepare('SELECT * FROM ticket_comments WHERE ticket_id=? AND is_internal=0 ORDER BY created_at ASC');
        $comments->execute([$ticket['id']]);
        $ticket['comments'] = $comments->fetchAll();
        exit(json_encode(['ticket'=>$ticket]));
    }
    http_response_code(404); exit(json_encode(['error'=>'Ticket no encontrado o correo incorrecto']));
}

http_response_code(404);
exit(json_encode(['error'=>'Endpoint no encontrado']));
?>