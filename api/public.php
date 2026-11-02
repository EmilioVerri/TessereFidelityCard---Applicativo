<?php
/* API pubblica di sola lettura: la pagina tessera del cliente la interroga
   ogni pochi secondi per aggiornarsi da sola quando il gestore mette un timbro. */
require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$code = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? ''));
$c = $code !== '' ? load_customer_by_code($code) : null;
if (!$c) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
$card = load_card((int)$c['card_id']);
echo json_encode([
    'ok'       => true,
    'stamps'   => (int)$c['stamps'],
    'required' => (int)$card['stamps_required'],
    'redeemed' => (int)$c['rewards_redeemed'],
]);
