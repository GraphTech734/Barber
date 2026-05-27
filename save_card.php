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

// --- 2. CONFIGURAÇÕES E CABEÇALHOS ---
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
    echo json_encode(['error' => 'Email do cliente e token do cartão são obrigatórios.']);
    exit();
}

$email = $data['email'];
$card_token = $data['token'];

// --- 3. PASSO 1: BUSCAR OU CRIAR CUSTOMER NO MERCADO PAGO ---

// Tenta buscar o cliente pelo email
$ch_search = curl_init();
curl_setopt($ch_search, CURLOPT_URL, "https://api.mercadopago.com/v1/customers/search?email=" . urlencode($email));
curl_setopt($ch_search, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_search, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $access_token
]);
$search_response = curl_exec($ch_search);
curl_close($ch_search);

$search_data = json_decode($search_response, true);
$customer_id = null;

if (isset($search_data['results']) && count($search_data['results']) > 0) {
    // Cliente já existe
    $customer_id = $search_data['results'][0]['id'];
} else {
    // Cliente não existe, vamos criar
    $customer_data = [
        "email" => $email
    ];
    
    $ch_create = curl_init();
    curl_setopt($ch_create, CURLOPT_URL, "https://api.mercadopago.com/v1/customers");
    curl_setopt($ch_create, CURLOPT_POST, true);
    curl_setopt($ch_create, CURLOPT_POSTFIELDS, json_encode($customer_data));
    curl_setopt($ch_create, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_create, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $access_token
    ]);
    
    $create_response = curl_exec($ch_create);
    curl_close($ch_create);
    $create_data = json_decode($create_response, true);
    
    if (isset($create_data['id'])) {
        $customer_id = $create_data['id'];
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao criar Customer no Mercado Pago.', 'details' => $create_data]);
        exit();
    }
}

// --- 4. PASSO 2: ATRELAR O CARTÃO AO CUSTOMER ---

$card_data = [
    "token" => $card_token
];

$ch_card = curl_init();
curl_setopt($ch_card, CURLOPT_URL, "https://api.mercadopago.com/v1/customers/" . $customer_id . "/cards");
curl_setopt($ch_card, CURLOPT_POST, true);
curl_setopt($ch_card, CURLOPT_POSTFIELDS, json_encode($card_data));
curl_setopt($ch_card, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_card, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $access_token
]);

$card_response = curl_exec($ch_card);
$http_code_card = curl_getinfo($ch_card, CURLINFO_HTTP_CODE);
curl_close($ch_card);

$mp_card_response = json_decode($card_response, true);

if ($http_code_card === 201 || $http_code_card === 200) {
    // Sucesso! Retorna os dados inofensivos do cartão para o app salvar no Firebase
    echo json_encode([
        "status" => "success",
        "message" => "Cartão salvo com sucesso",
        "customer_id" => $customer_id,
        "card_info" => [
            "id" => $mp_card_response['id'], // ID do cartão no MP (use isso para pagamentos futuros)
            "first_six_digits" => $mp_card_response['first_six_digits'],
            "last_four_digits" => $mp_card_response['last_four_digits'],
            "payment_method" => $mp_card_response['payment_method']['id'], // ex: visa, master
            "payment_method_thumbnail" => $mp_card_response['payment_method']['thumbnail'],
            "expiration_month" => $mp_card_response['expiration_month'],
            "expiration_year" => $mp_card_response['expiration_year']
        ]
    ]);
} else {
    http_response_code($http_code_card);
    echo json_encode([
        "error" => "Erro ao salvar cartão no Mercado Pago", 
        "details" => $mp_card_response
    ]);
}
?>
