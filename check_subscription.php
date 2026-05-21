<?php
// check_subscription.php

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

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// MUDANÇA AQUI: Trocado $_ENV por getenv() para compatibilidade com o Render
$access_token = getenv('MP_ACCESS_TOKEN');
$payment_id = $_GET['id'] ?? null;

if (!$payment_id) {
    echo json_encode(['status' => 'error', 'message' => 'ID do pagamento necessário']);
    exit;
}

// Consulta a API do Mercado Pago
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/$payment_id");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $access_token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200) {
    $data = json_decode($response, true);
    
    // Retorna apenas o que o App precisa saber
    echo json_encode([
        'id' => $data['id'],
        'status' => $data['status'], // approved, pending, rejected
        'status_detail' => $data['status_detail']
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Pagamento não encontrado']);
}
?>