<?php
// send_code.php (COM PHPMAILER VIA SMTP NO RENDER)

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Carrega as bibliotecas do Composer (Google Auth e PHPMailer)
require 'vendor/autoload.php';

use Google\Auth\Credentials\ServiceAccountCredentials;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Permite requisições do App (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Captura erros fatais para não retornar HTML
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        echo json_encode(['error' => 'Erro Fatal PHP: ' . $error['message']]);
        exit;
    }
});

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $email = $data['email'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Email inválido.");
    }

    // --- VERIFICAÇÃO SE USUÁRIO EXISTE NO FIREBASE ---
    $serviceAccountPath = __DIR__ . '/firebase_key.json'; 
    
    if (file_exists($serviceAccountPath)) {
        $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
        $accessToken = getGoogleAccessToken_Local($serviceAccount); 
        
        try {
            getUidByEmail_Local($email, $accessToken); 
        } catch (Exception $e) {
            throw new Exception("Este email não está cadastrado em nosso sistema.");
        }
    } else {
        throw new Exception("Erro no servidor: Arquivo de chaves ausente.");
    }

    // --- GERAÇÃO DO CÓDIGO ---
    $code = rand(100000, 999999);
    $secret_salt = "SuaChaveSecretaDeValidacao123"; 
    $hash = password_hash($code . $secret_salt, PASSWORD_DEFAULT);

    // --- CONFIGURAÇÃO E ENVIO VIA PHPMAILER (SMTP) ---
    $mail = new PHPMailer(true);

    try {
        // Configurações do Servidor SMTP
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST');            // Ex: smtp.hostinger.com
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER');            // Seu e-mail completo
        $mail->Password   = getenv('SMTP_PASS');            // A senha do seu e-mail
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;     // Ativa criptografia SSL segura
        $mail->Port       = getenv('SMTP_PORT') ?: 465;     // Porta padrão para SSL
        $mail->CharSet    = 'UTF-8';

        // Remetente e Destinatário
        $mail->setFrom(getenv('SMTP_USER'), 'BarberApp');
        $mail->addAddress($email);

        // Conteúdo do E-mail
        $mail->isHTML(true);
        $mail->Subject = "Código de Recuperação - BarberApp";
        $mail->Body    = "Olá!<br><br>Seu código de recuperação de senha é: <b style='font-size: 18px; color: #D4AF37;'> " . $code . " </b><br><br>Use este código dentro do aplicativo para criar uma nova senha.";
        $mail->AltBody = "Seu código de recuperação é: " . $code;

        $mail->send();
        
        // Retorna o hash para o app validar no próximo passo
        echo json_encode(['status' => 'sent', 'hash' => $hash]);

    } catch (Exception $mailError) {
        throw new Exception("Falha técnica ao enviar o e-mail: " . $mail->ErrorInfo);
    }

} catch (Exception $e) {
    http_response_code(500); 
    echo json_encode(['error' => $e->getMessage()]);
}

// --- FUNÇÕES AUXILIARES LOCAIS ---

function getGoogleAccessToken_Local($keys) {
    if (!function_exists('openssl_sign')) throw new Exception("Extensão OpenSSL não ativada no PHP.");
    
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
    
    $jwt = $base64Header . "." . $base64Claim . "." . $base64Signature;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? '';
}

function getUidByEmail_Local($email, $token) {
    $projectId = getenv('FIREBASE_PROJECT_ID');
    if (!$projectId) throw new Exception("Project ID não configurado no Render.");

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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($httpCode != 200 || !isset($data['users'][0]['localId'])) {
        throw new Exception("User not found");
    }
    
    return $data['users'][0]['localId'];
}
?>