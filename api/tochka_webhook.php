<?php
/**
 * @file: api/tochka_webhook.php
 * @description: Обработчик входящих уведомлений от банка Точка (банковский модуль)
 */

require_once '../db.php';

function decodePayload($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
}

// Список доверенных IP Точки (согласно документации uAPI)
$allowed_ips = [
    '185.111.41.0/24',
    '31.43.34.192/27',
    '193.124.9.48/29'
];

function ip_in_range($ip, $range) {
    if (strpos($range, '/') === false) $range .= '/32';
    list($range, $netmask) = explode('/', $range, 2);
    $range_dec = ip2long($range);
    $ip_dec = ip2long($ip);
    $wildcard_dec = pow(2, (32 - $netmask)) - 1;
    $netmask_dec = ~ $wildcard_dec;
    return (($ip_dec & $netmask_dec) == ($range_dec & $netmask_dec));
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_allowed = false;
foreach ($allowed_ips as $range) {
    if (ip_in_range($client_ip, $range)) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed && $client_ip !== '127.0.0.1' && $client_ip !== '::1') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied for IP: ' . $client_ip);
}


$input = file_get_contents('php://input');
if (!$input) {
    header('HTTP/1.1 400 Bad Request');
    exit('Empty input');
}

$payload = decodePayload($input);
if (!$payload) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid JWT');
}

$log_file = __DIR__ . '/webhook_debug.log';
$event = $payload['webhookType'] ?? ($payload['type'] ?? '');

if ($event === 'webhookCheck') {
    $data = $payload['data'] ?? $payload;
    $challenge = $data['challenge'] ?? '';
    header('Content-Type: application/json');
    echo json_encode(['data' => ['challenge' => $challenge]]);
    exit;
}

$valid_events = ['incomingPayment', 'incomingSbpPayment', 'acquiringInternetPayment'];
if (in_array($event, $valid_events)) {
    try {
        $data = $payload['data'] ?? $payload;
        $amount = 0;
        $sender = 'Клиент';
        $description = $data['purpose'] ?? ($data['description'] ?? 'Автоматическое зачисление');
        
        if ($event === 'incomingPayment') {
            $amount = (float)($data['SidePayer']['amount'] ?? ($data['amount'] ?? 0));
            $sender = $data['SidePayer']['name'] ?? 'Клиент';
        } else {
            $amount = (float)($data['amount'] ?? 0);
            $sender = $data['payerName'] ?? ($data['debtorName'] ?? 'Аноним');
        }
        
        if ($event === 'acquiringInternetPayment') {
            $sender = "Эквайринг: " . ($data['brandName'] ?? ($data['customerCode'] ?? 'Точка'));
        }
        
        $date = date('Y-m-d'); 
        $quarter = 'Q' . ceil(date('n') / 3);
        $full_desc = "[WEBHOOK] $sender: $description";
        $external_id = $data['operationId'] ?? ($data['paymentId'] ?? ($data['transactionId'] ?? null));
        
        $stmt = $pdo->prepare("INSERT IGNORE INTO incomes (amount, date, quarter, description, category_id, is_imported, is_verified, external_id) VALUES (?, ?, ?, ?, NULL, 1, 0, ?)");
        $stmt->execute([$amount, $date, $quarter, $full_desc, $external_id]);
        
        if ($stmt->rowCount() > 0) {
            $log_msg = date('Y-m-d H:i:s') . " - Success: Recorded operation $external_id\n";
            $tg_token = getSetting('tg_bot_token', $pdo);
            $tg_chat_id = getSetting('tg_chat_id', $pdo);
            
            if ($tg_token && $tg_chat_id) {
                $tg_msg = "💰 <b>Новое поступление!</b>\n\n";
                $tg_msg .= "💵 Сумма: <b>" . number_format($amount, 2, ',', ' ') . " ₽</b>\n";
                $tg_msg .= "👤 От: " . htmlspecialchars($sender) . "\n";
                $tg_msg .= "📝 Назначение: " . htmlspecialchars($description) . "\n";
                sendTelegramMessage($tg_token, $tg_chat_id, $tg_msg);
            }
        } else {
            $log_msg = date('Y-m-d H:i:s') . " - Info: Operation $external_id already exists\n";
        }
        file_put_contents($log_file, $log_msg, FILE_APPEND);
        header('HTTP/1.1 200 OK');
        exit('Success');
    } catch (Exception $e) {
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        header('HTTP/1.1 500 Internal Server Error');
        exit('Database Error');
    }
}

header('HTTP/1.1 200 OK');
exit('Event ignored');
?>
