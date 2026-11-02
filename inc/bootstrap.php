<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('FIDSESS');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* ---------------- Database ---------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

/* ---------------- Helper vari ---------------- */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function random_password(int $len = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/** Codice univoco della tessera: FC + 10 caratteri senza simboli ambigui */
function generate_card_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'FC';
        for ($i = 0; $i < 10; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $st = db()->prepare('SELECT 1 FROM customers WHERE code = ?');
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}

function tessera_url(string $code): string
{
    return url('tessera.php?c=' . urlencode($code));
}

function valid_hex_color(string $c): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#1f2937';
}

function time_ago(?string $dt): string
{
    if (!$dt) return 'mai';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'adesso';
    if ($diff < 3600)   return floor($diff / 60) . ' min fa';
    if ($diff < 86400)  return floor($diff / 3600) . ' ore fa';
    if ($diff < 172800) return 'ieri';
    return date('d/m/Y', strtotime($dt));
}

/* ---------------- CSRF ---------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Richiesta non valida (token scaduto). Torna indietro e ricarica la pagina.');
    }
}

/* ---------------- Autenticazione ---------------- */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, username, role, card_id, full_name FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('index.php');
    }
    return $u;
}

function is_admin(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && $u['role'] === 'admin';
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) {
        http_response_code(403);
        exit('Accesso riservato all\'amministratore.');
    }
    return $u;
}

/** Il barbiere può accedere solo alla sua carta, l'admin a tutte */
function can_access_card(array $u, int $cardId): bool
{
    return is_admin($u) || (int)$u['card_id'] === $cardId;
}

function load_card(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM cards WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function load_customer(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function load_customer_by_code(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM customers WHERE code = ?');
    $st->execute([strtoupper(trim($code))]);
    return $st->fetch() ?: null;
}

/* ---------------- Colori ---------------- */
function hex_to_rgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    return sprintf('rgb(%d, %d, %d)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

/** Testo bianco o nero a seconda della luminosità dello sfondo */
function contrast_color(string $hex): string
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111111' : '#ffffff';
}

/* ---------------- Elementi grafici condivisi ---------------- */
/** Icone SVG a tratto (ereditano il colore del testo) */
function icon(string $name, int $size = 22): string
{
    $p = [
        'scissors' => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/>',
        'camera'   => '<path d="M3 8a2 2 0 0 1 2-2h2l2-2h6l2 2h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.5"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'minus'    => '<path d="M5 12h14"/>',
        'home'     => '<path d="M4 11 12 4l8 7v9H4z"/><path d="M10 20v-5h4v5"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
        'logout'   => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'gift'     => '<rect x="3" y="9" width="18" height="12" rx="1"/><path d="M3 13h18M12 9v12M12 9c-2-4-6-4-6-1.5S10 9 12 9c2 0 6 .5 6-1.5S14 5 12 9z"/>',
        'reset'    => '<path d="M4 12a8 8 0 1 0 2.5-5.8L4 8.5"/><path d="M4 4v4.5h4.5"/>',
        'check'    => '<path d="m5 12 5 5 9-10"/>',
        'back'     => '<path d="M15 5 8 12l7 7"/>',
        'share'    => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
        'trash'    => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'chat'     => '<path d="M4 20l1.5-4A8 8 0 1 1 8 19z"/>',
        'eye'      => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'x'        => '<path d="M6 6l12 12M18 6 6 18"/>',
    ][$name] ?? '';
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Griglia dei timbri: cerchi pieni con le forbici, l'ultimo è il premio */
function stamp_grid(int $stamps, int $required, string $extraClass = ''): string
{
    $cols = $required <= 5 ? $required : ($required <= 12 ? (int)ceil($required / 2) : 6);
    $h = '<div class="tokens ' . e($extraClass) . '" style="--cols:' . $cols . '" role="img" aria-label="' . $stamps . ' timbri su ' . $required . '">';
    for ($i = 1; $i <= $required; $i++) {
        $on = $i <= $stamps;
        $last = $i === $required;
        $h .= '<span class="token' . ($on ? ' on' : '') . ($last ? ' prize' : '') . '">'
            . ($on ? icon($last ? 'gift' : 'scissors', 18) : ($last ? icon('gift', 18) : '<i>' . $i . '</i>'))
            . '</span>';
    }
    return $h . '</div>';
}

/** Barretta compatta di puntini per le liste */
function pips(int $stamps, int $required): string
{
    $h = '<span class="pips" aria-hidden="true">';
    for ($i = 1; $i <= $required; $i++) $h .= '<b class="' . ($i <= $stamps ? 'on' : '') . '"></b>';
    return $h . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $s = mb_substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '');
    return mb_strtoupper($s ?: '?');
}

/* ---------------- Tipi di attività ---------------- */
/** Preset proposti quando si crea una carta (tutto resta modificabile) */
function business_presets(): array
{
    return [
        'barbiere'   => ['label' => 'Barbiere / parrucchiere', 'icon' => '✂️', 'one' => 'taglio',      'many' => 'tagli',       'reward' => 'Taglio gratis'],
        'bar'        => ['label' => 'Bar / caffetteria',        'icon' => '☕', 'one' => 'caffè',       'many' => 'caffè',       'reward' => 'Caffè gratis'],
        'estetica'   => ['label' => 'Estetista / nail',         'icon' => '💅', 'one' => 'trattamento', 'many' => 'trattamenti', 'reward' => 'Trattamento gratis'],
        'pizzeria'   => ['label' => 'Pizzeria / ristorante',    'icon' => '🍕', 'one' => 'pizza',       'many' => 'pizze',       'reward' => 'Pizza gratis'],
        'lavaggio'   => ['label' => 'Autolavaggio',             'icon' => '🚗', 'one' => 'lavaggio',    'many' => 'lavaggi',     'reward' => 'Lavaggio gratis'],
        'palestra'   => ['label' => 'Palestra / corsi',         'icon' => '💪', 'one' => 'ingresso',    'many' => 'ingressi',    'reward' => 'Ingresso gratis'],
        'altro'      => ['label' => 'Altro',                    'icon' => '⭐', 'one' => 'visita',      'many' => 'visite',      'reward' => 'Premio'],
    ];
}

function unit(array $card, int $n): string
{
    return $n === 1 ? $card['unit_singular'] : $card['unit_plural'];
}

/** Disegna la carta a timbri (usata da gestore e cliente) */
function stamp_card_html(array $card, array $customer, string $title, string $subtitle = ''): string
{
    $req   = (int)$card['stamps_required'];
    $s     = (int)$customer['stamps'];
    $ready = $s >= $req;
    $fg    = contrast_color($card['color']);
    $h  = '<div class="scard' . ($ready ? ' is-ready' : '') . '" style="--c:' . e($card['color']) . ';--fg:' . e($fg) . '">';
    $h .= '<div class="scard-head"><div><div class="scard-title">' . e($title) . '</div>';
    if ($subtitle !== '') $h .= '<div class="scard-sub">' . e($subtitle) . '</div>';
    $h .= '</div><div class="scard-count"><b>' . $s . '</b><span>/' . $req . '</span></div></div>';
    $h .= '<div class="stamps" style="--cols:' . ($req <= 6 ? $req : ($req <= 10 ? 5 : 6)) . '">';
    for ($i = 1; $i <= $req; $i++) {
        $rot = (($i * 37) % 23) - 11;             // leggera rotazione "a mano", sempre uguale
        $on  = $i <= $s;
        $cls = 'stamp' . ($on ? ' on' : '') . ($i === $req ? ' last' : '');
        $h .= '<span class="' . $cls . '" style="--r:' . $rot . 'deg">' . ($on ? e($card['icon']) : ($i === $req ? '🎁' : $i)) . '</span>';
    }
    $h .= '</div>';
    $left = $req - $s;
    $h .= '<div class="scard-foot">' . ($ready
        ? 'Carta completa: ' . e(mb_strtolower($card['reward_text']))
        : 'Ancora ' . $left . ' ' . e(unit($card, $left)) . ' per: ' . e(mb_strtolower($card['reward_text']))) . '</div>';
    $h .= '</div>';
    return $h;
}
