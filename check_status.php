<?php
// check_status.php

// Carrega .env
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

$access_token = getenv('MP_ACCESS_TOKEN');
$id = $_GET['id']; // Recebe o ID pela URL (ex: ?id=12345)

if (!$id) {
    echo json_encode(['error' => 'ID não fornecido']);
    exit();
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/" . $id);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $access_token
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

// Retorna apenas o que interessa
if (isset($data['status'])) {
    echo json_encode([
        'status' => $data['status'], // approved, pending, rejected
        'status_detail' => $data['status_detail']
    ]);
} else {
    echo json_encode(['status' => 'error', 'raw' => $data]);
}
?>