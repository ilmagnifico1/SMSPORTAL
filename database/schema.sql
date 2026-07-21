-- SMS Portal database schema (structure only)
-- Generated: 2026-07-20T15:26:16+00:00

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `app_settings`;
CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `auth_login_attempts`;
CREATE TABLE `auth_login_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username_hash` char(64) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_auth_attempt_lookup` (`ip_address`,`username_hash`,`attempted_at`),
  KEY `idx_auth_attempt_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `campaigns`;
CREATE TABLE `campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT 1,
  `team_id` int(11) DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `provider_id` int(11) DEFAULT 0,
  `list_id` int(11) DEFAULT 0,
  `sender` varchar(100) DEFAULT '',
  `message` text NOT NULL,
  `csv_path` varchar(500) DEFAULT '',
  `csv_name` varchar(255) DEFAULT '',
  `created_by` varchar(100) DEFAULT '',
  `last_status` varchar(50) DEFAULT 'draft',
  `last_result` text DEFAULT '',
  `last_sent_at` datetime DEFAULT NULL,
  `job_total` int(11) NOT NULL DEFAULT 0,
  `job_processed` int(11) NOT NULL DEFAULT 0,
  `job_sent` int(11) NOT NULL DEFAULT 0,
  `job_failed` int(11) NOT NULL DEFAULT 0,
  `job_cursor` int(11) NOT NULL DEFAULT 0,
  `job_user` varchar(100) DEFAULT '',
  `job_token` char(64) DEFAULT NULL,
  `job_lock_token` char(32) DEFAULT NULL,
  `job_lock_until` datetime DEFAULT NULL,
  `job_updated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaigns_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `provider_access_configured` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `company_credits`;
CREATE TABLE `company_credits` (
  `company_id` int(11) NOT NULL,
  `balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `billing_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `company_provider_prices`;
CREATE TABLE `company_provider_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `prefix` varchar(64) NOT NULL,
  `destination` varchar(150) NOT NULL DEFAULT '',
  `operator_name` varchar(150) NOT NULL DEFAULT '',
  `sale_price` decimal(10,4) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_provider_prefix_price` (`company_id`,`provider_id`,`prefix`),
  KEY `idx_company_provider_price_company` (`company_id`),
  KEY `idx_company_provider_price_provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `company_providers`;
CREATE TABLE `company_providers` (
  `company_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`company_id`,`provider_id`),
  KEY `idx_company_providers_provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `credit_transactions`;
CREATE TABLE `credit_transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `amount` decimal(14,4) NOT NULL,
  `transaction_type` varchar(30) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `reference_id` bigint(20) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_credit_transactions_company` (`company_id`),
  KEY `idx_credit_transactions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `firewall_access_requests`;
CREATE TABLE `firewall_access_requests` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `blocked_ip_id` bigint(20) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `company_id` int(11) NOT NULL DEFAULT 0,
  `rule_id` bigint(20) NOT NULL DEFAULT 0,
  `reviewed_by` int(11) NOT NULL DEFAULT 0,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_requests_status` (`status`),
  KEY `idx_firewall_requests_ip` (`ip_address`),
  KEY `idx_firewall_requests_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `firewall_blocked_ip_attempts`;
CREATE TABLE `firewall_blocked_ip_attempts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `blocked_ip_id` bigint(20) NOT NULL,
  `request_uri` varchar(1000) NOT NULL DEFAULT '',
  `attempted_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_attempts_blocked_ip` (`blocked_ip_id`),
  KEY `idx_firewall_attempts_date` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `firewall_blocked_ips`;
CREATE TABLE `firewall_blocked_ips` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `country_code` varchar(8) NOT NULL DEFAULT '',
  `country_name` varchar(100) NOT NULL DEFAULT 'Sconosciuto',
  `flag` varchar(16) NOT NULL DEFAULT '?',
  `user_id` int(11) NOT NULL DEFAULT 0,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `company_id` int(11) NOT NULL DEFAULT 0,
  `request_uri` varchar(1000) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `attempt_count` int(11) NOT NULL DEFAULT 1,
  `first_attempt_at` timestamp NULL DEFAULT current_timestamp(),
  `last_attempt_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_firewall_blocked_ip` (`ip_address`),
  KEY `idx_firewall_blocked_last_attempt` (`last_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `firewall_rule_users`;
CREATE TABLE `firewall_rule_users` (
  `rule_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`rule_id`,`user_id`),
  KEY `idx_firewall_rule_users_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `firewall_rules`;
CREATE TABLE `firewall_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `ip_ranges` text NOT NULL,
  `super_admin_only` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_firewall_rules_company` (`company_id`),
  KEY `idx_firewall_rules_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `ip_geo_cache`;
CREATE TABLE `ip_geo_cache` (
  `ip_address` varchar(45) NOT NULL,
  `country_code` varchar(8) NOT NULL DEFAULT '',
  `country_name` varchar(100) NOT NULL DEFAULT '',
  `flag` varchar(16) NOT NULL DEFAULT '',
  `expires_at` datetime NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `leads`;
CREATE TABLE `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_list_phone` (`list_id`,`phone`),
  KEY `idx_leads_list_id` (`list_id`),
  CONSTRAINT `fk_leads_list` FOREIGN KEY (`list_id`) REFERENCES `sms_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `message_logs`;
CREATE TABLE `message_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT 1,
  `team_id` int(11) DEFAULT 1,
  `user_name` varchar(100) DEFAULT '',
  `provider_id` int(11) DEFAULT 0,
  `provider_name` varchar(100) DEFAULT '',
  `lead_id` int(11) DEFAULT 0,
  `list_id` int(11) DEFAULT 0,
  `campaign_id` int(11) DEFAULT 0,
  `campaign_run_token` char(64) DEFAULT NULL,
  `recipient` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `response` text DEFAULT '',
  `credit_cost` decimal(10,4) DEFAULT 0.0000,
  `purchase_cost` decimal(10,4) DEFAULT 0.0000,
  `sale_amount` decimal(10,4) DEFAULT 0.0000,
  `profit_amount` decimal(10,4) DEFAULT 0.0000,
  `purchase_unit_price` decimal(10,4) DEFAULT 0.0000,
  `sale_unit_price` decimal(10,4) DEFAULT 0.0000,
  `credit_balance_before` decimal(14,4) DEFAULT 0.0000,
  `credit_balance_after` decimal(14,4) DEFAULT 0.0000,
  `sms_segments` int(11) DEFAULT 1,
  `price_prefix` varchar(64) NOT NULL DEFAULT '',
  `price_operator` varchar(150) DEFAULT '',
  `purchase_prefix` varchar(64) NOT NULL DEFAULT '',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_message_campaign_run_lead` (`campaign_run_token`,`lead_id`),
  KEY `idx_message_logs_lead_id` (`lead_id`),
  KEY `idx_message_logs_list_id` (`list_id`),
  KEY `idx_message_logs_campaign_id` (`campaign_id`),
  KEY `idx_message_logs_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `provider_credit_transactions`;
CREATE TABLE `provider_credit_transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider_id` int(11) NOT NULL,
  `amount` decimal(14,4) NOT NULL,
  `transaction_type` varchar(30) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `reference_id` bigint(20) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_provider_credit_transactions_provider` (`provider_id`),
  KEY `idx_provider_credit_transactions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `provider_credits`;
CREATE TABLE `provider_credits` (
  `provider_id` int(11) NOT NULL,
  `balance` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `credit_control_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `provider_prefix_costs`;
CREATE TABLE `provider_prefix_costs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider_id` int(11) NOT NULL,
  `prefix` varchar(64) NOT NULL,
  `destination` varchar(150) NOT NULL DEFAULT '',
  `operator_name` varchar(150) NOT NULL DEFAULT '',
  `purchase_price` decimal(10,4) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_provider_prefix_cost` (`provider_id`,`prefix`),
  KEY `idx_provider_prefix_cost_provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `providers`;
CREATE TABLE `providers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT 1,
  `name` varchar(100) NOT NULL,
  `endpoint` varchar(500) NOT NULL,
  `provider_type` varchar(20) DEFAULT 'generic',
  `username` varchar(100) DEFAULT '',
  `password` varchar(255) DEFAULT '',
  `api_key` varchar(255) DEFAULT '',
  `request_type` varchar(20) DEFAULT 'GET',
  `default_from` varchar(100) DEFAULT '',
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_providers_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `registered_devices`;
CREATE TABLE `registered_devices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `device_uuid` char(36) NOT NULL,
  `device_name` varchar(150) NOT NULL DEFAULT '',
  `public_key_jwk` text NOT NULL,
  `public_key_fingerprint` char(64) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `approved_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_uuid` (`device_uuid`),
  KEY `idx_registered_devices_user` (`user_id`,`status`),
  KEY `idx_registered_devices_company` (`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `send_authorizations`;
CREATE TABLE `send_authorizations` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `authorization_id` char(48) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `action_type` varchar(30) NOT NULL,
  `challenge` varchar(100) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `summary_json` text NOT NULL,
  `signature` varchar(500) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `expiration_logged_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `authorization_id` (`authorization_id`),
  KEY `idx_send_auth_user` (`user_id`,`expires_at`),
  KEY `idx_send_auth_device` (`device_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sms_lists`;
CREATE TABLE `sms_lists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT 1,
  `team_id` int(11) DEFAULT 1,
  `name` varchar(150) NOT NULL,
  `csv_path` varchar(500) DEFAULT '',
  `csv_name` varchar(255) DEFAULT '',
  `total_contacts` int(11) DEFAULT 0,
  `invalid_contacts` int(11) DEFAULT 0,
  `created_by` varchar(100) DEFAULT '',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sms_lists_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sms_prefix_prices`;
CREATE TABLE `sms_prefix_prices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `prefix` varchar(24) NOT NULL,
  `destination` varchar(150) NOT NULL DEFAULT '',
  `price` decimal(10,4) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `prefix` (`prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `system_logs`;
CREATE TABLE `system_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL DEFAULT 1,
  `team_id` int(11) NOT NULL DEFAULT 1,
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `category` varchar(50) NOT NULL DEFAULT 'system',
  `event_name` varchar(120) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `country_code` varchar(8) NOT NULL DEFAULT '',
  `country_name` varchar(100) NOT NULL DEFAULT '',
  `flag` varchar(16) NOT NULL DEFAULT '',
  `request_method` varchar(10) NOT NULL DEFAULT '',
  `request_uri` varchar(1000) NOT NULL DEFAULT '',
  `proxy_chain` varchar(1000) NOT NULL DEFAULT '',
  `context_json` mediumtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_system_logs_level` (`level`),
  KEY `idx_system_logs_category` (`category`),
  KEY `idx_system_logs_created_at` (`created_at`),
  KEY `idx_system_logs_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_team` (`company_id`,`name`),
  KEY `idx_teams_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `user_providers`;
CREATE TABLE `user_providers` (
  `user_id` int(11) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`provider_id`),
  KEY `idx_user_providers_provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `utenti`;
CREATE TABLE `utenti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT 1,
  `team_id` int(11) DEFAULT 1,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'user',
  `preferred_language` varchar(5) NOT NULL DEFAULT 'it',
  `active` tinyint(1) DEFAULT 1,
  `can_send_single` tinyint(1) DEFAULT 1,
  `can_send_bulk` tinyint(1) DEFAULT 1,
  `can_manage_providers` tinyint(1) DEFAULT 1,
  `can_manage_users` tinyint(1) DEFAULT 1,
  `can_view_dashboard` tinyint(1) DEFAULT 1,
  `can_view_campaigns` tinyint(1) DEFAULT 1,
  `can_view_lists` tinyint(1) DEFAULT 1,
  `can_view_team_messages` tinyint(1) DEFAULT 1,
  `can_create_campaigns` tinyint(1) DEFAULT 1,
  `can_edit_campaigns` tinyint(1) DEFAULT 1,
  `can_delete_campaigns` tinyint(1) DEFAULT 1,
  `can_create_lists` tinyint(1) DEFAULT 1,
  `can_edit_lists` tinyint(1) DEFAULT 1,
  `can_delete_lists` tinyint(1) DEFAULT 1,
  `provider_access_configured` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_utenti_username` (`username`),
  KEY `idx_utenti_company_id` (`company_id`),
  KEY `idx_utenti_team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
