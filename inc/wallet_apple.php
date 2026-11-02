<?php
/* =====================================================================
 *  APPLE WALLET - generazione file .pkpass (Store Card)
 *  Il pass contiene: QR del codice tessera, timbri attuali, premio.
 *  Per vedere i timbri aggiornati il cliente riscarica il pass dalla
 *  pagina della tessera (l'aggiornamento "push" automatico richiede
 *  anche il web service Apple + APNs, non incluso).
 * ===================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function apple_wallet_ready(): bool
{
    return APPLE_WALLET_ENABLED && is_readable(APPLE_CERT_P12) && is_readable(APPLE_WWDR_PEM)
        && class_exists('ZipArchive');
}

function apple_pass_json(array $customer, array $card): array
{
    $req = (int)$card['stamps_required'];
    $st  = (int)$customer['stamps'];
    $bg  = $st >= $req ? '#0e9f63' : $card['color'];   // verde quando il premio è pronto
    $fg  = contrast_color($bg);
    return [
        'formatVersion'      => 1,
        'passTypeIdentifier' => APPLE_PASS_TYPE_ID,
        'teamIdentifier'     => APPLE_TEAM_ID,
        'serialNumber'       => $customer['code'],
        'organizationName'   => $card['name'],
        'description'        => 'Carta fedeltà ' . $card['name'],
        'logoText'           => $card['name'],
        'backgroundColor'    => hex_to_rgb($bg),
        'foregroundColor'    => hex_to_rgb($fg),
        'labelColor'         => hex_to_rgb($fg),
        'storeCard'          => [
            'primaryFields'   => [[
                'key' => 'stamps', 'label' => 'TIMBRI', 'value' => "$st / $req",
                'changeMessage' => 'Timbri: %@',
            ]],
            'secondaryFields' => [[
                'key' => 'name', 'label' => 'CLIENTE', 'value' => $customer['name'],
            ]],
            'auxiliaryFields' => [[
                'key' => 'reward', 'label' => 'PREMIO',
                'value' => $st >= $req ? 'PRONTO! ' . $card['reward_text'] : 'Ancora ' . ($req - $st) . ' per: ' . $card['reward_text'],
            ]],
            'backFields'      => [
                ['key' => 'code', 'label' => 'Codice tessera', 'value' => $customer['code']],
                ['key' => 'link', 'label' => 'La tua tessera online', 'value' => tessera_url($customer['code'])],
                ['key' => 'rules', 'label' => 'Regolamento', 'value' => 'Ogni ' . $req . ' ' . $card['unit_plural'] . ' ricevi: ' . $card['reward_text'] . '. Mostra il QR in negozio a ogni visita.'],
            ],
        ],
        'barcodes' => [[
            'format'          => 'PKBarcodeFormatQR',
            'message'         => $customer['code'],
            'messageEncoding' => 'iso-8859-1',
            'altText'         => $customer['code'],
        ]],
    ];
}

/** Crea il file .pkpass e ne ritorna il contenuto binario */
function apple_build_pkpass(array $customer, array $card): string
{
    $p12 = file_get_contents(APPLE_CERT_P12);
    $certs = [];
    if (!openssl_pkcs12_read($p12, $certs, APPLE_CERT_PASSWORD)) {
        throw new RuntimeException('Impossibile leggere il certificato .p12 (password errata o formato non supportato da OpenSSL 3: vedi README).');
    }

    $tmp = sys_get_temp_dir() . '/pkpass_' . bin2hex(random_bytes(6));
    mkdir($tmp, 0700);

    $files = [
        'pass.json' => json_encode(apple_pass_json($customer, $card), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    foreach (['icon.png', 'icon@2x.png', 'logo.png', 'logo@2x.png'] as $img) {
        $files[$img] = file_get_contents(__DIR__ . '/../assets/pass/' . $img);
    }

    $manifest = [];
    foreach ($files as $name => $content) {
        file_put_contents("$tmp/$name", $content);
        $manifest[$name] = sha1($content);
    }
    file_put_contents("$tmp/manifest.json", json_encode($manifest, JSON_UNESCAPED_SLASHES));

    $ok = openssl_pkcs7_sign(
        "$tmp/manifest.json",
        "$tmp/signature.pem",
        $certs['cert'],
        [$certs['pkey'], APPLE_CERT_PASSWORD],
        [],
        PKCS7_BINARY | PKCS7_DETACHED,
        APPLE_WWDR_PEM
    );
    if (!$ok) throw new RuntimeException('Firma del pass fallita: ' . openssl_error_string());

    // Da S/MIME (PEM) a DER
    $smime = file_get_contents("$tmp/signature.pem");
    $start = strpos($smime, 'filename="smime.p7s"') + strlen('filename="smime.p7s"');
    $sig   = substr($smime, $start);
    $sig   = substr($sig, 0, strpos($sig, '------'));
    file_put_contents("$tmp/signature", base64_decode(trim($sig)));

    $zipPath = "$tmp/pass.pkpass";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (array_keys($files) as $name) $zip->addFile("$tmp/$name", $name);
    $zip->addFile("$tmp/manifest.json", 'manifest.json');
    $zip->addFile("$tmp/signature", 'signature');
    $zip->close();

    $data = file_get_contents($zipPath);
    array_map('unlink', glob("$tmp/*"));
    rmdir($tmp);
    return $data;
}
