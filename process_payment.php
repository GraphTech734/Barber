<?php
// process_payment.php (BUSCA DINÂMICA DO ADMIN)

$PROJECT_ID = "barbershop-96327"; 
$FIREBASE_KEY_PATH = __DIR__ . '/firebase_key.json';

require 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

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
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$access_token = getenv('MP_ACCESS_TOKEN');
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['payment_method_id']) || !isset($data['amount']) || !isset($data['email'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados incompletos']);
    exit();
}

$payment_data = [
    "transaction_amount" => (float)$data['amount'],
    "description" => $data['description'],
    "payment_method_id" => $data['payment_method_id'],
    "payer" => ["email" => $data['email']]
];

if ($data['payment_method_id'] === 'pix') {
    $payment_data['date_of_expiration'] = date('Y-m-d\TH:i:s.000P', strtotime('+30 minutes'));
} else {
    if (!isset($data['token'])) {
        echo json_encode(['error' => 'Token do cartão é obrigatório']);
        exit();
    }
    $payment_data['token'] = $data['token'];
    $payment_data['installments'] = 1; 
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $access_token,
    "X-Idempotency-Key: " . uniqid()
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$mp_response = json_decode($response, true);

if ($http_code === 201) {
    $result = [
        "status" => $mp_response['status'],
        "id" => $mp_response['id']
    ];

    if ($data['payment_method_id'] === 'pix') {
        $result['qr_code'] = $mp_response['point_of_interaction']['transaction_data']['qr_code'];
        $result['qr_code_base64'] = $mp_response['point_of_interaction']['transaction_data']['qr_code_base64'];
    }

    if ($mp_response['status'] === 'approved') {
        $msg = "💰 Pagamento Recebido: R$ " . number_format($data['amount'], 2, ',', '.') . "\n" . $data['description'];
        
        try {
            // Permissão para enviar notificação E ler banco de dados
            $scopes = [
                'https://www.googleapis.com/auth/firebase.messaging',
                'https://www.googleapis.com/auth/datastore'
            ];
            $creds = new ServiceAccountCredentials($scopes, $FIREBASE_KEY_PATH);
            $fcmAccessToken = $creds->fetchAuthToken()['access_token'];
            
            // 1. Busca os admins vivos no banco
            $adminTokens = buscarTokensAdminREST($PROJECT_ID, $fcmAccessToken);
            
            // 2. Envia a notificação de pagamento para todos eles
            foreach($adminTokens as $admTk) {
                enviarPushFCM($admTk, "Novo Pagamento Aprovado!", $msg, $fcmAccessToken, $PROJECT_ID, 'AdminAppointmentsScreen');
            }
        } catch (Exception $e) {
            // Falha silenciosa
        }
    }

    echo json_encode($result);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Erro MP", "details" => $mp_response]);
}

// --- FUNÇÕES AUXILIARES ---

function buscarTokensAdminREST($projectId, $accessToken) {
    $url = "https://firestore.googleapis.com/v1/projects/$projectId/databases/(default)/documents:runQuery";
    $query = [
        "structuredQuery" => [
            "from" => [["collectionId" => "users"]],
            "where" => [
                "fieldFilter" => [
                    "field" => ["fieldPath" => "role"],
                    "op" => "EQUAL",
                    "value" => ["stringValue" => "admin"]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $accessToken"
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $tokens = [];
    $data = json_decode($response, true);
    if (is_array($data)) {
        foreach ($data as $doc) {
            if (isset($doc['document']['fields']['fcmToken']['stringValue'])) {
                $token = $doc['document']['fields']['fcmToken']['stringValue'];
                if (!empty($token)) {
                    $tokens[] = $token;
                }
            }
        }
    }
    return $tokens;
}

function enviarPushFCM($token, $title, $body, $accessToken, $projectId, $screen) {
    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";
    $data = [
        "message" => [
            "token" => $token,
            "notification" => ["title" => $title, "body" => $body],
            "android" => [
                "priority" => "HIGH",
                "notification" => [
                    "sound" => "default",
                    "default_vibrate_timings" => true,
                    "default_light_settings" => true
                ]
            ],
            "data" => ["screen" => (string)$screen]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $accessToken"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch); 
    curl_close($ch);
}
?>