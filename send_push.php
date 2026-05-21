<?php
// send_push.php (MIGRAÇÃO PARA FCM NATIVO V1)

// Carrega as bibliotecas do Google
require 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

// --- PROTEÇÃO CORS PARA O REACT NATIVE ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ----------------------------------------

// --- CONFIGURAÇÕES ---
$FIREBASE_KEY_PATH = __DIR__ . '/firebase_key.json';
$PROJECT_ID = "barbershop-96327"; 

// Lê o JSON recebido (App ou Web manda pra cá)
$data = json_decode(file_get_contents('php://input'), true);

// Validação simples
$to = $data['to'] ?? []; 
$title = $data['title'] ?? 'BarberApp';
$body = $data['body'] ?? 'Você tem uma nova notificação!';
$dataPayload = $data['data'] ?? [];

if (empty($to)) {
    echo json_encode(['status' => 'error', 'message' => 'Nenhum destinatário.']);
    exit;
}

// Normaliza para array caso tenham mandado apenas uma string
if (!is_array($to)) $to = [$to];

// Filtra tokens válidos (Agora são tokens do FCM)
$validTokens = [];
foreach ($to as $token) {
    // Apenas garante que não é vazio (tokens FCM são strings longas aleatórias)
    if (is_string($token) && trim($token) !== '') {
        $validTokens[] = trim($token);
    }
}

if (empty($validTokens)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Nenhum token FCM válido encontrado.']);
    exit;
}

// --- 1. AUTENTICAÇÃO NO FIREBASE (OAuth2) ---
try {
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $creds = new ServiceAccountCredentials($scopes, $FIREBASE_KEY_PATH);
    $accessToken = $creds->fetchAuthToken()['access_token'];
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Erro de auth no Firebase: ' . $e->getMessage()]);
    exit;
}

// --- 2. PREPARAÇÃO DO PAYLOAD DATA ---
// O FCM v1 exige obrigatoriamente que TODOS os valores dentro do array 'data' sejam Strings.
$formattedData = [];
if (is_array($dataPayload)) {
    foreach ($dataPayload as $k => $v) {
        $formattedData[$k] = (string)$v;
    }
}

// --- 3. ENVIO DAS MENSAGENS (FCM v1 REST API) ---
$url = "https://fcm.googleapis.com/v1/projects/$PROJECT_ID/messages:send";
$results = [];

// A API REST v1 do FCM exige o envio um por um 
foreach ($validTokens as $token) {
    
    // Montagem exata do payload que acorda o Android
    $payload = [
        "message" => [
            "token" => $token,
            "notification" => [
                "title" => $title,
                "body" => $body
            ],
            "android" => [
                "priority" => "HIGH",
                "notification" => [
                    "sound" => "default",
                    "default_vibrate_timings" => true,
                    "default_light_settings" => true
                ]
            ],
            "data" => $formattedData
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $accessToken"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $results[] = [
        'token' => substr($token, 0, 15) . '...', // Mostra só o comecinho no log para não poluir
        'http_code' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

// Retorna o resultado para o App/Sistema
echo json_encode(['status' => 'success', 'details' => $results]);
?>