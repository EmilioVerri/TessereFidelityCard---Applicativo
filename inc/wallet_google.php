<?php
/* =====================================================================
 *  GOOGLE WALLET - Loyalty Card
 *  - Link "Aggiungi a Google Wallet" con JWT firmato (RS256)
 *  - Aggiornamento automatico dei punti dopo ogni timbro (REST API)
 *  Nessuna libreria esterna: solo openssl + curl di PHP.
 * ===================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function google_wallet_ready(): bool
{
    return GOOGLE_WALLET_ENABLED && is_readable(GOOGLE_SA_KEYFILE);
}

function google_sa(): array
{
    static $sa = null;
    if ($sa === null) {
        $sa = json_decode((string)file_get_contents(GOOGLE_SA_KEYFILE), true);
        if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) {
            throw new RuntimeException('File service account Google non valido.');
        }
    }
    return $sa;
}

function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwt_rs256(array $payload, string $privateKey): string
{
    $header = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $body   = b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $sig    = '';
    if (!openssl_sign("$header.$body", $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Firma JWT fallita.');
    }
    return "$header.$body." . b64url($sig);
}

function gw_class_id(array $card): string
{
    return GOOGLE_ISSUER_ID . '.fidelity_card_' . (int)$card['id'];
}

function gw_object_id(array $customer): string
{
    return GOOGLE_ISSUER_ID . '.fidelity_cust_' . preg_replace('/[^A-Za-z0-9_.-]/', '', $customer['code']);
}

function gw_points(array $customer, array $card): array
{
    return [
        'label'   => 'Timbri',
        'balance' => ['string' => (int)$customer['stamps'] . ' / ' . (int)$card['stamps_required']],
    ];
}

/** Verde quando il premio è pronto, altrimenti il colore della carta */
function gw_color(array $customer, array $card): string
{
    return (int)$customer['stamps'] >= (int)$card['stamps_required'] ? '#0e9f63' : $card['color'];
}

function gw_class(array $card): array
{
    return [
        'id'                 => gw_class_id($card),
        'issuerName'         => $card['name'],
        'programName'        => 'Carta Fedeltà',
        'programLogo'        => [
            'sourceUri'          => ['uri' => url('assets/logo.png')],
            'contentDescription' => ['defaultValue' => ['language' => 'it-IT', 'value' => $card['name']]],
        ],
        'hexBackgroundColor' => $card['color'],
        'reviewStatus'       => 'UNDER_REVIEW',
        'countryCode'        => 'IT',
    ];
}

function gw_object(array $customer, array $card): array
{
    return [
        'id'                 => gw_object_id($customer),
        'classId'            => gw_class_id($card),
        'state'              => 'ACTIVE',
        'accountId'          => $customer['code'],
        'accountName'        => $customer['name'],
        'loyaltyPoints'      => gw_points($customer, $card),
        'hexBackgroundColor' => gw_color($customer, $card),
        'barcode'            => [
            'type'          => 'QR_CODE',
            'value'         => $customer['code'],
            'alternateText' => $customer['code'],
        ],
        'textModulesData'    => [[
            'id'     => 'premio',
            'header' => 'Premio',
            'body'   => 'Ogni ' . (int)$card['stamps_required'] . ' ' . $card['unit_plural'] . ': ' . $card['reward_text'],
        ]],
        'linksModuleData'    => ['uris' => [[
            'uri'         => tessera_url($customer['code']),
            'description' => 'Vedi la tua tessera',
            'id'          => 'tessera',
        ]]],
    ];
}

/** URL "Aggiungi a Google Wallet" */
function google_wallet_save_url(array $customer, array $card): string
{
    $sa = google_sa();
    $jwt = jwt_rs256([
        'iss'     => $sa['client_email'],
        'aud'     => 'google',
        'typ'     => 'savetowallet',
        'iat'     => time(),
        'origins' => [parse_url(BASE_URL, PHP_URL_SCHEME) . '://' . parse_url(BASE_URL, PHP_URL_HOST)],
        'payload' => [
            'loyaltyClasses' => [gw_class($card)],
            'loyaltyObjects' => [gw_object($customer, $card)],
        ],
    ], $sa['private_key']);
    return 'https://pay.google.com/gp/v/save/' . $jwt;
}

/** Token OAuth2 del service account (in cache in sessione per 50 minuti) */
function google_access_token(): string
{
    if (!empty($_SESSION['gw_token']) && $_SESSION['gw_token_exp'] > time()) {
        return $_SESSION['gw_token'];
    }
    $sa  = google_sa();
    $now = time();
    $assertion = jwt_rs256([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ], $sa['private_key']);

    $res = http_request('POST', 'https://oauth2.googleapis.com/token', http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $assertion,
    ]), ['Content-Type: application/x-www-form-urlencoded']);

    $data = json_decode($res['body'], true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('Token Google non ottenuto: ' . $res['body']);
    }
    $_SESSION['gw_token']     = $data['access_token'];
    $_SESSION['gw_token_exp'] = $now + 3000;
    return $data['access_token'];
}

/** Aggiorna i timbri sul pass già salvato dal cliente (se non l'ha salvato, Google risponde 404 e si ignora) */
function google_wallet_update_points(array $customer, array $card): void
{
    if (!google_wallet_ready()) return;
    $token = google_access_token();
    $res = http_request(
        'PATCH',
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/' . rawurlencode(gw_object_id($customer)),
        json_encode(['loyaltyPoints' => gw_points($customer, $card), 'hexBackgroundColor' => gw_color($customer, $card)]),
        ['Content-Type: application/json', 'Authorization: Bearer ' . $token]
    );
    if ($res['code'] >= 400 && $res['code'] !== 404) {
        throw new RuntimeException('Google Wallet HTTP ' . $res['code'] . ': ' . $res['body']);
    }
}

function http_request(string $method, string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $out  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('Errore di rete: ' . $err);
    return ['code' => $code, 'body' => (string)$out];
}
