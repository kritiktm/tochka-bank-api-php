<?php
/**
 * @file: api/tochka_service.php
 * @description: Простая обертка для работы с API банка Точка через JWT (банковский модуль)
 */

class TochkaService {
    private $jwt;
    private $client_id;
    private $account;
    private $bik;
    private $customer_code;
    private $base_url = 'https://enter.tochka.com/uapi/open-banking/v1.0'; 

    public function __construct($jwt, $client_id, $account, $bik, $customer_code = '') {
        $this->jwt = $jwt;
        $this->client_id = $client_id;
        $this->account = $account;
        $this->bik = $bik;
        $this->customer_code = $customer_code;
    }

    private function request($method, $uri, $data = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->base_url . $uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = [
            'Authorization: Bearer ' . $this->jwt,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if (!empty($this->client_id)) {
            $headers[] = 'X-Client-Id: ' . $this->client_id;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        // Рекомендуется указать путь к сертификату, если система его не находит автоматически
        // curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/cacert.pem');
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $http_code,
            'data' => json_decode($response, true)
        ];
    }

    public function getStatementSync($start_date, $end_date) {
        $accountId = $this->account . '/' . $this->bik;
        $res = $this->request('POST', '/statements', [
            'Data' => [
                'Statement' => [
                    'accountId' => $accountId,
                    'startDateTime' => $start_date,
                    'endDateTime' => $end_date
                ]
            ]
        ]);

        if ($res['code'] < 200 || $res['code'] >= 300 || !isset($res['data']['Data']['Statement']['statementId'])) {
            $msg = $res['data']['Errors'][0]['Message'] ?? ($res['data']['message'] ?? 'Неизвестная ошибка');
            $full_res = json_encode($res['data'], JSON_UNESCAPED_UNICODE);
            return ['error' => "Ошибка uAPI (Код {$res['code']}): $msg. Ответ банка: $full_res"];
        }

        $statementId = $res['data']['Data']['Statement']['statementId'];
        $attempts = 0;
        while ($attempts < 15) {
            $result_res = $this->request('GET', "/accounts/" . urlencode($accountId) . "/statements/" . $statementId);
            if ($result_res['code'] === 200) {
                $data = $result_res['data'];
                $stmt_data = $data['Data']['Statement'][0] ?? ($data['Data']['Statement'] ?? []);
                $status = $stmt_data['status'] ?? '';
                if ($status === 'Ready' || isset($stmt_data['Transaction'])) {
                    return $data;
                }
                if (in_array($status, ['Processing', 'Created', 'Accepted'])) {
                    sleep(2);
                    $attempts++;
                    continue;
                }
                return ['error' => "Банк вернул статус: $status"];
            }
            sleep(2);
            $attempts++;
        }
        return ['error' => 'Превышено время ожидания выписки'];
    }

    public function getBalance() {
        $accountId = $this->account . '/' . $this->bik;
        $res = $this->request('GET', "/accounts/" . urlencode($accountId) . "/balances");
        if ($res['code'] === 200) {
            $balances = $res['data']['Data']['Balance'] ?? [];
            foreach ($balances as $b) {
                if ($b['type'] === 'ClosingAvailable') {
                    return (float)$b['Amount']['amount'];
                }
            }
            return (float)($balances[0]['Amount']['amount'] ?? 0);
        }
        return ['error' => "Ошибка получения баланса: Код " . $res['code']];
    }

    public function registerWebhook($url) {
        $old_base = $this->base_url;
        $this->base_url = 'https://enter.tochka.com/uapi/webhook/v1.0';
        $res = $this->request('PUT', '/' . $this->client_id, [
            'webhooksList' => ['incomingPayment', 'incomingSbpPayment', 'acquiringInternetPayment'],
            'url' => $url
        ]);
        $this->base_url = $old_base;
        return $res;
    }

    public function createPaymentLink($amount, $purpose, $redirectUrl, $ttl = 1440, $paymentMode = ['sbp', 'card']) {
        $old_base = $this->base_url;
        $this->base_url = 'https://enter.tochka.com/uapi/acquiring/v1.0';
        $res = $this->request('POST', '/payments', [
            'Data' => [
                'amount' => number_format((float)$amount, 2, '.', ''),
                'purpose' => $purpose,
                'redirectUrl' => $redirectUrl,
                'failRedirectUrl' => $redirectUrl,
                'customerCode' => $this->customer_code,
                'paymentMode' => $paymentMode,
                'ttl' => (int)$ttl,
                'items' => [
                    [
                        'name' => $purpose,
                        'amount' => number_format((float)$amount, 2, '.', ''),
                        'quantity' => 1,
                        'vatType' => 'none'
                    ]
                ]
            ]
        ]);
        $this->base_url = $old_base;
        return $res;
    }
}
?>
