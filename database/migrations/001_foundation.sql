CREATE TABLE roles (
 id TINYINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 slug VARCHAR(30) NOT NULL UNIQUE,
 name VARCHAR(80) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE users (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 role_id TINYINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 auth_version INT UNSIGNED NOT NULL DEFAULT 1,
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 email_verified_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 deleted_at DATETIME NULL,
 FOREIGN KEY (role_id) REFERENCES roles(id),
 INDEX idx_users_status (status,deleted_at)
) ENGINE=InnoDB;
CREATE TABLE user_profiles (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 phone VARCHAR(30) NULL,
 timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
 photograph_path VARCHAR(255) NULL,
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE organizations (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 type ENUM('donor','receiver') NOT NULL,
 verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
 address TEXT NULL,
 latitude DECIMAL(10,7) NULL,
 longitude DECIMAL(10,7) NULL,
 verified_by BIGINT UNSIGNED NULL,
 verified_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (owner_user_id) REFERENCES users(id),
 FOREIGN KEY (verified_by) REFERENCES users(id),
 CHECK (latitude BETWEEN -90 AND 90),
 CHECK (longitude BETWEEN -180 AND 180),
 INDEX idx_organization_owner (owner_user_id),
 INDEX idx_organization_verification (verification_status)
) ENGINE=InnoDB;
CREATE TABLE organization_documents (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 storage_path VARCHAR(255) NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;
CREATE TABLE account_tokens (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL,
 purpose ENUM('verify_email','reset_password') NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX idx_tokens_user (user_id,purpose)
) ENGINE=InnoDB;
CREATE TABLE auth_rate_limits (
 bucket CHAR(64) PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_start DATETIME NOT NULL,
 INDEX idx_rate_window (window_start)
) ENGINE=InnoDB;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NULL,
 event VARCHAR(80) NOT NULL,
 context JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX idx_audit_user_time (user_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE mail_outbox (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 recipient VARCHAR(254) NOT NULL,
 subject VARCHAR(200) NOT NULL,
 body TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 sent_at DATETIME NULL
) ENGINE=InnoDB;
