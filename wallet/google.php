<?php
/* Reindirizza al link "Salva su Google Wallet" */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/wallet_google.php';

$customer = load_customer_by_code(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
if (!$customer || !google_wallet_ready()) {
    http_response_code(404);
    exit('Non disponibile.');
}
$card = load_card((int)$customer['card_id']);

try {
    header('Location: ' . google_wallet_save_url($customer, $card));
} catch (Throwable $ex) {
    error_log('Google Wallet: ' . $ex->getMessage());
    http_response_code(500);
    echo 'Errore nella creazione del pass Google Wallet.';
}
