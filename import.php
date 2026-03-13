<?php
/**
 * @file: import.php (ранее import_tochka.php)
 * @description: Инструмент импорта выписки из банка Точка (банковский модуль)
 */

require_once 'db.php';
require_once 'api/tochka_service.php';

$jwt = getSetting('tochka_jwt_token', $pdo);
$client_id = getSetting('tochka_client_id', $pdo);
$bik = getSetting('tochka_bank_bik', $pdo);
$account = getSetting('tochka_account_number', $pdo);

$tochka = new TochkaService($jwt, $client_id, $account, $bik);
$transactions = [];
$error = null;

// Загрузка ключевых слов для игнорирования
$stmt = $pdo->query("SELECT keyword FROM ignore_keywords");
$ignore_keywords = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['fetch_statement'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    $stmt = $pdo->prepare("SELECT external_id FROM incomes WHERE date >= ? AND date <= ? AND external_id IS NOT NULL");
    $stmt->execute([$start_date, $end_date]);
    $existing_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $result = $tochka->getStatementSync($start_date, $end_date);
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        $uapi_txs = $result['Data']['Statement'][0]['Transaction'] ?? [];
        foreach ($uapi_txs as $tx) {
            $counterparty = $tx['DebtorParty']['name'] ?? ($tx['CreditorParty']['name'] ?? ($tx['Counterparty']['name'] ?? 'Неизвестно'));
            $raw_date = $tx['bookingDateTime'] ?? ($tx['valueDateTime'] ?? 'now');
            $description = $tx['description'] ?? '';
            $indicator = $tx['creditDebitIndicator'] ?? 'Debit';
            
            $is_ignored = false;
            foreach ($ignore_keywords as $kw) {
                if (stripos($description, $kw) !== false || stripos($counterparty, $kw) !== false) {
                    $is_ignored = true;
                    break;
                }
            }
            if ($indicator !== 'Credit') $is_ignored = true;

            $ext_id = $tx['transactionId'] ?? null;
            $is_duplicate = ($ext_id && in_array($ext_id, $existing_ids));
            
            $transactions[] = [
                'date' => date('Y-m-d', strtotime($raw_date)),
                'amount' => (float)($tx['Amount']['amount'] ?? 0),
                'description' => $description,
                'counterparty' => $counterparty,
                'indicator' => $indicator,
                'is_ignored' => $is_ignored,
                'is_duplicate' => $is_duplicate,
                'transactionId' => $ext_id
            ];
        }
        $_SESSION['tochka_temp_data'] = $transactions;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_transactions'])) {
    $selected_indexes = $_POST['selected_transactions'] ?? [];
    $temp_data = $_SESSION['tochka_temp_data'] ?? [];
    $count = 0;
    foreach ($selected_indexes as $idx) {
        if (isset($temp_data[$idx])) {
            $t = $temp_data[$idx];
            if ($t['indicator'] !== 'Credit') continue;
            $quarter = 'Q' . ceil(date('n', strtotime($t['date'])) / 3);
            $stmt = $pdo->prepare("INSERT IGNORE INTO incomes (amount, date, quarter, description, is_imported, is_verified, external_id) VALUES (?, ?, ?, ?, 1, 1, ?)");
            $stmt->execute([$t['amount'], $t['date'], $quarter, $t['counterparty'] . ": " . $t['description'], $t['transactionId']]);
            $count++;
        }
    }
    echo "Импортировано $count записей";
}
?>

<!DOCTYPE html>
<html>
<head><title>Импорт из банка Точка</title></head>
<body>
    <h1>Импорт выписки</h1>
    <?php if ($error): ?> <p style="color:red"><?= $error ?></p> <?php endif; ?>
    <form method="POST">
        Начало: <input type="date" name="start_date" required>
        Конец: <input type="date" name="end_date" required>
        <button type="submit" name="fetch_statement">Запросить</button>
    </form>
    <!-- Остальная HTML верстка упрощена для автономности -->
</body>
</html>
