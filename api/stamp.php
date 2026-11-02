<?php
/* API JSON per gestore: lookup (controlla) / stamp / unstamp / reset */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/stamps.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) out(['ok' => false, 'error' => 'Sessione scaduta: accedi di nuovo.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Metodo non consentito'], 405);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals(csrf_token(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    out(['ok' => false, 'error' => 'Pagina scaduta: ricaricala.'], 403);
}

// Il QR può contenere il codice puro oppure il link della tessera (…tessera.php?c=CODICE)
$raw = trim((string)($in['code'] ?? ''));
if (preg_match('/[?&]c=([A-Za-z0-9]+)/', $raw, $m)) $raw = $m[1];
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
if ($code === '') out(['ok' => false, 'error' => 'Codice vuoto.']);

$customer = load_customer_by_code($code);
if (!$customer) out(['ok' => false, 'error' => 'Nessuna tessera con il codice ' . $code . '.']);
if (!can_access_card($user, (int)$customer['card_id'])) {
    out(['ok' => false, 'error' => 'Questa tessera è di un\'altra attività.']);
}
$card = load_card((int)$customer['card_id']);

$action = (string)($in['action'] ?? 'lookup');
$event = 'lookup';
try {
    if ($action !== 'lookup') {
        $customer = card_action($customer, $card, $action, (int)$user['id']);
        $event = $customer['event'];
    }
} catch (RuntimeException $ex) {
    out(['ok' => false, 'error' => $ex->getMessage()]);
}

$req = (int)$card['stamps_required'];
$s   = (int)$customer['stamps'];
out([
    'ok'         => true,
    'event'      => $event,          // lookup | stamped | completed | unstamped | redeemed | reset
    'id'         => (int)$customer['id'],
    'name'       => $customer['name'],
    'code'       => $customer['code'],
    'stamps'     => $s,
    'required'   => $req,
    'left'       => max(0, $req - $s),
    'ready'      => $s >= $req,
    'reward'     => $card['reward_text'],
    'icon'       => $card['icon'],
    'unit_one'   => $card['unit_singular'],
    'unit_many'  => $card['unit_plural'],
    'last_stamp' => seconds_since_last_stamp((int)$customer['id']),
    'card_html'  => stamp_card_html($card, $customer, $customer['name'], $card['name']),
    'detail_url' => url('customer.php?id=' . (int)$customer['id']),
]);
