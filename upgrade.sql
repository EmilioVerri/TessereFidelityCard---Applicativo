-- =====================================================================
--  AGGIORNAMENTO per chi ha già importato la prima versione del database
--  phpMyAdmin -> seleziona il database "my_zetaservizi" -> Importa questo file.
--  (Chi installa da zero usa solo DATABASE_DA_IMPORTARE.sql e NON serve questo file.)
-- =====================================================================
ALTER TABLE cards
  ADD COLUMN icon          VARCHAR(16) NOT NULL DEFAULT '✂️'   AFTER reward_text,
  ADD COLUMN unit_singular VARCHAR(30) NOT NULL DEFAULT 'taglio' AFTER icon,
  ADD COLUMN unit_plural   VARCHAR(30) NOT NULL DEFAULT 'tagli'  AFTER unit_singular;


ALTER TABLE stamp_log
  MODIFY action ENUM('stamp','unstamp','redeem','reset') NOT NULL;
