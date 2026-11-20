-- Hitech Medicos Delivery — database schema v1.0.0
-- All timestamps stored in UTC (DATETIME), shown in IST by the app.

CREATE TABLE IF NOT EXISTS hm_role (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  permissions TEXT NOT NULL,              -- JSON array of "page.action" keys, or ["*"]
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_user (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  mobile VARCHAR(15) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY idx_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_warehouse (
  warehouse_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  address VARCHAR(255) NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  radius_m INT NOT NULL DEFAULT 200,
  status TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_rider (
  rider_id INT AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  mobile VARCHAR(15) NOT NULL UNIQUE,
  emergency_mobile VARCHAR(15) NULL,
  pin_hash VARCHAR(255) NOT NULL,
  warehouse_id INT NULL,
  vehicle_type VARCHAR(30) NULL,
  vehicle_number VARCHAR(20) NULL,
  licence_number VARCHAR(30) NULL,
  licence_expiry DATE NULL,
  capacity INT NOT NULL DEFAULT 30,
  cod_limit DECIMAL(12,2) NOT NULL DEFAULT 50000,
  status TINYINT NOT NULL DEFAULT 1,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  device_id VARCHAR(100) NULL,
  last_seen_at DATETIME NULL,
  last_lat DECIMAL(10,7) NULL,
  last_lng DECIMAL(10,7) NULL,
  last_speed DECIMAL(6,2) NULL,
  last_battery INT NULL,
  live_status VARCHAR(20) NOT NULL DEFAULT 'off_duty',
  app_version VARCHAR(20) NULL,
  gps_on TINYINT NULL,
  loc_permission VARCHAR(20) NULL,
  battery_exempt TINYINT NULL,
  phone_pending INT NULL,
  phone_gps_queue INT NULL,
  last_heartbeat_at DATETIME NULL,
  last_point_at DATETIME NULL,
  last_accuracy DECIMAL(8,2) NULL,
  last_fix_at DATETIME NULL,
  joined_on DATE NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  KEY idx_status (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_rider_token (
  token_id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  device_id VARCHAR(100) NOT NULL,
  access_hash CHAR(64) NOT NULL,
  access_expires DATETIME NOT NULL,
  refresh_hash CHAR(64) NOT NULL,
  refresh_expires DATETIME NOT NULL,
  revoked TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_access (access_hash),
  UNIQUE KEY uq_refresh (refresh_hash),
  KEY idx_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_duty (
  duty_id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  duty_date DATE NOT NULL,
  start_at DATETIME NOT NULL,
  start_lat DECIMAL(10,7) NULL,
  start_lng DECIMAL(10,7) NULL,
  start_accuracy DECIMAL(8,2) NULL,
  start_battery INT NULL,
  start_in_geofence TINYINT NULL,
  selfie_media_id INT NULL,
  route_start_at DATETIME NULL,
  route_end_at DATETIME NULL,
  end_at DATETIME NULL,
  end_lat DECIMAL(10,7) NULL,
  end_lng DECIMAL(10,7) NULL,
  km_measured DECIMAL(10,3) NOT NULL DEFAULT 0,
  km_estimated DECIMAL(10,3) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',  -- active, ended, auto_ended
  KEY idx_rider_date (rider_id, duty_date),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_location (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  duty_id INT NOT NULL,
  rider_id INT NOT NULL,
  seq INT NOT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  accuracy DECIMAL(8,2) NULL,
  speed DECIMAL(6,2) NULL,
  heading DECIMAL(6,2) NULL,
  battery INT NULL,
  is_mock TINYINT NOT NULL DEFAULT 0,
  delivery_id INT NULL,
  device_at DATETIME NOT NULL,
  server_at DATETIME NOT NULL,
  kept TINYINT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_duty_seq (duty_id, seq),
  KEY idx_duty_time (duty_id, device_at),
  KEY idx_rider_time (rider_id, device_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_delivery (
  delivery_id INT AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(40) NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_mobile VARCHAR(15) NOT NULL,
  address VARCHAR(500) NOT NULL,
  landmark VARCHAR(200) NULL,
  pincode VARCHAR(10) NULL,
  cust_lat DECIMAL(10,7) NULL,
  cust_lng DECIMAL(10,7) NULL,
  items_count INT NOT NULL DEFAULT 1,
  order_value DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_type VARCHAR(10) NOT NULL DEFAULT 'prepaid', -- prepaid, cod
  cod_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',     -- normal, priority, urgent
  is_cold_chain TINYINT NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ready',
  rider_id INT NULL,
  warehouse_id INT NULL,
  sequence INT NOT NULL DEFAULT 0,
  attempt_count INT NOT NULL DEFAULT 0,
  sla_due_at DATETIME NULL,
  scheduled_for DATE NULL,
  otp_hash CHAR(64) NULL,
  otp_tries INT NOT NULL DEFAULT 0,
  assigned_at DATETIME NULL,
  loaded_at DATETIME NULL,
  out_at DATETIME NULL,
  delivered_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_order_no (order_no),
  KEY idx_rider_status (rider_id, status),
  KEY idx_status_created (status, created_at),
  KEY idx_mobile (customer_mobile),
  KEY idx_sla (sla_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_attempt (
  attempt_id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT NOT NULL,
  rider_id INT NOT NULL,
  duty_id INT NULL,
  attempt_no INT NOT NULL,
  started_at DATETIME NULL,
  arrived_at DATETIME NULL,
  arrived_manual TINYINT NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  outcome VARCHAR(20) NULL,       -- delivered, failed, refused, rescheduled
  reason_code VARCHAR(40) NULL,
  remark VARCHAR(500) NULL,
  reschedule_date DATE NULL,
  distance_from_address_m INT NULL,
  received_by VARCHAR(100) NULL,
  KEY idx_delivery (delivery_id),
  KEY idx_rider_closed (rider_id, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_event (
  event_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  client_uuid CHAR(36) NULL,
  delivery_id INT NULL,
  duty_id INT NULL,
  rider_id INT NULL,
  type VARCHAR(30) NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NULL,
  actor_type VARCHAR(10) NOT NULL,  -- rider, admin, system
  actor_id INT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  device_at DATETIME NULL,
  server_at DATETIME NOT NULL,
  meta TEXT NULL,
  UNIQUE KEY uq_client (rider_id, client_uuid),
  KEY idx_delivery (delivery_id, server_at),
  KEY idx_duty (duty_id, server_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_media (
  media_id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  kind VARCHAR(20) NOT NULL,     -- selfie, pod, failed, parcel
  file_path VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size_bytes INT NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rider (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_proof (
  proof_id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  type VARCHAR(20) NOT NULL,     -- photo, otp, signature
  media_id INT NULL,
  otp_verified TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_cod_txn (
  txn_id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  delivery_id INT NULL,
  settlement_id INT NULL,
  type VARCHAR(20) NOT NULL,     -- collect, deposit, short, writeoff, recover
  mode VARCHAR(10) NULL,         -- cash, upi
  amount DECIMAL(12,2) NOT NULL,
  reference VARCHAR(100) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rider_time (rider_id, created_at),
  KEY idx_delivery (delivery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_settlement (
  settlement_id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  expected DECIMAL(12,2) NOT NULL,
  received DECIMAL(12,2) NULL,
  difference DECIMAL(12,2) NULL,
  remark VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'requested', -- requested, settled, short
  requested_at DATETIME NOT NULL,
  accepted_by INT NULL,
  accepted_at DATETIME NULL,
  KEY idx_rider_status (rider_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_exception (
  exception_id INT AUTO_INCREMENT PRIMARY KEY,
  rule_code VARCHAR(40) NOT NULL,
  severity VARCHAR(10) NOT NULL,   -- low, medium, high, critical
  rider_id INT NULL,
  delivery_id INT NULL,
  message VARCHAR(500) NOT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  resolved_by INT NULL,
  resolution_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  KEY idx_status (status, severity, created_at),
  KEY idx_rule_rider (rule_code, rider_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_daily_summary (
  rider_id INT NOT NULL,
  summary_date DATE NOT NULL,
  duty_start DATETIME NULL,
  duty_end DATETIME NULL,
  duty_minutes INT NOT NULL DEFAULT 0,
  assigned INT NOT NULL DEFAULT 0,
  delivered INT NOT NULL DEFAULT 0,
  failed INT NOT NULL DEFAULT 0,
  refused INT NOT NULL DEFAULT 0,
  rescheduled INT NOT NULL DEFAULT 0,
  attempts INT NOT NULL DEFAULT 0,
  km DECIMAL(10,3) NOT NULL DEFAULT 0,
  avg_cycle_min DECIMAL(8,2) NULL,
  cod_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
  cod_upi DECIMAL(12,2) NOT NULL DEFAULT 0,
  cod_deposited DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (rider_id, summary_date),
  KEY idx_date (summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_setting (
  skey VARCHAR(60) PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_audit_log (
  audit_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_type VARCHAR(10) NOT NULL,
  actor_id INT NULL,
  actor_name VARCHAR(100) NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id VARCHAR(40) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_time (created_at),
  KEY idx_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_job_run (
  job VARCHAR(40) PRIMARY KEY,
  last_run_at DATETIME NULL,
  last_status VARCHAR(10) NULL,
  last_message VARCHAR(500) NULL,
  failures INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_filter_preset (
  preset_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page VARCHAR(40) NOT NULL,
  name VARCHAR(60) NOT NULL,
  query VARCHAR(1000) NOT NULL,
  KEY idx_user_page (user_id, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hm_rate_limit (
  rkey VARCHAR(120) PRIMARY KEY,
  hits INT NOT NULL,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
