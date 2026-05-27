<?php
// save_card.php

// --- 1. FUNÇÃO PARA CARREGAR O .ENV ---
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

// --- 2. CONFIGURAÇÕES GERAIS E CORS ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$access_token = getenv('MP_ACCESS_TOKEN');
if (!$access_token) {
    http_response_code(500);
    echo json_encode(['error' => 'Token do Mercado Pago ausente no servidor.']);
    exit();
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email']) || !isset($data['token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email do cliente e Token do cartão são obrigatórios.']);
    exit();
}

$email = $data['email'];
$card_token = $data['token'];

// --- 3. FUNÇÃO AUXILIAR PARA REQUISIÇÕES CURL ---
function mpRequest($url, $method = 'GET', $body = null, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $token
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $http_code, 'data' => json_decode($response, true)];
}

// --- 4. BUSCAR OU CRIAR O CUSTOMER (CLIENTE) ---
// Busca se o cliente já existe pelo email
$search_response = mpRequest("https://api.mercadopago.com/v1/customers/search?email=" . urlencode($email), 'GET', null, $access_token);

$customer_id = null;

if ($search_response['code'] == 200 && !empty($search_response['data']['results'])) {
    // Cliente existe, pega o ID do primeiro resultado
    $customer_id = $search_response['data']['results'][0]['id'];
} else {
    // Cliente não existe, vamos criar
    $create_response = mpRequest("https://api.mercadopago.com/v1/customers", 'POST', ["email" => $email], $access_token);
    
    if ($create_response['code'] == 201) {
        $customer_id = $create_response['data']['id'];
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao criar Customer no Mercado Pago', 'details' => $create_response['data']]);
        exit();
    }
}

// --- 5. SALVAR O CARTÃO NO CUSTOMER ---
$save_card_payload = [
    "token" => $card_token
];

$save_response = mpRequest("https://api.mercadopago.com/v1/customers/" . $customer_id . "/cards", 'POST', $save_card_payload, $access_token);

if ($save_response['code'] == 201 || $save_response['code'] == 200) {
    // Cartão salvo com sucesso
    echo json_encode([
        "status" => "success",
        "message" => "Cartão salvo com sucesso",
        "customer_id" => $customer_id,
        "card_id" => $save_response['data']['id'],
        "first_six_digits" => $save_response['data']['first_six_digits'],
        "last_four_digits" => $save_response['data']['last_four_digits'],
        "payment_method" => $save_response['data']['payment_method']['id']
    ]);
} else {
    // Erro ao salvar o cartão
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "error" => "Falha ao vincular cartão ao cliente", 
        "details" => $save_response['data']
    ]);
}
?>
