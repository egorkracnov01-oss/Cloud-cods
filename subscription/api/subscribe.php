<?php
// Приём заявки на подписку со страницы. Сохраняет заявку, пишет на почту
// и, если оплата включена, создаёт первый платёж в ЮKassa.
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, ['error' => 'Метод не поддерживается']);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(400, ['error' => 'Некорректный запрос']);
}

// Ловушка для спам-ботов: людям это поле не видно
if (!empty($in['website'])) {
    json_response(200, ['ok' => true]);
}

$c = config();
$field = function (string $key, int $max) use ($in): string {
    return mb_substr(trim((string) ($in[$key] ?? '')), 0, $max);
};

$tier = $field('tier', 20);
$name = $field('name', 100);
$phone = $field('phone', 30);
$email = $field('email', 120);
$service = $field('delivery_service', 20);
$city = $field('city', 100);
$pickup = $field('pickup_point', 300);
$comment = $field('comment', 500);

$digits = preg_replace('/\D/', '', $phone);
$errors = [];
if (!isset($c['tiers'][$tier])) $errors[] = 'Выберите набор.';
if ($name === '') $errors[] = 'Укажите имя.';
if (strlen($digits) < 10 || strlen($digits) > 15) $errors[] = 'Проверьте номер телефона.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Проверьте адрес электронной почты.';
if (!isset($c['delivery_services'][$service])) $errors[] = 'Выберите службу доставки.';
if ($city === '' || $pickup === '') $errors[] = 'Укажите город и адрес пункта выдачи.';
if (empty($in['agree']) || empty($in['agree_recurring'])) $errors[] = 'Отметьте оба согласия.';
if ($errors) {
    json_response(422, ['error' => implode(' ', $errors)]);
}

try {
    db()->prepare('INSERT INTO subscriptions (created_at, tier, price, name, phone, email, delivery_service, city, pickup_point, comment, status, cancel_token)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([now(), $tier, $c['tiers'][$tier]['price'], $name, $phone, $email, $service, $city, $pickup, $comment, 'pending', bin2hex(random_bytes(16))]);
    $s = load_subscription((int) db()->lastInsertId());

    if (!payments_enabled()) {
        send_mail($c['mail_to'], "Новая заявка на подписку №{$s['id']}",
            subscription_text($s) . "\n\nОнлайн-оплата не подключена — свяжитесь с клиентом.");
        json_response(200, ['ok' => true]);
    }

    $payment = create_payment($s, 'first');
    send_mail($c['mail_to'], "Новая заявка на подписку №{$s['id']} — ожидает оплаты",
        subscription_text($s) . "\n\nПисьмо об оплате придёт отдельно.");
    json_response(200, ['confirmation_url' => $payment['confirmation']['confirmation_url']]);
} catch (Throwable $e) {
    error_log('subscription: ' . $e->getMessage());
    json_response(500, ['error' => 'Не удалось оформить подписку. Попробуйте позже или позвоните нам.']);
}
