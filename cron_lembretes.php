<?php
// cron_lembretes.php (BUSCA DINÂMICA DO ADMIN - OTIMIZADO PARA O RENDER)

ini_set('display_errors', 0);
error_reporting(E_ALL);

require 'vendor/autoload.php';

use Google\Auth\Credentials\ServiceAccountCredentials;

date_default_timezone_set('America/Sao_Paulo');

// --- SEGURANÇA: IMPEDE ACESSO NÃO AUTORIZADO PELA URL ---
// Quando for configurar o robô (cron), você usará a URL: 
// https://barber-5k9d.onrender.com/cron_lembretes.php?token=SEGREDO123
$tokenAcesso = $_GET['token'] ?? '';
if ($tokenAcesso !== 'SEGREDO123') {
    http_response_code(403);
    die("Acesso negado.");
}

// --- CONFIGURAÇÕES ---
$FIREBASE_KEY_PATH = __DIR__ . '/firebase_key.json';
$PROJECT_ID = "barbershop-96327"; 

// --- ATUALIZAÇÃO DO LOG PARA O RENDER ---
function logMsg($msg) {
    $dataHora = date('d/m/Y H:i:s');
    // error_log envia a mensagem direto para o painel "Logs" do Render
    error_log("[$dataHora] CRON LEMBRETES: $msg");
}

// 1. AUTENTICAÇÃO
function getAccessToken($keyPath) {
    if (!file_exists($keyPath)) {
        logMsg("ERRO: Arquivo JSON de chaves do Firebase não encontrado no Render.");
        die();
    }
    try {
        $scopes = [
            'https://www.googleapis.com/auth/datastore',
            'https://www.googleapis.com/auth/firebase.messaging'
        ];
        $creds = new ServiceAccountCredentials($scopes, $keyPath);
        return $creds->fetchAuthToken()['access_token'];
    } catch (Exception $e) {
        logMsg("ERRO AUTH: " . $e->getMessage());
        die();
    }
}

$accessToken = getAccessToken($FIREBASE_KEY_PATH);

// 2. BUSCA DINÂMICA: Pegar todos os tokens de Admins atuais
$adminTokens = buscarTokensAdminREST($PROJECT_ID, $accessToken);

// 3. BUSCAR AGENDAMENTOS
$baseUrl = "https://firestore.googleapis.com/v1/projects/$PROJECT_ID/databases/(default)/documents";
$url = "$baseUrl/appointments?pageSize=100"; 

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$documents = $data['documents'] ?? [];
$agora = new DateTime("now");

$processados = 0;

foreach ($documents as $doc) {
    $agenda = parseFirestoreDoc($doc);
    $id = basename($doc['name']); 

    if (($agenda['status'] ?? '') !== 'confirmed') continue;
    if (empty($agenda['date']) || empty($agenda['time'])) continue;

    $dataHoraString = $agenda['date'] . ' ' . $agenda['time']; 
    $dataAgendamento = new DateTime($dataHoraString);
    $diff = $agora->diff($dataAgendamento);
    $minutosRestantes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    if ($diff->invert) $minutosRestantes *= -1;

    if ($minutosRestantes < -20 || $minutosRestantes > 70) continue;

    $tokenCliente = null;
    if (!empty($agenda['userId'])) {
        $tokenCliente = buscarTokenUsuarioREST($agenda['userId'], $baseUrl, $accessToken);
    }

    // --- VARIÁVEIS EXTRAS PARA NOTIFICAÇÕES PROFISSIONAIS ---
    $serviceName = $agenda['serviceName'] ?? 'Atendimento';
    $clientName = $agenda['userName'] ?? 'Cliente';
    $profName = $agenda['professionalName'] ?? 'o profissional';
    $horaAgendada = $agenda['time'];

    // --- REGRAS DE ENVIO ---

    // 1h (Cliente)
    if ($minutosRestantes >= 55 && $minutosRestantes <= 65) {
        if ($tokenCliente && empty($agenda['notified_client_1h'])) {
            // --- TEXTOS ATUALIZADOS ---
            enviarPushFCM($tokenCliente, "Lembrete: $serviceName ✂️", "Falta 1 hora para o seu agendamento com $profName!", $accessToken, $PROJECT_ID, "MyAppointmentsScreen");
            atualizarFlagREST($id, 'notified_client_1h', $baseUrl, $accessToken);
            logMsg("1h Cliente enviado: $id");
            $processados++;
        }
    }
    // 30m (Cliente e Admin)
    elseif ($minutosRestantes >= 28 && $minutosRestantes <= 32) {
        if ($tokenCliente && empty($agenda['notified_client_30m'])) {
            // --- TEXTOS ATUALIZADOS ---
            enviarPushFCM($tokenCliente, "Está chegando! ⏰", "$profName aguarda você em 30 minutos para o seu $serviceName.", $accessToken, $PROJECT_ID, "MyAppointmentsScreen");
            atualizarFlagREST($id, 'notified_client_30m', $baseUrl, $accessToken);
            logMsg("30m Cliente enviado: $id");
            $processados++;
        }
        if (!empty($adminTokens) && empty($agenda['notified_admin_30m'])) {
            foreach($adminTokens as $admTk) {
                // --- TEXTOS ATUALIZADOS ---
                enviarPushFCM($admTk, "Faltam 30 minutos ⏰", "$clientName tem um(a) $serviceName com $profName às $horaAgendada.", $accessToken, $PROJECT_ID, "AdminAppointmentsScreen");
            }
            atualizarFlagREST($id, 'notified_admin_30m', $baseUrl, $accessToken);
            $processados++;
        }
    }
    // 15m (Apenas Admin)
    elseif ($minutosRestantes >= 13 && $minutosRestantes <= 17) {
        if (!empty($adminTokens) && empty($agenda['notified_admin_15m'])) {
            foreach($adminTokens as $admTk) {
                // --- TEXTOS ATUALIZADOS ---
                enviarPushFCM($admTk, "Prepare-se! ✂️", "Em 15 minutos: $serviceName para $clientName (Profissional: $profName).", $accessToken, $PROJECT_ID, "AdminAppointmentsScreen");
            }
            atualizarFlagREST($id, 'notified_admin_15m', $baseUrl, $accessToken);
            $processados++;
        }
    }
    // 10m (Cliente e Admin)
    elseif ($minutosRestantes >= 8 && $minutosRestantes <= 12) {
        if ($tokenCliente && empty($agenda['notified_client_10m'])) {
            // --- TEXTOS ATUALIZADOS ---
            enviarPushFCM($tokenCliente, "Corre que dá tempo! 🏃", "$profName já está te aguardando para o seu $serviceName em 10 minutos.", $accessToken, $PROJECT_ID, "MyAppointmentsScreen");
            atualizarFlagREST($id, 'notified_client_10m', $baseUrl, $accessToken);
            logMsg("10m Cliente enviado: $id");
            $processados++;
        }
        if (!empty($adminTokens) && empty($agenda['notified_admin_10m'])) {
            foreach($adminTokens as $admTk) {
                // --- TEXTOS ATUALIZADOS ---
                enviarPushFCM($admTk, "Atenção: 10 minutos! 🚨", "$clientName já deve estar chegando para o atendimento com $profName.", $accessToken, $PROJECT_ID, "AdminAppointmentsScreen");
            }
            atualizarFlagREST($id, 'notified_admin_10m', $baseUrl, $accessToken);
            $processados++;
        }
    }
}

echo "Execução finalizada. Alertas processados: $processados";

// --- FUNÇÕES AUXILIARES CONTINUAM AS MESMAS ---

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

function parseFirestoreDoc($doc) {
    if (!isset($doc['fields'])) return [];
    $clean = [];
    foreach ($doc['fields'] as $key => $val) {
        if (isset($val['stringValue'])) $clean[$key] = $val['stringValue'];
        elseif (isset($val['integerValue'])) $clean[$key] = $val['integerValue'];
        elseif (isset($val['doubleValue'])) $clean[$key] = $val['doubleValue'];
        elseif (isset($val['booleanValue'])) $clean[$key] = $val['booleanValue'];
        elseif (isset($val['timestampValue'])) $clean[$key] = $val['timestampValue'];
    }
    return $clean;
}

function buscarTokenUsuarioREST($userId, $baseUrl, $token) {
    $ch = curl_init("$baseUrl/users/$userId");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['fields']['fcmToken']['stringValue'] ?? null;
}

function atualizarFlagREST($docId, $field, $baseUrl, $token) {
    $url = "$baseUrl/appointments/$docId?updateMask.fieldPaths=$field";
    $body = ["fields" => [$field => ["booleanValue" => true]]];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_exec($ch);
    curl_close($ch);
}
?>
