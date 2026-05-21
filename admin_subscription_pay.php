<?php
// ARQUIVO: admin_subscription_pay.php (OTIMIZADO PARA O RENDER)

// --- PROTEÇÃO CORS PARA O REACT NATIVE ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Permite a requisição de pré-verificação do celular
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ----------------------------------------

// PEGA O TOKEN DIRETAMENTE DO AMBIENTE DO RENDER
$access_token = getenv('MP_ACCESS_TOKEN');

if (empty($access_token)) {
    echo json_encode(['status' => 'error', 'message' => 'Token do Mercado Pago não configurado no servidor.']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data)) {
    echo json_encode(['status' => 'error', 'message' => 'Sem dados']);
    exit;
}

// Dados comuns
$email = $data['email'];
$amount = (float) $data['amount'];
$description = $data['description'] ?? 'Mensalidade App';
$payment_method_id = $data['payment_method_id']; 

$payment_data = [
    "transaction_amount" => $amount,
    "description" => $description,
    "payment_method_id" => $payment_method_id,
    "payer" => [
        "email" => $email,
        "first_name" => "Admin",
        "last_name" => "User"
    ],
    // --- URL ATUALIZADA PARA O SEU NOVO SERVIDOR RENDER ---
    "notification_url" => "https://barber-5k9d.onrender.com/webhook_mercadopago.php" 
];

// Lógica PIX
if ($payment_method_id === 'pix') {
    $payment_data['date_of_expiration'] = date('Y-m-d\TH:i:s.000P', strtotime('+30 minutes'));
} 
// Lógica Cartão
else {
    if (empty($data['token'])) {
        echo json_encode(['status' => 'error', 'message' => 'Token do cartão obrigatório']);
        exit;
    }
    $payment_data['token'] = $data['token'];
    $payment_data['installments'] = (int) ($data['installments'] ?? 1);
    
    if (!empty($data['docNumber'])) {
        $payment_data['payer']['identification'] = [
            "type" => "CPF",
            "number" => $data['docNumber']
        ];
    }
}

// Envia para o Mercado Pago
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $access_token",
    "Content-Type: application/json",
    "X-Idempotency-Key: " . uniqid() 
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$mp_response = json_decode($response, true);

if ($http_code == 201 || $http_code == 200) {
    $result = [
        'status' => $mp_response['status'],
        'id' => $mp_response['id'],
        'status_detail' => $mp_response['status_detail']
    ];

    if ($payment_method_id === 'pix') {
        $result['qr_code'] = $mp_response['point_of_interaction']['transaction_data']['qr_code'];
        $result['qr_code_base64'] = $mp_response['point_of_interaction']['transaction_data']['qr_code_base64'];
    }
    
    echo json_encode($result);
} else {
    echo json_encode([
        'status' => 'error', 
        'message' => $mp_response['message'] ?? 'Erro desconhecido',
        'details' => $mp_response
    ]);
}
?>