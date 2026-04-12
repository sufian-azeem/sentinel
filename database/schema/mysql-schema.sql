/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `executed_trades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `executed_trades` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signal_id` bigint unsigned DEFAULT NULL,
  `exchange` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pair` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `side` enum('long','short') COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_type` enum('market','limit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `leverage` tinyint NOT NULL DEFAULT '1',
  `quantity` decimal(20,8) NOT NULL,
  `notional_usd` decimal(20,8) NOT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `entry_filled_at` datetime DEFAULT NULL,
  `entry_fill_status` enum('pending','partial','filled','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `entry_fill_qty` decimal(20,8) DEFAULT NULL,
  `entry_fee` decimal(20,8) DEFAULT NULL,
  `sl_price` decimal(20,8) DEFAULT NULL,
  `sl_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tp1_price` decimal(20,8) DEFAULT NULL,
  `tp1_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tp2_price` decimal(20,8) DEFAULT NULL,
  `tp2_order_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `breakeven_moved_at` datetime DEFAULT NULL,
  `trailing_tp_json` json DEFAULT NULL,
  `exit_price` decimal(20,8) DEFAULT NULL,
  `exit_filled_at` datetime DEFAULT NULL,
  `exit_fee` decimal(20,8) DEFAULT NULL,
  `total_fees_usd` decimal(20,8) DEFAULT NULL,
  `pnl_pct` decimal(8,4) DEFAULT NULL,
  `pnl_usd` decimal(20,8) DEFAULT NULL,
  `pnl_r` decimal(8,4) DEFAULT NULL,
  `status` enum('pending','open','closed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `executed_trades_signal_id_index` (`signal_id`),
  KEY `executed_trades_exchange_index` (`exchange`),
  KEY `executed_trades_status_index` (`status`),
  KEY `executed_trades_pair_index` (`pair`),
  CONSTRAINT `executed_trades_signal_id_foreign` FOREIGN KEY (`signal_id`) REFERENCES `signals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `screener_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `screener_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `screener_run_id` bigint unsigned NOT NULL,
  `symbol` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pair` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(20,8) NOT NULL,
  `rvol` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `score` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `alligator_tf` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bullish_count` tinyint NOT NULL DEFAULT '0',
  `confluence` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `qualified` tinyint NOT NULL DEFAULT '0',
  `disqualify_reason` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tf_data_json` json NOT NULL,
  `filters_json` json NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `screener_results_screener_run_id_index` (`screener_run_id`),
  KEY `screener_results_qualified_index` (`qualified`),
  CONSTRAINT `screener_results_screener_run_id_foreign` FOREIGN KEY (`screener_run_id`) REFERENCES `screener_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `screener_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `screener_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `data_source` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_scanned` int NOT NULL DEFAULT '0',
  `total_matched` int NOT NULL DEFAULT '0',
  `filters_json` json NOT NULL,
  `status` enum('running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signal_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signal_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signal_id` bigint unsigned NOT NULL,
  `status` enum('tp1_hit','tp2_hit','sl_hit','breakeven','expired','manual_close') COLLATE utf8mb4_unicode_ci NOT NULL,
  `exit_price` decimal(20,8) DEFAULT NULL,
  `exit_time` datetime DEFAULT NULL,
  `tp1_hit_price` decimal(20,8) DEFAULT NULL,
  `tp1_hit_at` datetime DEFAULT NULL,
  `tp2_hit_price` decimal(20,8) DEFAULT NULL,
  `tp2_hit_at` datetime DEFAULT NULL,
  `sl_hit_price` decimal(20,8) DEFAULT NULL,
  `sl_hit_at` datetime DEFAULT NULL,
  `breakeven_moved_at` datetime DEFAULT NULL,
  `trailing_tp_json` json DEFAULT NULL,
  `candles_to_exit` int DEFAULT NULL,
  `pnl_pct` decimal(8,4) DEFAULT NULL,
  `pnl_usd` decimal(20,8) DEFAULT NULL,
  `pnl_r` decimal(8,4) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `signal_outcomes_signal_id_index` (`signal_id`),
  CONSTRAINT `signal_outcomes_signal_id_foreign` FOREIGN KEY (`signal_id`) REFERENCES `signals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signal_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signal_scans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `screener_run_id` bigint unsigned NOT NULL,
  `screener_result_id` bigint unsigned DEFAULT NULL,
  `pair` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timeframe` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `strategy` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `candles_fetched` int DEFAULT NULL,
  `status` enum('scanned','skipped','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scanned',
  `conditions_json` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `signal_scans_screener_run_id_index` (`screener_run_id`),
  KEY `signal_scans_screener_result_id_index` (`screener_result_id`),
  CONSTRAINT `signal_scans_screener_result_id_foreign` FOREIGN KEY (`screener_result_id`) REFERENCES `screener_results` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signal_scans_screener_run_id_foreign` FOREIGN KEY (`screener_run_id`) REFERENCES `screener_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signal_scan_id` bigint unsigned NOT NULL,
  `pair` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timeframe` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `strategy` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `sl_price` decimal(20,8) DEFAULT NULL,
  `tp1_price` decimal(20,8) DEFAULT NULL,
  `tp2_price` decimal(20,8) DEFAULT NULL,
  `risk_pct` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `candle_time` datetime NOT NULL,
  `candles_ago` tinyint NOT NULL DEFAULT '1',
  `screener_score` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `confluence` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `conditions_json` json NOT NULL,
  `status` enum('active','tp1_hit','tp2_hit','sl_hit','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `signals_pair_index` (`pair`),
  KEY `signals_status_index` (`status`),
  KEY `signals_signal_scan_id_index` (`signal_scan_id`),
  CONSTRAINT `signals_signal_scan_id_foreign` FOREIGN KEY (`signal_scan_id`) REFERENCES `signal_scans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_04_04_151534_create_screener_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_04_04_151535_create_screener_results_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_04_04_151536_create_signal_scans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_04_04_151537_create_signals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_04_04_151538_create_signal_outcomes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_04_04_151539_create_executed_trades_table',1);
