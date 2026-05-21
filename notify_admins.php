<?php
// notify_admins.php (Dispara push para todos os admins)

$PROJECT_ID = "barbershop-96327"; 
$FIREBASE_KEY_PATH = __DIR__ . '/firebase_key.json';

require 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

// --- PROTEÇÃO CORS PARA O REACT NATIVE ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
// ----------------------------------------

$data = json_decode(file_get_contents('php://input'), true);
$title = $data['title'] ?? 'BarberApp';
$body = $data['body'] ?? 'Nova notificação do sistema!';
$screen = $data['data']['screen'] ?? 'AdminHomeScreen';

try {
    $scopes = [
        'https://www.googleapis.com/auth/firebase.messaging',
        'https://www.googleapis.com/auth/datastore'
    ];
    $creds = new ServiceAccountCredentials($scopes, $FIREBASE_KEY_PATH);
    $accessToken = $creds->fetchAuthToken()['access_token'];
    
    // 1. Busca todos os tokens dos admins no banco
    $adminTokens = buscarTokensAdminREST($PROJECT_ID, $accessToken);
    
    if(empty($adminTokens)){
        echo json_encode(['status' => 'error', 'message' => 'Nenhum admin encontrado.']);
        exit;
    }

    // 2. Dispara para cada admin encontrado
    $results = [];
    foreach($adminTokens as $token) {
        $results[] = enviarPushFCM($token, $title, $body, $accessToken, $PROJECT_ID, $screen);
    }
    
    echo json_encode(['status' => 'success', 'details' => $results]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
    $res = curl_exec($ch); 
    curl_close($ch);
    return json_decode($res, true);
}
?>