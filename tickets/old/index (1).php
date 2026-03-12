<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { http_response_code(200); exit('OK'); }

// CONEXION BASE DE DATOS - CAMBIA USUARIO Y PASSWORD
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=essadmx_javiercp_tickets_essad;charset=utf8mb4',
        'CPANEL_USER',
        'CPANEL_PASS',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode(['error' => 'DB Error: ' . $e->getMessage()]));
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// === GET OFICINAS ===
if (strpos($path, '/api/offices') !== false && $method == 'GET') {
    $stmt = $pdo->query('SELECT id, name FROM offices ORDER BY name');
    exit(json_encode(['data' => $stmt->fetchAll()]));
}

// === GET CATEGORIAS ===
if (strpos($path, '/api/categories') !== false && $method == 'GET') {
    $stmt = $pdo->query('SELECT id, name, type FROM ticket_categories ORDER BY type, name');
    exit(json_encode(['data' => $stmt->fetchAll()]));
}

// === LOGIN TI ===
if (strpos($path, '/api/login') !== false && $method == 'POST') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$input['email'] ?? '']);
    $user = $stmt->fetch();
    if ($user && password_verify($input['password'] ?? '', $user['password'])) {
        exit(json_encode([
            'token' => base64_encode($user['id'] . ':' . time() . ':' . $user['role']),
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']]
        ]));
    }
    http_response_code(401);
    exit(json_encode(['error' => 'Credenciales invalidas']));
}

// === CREAR TICKET ===
if (strpos($path, '/api/tickets') !== false && $method == 'POST' && strpos($path, 'admin') === false) {
    if (empty($input['user_email']) || empty($input['user_name']) || empty($input['description'])) {
        http_response_code(422);
        exit(json_encode(['error' => 'Campos requeridos: user_email, user_name, description']));
    }
    $stmt = $pdo->prepare('INSERT INTO tickets (user_email, user_name, office_id, category_id, priority, description) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $input['user_email'],
        $input['user_name'],
        $input['office_id'],
        $input['category_id'],
        $input['priority'] ?? 'media',
        $input['description']
    ]);
    $id = $pdo->lastInsertId();
    $folio = $pdo->query("SELECT folio FROM tickets WHERE id = $id")->fetchColumn();
    exit(json_encode(['success' => true, 'folio' => $folio, 'message' => 'Ticket creado exitosamente']));
}

// === DASHBOARD ===
if (strpos($path, '/api/dashboard') !== false && $method == 'GET') {
    $stmt = $pdo->query('
        SELECT t.id, t.folio, t.user_name, t.user_email, t.priority, t.status, t.description, t.created_at,
               o.name as office_name, c.name as category_name, u.name as assigned_name
        FROM tickets t
        LEFT JOIN offices o ON t.office_id = o.id
        LEFT JOIN ticket_categories c ON t.category_id = c.id
        LEFT JOIN users u ON t.assigned_to = u.id
        ORDER BY t.created_at DESC
    ');
    exit(json_encode(['tickets' => $stmt->fetchAll()]));
}

// === CAMBIAR ESTADO TICKET ===
if (strpos($path, '/api/tickets/') !== false && $method == 'PATCH') {
    preg_match('/\/api\/tickets\/(\d+)/', $path, $matches);
    $id = $matches[1] ?? 0;
    $fields = [];
    $vals = [];
    foreach (['status','priority','assigned_to','sla_status'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $vals[] = $input[$f]; }
    }
    if ($fields) {
        if (isset($input['status']) && $input['status'] == 'cerrado') { $fields[] = 'closed_at = NOW()'; }
        $vals[] = $id;
        $pdo->prepare('UPDATE tickets SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    }
    exit(json_encode(['success' => true]));
}

// === METRICAS ===
if (strpos($path, '/api/metrics') !== false && $method == 'GET') {
    $byOffice = $pdo->query('SELECT o.name, COUNT(t.id) as total FROM tickets t LEFT JOIN offices o ON t.office_id = o.id GROUP BY o.name')->fetchAll();
    $byStatus = $pdo->query('SELECT status, COUNT(*) as total FROM tickets GROUP BY status')->fetchAll();
    $avgTime = $pdo->query('SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, closed_at)) as avg_hours FROM tickets WHERE closed_at IS NOT NULL')->fetchColumn();
    $slaVencido = $pdo->query("SELECT COUNT(*) FROM tickets WHERE sla_status = 'vencido'")->fetchColumn();
    exit(json_encode([
        'by_office' => $byOffice,
        'by_status' => $byStatus,
        'avg_resolution_hours' => round($avgTime, 2),
        'sla_vencido' => $slaVencido
    ]));
}

// === ADMIN USUARIOS - LISTAR ===
if (strpos($path, '/api/admin/users') !== false && $method == 'GET') {
    $stmt = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY role, name');
    exit(json_encode(['users' => $stmt->fetchAll()]));
}

// === ADMIN USUARIOS - CREAR ===
if (strpos($path, '/api/admin/users') !== false && $method == 'POST') {
    if (empty($input['name']) || empty($input['email'])) {
        http_response_code(422);
        exit(json_encode(['error' => 'Nombre y email requeridos']));
    }
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$input['name'], $input['email'], password_hash('123456', PASSWORD_DEFAULT), $input['role'] ?? 'agente']);
    exit(json_encode(['success' => true, 'message' => 'Usuario creado. Password inicial: 123456']));
}

// === ADMIN USUARIOS - ELIMINAR ===
if (strpos($path, '/api/admin/users/') !== false && $method == 'DELETE') {
    preg_match('/\/api\/admin\/users\/(\d+)/', $path, $matches);
    $id = $matches[1] ?? 0;
    $pdo->prepare('DELETE FROM users WHERE id = ? AND role != "admin"')->execute([$id]);
    exit(json_encode(['success' => true]));
}

// === CONSULTAR TICKET POR FOLIO ===
if (strpos($path, '/api/track') !== false && $method == 'POST') {
    $stmt = $pdo->prepare('SELECT t.*, o.name as office_name, c.name as category_name FROM tickets t LEFT JOIN offices o ON t.office_id = o.id LEFT JOIN ticket_categories c ON t.category_id = c.id WHERE t.folio = ? AND t.user_email = ?');
    $stmt->execute([$input['folio'] ?? '', $input['email'] ?? '']);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $comments = $pdo->prepare('SELECT * FROM ticket_comments WHERE ticket_id = ? AND is_internal = 0 ORDER BY created_at ASC');
        $comments->execute([$ticket['id']]);
        $ticket['comments'] = $comments->fetchAll();
        exit(json_encode(['ticket' => $ticket]));
    }
    http_response_code(404);
    exit(json_encode(['error' => 'Ticket no encontrado o correo incorrecto']));
}

// 404
http_response_code(404);
exit(json_encode(['error' => 'Endpoint no encontrado: ' . $path]));
?>