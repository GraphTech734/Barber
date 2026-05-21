<?php
// ARQUIVO: google_sync.php (OTIMIZADO PARA O RENDER)

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Permite a requisição de pré-verificação (Preflight) do React Native
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    $uid = $data['uid'] ?? '';
    $email = $data['email'] ?? '';
    $name = $data['name'] ?? '';

    if (!$uid || (!$email && !$name)) {
        throw new Exception("Dados incompletos do Google.");
    }

    // AQUI VOCÊ PODE:
    // 1. Salvar num banco MySQL se tiver.
    // 2. Criar um log de acesso.
    // 3. Apenas validar e retornar sucesso.

    // Retorna sucesso de sincronização para o aplicativo
    echo json_encode([
        'status' => 'success',
        'message' => 'Usuário Google sincronizado com o backend PHP.',
        'user' => [
            'uid' => $uid,
            'email' => $email,
            'role' => 'client' // Padrão
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>