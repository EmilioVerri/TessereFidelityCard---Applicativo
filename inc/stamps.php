<?php
/* Logica timbri: aggiungi, togli, reset (con o senza premio) */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/wallet_google.php';

/**
 * Esegue un'azione sulla tessera.
 * 'stamp'   +1 timbro (fino al numero richiesto)
 * 'unstamp' -1 timbro
 * 'reset'   azzera la carta. Se era completa conta come premio consegnato.
 * Ritorna il cliente aggiornato con in più la chiave 'event':
 *   'completed' = ha appena raggiunto il traguardo, 'redeemed' = premio usato, 'reset', 'stamped', 'unstamped'
 */
function card_action(array $customer, array $card, string $action, int $userId): array
{
    if ($action === 'redeem') $action = 'reset';   // compatibilità

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM customers WHERE id = ? FOR UPDATE');
        $st->execute([$customer['id']]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('Cliente non trovato.');

        $required = (int)$card['stamps_required'];
        $stamps   = (int)$c['stamps'];
        $logAction = $action;

        switch ($action) {
            case 'stamp':
                if ($stamps >= $required) {
                    throw new RuntimeException('La carta è già completa: consegna il premio e premi Reset.');
                }
                $pdo->prepare('UPDATE customers SET stamps = stamps + 1, total_stamps = total_stamps + 1, last_visit = NOW() WHERE id = ?')
                    ->execute([$c['id']]);
                $stamps++;
                $event = $stamps >= $required ? 'completed' : 'stamped';
                break;

            case 'unstamp':
                if ($stamps <= 0) throw new RuntimeException('Non ci sono timbri da togliere.');
                $pdo->prepare('UPDATE customers SET stamps = stamps - 1, total_stamps = GREATEST(total_stamps - 1, 0) WHERE id = ?')
                    ->execute([$c['id']]);
                $stamps--;
                $event = 'unstamped';
                break;

            case 'reset':
                if ($stamps >= $required) {
                    $pdo->prepare('UPDATE customers SET stamps = 0, rewards_redeemed = rewards_redeemed + 1, last_visit = NOW() WHERE id = ?')
                        ->execute([$c['id']]);
                    $logAction = 'redeem';
                    $event = 'redeemed';
                } else {
                    $pdo->prepare('UPDATE customers SET stamps = 0 WHERE id = ?')->execute([$c['id']]);
                    $event = 'reset';
                }
                $stamps = 0;
                break;

            default:
                throw new RuntimeException('Azione non valida.');
        }

        $pdo->prepare('INSERT INTO stamp_log (customer_id, user_id, action, stamps_after) VALUES (?, ?, ?, ?)')
            ->execute([$c['id'], $userId, $logAction, $stamps]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    $updated = load_customer((int)$customer['id']);
    $updated['event'] = $event;

    try {
        google_wallet_update_points($updated, $card);
    } catch (Throwable $ex) {
        error_log('Google Wallet update: ' . $ex->getMessage());
    }

    return $updated;
}

/** Secondi dall'ultimo timbro (per avvisare di doppie scansioni) */
function seconds_since_last_stamp(int $customerId): ?int
{
    $st = db()->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM stamp_log
                         WHERE customer_id = ? AND action = 'stamp' ORDER BY id DESC LIMIT 1");
    $st->execute([$customerId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}
