<?php
// delete_card.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// ... (Mesma função loadEnv e mpRequest do list_cards.php) ...

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['email']) || !isset($data['card_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados incompletos']);
    exit();
}

// 1. Buscar Customer
$search = mpRequest("https://api.mercadopago.com/v1/customers/search?email=" . urlencode($data['email']), 'GET', $access_token);
$customer_id = $search['data']['results'][0]['id'] ?? null;

if ($customer_id) {
    $delete = mpRequest("https://api.mercadopago.com/v1/customers/$customer_id/cards/" . $data['card_id'], 'DELETE', $access_token);
    echo json_encode(['status' => 'success', 'code' => $delete['code']]);
} else {
    echo json_encode(['error' => 'Cliente não encontrado']);
}
?>
