<?php
// Tickets Essad - Sistema completo para cPanel
// Javier Castillo - sistemaspuebla@essad.mx

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PATCH");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    http_response_code(200);
    exit(0);
}

require_once __DIR__."/../vendor/autoload.php";

use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule;
$capsule->addConnection([
    "driver" => "mysql",
    "host" => getenv('DB_HOST') ?: "localhost",
    "database" => "essadmx_javiercp_tickets_essad",
    "username" => getenv('DB_USERNAME') ?: "",
    "password" => getenv('DB_PASSWORD') ?: "",
    "charset" => "utf8mb4",
    "collation" => "utf8mb4_unicode_ci",
    "prefix" => "",
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$request = $_SERVER;
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$method = $_SERVER["REQUEST_METHOD"];

// === LOGIN TI ===
if ($path === "/api/login" && $method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $user = Capsule::table("users")
        ->where("email", $input["email"] ?? "")
        ->first();
    
    if ($user && password_verify($input["password"] ?? "", $user->password)) {
        echo json_encode([
            "token" => base64_encode($user->id . ":" . time()),
            "user" => [
                "id" => $user->id,
                "name" => $user->name,
                "email" => $user->email,
                "role" => $user->role
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Credenciales inválidas"]);
    }
    exit;
}

// === OFICINAS ===
if ($path === "/api/offices" && $method === "GET") {
    $offices = Capsule::table("offices")
        ->orderBy("name")
        ->get(["id", "name"])
        ->toArray();
    echo json_encode(["data" => $offices]);
    exit;
}

// === CATEGORÍAS ===
if ($path === "/api/categories" && $method === "GET") {
    $categories = Capsule::table("ticket_categories")
        ->orderBy("type")
        ->orderBy("name")
        ->get(["id", "name", "type"])
        ->toArray();
    echo json_encode(["data" => $categories]);
    exit;
}

// === CREAR TICKET ===
if ($path === "/api/tickets" && $method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    
    $ticketId = Capsule::table("tickets")->insertGetId([
        "user_email" => $input["user_email"],
        "user_name" => $input["user_name"],
        "office_id" => $input["office_id"],
        "category_id" => $input["category_id"],
        "priority" => $input["priority"] ?? "media",
        "description" => $input["description"]
    ]);
    
    $folio = Capsule::table("tickets")
        ->where("id", $ticketId)
        ->value("folio");
    
    echo json_encode([
        "success" => true,
        "folio" => $folio,
        "message" => "Ticket creado exitosamente"
    ]);
    exit;
}

// === DASHBOARD TI ===
if ($path === "/api/dashboard" && $method === "GET") {
    $tickets = Capsule::table("tickets")
        ->leftJoin("offices", "tickets.office_id", "=", "offices.id")
        ->leftJoin("ticket_categories", "tickets.category_id", "=", "ticket_categories.id")
        ->leftJoin("users", "tickets.assigned_to", "=", "users.id")
        ->select(
            "tickets.*",
            "offices.name as office_name",
            "ticket_categories.name as category_name",
            "users.name as assigned_name"
        )
        ->orderBy("tickets.created_at", "desc")
        ->get()
        ->toArray();
    
    echo json_encode(["tickets" => $tickets]);
    exit;
}

// === PANEL USUARIOS ADMIN ===
if (strpos($path, "/api/admin/users") !== false) {
    // Verificar token admin
    $auth = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
    $tokenData = explode(":", base64_decode(str_replace("Bearer ", "", $auth)));
    $adminUser = Capsule::table("users")->where("id", $tokenData[0] ?? 0)->first();
    
    if (!$adminUser || $adminUser->role !== "admin") {
        http_response_code(403);
        echo json_encode(["error" => "Acceso denegado"]);
        exit;
    }
    
    if ($method === "GET") {
        $users = Capsule::table("users")
            ->select("id", "name", "email", "role", "created_at")
            ->orderBy("role")
            ->orderBy("name")
            ->get()
            ->toArray();
        echo json_encode(["users" => $users]);
        exit;
    }
    
    if ($method === "POST") {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = Capsule::table("users")->insertGetId([
            "name" => $input["name"],
            "email" => $input["email"],
            "password" => password_hash("123456", PASSWORD_DEFAULT),
            "role" => $input["role"] ?? "agente"
        ]);
        echo json_encode(["success" => true, "id" => $id]);
        exit;
    }
}

// === 404 ===
http_response_code(404);
echo json_encode([
    "error" => "Endpoint no encontrado",
    "path" => $path,
    "available" => [
        "POST /api/tickets - Crear ticket",
        "GET /api/offices - Lista oficinas", 
        "GET /api/categories - Lista categorías",
        "POST /api/login - Login TI",
        "GET /api/dashboard - Kanban TI",
        "GET/POST /api/admin/users - Gestión usuarios"
    ]
]);
?>
