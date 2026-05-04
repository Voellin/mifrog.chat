/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admin_operation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_operation_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned DEFAULT NULL,
  `admin_username` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_type` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `context` json DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_operation_logs_admin_user_id_created_at_index` (`admin_user_id`,`created_at`),
  KEY `admin_operation_logs_action_created_at_index` (`action`,`created_at`),
  KEY `admin_operation_logs_target_type_index` (`target_type`),
  CONSTRAINT `admin_operation_logs_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_permissions_permission_key_unique` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_user_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned NOT NULL,
  `admin_permission_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_user_permission_unique` (`admin_user_id`,`admin_permission_id`),
  KEY `admin_user_permissions_admin_permission_id_foreign` (`admin_permission_id`),
  CONSTRAINT `admin_user_permissions_admin_permission_id_foreign` FOREIGN KEY (`admin_permission_id`) REFERENCES `admin_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `admin_user_permissions_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `otp_secret` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_super_admin` tinyint(1) NOT NULL DEFAULT '0',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attachment_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attachment_chunks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attachment_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `chunk_index` int(10) unsigned NOT NULL DEFAULT '0',
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `keywords` json DEFAULT NULL,
  `token_estimate` int(10) unsigned NOT NULL DEFAULT '0',
  `embedding_source_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `embedding_vector` json DEFAULT NULL,
  `embedding_model` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attachment_chunks_attachment_id_foreign` (`attachment_id`),
  KEY `attachment_chunks_run_id_foreign` (`run_id`),
  KEY `attachment_chunks_user_id_attachment_id_chunk_index_index` (`user_id`,`attachment_id`,`chunk_index`),
  KEY `attachment_chunks_user_id_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `attachment_chunks_attachment_id_foreign` FOREIGN KEY (`attachment_id`) REFERENCES `attachments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attachment_chunks_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachment_chunks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `conversation_id` bigint(20) unsigned DEFAULT NULL,
  `message_id` bigint(20) unsigned DEFAULT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `source_channel` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'feishu',
  `source_message_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'file',
  `file_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_ext` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `storage_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_hash` char(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parse_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `parse_error` text COLLATE utf8mb4_unicode_ci,
  `parsed_at` timestamp NULL DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attachments_conversation_id_foreign` (`conversation_id`),
  KEY `attachments_message_id_foreign` (`message_id`),
  KEY `attachments_run_id_foreign` (`run_id`),
  KEY `attachments_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `attachments_user_id_parse_status_index` (`user_id`,`parse_status`),
  KEY `attachments_source_message_id_index` (`source_message_id`),
  KEY `attachments_content_hash_index` (`content_hash`),
  KEY `attachments_parse_status_index` (`parse_status`),
  CONSTRAINT `attachments_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachments_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachments_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `attachments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `priority` int(10) unsigned NOT NULL DEFAULT '100',
  `input_action` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'block',
  `output_action` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mask',
  `blocked_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_policies_department_id_foreign` (`department_id`),
  KEY `audit_policies_scope_active_idx` (`scope_type`,`department_id`,`is_active`),
  KEY `audit_policies_priority_idx` (`priority`,`id`),
  KEY `audit_policies_deleted_at_idx` (`deleted_at`),
  CONSTRAINT `audit_policies_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_policy_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_policy_terms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `policy_id` bigint(20) unsigned NOT NULL,
  `term` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_policy_terms_policy_active_idx` (`policy_id`,`is_active`),
  KEY `audit_policy_terms_term_idx` (`term`),
  CONSTRAINT `audit_policy_terms_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `audit_policies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `channel` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'feishu',
  `channel_conversation_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topic` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversations_user_id_foreign` (`user_id`),
  KEY `conversations_channel_conversation_id_index` (`channel_conversation_id`),
  CONSTRAINT `conversations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `feishu_department_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_feishu_department_id_unique` (`feishu_department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `memory_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memory_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `layer` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `session_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_file` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_date` date DEFAULT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `tags` json DEFAULT NULL,
  `keywords` json DEFAULT NULL,
  `embedding_source_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `embedding_vector` json DEFAULT NULL,
  `embedding_model` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_hash` char(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `expire_reason` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `memory_entries_run_id_foreign` (`run_id`),
  KEY `memory_entries_user_id_layer_created_at_index` (`user_id`,`layer`,`created_at`),
  KEY `memory_entries_user_id_session_key_created_at_index` (`user_id`,`session_key`,`created_at`),
  KEY `memory_entries_user_id_content_hash_index` (`user_id`,`content_hash`),
  KEY `memory_entries_layer_index` (`layer`),
  KEY `memory_entries_session_key_index` (`session_key`),
  KEY `memory_entries_source_date_index` (`source_date`),
  KEY `memory_entries_expired_at_index` (`expired_at`),
  CONSTRAINT `memory_entries_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memory_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `memory_facts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memory_facts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `source_entry_id` bigint(20) unsigned DEFAULT NULL,
  `last_run_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `fact` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `fact_hash` char(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` int(10) unsigned NOT NULL DEFAULT '50',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `memory_facts_user_id_fact_hash_unique` (`user_id`,`fact_hash`),
  KEY `memory_facts_source_entry_id_foreign` (`source_entry_id`),
  KEY `memory_facts_last_run_id_foreign` (`last_run_id`),
  KEY `memory_facts_user_id_category_is_active_index` (`user_id`,`category`,`is_active`),
  CONSTRAINT `memory_facts_last_run_id_foreign` FOREIGN KEY (`last_run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memory_facts_source_entry_id_foreign` FOREIGN KEY (`source_entry_id`) REFERENCES `memory_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memory_facts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `memory_retrieval_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memory_retrieval_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `query_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `retrieved_l3_fact_ids` json DEFAULT NULL,
  `retrieved_l2_entry_ids` json DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `memory_retrieval_logs_run_id_foreign` (`run_id`),
  KEY `memory_retrieval_logs_user_id_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `memory_retrieval_logs_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memory_retrieval_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `memory_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `memory_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `memory_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` text COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `memory_snapshots_run_id_foreign` (`run_id`),
  KEY `memory_snapshots_user_id_memory_type_index` (`user_id`,`memory_type`),
  CONSTRAINT `memory_snapshots_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `memory_snapshots_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `role` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_conversation_id_foreign` (`conversation_id`),
  KEY `messages_user_id_foreign` (`user_id`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider_id` bigint(20) unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `model_keys_provider_id_foreign` (`provider_id`),
  CONSTRAINT `model_keys_provider_id_foreign` FOREIGN KEY (`provider_id`) REFERENCES `model_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_providers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vendor_key` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_url` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_model` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `models` json DEFAULT NULL,
  `defaults` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(10) unsigned NOT NULL DEFAULT '100',
  `last_test_status` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_test_at` timestamp NULL DEFAULT NULL,
  `last_test_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `model_providers_vendor_key_unique` (`vendor_key`),
  KEY `model_providers_sort_order_idx` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `proactive_activity_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `proactive_activity_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `scan_window_minutes` smallint(5) unsigned NOT NULL DEFAULT '30',
  `calendar_data` json DEFAULT NULL,
  `messages_data` json DEFAULT NULL,
  `documents_data` json DEFAULT NULL,
  `meetings_data` json DEFAULT NULL,
  `sheets_data` json DEFAULT NULL,
  `bitables_data` json DEFAULT NULL,
  `mails_data` json DEFAULT NULL,
  `calendar_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `messages_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `documents_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `meetings_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `sheets_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `bitables_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `mails_count` smallint(5) unsigned NOT NULL DEFAULT '0',
  `has_activity` tinyint(1) NOT NULL DEFAULT '0',
  `llm_should_notify` tinyint(1) NOT NULL DEFAULT '0',
  `llm_reasoning` text COLLATE utf8mb4_unicode_ci,
  `llm_message` text COLLATE utf8mb4_unicode_ci,
  `activity_fingerprint` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_sent` tinyint(1) NOT NULL DEFAULT '0',
  `notification_sent_at` timestamp NULL DEFAULT NULL,
  `notification_message_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_channel` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skip_reason` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notification_error` text COLLATE utf8mb4_unicode_ci,
  `scanned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `proactive_activity_snapshots_user_id_index` (`user_id`),
  KEY `proactive_activity_snapshots_scanned_at_index` (`scanned_at`),
  KEY `proactive_activity_snapshots_activity_fingerprint_index` (`activity_fingerprint`),
  KEY `proactive_activity_snapshots_notification_sent_at_index` (`notification_sent_at`),
  KEY `proactive_activity_snapshots_skip_reason_index` (`skip_reason`),
  CONSTRAINT `proactive_activity_snapshots_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quota_alert_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quota_alert_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `period_key` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `level` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_period_level` (`user_id`,`period_key`,`level`),
  KEY `quota_alert_logs_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quota_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quota_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `token_limit` bigint(20) unsigned NOT NULL DEFAULT '0',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quota_policies_user_id_foreign` (`user_id`),
  KEY `quota_policies_department_id_user_id_period_index` (`department_id`,`user_id`,`period`),
  CONSTRAINT `quota_policies_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quota_policies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quota_usage_ledgers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quota_usage_ledgers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `used_tokens` bigint(20) unsigned NOT NULL DEFAULT '0',
  `period_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quota_usage_ledgers_department_id_foreign` (`department_id`),
  KEY `quota_usage_ledgers_user_id_period_key_index` (`user_id`,`period_key`),
  KEY `quota_usage_ledgers_run_id_foreign` (`run_id`),
  CONSTRAINT `quota_usage_ledgers_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_usage_ledgers_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quota_usage_ledgers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `run_audit_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `run_audit_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned DEFAULT NULL,
  `conversation_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `stage` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hit` tinyint(1) NOT NULL DEFAULT '0',
  `matched_terms` json DEFAULT NULL,
  `matched_policy_ids` json DEFAULT NULL,
  `matched_policy_names` json DEFAULT NULL,
  `action` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'allow',
  `decision` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pass',
  `content_excerpt` text COLLATE utf8mb4_unicode_ci,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_audit_records_conversation_id_foreign` (`conversation_id`),
  KEY `run_audit_records_run_id_stage_index` (`run_id`,`stage`),
  KEY `run_audit_records_user_id_stage_hit_index` (`user_id`,`stage`,`hit`),
  KEY `run_audit_records_decision_created_at_index` (`decision`,`created_at`),
  CONSTRAINT `run_audit_records_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `run_audit_records_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `run_audit_records_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `run_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `run_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_events_run_id_id_index` (`run_id`,`id`),
  CONSTRAINT `run_events_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `run_state_transitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `run_state_transitions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `from_status` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `run_state_transitions_run_id_id_index` (`run_id`,`id`),
  KEY `run_state_transitions_to_status_created_at_index` (`to_status`,`created_at`),
  CONSTRAINT `run_state_transitions_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `model` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intent_type` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intent_confidence` decimal(6,4) DEFAULT NULL,
  `intent_meta` json DEFAULT NULL,
  `interaction_mode` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feishu_chat_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feishu_message_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_tokens` bigint(20) unsigned NOT NULL DEFAULT '0',
  `output_tokens` bigint(20) unsigned NOT NULL DEFAULT '0',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `runs_conversation_id_foreign` (`conversation_id`),
  KEY `runs_user_id_foreign` (`user_id`),
  KEY `runs_intent_type_index` (`intent_type`),
  CONSTRAINT `runs_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `runs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_setting_key_unique` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `skill_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `skill_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `skill_id` bigint(20) unsigned NOT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `skill_assignments_skill_id_foreign` (`skill_id`),
  KEY `skill_assignments_user_id_foreign` (`user_id`),
  KEY `skill_assignments_department_id_user_id_index` (`department_id`,`user_id`),
  CONSTRAINT `skill_assignments_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `skill_assignments_skill_id_foreign` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE,
  CONSTRAINT `skill_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `skills` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_key` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `meta` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `skills_skill_key_deleted_unique` (`skill_key`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_invocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tool_invocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `tool_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input` json DEFAULT NULL,
  `output` json DEFAULT NULL,
  `status` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tool_invocations_run_id_foreign` (`run_id`),
  CONSTRAINT `tool_invocations_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_user_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `extra` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_identities_provider_provider_user_id_unique` (`provider`,`provider_user_id`),
  KEY `user_identities_user_id_foreign` (`user_id`),
  CONSTRAINT `user_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feishu_open_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feishu_union_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferences` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_feishu_open_id_unique` (`feishu_open_id`),
  UNIQUE KEY `users_feishu_union_id_unique` (`feishu_union_id`),
  KEY `users_department_id_foreign` (`department_id`),
  CONSTRAINT `users_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2014_10_12_100000_create_password_resets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_03_30_010000_create_mifrog_core_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_03_30_120000_create_memory_layer_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_03_30_230000_add_intent_columns_to_runs_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_03_31_010000_create_attachments_and_knowledge_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_03_31_060000_create_run_audit_records_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_03_31_170000_create_audit_policies_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_03_31_171000_add_policy_meta_to_run_audit_records',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_04_01_000000_create_proactive_message_hits_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_04_02_000000_create_run_state_transitions_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_04_05_000001_widen_columns_for_encrypted_storage',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_04_07_000001_add_email_to_admin_users',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_04_08_010000_create_proactive_activity_snapshots_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_04_10_120000_enhance_proactive_activity_snapshots_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_04_11_220000_add_expiry_columns_to_memory_entries_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_04_14_100000_create_quota_alert_logs_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_04_17_080000_widen_skip_reason_on_proactive_activity_snapshots_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_04_17_100000_remove_use_tool_calling_setting',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_04_20_150000_add_sheets_bitables_mails_to_snapshots',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_04_24_153000_create_admin_permissions',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_04_27_000000_drop_confidence_from_memory_facts',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_04_27_005500_add_deleted_at_to_skills',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_04_27_010000_create_admin_operation_logs',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_04_27_020000_change_skills_skill_key_unique_to_composite',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_04_27_030000_add_deleted_at_to_audit_policies',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_04_27_040000_extend_model_providers_for_multi_vendor',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_04_27_040100_backfill_vendor_key_from_setting_model_gateway',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_04_27_050000_add_active_model_settings_and_capabilities',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_04_27_060000_add_last_test_to_model_providers',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_04_28_095051_drop_proactive_message_hits_table',26);
