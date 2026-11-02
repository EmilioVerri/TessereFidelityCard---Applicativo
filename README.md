# Fidelity: carte fedeltà a timbri con QR, Google Wallet e Apple Wallet realizzato da Emilio Verri

Va bene per qualsiasi attività: barbiere, bar, estetista, pizzeria, autolavaggio, palestra… Ogni attività sceglie icona, premio e cosa vale un timbro (taglio, caffè, visita…).

Requisiti: PHP 8.1+ (estensioni pdo_mysql, openssl, curl, zip), MySQL/MariaDB, **HTTPS** (serve per usare la fotocamera dal telefono).

## Installazione su Altervista (già configurato per zetaservizi)
1. Pannello Altervista: imposta **PHP 8.1 o superiore** e attiva **HTTPS** (serve per la fotocamera del telefono).
2. Carica via FTP tutto il contenuto di questa cartella nella cartella principale del sito (oppure in una sottocartella: l'indirizzo viene calcolato da solo).
3. phpMyAdmin → clicca **my_zetaservizi** a sinistra → **Importa** → `DATABASE_DA_IMPORTARE.sql` → **Esegui**.
4. Apri `https://zetaservizi.altervista.org` e accedi.

`config.php` è già pronto: database `my_zetaservizi`, utente `zetaservizi`, password vuota (standard Altervista).
**Avevi già importato una versione precedente con clienti veri?** Non reimportare: importa solo `upgrade.sql`.
**Errore 500?** Rinomina `.htaccess` in `htaccess.txt`.

## Accessi già creati
| Ruolo | Utente | Password |
|---|---|---|
| Amministratore | `admin` | `Admin@2026` |
| Gestore demo (Barberia Demo) | `barbiere` | `Barbiere@2026` |

Cambiale subito da **Account**.

## Come funziona
- **Admin**: vede tutte le attività e tutti i clienti. Con "Nuova attività" sceglie il tipo (barbiere, bar, estetista…), crea la carta **e** l'accesso del gestore (password generata, mostrata una volta).
- **Gestore**: vede solo la sua attività. Lista clienti con i timbri di ognuno, quelli con premio pronto in verde in cima. Decide ogni quanti timbri c'è il premio.
- **Controllo e timbro**: tasto fotocamera, inquadra QR o codice a barre: compare subito la carta del cliente con quanti timbri ha. Poi "Aggiungi timbro". Avvisa se è stato timbrato negli ultimi 5 minuti.
- **Premio pronto**: quando il cliente arriva al numero di timbri (es. 10) si apre la **schermata verde a tutto schermo** "Taglio gratis!" con il pulsante **Reset carta**: dopo aver dato il premio lo premi e la carta riparte da zero. C'è anche un Reset manuale nella scheda cliente.
- **Cliente**: il gestore gli manda il link con WhatsApp o Condividi. Il cliente vede i suoi timbri; quando raggiunge il traguardo **tutta la pagina diventa verde** con "il premio è tuo". La pagina si aggiorna da sola ogni 8 secondi quando riceve un timbro.

## Google Wallet (facoltativo)
1. https://pay.google.com/business/console → attiva Google Wallet API → copia l'**Issuer ID**.
2. Google Cloud: abilita "Google Wallet API", crea un **Service Account**, scarica la chiave **JSON**.
3. Nella Wallet console aggiungi l'email del service account come utente.
4. Salva il JSON come `private/google-service-account.json`, in `config.php` metti Issuer ID e `GOOGLE_WALLET_ENABLED = true`.

I timbri si aggiornano **da soli** nel Wallet dopo ogni scansione. In modalità demo il pass funziona solo per gli account di test finché Google non approva l'emittente (richiesta di pubblicazione dalla console).

## Apple Wallet (facoltativo — serve Apple Developer Program)
1. developer.apple.com → Identifiers → **Pass Type ID** (es. `pass.it.miosito.fidelity`) → crea il certificato.
2. Esportalo dal Portachiavi del Mac come `.p12` con password → `private/pass-certificate.p12`.
3. Scarica il certificato **Apple WWDR G4**, convertilo in PEM:
   `openssl x509 -inform der -in AppleWWDRCAG4.cer -out private/AppleWWDRCAG4.pem`
4. In `config.php` metti Pass Type ID, Team ID, password del .p12 e `APPLE_WALLET_ENABLED = true`.

Se `openssl_pkcs12_read` fallisce (OpenSSL 3 e .p12 vecchi), riconverti il p12:
```
openssl pkcs12 -in cert.p12 -legacy -nodes -out tmp.pem
openssl pkcs12 -export -in tmp.pem -out private/pass-certificate.p12
```
Su iPhone i timbri si vedono aggiornati riscaricando il pass dalla pagina tessera (l'aggiornamento push automatico richiede il web service Apple + APNs, non incluso).

## Sicurezza
Password con `password_hash` (bcrypt), query preparate PDO, token CSRF su ogni form, blocco dopo 8 login falliti in 15 minuti, `.htaccess` che blocca `inc/`, `private/`, `config.php` e i file .sql. Il barbiere non può aprire né timbrare tessere di altri negozi (controllo lato server).

## File
```
index.php        home + login (chiede subito la password)
dashboard.php    pannello admin (il barbiere viene mandato alla sua carta)
card.php         carta: clienti, nuovo cliente, impostazioni, utenza barbiere
customer.php     scheda cliente: timbro, premio, QR/link, storico
scan.php         scanner QR / codice a barre dalla fotocamera
api/stamp.php    API per lo scanner (controlla, timbra, reset)
api/public.php   API di sola lettura per l'aggiornamento automatico della tessera
tessera.php      pagina pubblica della tessera del cliente
wallet/          google.php (link Google Wallet), apple.php (.pkpass)
inc/             bootstrap, logica timbri, Google/Apple Wallet, header/footer
assets/          css, js, librerie QR/barcode/scanner, immagini del pass
```
