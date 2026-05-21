<?php
// checkout.php

// --- 1. FUNÇÃO PARA CARREGAR O .ENV (Sem precisar de Composer) ---
function loadEnv($path) {
    if (!file_exists($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora comentários
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Separa Nome=Valor
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            // Remove aspas se houver
            $value = trim($value, '"\''); 
            
            // Define no ambiente e no array $_ENV
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Carrega o arquivo .env do diretório atual
loadEnv(__DIR__ . '/.env');

// --- 2. CONFIGURAÇÕES DA API ---

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// PEGA O TOKEN DO AMBIENTE CARREGADO
$access_token = getenv('MP_ACCESS_TOKEN');

// Verificação de segurança: Se não tiver token, para tudo.
if (!$access_token) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuração de servidor inválida (Token ausente)']);
    exit();
}

// --- 3. LÓGICA DO PAGAMENTO ---

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['title']) || !isset($data['price']) || !isset($data['email'])) {
    echo json_encode(['error' => 'Dados incompletos']);
    http_response_code(400);
    exit();
}

$preference_data = [
    "items" => [
        [
            "title" => $data['title'],
            "quantity" => 1,
            "currency_id" => "BRL",
            "unit_price" => (float)$data['price']
        ]
    ],
    "payer" => [
        "email" => $data['email']
    ],
    "back_urls" => [
        "success" => "barberapp://redirect",
        "failure" => "barberapp://redirect",
        "pending" => "barberapp://redirect"
    ],
    "auto_return" => "approved",
    "payment_methods" => [
        "excluded_payment_types" => [
            ["id" => "ticket"]
        ],
        "installments" => 6
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/checkout/preferences");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preference_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $access_token // Usa a variável carregada do .env
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 201 || $http_code == 200) {
    $mp_response = json_decode($response, true);
    echo json_encode([
        "init_point" => $mp_response['init_point']
    ]);
} else {
    http_response_code(500);
    echo $response;
}
?>