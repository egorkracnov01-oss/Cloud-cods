<?php
// Отмена подписки по ссылке из письма. Уже оплаченный месяц доставляется.
require __DIR__ . '/lib.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$s = null;
if (preg_match('/^[0-9a-f]{32}$/', $token)) {
    $st = db()->prepare('SELECT * FROM subscriptions WHERE cancel_token = ?');
    $st->execute([$token]);
    $s = $st->fetch() ?: null;
}

$message = null;
if ($s && $_SERVER['REQUEST_METHOD'] === 'POST' && $s['status'] !== 'canceled') {
    db()->prepare("UPDATE subscriptions SET status = 'canceled' WHERE id = ?")->execute([$s['id']]);
    send_mail(config()['mail_to'], "Клиент отменил подписку №{$s['id']}", subscription_text($s));
    $s['status'] = 'canceled';
    $message = 'Подписка отменена. Новых списаний не будет, а уже оплаченный набор мы доставим.';
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$page = config()['page_path'];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Отмена подписки — Дикоросы большой страны</title>
  <style>
    body { margin: 0; font-family: Nunito, system-ui, sans-serif; background: #eef6e3; color: #2c3324; }
    main { max-width: 480px; margin: 64px auto; padding: 32px 24px; background: #fff; border-radius: 28px; text-align: center; }
    h1 { color: #2c5216; font-size: 1.6rem; }
    button, a.btn { display: inline-block; font: inherit; font-weight: 800; padding: 14px 30px; border-radius: 999px; border: 0; background: #5f9e32; color: #fff; text-decoration: none; cursor: pointer; }
  </style>
</head>
<body>
<main>
<?php if (!$s): ?>
  <h1>Ссылка недействительна</h1>
  <p>Проверьте ссылку из письма или свяжитесь с нами.</p>
<?php elseif ($message || $s['status'] === 'canceled'): ?>
  <h1>Подписка отменена</h1>
  <p><?= $h($message ?? 'Эта подписка уже отменена.') ?></p>
  <a class="btn" href="<?= $h($page) ?>">На страницу подписки</a>
<?php else: ?>
  <h1>Отменить подписку №<?= $h($s['id']) ?>?</h1>
  <p>Набор «<?= $h(config()['tiers'][$s['tier']]['name']) ?>», <?= $h($s['price']) ?> ₽ в месяц. Новых списаний не будет, уже оплаченный набор мы доставим.</p>
  <form method="post">
    <input type="hidden" name="token" value="<?= $h($token) ?>">
    <button type="submit">Отменить подписку</button>
  </form>
<?php endif; ?>
</main>
</body>
</html>
