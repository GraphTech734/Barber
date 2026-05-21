<?php
// ARQUIVO: reset_pass.php

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Permite requisições do App (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Captura erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        echo json_encode(['error' => 'Erro Fatal PHP: ' . $error['message']]);
        exit;
    }
});

try {
    // --- MUDANÇA 1: Usar o mesmo firebase_key.json ---
    $serviceAccountPath = __DIR__ . '/firebase_key.json'; 
    if (!file_exists($serviceAccountPath)) {
        throw new Exception("Arquivo de chaves Firebase não encontrado.");
    }
    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$serviceAccount) throw new Exception("Arquivo JSON inválido.");

    // --- MUDANÇA 2: Removida a função de ler o .env manualmente (Render já faz isso) ---

    // 3. Recebe Dados
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    $role = $data['role'] ?? ''; 
    $userCode = $data['code'] ?? '';
    $hash = $data['hash'] ?? '';
    $adminToken = $data['adminToken'] ?? '';
    $newPassword = $data['newPassword'] ?? '';
    $targetEmail = $data['email'] ?? '';

    // 4. Validações Locais
    $secret_salt = "SuaChaveSecretaDeValidacao123"; 
    if (!password_verify($userCode . $secret_salt, $hash)) {
        throw new Exception("Código de verificação incorreto.");
    }

    if ($role === 'admin') {
        $server_token = getenv('ADMIN_REGISTRATION_TOKEN');
        if (trim($adminToken) !== trim($server_token)) {
            throw new Exception("Token de Admin inválido.");
        }
    }

    // 5. Fluxo OAuth2 do Firebase
    $jwt = createSignedJWT($serviceAccount);
    $accessToken = exchangeJwtForAccessToken($jwt);
    $uid = getUidByEmail($targetEmail, $accessToken);
    updatePassword($uid, $newPassword, $accessToken);

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    http_response_code(500); 
    echo json_encode(['error' => $e->getMessage()]);
}

// ---------------------------------------------------------
// FUNÇÕES OAUTH2 E API
// ---------------------------------------------------------

function createSignedJWT($keys) {
    if (!function_exists('openssl_sign')) throw new Exception("OpenSSL não ativado no PHP.");

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    
    $claim = [
        'iss' => $keys['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform', 
        'aud' => 'https://oauth2.googleapis.com/token', 
        'exp' => $now + 3600,
        'iat' => $now
    ];

    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($header)));
    $base64Claim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($claim)));

    $signature = '';
    openssl_sign($base64Header . "." . $base64Claim, $signature, $keys['private_key'], "SHA256");
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    return $base64Header . "." . $base64Claim . "." . $base64Signature;
}

function exchangeJwtForAccessToken($jwt) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode != 200 || !isset($data['access_token'])) {
        $msg = $data['error_description'] ?? $data['error'] ?? 'Falha na troca do token';
        throw new Exception("Erro Auth Google ($httpCode): " . $msg);
    }

    return $data['access_token'];
}

function getUidByEmail($email, $token) {
    $projectId = getenv('FIREBASE_PROJECT_ID'); 
    
    $url = "https://identitytoolkit.googleapis.com/v1/projects/$projectId/accounts:lookup";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => [$email]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    
    if (!isset($data['users'][0]['localId'])) {
        throw new Exception("Usuário não encontrado no Firebase.");
    }
    
    return $data['users'][0]['localId'];
}

function updatePassword($uid, $password, $token) {
    $projectId = getenv('FIREBASE_PROJECT_ID');
    $url = "https://identitytoolkit.googleapis.com/v1/projects/$projectId/accounts:update";
    
    $body = json_encode([
        'localId' => $uid,
        'password' => $password,
        'returnSecureToken' => false
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode != 200) {
        $err = json_decode($response, true);
        throw new Exception("Erro Troca Senha: " . ($err['error']['message'] ?? "Desconhecido"));
    }
}
?>