-- =====================================================================
--  FIDELITY CARD BARBIERI - Database MySQL / MariaDB
--  Importa questo file da phpMyAdmin (scheda "Importa").
--  Versione per Altervista: importala DENTRO il database my_zetaservizi.
-- =====================================================================


SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS stamp_log;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS cards;
DROP TABLE IF EXISTS login_attempts;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Carte fedeltà (una per ogni attività: barbiere, bar, estetista...)
-- ---------------------------------------------------------------------
CREATE TABLE cards (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(100) NOT NULL,
  color           CHAR(7)      NOT NULL DEFAULT '#1f2937',
  stamps_required TINYINT UNSIGNED NOT NULL DEFAULT 10,   -- ogni quanti tagli uno è gratis
  reward_text     VARCHAR(150) NOT NULL DEFAULT 'Taglio gratis',
  icon            VARCHAR(16)  NOT NULL DEFAULT '✂️',             -- icona dei timbri
  unit_singular   VARCHAR(30)  NOT NULL DEFAULT 'taglio',         -- es. taglio, caffè, visita
  unit_plural     VARCHAR(30)  NOT NULL DEFAULT 'tagli',
  address         VARCHAR(150) NULL,
  phone           VARCHAR(30)  NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Utenze: admin (vede tutto) e barber = gestore attività (vede solo la sua carta)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','barber') NOT NULL DEFAULT 'barber',
  card_id       INT UNSIGNED NULL,
  full_name     VARCHAR(100) NULL,
  last_login    DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username),
  KEY idx_card (card_id),
  CONSTRAINT fk_users_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Clienti che possiedono una carta (tessera)
-- ---------------------------------------------------------------------
CREATE TABLE customers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  card_id          INT UNSIGNED NOT NULL,
  code             VARCHAR(20)  NOT NULL,          -- codice nel QR / barcode
  name             VARCHAR(100) NOT NULL,
  phone            VARCHAR(30)  NULL,
  email            VARCHAR(120) NULL,
  stamps           SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- timbri attuali
  total_stamps     INT UNSIGNED NOT NULL DEFAULT 0,       -- timbri totali storici
  rewards_redeemed INT UNSIGNED NOT NULL DEFAULT 0,       -- tagli gratis riscattati
  last_visit       DATETIME     NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code),
  KEY idx_card (card_id),
  CONSTRAINT fk_customers_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Storico timbrature
-- ---------------------------------------------------------------------
CREATE TABLE stamp_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NULL,
  action       ENUM('stamp','unstamp','redeem','reset') NOT NULL,
  stamps_after SMALLINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customer (customer_id, created_at),
  CONSTRAINT fk_log_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tentativi di login (protezione brute-force)
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45) NOT NULL,
  username     VARCHAR(50) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  DATI INIZIALI
--  Admin      ->  utente: admin      password: Admin@2026
--  Demo       ->  utente: barbiere   password: Barbiere@2026
--  (cambiale subito dal menu "Account")
-- =====================================================================
INSERT INTO cards (id, name, color, stamps_required, reward_text, icon, unit_singular, unit_plural, address, phone) VALUES
 (1, 'Barberia Demo', '#1d4ed8', 10, 'Taglio gratis', '✂️', 'taglio', 'tagli', 'Via Roma 1', '0342 000000');

INSERT INTO users (username, password_hash, role, card_id, full_name) VALUES
 ('admin',    '$2y$10$tjvcfaCD24c8Xmp85x1qR.qv9YOa52tRmVrh7LGW3irXXwtg0Tx9W', 'admin',  NULL, 'Amministratore'),
 ('barbiere', '$2y$10$odOfgpSJrScpobikko/7RuMWolOgJNAQnG0U22zU55F8TFi5OWyRS', 'barber', 1,    'Barbiere Demo');

INSERT INTO customers (card_id, code, name, phone, stamps, total_stamps, last_visit) VALUES
 (1, 'FC7K3M9QPX2A', 'Mario Rossi',   '333 1234567', 4,  4,  NOW()),
 (1, 'FC4T8WZ2NHB6', 'Luca Bianchi',  '333 7654321', 10, 10, NOW());
