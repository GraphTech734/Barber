<?php
// list_cards.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

function loadEnv($path) {
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(sprintf('%s=%s', trim($name), trim(trim($value), '"\'')));
        }
    }
}
loadEnv(__DIR__ . '/.env');

$access_token = getenv('MP_ACCESS_TOKEN');
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email é obrigatório']);
    exit();
}

function mpRequest($url, $method, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true)];
}

// 1. Buscar Customer
$search = mpRequest("https://api.mercadopago.com/v1/customers/search?email=" . urlencode($data['email']), 'GET', $access_token);
$customer_id = $search['data']['results'][0]['id'] ?? null;

if (!$customer_id) {
    echo json_encode(['cards' => []]);
    exit();
}

// 2. Listar Cartões
$cards = mpRequest("https://api.mercadopago.com/v1/customers/$customer_id/cards", 'GET', $access_token);
echo json_encode(['cards' => $cards['data']]);
?>
