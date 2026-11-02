<?php
/* =====================================================================
 *  CONFIGURAZIONE - modifica solo questo file
 * ===================================================================== */

// --- Database (gli stessi dati che usi per entrare in phpMyAdmin) ---
define('DB_HOST', 'localhost');
// Altervista: il database è "my_" + nome utente, l'utente è il nome utente, la password è vuota
define('DB_NAME', 'my_zetaservizi');
define('DB_USER', 'zetaservizi');
define('DB_PASS', '');

// --- Indirizzo pubblico del sito ---
// Viene calcolato da solo (funziona sia nella cartella principale sia in una sottocartella).
// Se vuoi fissarlo a mano scrivi l'indirizzo qui, senza "/" finale, es. 'https://zetaservizi.altervista.org'
$FORCE_BASE_URL = '';

if ($FORCE_BASE_URL !== '') {
    define('BASE_URL', $FORCE_BASE_URL);
} else {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = $_SERVER['HTTP_HOST'] ?? 'zetaservizi.altervista.org';
    $dir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir  = preg_replace('#/(api|wallet)$#', '', $dir);      // gli script dentro api/ e wallet/
    define('BASE_URL', ($https ? 'https' : 'http') . '://' . $host . rtrim($dir, '/'));
}

define('APP_NAME', 'Fidelity');   // nome del sito: cambialo come vuoi
date_default_timezone_set('Europe/Rome');

/* ---------------------------------------------------------------------
 *  GOOGLE WALLET (facoltativo)
 *  1. Crea un account su https://pay.google.com/business/console  -> Google Wallet API
 *  2. Prendi l'Issuer ID
 *  3. In Google Cloud crea un Service Account, scarica la chiave JSON,
 *     abilita "Google Wallet API" e aggiungi l'email del service account
 *     come utente nella Wallet console.
 *  4. Copia il file JSON in /private/google-service-account.json
 * --------------------------------------------------------------------- */
define('GOOGLE_WALLET_ENABLED', false);
define('GOOGLE_ISSUER_ID', '3388000000000000000');
define('GOOGLE_SA_KEYFILE', __DIR__ . '/private/google-service-account.json');

/* ---------------------------------------------------------------------
 *  APPLE WALLET (facoltativo, serve Apple Developer Program)
 *  1. Crea un Pass Type ID (es. pass.it.miosito.fidelity)
 *  2. Genera il certificato, esportalo come .p12 con password
 *  3. Scarica il certificato Apple WWDR (G4) in formato .pem
 *  4. Mettili in /private/
 * --------------------------------------------------------------------- */
define('APPLE_WALLET_ENABLED', false);
define('APPLE_PASS_TYPE_ID', 'pass.it.miosito.fidelity');
define('APPLE_TEAM_ID', 'ABCDE12345');
define('APPLE_CERT_P12', __DIR__ . '/private/pass-certificate.p12');
define('APPLE_CERT_PASSWORD', 'password-del-p12');
define('APPLE_WWDR_PEM', __DIR__ . '/private/AppleWWDRCAG4.pem');
