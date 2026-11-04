<?php
// Общие функции для обработчиков подписки.

function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Не найден api/config.php — скопируйте config.example.php');
        }
        $config = require $file;
    }
    return $config;
}

function payments_enabled(): bool
{
    return config()['yookassa']['shop_id'] !== '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $path = config()['db_path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            tier TEXT NOT NULL,
            price INTEGER NOT NULL,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            email TEXT NOT NULL,
            delivery_service TEXT NOT NULL,
            city TEXT NOT NULL,
            pickup_point TEXT NOT NULL,
            comment TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL,           -- pending | active | past_due | canceled
            payment_method_id TEXT,
            next_charge_at TEXT,
            boxes_paid INTEGER NOT NULL DEFAULT 0,
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            cancel_token TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS payments (
            id TEXT PRIMARY KEY,            -- id платежа в ЮKassa
            subscription_id INTEGER NOT NULL,
            kind TEXT NOT NULL,             -- first | renewal
            amount INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
    }
    return $pdo;
}

function now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function json_response(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function send_mail(string $to, string $subject, string $body): bool
{
    $from = config()['mail_from'];
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode('Дикоросы большой страны') . "?= <$from>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($body)), $headers);
    if (!$ok) {
        error_log("subscription: не удалось отправить письмо на $to: $subject");
    }
    return $ok;
}

function subscription_text(array $s): string
{
    $services = config()['delivery_services'];
    return implode("\n", [
        'Подписка №' . $s['id'],
        'Набор: ' . config()['tiers'][$s['tier']]['name'] . ' — ' . $s['price'] . ' ₽/мес',
        'Имя: ' . $s['name'],
        'Телефон: ' . $s['phone'],
        'Email: ' . $s['email'],
        'Доставка: ' . ($services[$s['delivery_service']] ?? $s['delivery_service']),
        'Город: ' . $s['city'],
        'Пункт выдачи: ' . $s['pickup_point'],
        'Комментарий: ' . ($s['comment'] !== '' ? $s['comment'] : '—'),
    ]);
}

function load_subscription(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM subscriptions WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function yookassa_request(string $method, string $path, ?array $body = null): array
{
    $yk = config()['yookassa'];
    $ch = curl_init($yk['api_url'] . $path);
    $headers = ['Content-Type: application/json'];
    if ($body !== null) {
        $headers[] = 'Idempotence-Key: ' . bin2hex(random_bytes(16));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $yk['shop_id'] . ':' . $yk['secret_key'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        throw new RuntimeException("ЮKassa $method $path: HTTP $code $err " . (is_string($raw) ? $raw : ''));
    }
    return $data;
}

// Создаёт платёж в ЮKassa. Первый платёж — с переходом на страницу оплаты
// и сохранением карты, следующие — автосписание с сохранённой карты.
function create_payment(array $s, string $kind): array
{
    $yk = config()['yookassa'];
    $tierName = config()['tiers'][$s['tier']]['name'];
    $amount = ['value' => number_format($s['price'], 2, '.', ''), 'currency' => 'RUB'];
    $description = "Подписка «{$tierName}» №{$s['id']}, 1 месяц";

    $body = [
        'amount' => $amount,
        'capture' => true,
        'description' => $description,
        'metadata' => ['subscription_id' => (string) $s['id']],
    ];
    if ($kind === 'first') {
        $c = config();
        $body['save_payment_method'] = true;
        $body['confirmation'] = [
            'type' => 'redirect',
            'return_url' => $c['site_url'] . $c['page_path'] . '?paid=' . $s['id'],
        ];
    } else {
        $body['payment_method_id'] = $s['payment_method_id'];
    }
    if ($yk['send_receipt']) {
        $body['receipt'] = [
            'customer' => ['email' => $s['email'], 'phone' => preg_replace('/\D/', '', $s['phone'])],
            'items' => [[
                'description' => mb_substr($description, 0, 128),
                'quantity' => '1.00',
                'amount' => $amount,
                'vat_code' => $yk['vat_code'],
                'payment_mode' => 'full_prepayment',
                'payment_subject' => 'commodity',
            ]],
        ];
    }

    $payment = yookassa_request('POST', '/payments', $body);
    db()->prepare('INSERT INTO payments (id, subscription_id, kind, amount, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$payment['id'], $s['id'], $kind, $s['price'], 'pending', now()]);
    return $payment;
}

// Применяет новый статус платежа к подписке. Вызывается из webhook.php
// и из charge.php. Повторный вызов с тем же статусом ничего не делает.
function apply_payment(array $payment): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$payment['id']]);
    $row = $st->fetch();
    if (!$row || $row['status'] === $payment['status']) {
        return;
    }
    if (!in_array($payment['status'], ['succeeded', 'canceled'], true)) {
        return;
    }
    $pdo->prepare('UPDATE payments SET status = ? WHERE id = ?')->execute([$payment['status'], $payment['id']]);

    $s = load_subscription((int) $row['subscription_id']);
    if (!$s) {
        return;
    }
    $c = config();
    $info = subscription_text($s);

    if ($payment['status'] === 'succeeded') {
        // Следующее списание — через месяц после текущего (или после оплаты, если это первый платёж)
        $base = ($row['kind'] === 'renewal' && $s['next_charge_at']) ? $s['next_charge_at'] : now();
        $next = (new DateTimeImmutable($base))->modify('+1 month')->format('Y-m-d H:i:s');
        $methodId = !empty($payment['payment_method']['saved']) ? $payment['payment_method']['id'] : $s['payment_method_id'];
        $boxNo = $s['boxes_paid'] + 1;
        $status = $s['status'] === 'canceled' ? 'canceled' : 'active';
        $pdo->prepare('UPDATE subscriptions SET status = ?, payment_method_id = ?, next_charge_at = ?, boxes_paid = ?, failed_attempts = 0 WHERE id = ?')
            ->execute([$status, $methodId, $next, $boxNo, $s['id']]);

        send_mail($c['mail_to'], "Оплачено: подписка №{$s['id']}, набор №$boxNo — отправить за 2 дня",
            "Оплата {$s['price']} ₽ получена (платёж {$payment['id']}).\nНабор №$boxNo нужно отправить в течение 2 дней.\n\n$info\n\nСледующее списание: $next");

        if ($row['kind'] === 'first') {
            $cancelUrl = $c['site_url'] . $c['page_path'] . 'api/cancel.php?token=' . $s['cancel_token'];
            send_mail($s['email'], 'Подписка «Дикоросы большой страны» оформлена',
                "Здравствуйте, {$s['name']}!\n\nСпасибо за подписку. Первый набор отправим в течение 2 дней в пункт выдачи:\n{$s['city']}, {$s['pickup_point']}\n\n"
                . "Следующее списание {$s['price']} ₽ — $next. Дальше раз в месяц.\n\n"
                . "Отменить подписку можно в любой момент по ссылке:\n$cancelUrl\n\nДикоросы большой страны");
        }
        return;
    }

    // Платёж отменён (не прошёл)
    if ($row['kind'] === 'first') {
        return; // клиент не оплатил при оформлении — подписка остаётся неоплаченной заявкой
    }
    $attempts = $s['failed_attempts'] + 1;
    if ($attempts >= $c['max_charge_attempts']) {
        $pdo->prepare("UPDATE subscriptions SET status = 'past_due', failed_attempts = ? WHERE id = ?")->execute([$attempts, $s['id']]);
        send_mail($c['mail_to'], "Не удалось списать оплату: подписка №{$s['id']} приостановлена",
            "После $attempts попыток оплата не прошла. Свяжитесь с клиентом.\n\n$info");
        send_mail($s['email'], 'Не удалось оплатить подписку',
            "Здравствуйте, {$s['name']}!\n\nНам не удалось списать {$s['price']} ₽ за следующий месяц подписки, поэтому она приостановлена. "
            . "Свяжитесь с нами, и мы поможем её возобновить.\n\nДикоросы большой страны");
    } else {
        // Повторим завтра
        $retry = (new DateTimeImmutable('now'))->modify('+1 day')->format('Y-m-d H:i:s');
        $pdo->prepare('UPDATE subscriptions SET failed_attempts = ?, next_charge_at = ? WHERE id = ?')->execute([$attempts, $retry, $s['id']]);
    }
}
