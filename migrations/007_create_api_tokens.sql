-- =====================================================================
-- Migration 007: `api_tokens` – Token-Authentifizierung fuer Mobile-App
-- Es wird nur der SHA-256-Hash des Tokens gespeichert.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT NOT NULL,
  `token_hash`   VARCHAR(255) NOT NULL,
  `device_name`  VARCHAR(100) DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_api_token_hash` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`),
  CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
