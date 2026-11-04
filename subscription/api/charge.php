<?php
// Ежемесячные автосписания. Запускается планировщиком (cron) раз в день:
//   0 10 * * * php /путь/к/subscription/api/charge.php
require __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!payments_enabled()) {
    exit("Оплата не подключена\n");
}

// Активные подписки, у которых подошла дата списания и нет незавершённого платежа
$due = db()->query("SELECT s.* FROM subscriptions s
    WHERE s.status = 'active' AND s.payment_method_id IS NOT NULL AND s.next_charge_at <= datetime('now', 'localtime')
      AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.subscription_id = s.id AND p.status = 'pending')")->fetchAll();

foreach ($due as $s) {
    try {
        $payment = create_payment($s, 'renewal');
        // Автосписание обычно завершается сразу; если нет — статус придёт в webhook.php
        apply_payment($payment);
        echo "Подписка №{$s['id']}: платёж {$payment['id']} — {$payment['status']}\n";
    } catch (Throwable $e) {
        error_log('subscription charge: ' . $e->getMessage());
        echo "Подписка №{$s['id']}: ошибка — {$e->getMessage()}\n";
    }
}
