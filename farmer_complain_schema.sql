
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` int DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `summary` varchar(500) NOT NULL,
  `metadata_json` longtext,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`,`created_at`),
  KEY `idx_audit_actor` (`actor_id`,`created_at`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_approvals` (
  `complaint_id` int NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `decided_by` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`),
  KEY `idx_complaint_approvals_status_created` (`status`,`created_at`),
  KEY `fk_complaint_approval_decider` (`decided_by`),
  CONSTRAINT `fk_complaint_approval_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_complaint_approval_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `complaint_id` int NOT NULL,
  `category` varchar(20) NOT NULL DEFAULT 'Other',
  `purpose` varchar(40) NOT NULL DEFAULT 'complaint',
  `material_event_id` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `stored_name` varchar(64) NOT NULL,
  `original_name` varchar(512) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stored_name` (`stored_name`),
  KEY `idx_attachments_complaint` (`complaint_id`),
  KEY `idx_attachments_material_event` (`material_event_id`),
  KEY `fk_attachments_uploader` (`uploaded_by`),
  CONSTRAINT `fk_attachments_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attachments_material_event` FOREIGN KEY (`material_event_id`) REFERENCES `complaint_material_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attachments_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=490 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_department_assignments` (
  `complaint_id` int NOT NULL,
  `department` varchar(100) NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approval_required` tinyint(1) NOT NULL DEFAULT '1',
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approval_reason` text,
  PRIMARY KEY (`complaint_id`,`department`),
  KEY `idx_assignments_department` (`department`,`complaint_id`),
  KEY `fk_assignments_approver` (`approved_by`),
  CONSTRAINT `fk_assignments_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assignments_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_draft_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `draft_id` int NOT NULL,
  `category` varchar(20) NOT NULL,
  `stored_name` varchar(64) NOT NULL,
  `original_name` varchar(512) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stored_name` (`stored_name`),
  KEY `idx_draft_attachments_draft` (`draft_id`),
  CONSTRAINT `fk_draft_attachments_draft` FOREIGN KEY (`draft_id`) REFERENCES `complaint_drafts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_drafts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `form_data` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaint_drafts_user` (`user_id`),
  CONSTRAINT `fk_complaint_drafts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_id_counters` (
  `state_code` char(3) NOT NULL,
  `last_sequence` int unsigned NOT NULL,
  PRIMARY KEY (`state_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_material_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `complaint_id` int NOT NULL,
  `event_type` varchar(30) NOT NULL,
  `material` varchar(300) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit` varchar(30) NOT NULL,
  `received_on` date NOT NULL,
  `received_from` varchar(150) NOT NULL,
  `received_by` varchar(150) NOT NULL,
  `remarks` text,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_material_events_complaint` (`complaint_id`,`received_on`,`id`),
  KEY `fk_material_events_user` (`recorded_by`),
  CONSTRAINT `fk_material_events_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_material_events_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaint_workflow` (
  `complaint_id` int NOT NULL,
  `so_number` varchar(100) DEFAULT NULL,
  `so_created_at` datetime DEFAULT NULL,
  `so_created_by` int DEFAULT NULL,
  `so_creation_delay_reason` text,
  `dispatch_sent_at` datetime DEFAULT NULL,
  `dispatch_sent_by` int DEFAULT NULL,
  `dispatch_delay_reason` text,
  `material_out_at` datetime DEFAULT NULL,
  `material_out_by` int DEFAULT NULL,
  `material_out_delay_reason` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`),
  KEY `fk_workflow_so_user` (`so_created_by`),
  KEY `fk_workflow_dispatch_user` (`dispatch_sent_by`),
  KEY `fk_workflow_material_out_user` (`material_out_by`),
  CONSTRAINT `fk_workflow_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_workflow_dispatch_user` FOREIGN KEY (`dispatch_sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_workflow_material_out_user` FOREIGN KEY (`material_out_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_workflow_so_user` FOREIGN KEY (`so_created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `complaints` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_by` int NOT NULL,
  `title` varchar(180) NOT NULL,
  `complaint_type` varchar(150) NOT NULL,
  `product` varchar(300) NOT NULL,
  `district` varchar(50) NOT NULL,
  `department` varchar(100) NOT NULL,
  `details` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `farmer_name` varchar(100) DEFAULT NULL,
  `village` varchar(100) DEFAULT NULL,
  `taluka` varchar(100) DEFAULT NULL,
  `supply_date` date DEFAULT NULL,
  `quantity_supplied` decimal(12,3) DEFAULT NULL,
  `invoice_reference` varchar(100) DEFAULT NULL,
  `quantity_unit` varchar(30) DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `dealer_id` int DEFAULT NULL,
  `farmer_mobile` varchar(15) DEFAULT NULL,
  `public_reference` varchar(32) DEFAULT NULL,
  `actual_product` varchar(300) DEFAULT NULL,
  `actual_quantity_supplied` decimal(12,3) DEFAULT NULL,
  `actual_quantity_unit` varchar(30) DEFAULT NULL,
  `wo_number` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaints_public_reference` (`public_reference`),
  KEY `idx_complaints_creator` (`created_by`,`created_at`),
  KEY `idx_complaints_district` (`district`,`created_at`),
  KEY `idx_complaints_department` (`department`,`created_at`),
  KEY `idx_complaints_type` (`complaint_type`),
  KEY `idx_complaints_product` (`product`),
  KEY `fk_complaints_dealer` (`dealer_id`),
  KEY `idx_complaints_farmer_mobile` (`farmer_mobile`,`created_at`),
  CONSTRAINT `fk_complaints_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1845 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dealers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dealer_code` varchar(60) NOT NULL,
  `name` varchar(200) NOT NULL,
  `address` text,
  `village` varchar(100) DEFAULT NULL,
  `pin_code` varchar(20) DEFAULT NULL,
  `taluka` varchar(100) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dealers_code` (`dealer_code`),
  KEY `idx_dealers_name` (`name`),
  KEY `idx_dealers_location` (`district`,`taluka`),
  KEY `fk_dealers_creator` (`created_by`),
  KEY `fk_dealers_updater` (`updated_by`),
  CONSTRAINT `fk_dealers_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dealers_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1069 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legacy_complaint_data` (
  `complaint_id` int NOT NULL,
  `source_id` varchar(100) NOT NULL,
  `dealer_name` text,
  `area_manager_name` text,
  `created_by_name` text,
  `original_json` longtext NOT NULL,
  `imported_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`complaint_id`),
  UNIQUE KEY `source_id` (`source_id`),
  CONSTRAINT `fk_legacy_data_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned NOT NULL DEFAULT '0',
  `depth` tinyint unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_location_parent_name` (`parent_id`,`name`),
  KEY `idx_location_depth_name` (`depth`,`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7581 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `identifier_hash` char(64) NOT NULL,
  `attempted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `succeeded` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_identifier` (`identifier_hash`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `notification_type` varchar(40) NOT NULL,
  `title` varchar(180) NOT NULL,
  `message` varchar(500) NOT NULL,
  `entity_type` varchar(60) DEFAULT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `dedupe_key` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_dedupe` (`dedupe_key`),
  KEY `idx_notifications_user` (`user_id`,`read_at`,`created_at`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=303 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(300) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2714 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schema_migrations` (
  `migration_key` varchar(100) NOT NULL,
  `applied_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`),
  KEY `fk_settings_user` (`updated_by`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `session_hash` char(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_hash` (`session_hash`),
  KEY `idx_user_sessions_user` (`user_id`,`revoked_at`,`expires_at`),
  KEY `fk_session_revoker` (`revoked_by`),
  CONSTRAINT `fk_session_revoker` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `mobile` varchar(15) NOT NULL,
  `firm_name` varchar(100) DEFAULT NULL,
  `address` text,
  `assigned_area` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `password` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `session_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
