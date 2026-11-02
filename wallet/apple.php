<?php
/* Scarica il file .pkpass per Apple Wallet */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/wallet_apple.php';

$customer = load_customer_by_code(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
if (!$customer || !apple_wallet_ready()) {
    http_response_code(404);
    exit('Non disponibile.');
}
$card = load_card((int)$customer['card_id']);

try {
    $pkpass = apple_build_pkpass($customer, $card);
} catch (Throwable $ex) {
    error_log('Apple Wallet: ' . $ex->getMessage());
    http_response_code(500);
    exit('Errore nella creazione del pass Apple Wallet.');
}

header('Content-Type: application/vnd.apple.pkpass');
header('Content-Disposition: attachment; filename="fidelity-' . $customer['code'] . '.pkpass"');
header('Content-Length: ' . strlen($pkpass));
header('Cache-Control: no-store');
echo $pkpass;
