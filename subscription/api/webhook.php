<?php
// Уведомления ЮKassa о статусе платежей.
// В личном кабинете ЮKassa: Интеграция → HTTP-уведомления → адрес этого файла,
// события payment.succeeded и payment.canceled.
require __DIR__ . '/lib.php';

$event = json_decode(file_get_contents('php://input'), true);
$id = $event['object']['id'] ?? null;
if (!is_string($id) || !preg_match('/^[\w-]{10,64}$/', $id)) {
    http_response_code(400);
    exit;
}

try {
    // Не доверяем телу уведомления: запрашиваем актуальный статус платежа у ЮKassa
    $payment = yookassa_request('GET', '/payments/' . $id);
    apply_payment($payment);
    http_response_code(200);
} catch (Throwable $e) {
    error_log('subscription webhook: ' . $e->getMessage());
    http_response_code(500); // ЮKassa повторит уведомление позже
}
