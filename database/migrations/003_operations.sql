ALTER TABLE organizations ADD contact_phone VARCHAR(30) NULL, ADD registration_number VARCHAR(100) NULL;
CREATE TABLE organization_members (
 organization_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(organization_id,user_id), FOREIGN KEY(organization_id) REFERENCES organizations(id), FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO organization_members (organization_id,user_id) SELECT id,owner_user_id FROM organizations;
CREATE TABLE food_categories (
 id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL UNIQUE, active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;
INSERT INTO food_categories (name) VALUES ('Prepared meals'),('Bakery'),('Fruit and vegetables'),('Packaged food'),('Dairy');
CREATE TABLE safety_rules (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, category_id INT UNSIGNED NOT NULL,
 version VARCHAR(40) NOT NULL, parameters JSON NOT NULL, source_url VARCHAR(500) NOT NULL,
 created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(category_id) REFERENCES food_categories(id), FOREIGN KEY(created_by) REFERENCES users(id), INDEX(category_id,id)
) ENGINE=InnoDB;
INSERT INTO safety_rules (category_id,version,parameters,source_url)
 SELECT id,'screening-v1',JSON_OBJECT('require_temperature',IF(name IN ('Prepared meals','Dairy'),TRUE,FALSE),'max_age_minutes',NULL,'max_temperature',NULL,'min_temperature',NULL),
 'https://www.fssai.gov.in/upload/uploadfiles/files/Gazette_Notification_Surplus_Food_06_08_2019.pdf' FROM food_categories;
CREATE TABLE donations (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, donor_id BIGINT UNSIGNED NOT NULL, organization_id BIGINT UNSIGNED NOT NULL,
 category_id INT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL, description TEXT NOT NULL,
 quantity DECIMAL(12,3) NOT NULL, unit ENUM('kg','g','portions','items') NOT NULL,
 unit_mass_kg DECIMAL(10,4) NOT NULL, quantity_kg DECIMAL(12,3) NOT NULL,
 prepared_at DATETIME NOT NULL, use_by DATETIME NOT NULL, deadline DATETIME NOT NULL,
 address TEXT NOT NULL, latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL,
 storage_method ENUM('ambient','chilled','frozen','hot') NOT NULL, temperature_c DECIMAL(6,2) NULL,
 packaging ENUM('intact','damaged','unknown') NOT NULL, allergens TEXT NOT NULL, ingredients TEXT NOT NULL,
 handling_declared BOOLEAN NOT NULL, unserved BOOLEAN NOT NULL, contamination_free BOOLEAN NOT NULL,
 status ENUM('available','review','rejected','accepted','scheduled','collected','completed','withdrawn','expired','failed','cancelled') NOT NULL,
 safety_reasons JSON NOT NULL, safety_rule_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(donor_id) REFERENCES users(id), FOREIGN KEY(organization_id) REFERENCES organizations(id), FOREIGN KEY(category_id) REFERENCES food_categories(id), FOREIGN KEY(safety_rule_id) REFERENCES safety_rules(id),
 CHECK(quantity>0), CHECK(quantity_kg>0), CHECK(latitude BETWEEN -90 AND 90), CHECK(longitude BETWEEN -180 AND 180),
 INDEX(status,deadline), INDEX(donor_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE food_safety_records (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, donation_id BIGINT UNSIGNED NOT NULL, rule_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, outcome VARCHAR(30) NOT NULL, reasons JSON NOT NULL, inputs JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(donation_id) REFERENCES donations(id), FOREIGN KEY(rule_id) REFERENCES safety_rules(id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE donation_images (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, donation_id BIGINT UNSIGNED NOT NULL, storage_path VARCHAR(255) NOT NULL,
 mime_type VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(donation_id) REFERENCES donations(id)
) ENGINE=InnoDB;
CREATE TABLE receivers (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL UNIQUE, organization_id BIGINT UNSIGNED NOT NULL,
 capacity_kg DECIMAL(12,3) NOT NULL, reserved_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
 transport_available BOOLEAN NOT NULL DEFAULT FALSE, collection_available BOOLEAN NOT NULL DEFAULT TRUE,
 latitude DECIMAL(10,7) NOT NULL, longitude DECIMAL(10,7) NOT NULL, max_distance_km DECIMAL(8,2) NOT NULL DEFAULT 30,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(organization_id) REFERENCES organizations(id),
 CHECK(capacity_kg>0), CHECK(reserved_kg>=0), CHECK(reserved_kg<=capacity_kg), CHECK(latitude BETWEEN -90 AND 90), CHECK(longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB;
CREATE TABLE receiver_categories (
 receiver_id BIGINT UNSIGNED NOT NULL, category_id INT UNSIGNED NOT NULL, PRIMARY KEY(receiver_id,category_id),
 FOREIGN KEY(receiver_id) REFERENCES receivers(id), FOREIGN KEY(category_id) REFERENCES food_categories(id)
) ENGINE=InnoDB;
CREATE TABLE receiver_availability (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, receiver_id BIGINT UNSIGNED NOT NULL, available_from DATETIME NOT NULL, available_until DATETIME NOT NULL,
 FOREIGN KEY(receiver_id) REFERENCES receivers(id), CHECK(available_until>available_from), INDEX(receiver_id,available_from,available_until)
) ENGINE=InnoDB;
CREATE TABLE algorithm_configurations (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, version VARCHAR(100) NOT NULL UNIQUE, weights JSON NOT NULL,
 parameters JSON NOT NULL, created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT INTO algorithm_configurations (version,weights,parameters) VALUES ('tamra-v1',JSON_OBJECT('urgency',0.25,'distance',0.20,'capacity',0.15,'compatibility',0.15,'feasibility',0.15,'reliability',0.10),JSON_OBJECT('speed_kmh',25,'handling_minutes',20,'urgency_horizon_minutes',1440,'cold_start_reliability',0.5,'minimum_history',5));
CREATE TABLE matches (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, donation_id BIGINT UNSIGNED NOT NULL, receiver_id BIGINT UNSIGNED NOT NULL,
 configuration_id BIGINT UNSIGNED NOT NULL, score DECIMAL(8,6) NOT NULL, inputs JSON NOT NULL, explanation JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(donation_id) REFERENCES donations(id), FOREIGN KEY(receiver_id) REFERENCES receivers(id), FOREIGN KEY(configuration_id) REFERENCES algorithm_configurations(id), INDEX(donation_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE match_scores (
 match_id BIGINT UNSIGNED NOT NULL, component VARCHAR(30) NOT NULL, score DECIMAL(8,6) NOT NULL, PRIMARY KEY(match_id,component), FOREIGN KEY(match_id) REFERENCES matches(id)
) ENGINE=InnoDB;
CREATE TABLE pickup_schedules (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, donation_id BIGINT UNSIGNED NOT NULL, receiver_id BIGINT UNSIGNED NOT NULL,
 match_id BIGINT UNSIGNED NOT NULL, volunteer_id BIGINT UNSIGNED NULL, scheduled_at DATETIME NULL,
 status ENUM('accepted','scheduled','collected','completed','failed','cancelled','expired') NOT NULL DEFAULT 'accepted',
 token_hash CHAR(64) NULL UNIQUE, token_consumed_at DATETIME NULL,
 collected_kg DECIMAL(12,3) NULL, collected_at DATETIME NULL, completed_at DATETIME NULL,
 donor_confirmed BOOLEAN NOT NULL DEFAULT FALSE, receiver_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
 active_donation_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('accepted','scheduled','collected','completed') THEN donation_id ELSE NULL END) STORED UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(donation_id) REFERENCES donations(id), FOREIGN KEY(receiver_id) REFERENCES receivers(id), FOREIGN KEY(match_id) REFERENCES matches(id), FOREIGN KEY(volunteer_id) REFERENCES users(id), INDEX(receiver_id,status)
) ENGINE=InnoDB;
CREATE TABLE volunteers (
 user_id BIGINT UNSIGNED PRIMARY KEY, available BOOLEAN NOT NULL DEFAULT TRUE, transport VARCHAR(100) NOT NULL DEFAULT 'Not specified', FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE pickup_verifications (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, pickup_id BIGINT UNSIGNED NOT NULL UNIQUE, verified_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(pickup_id) REFERENCES pickup_schedules(id), FOREIGN KEY(verified_by) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE pickup_events (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, donation_id BIGINT UNSIGNED NOT NULL, pickup_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NULL, event VARCHAR(60) NOT NULL, details JSON NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(donation_id) REFERENCES donations(id), FOREIGN KEY(pickup_id) REFERENCES pickup_schedules(id), FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE pickup_failures (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, pickup_id BIGINT UNSIGNED NOT NULL UNIQUE, reason TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(pickup_id) REFERENCES pickup_schedules(id)
) ENGINE=InnoDB;
CREATE TABLE notifications (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(190) NOT NULL UNIQUE,
 title VARCHAR(180) NOT NULL, message TEXT NOT NULL, link VARCHAR(255) NOT NULL, read_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(user_id,read_at,created_at)
) ENGINE=InnoDB;
CREATE TABLE reviews (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, pickup_id BIGINT UNSIGNED NOT NULL, author_id BIGINT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL, comment TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(pickup_id,author_id), FOREIGN KEY(pickup_id) REFERENCES pickup_schedules(id), FOREIGN KEY(author_id) REFERENCES users(id), CHECK(rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;
CREATE TABLE impact_records (
 pickup_id BIGINT UNSIGNED PRIMARY KEY, collected_kg DECIMAL(12,3) NOT NULL, distributed_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(pickup_id) REFERENCES pickup_schedules(id), CHECK(distributed_kg<=collected_kg)
) ENGINE=InnoDB;
CREATE TABLE datasets (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, owner_id BIGINT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL,
 source ENUM('simulated','anonymized','pilot') NOT NULL, metadata JSON NOT NULL, payload JSON NOT NULL,
 content_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE experiments (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, owner_id BIGINT UNSIGNED NOT NULL, dataset_id BIGINT UNSIGNED NOT NULL,
 configuration_id BIGINT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL, seed INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(owner_id) REFERENCES users(id), FOREIGN KEY(dataset_id) REFERENCES datasets(id), FOREIGN KEY(configuration_id) REFERENCES algorithm_configurations(id)
) ENGINE=InnoDB;
CREATE TABLE experiment_runs (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, experiment_id BIGINT UNSIGNED NOT NULL, algorithm VARCHAR(40) NOT NULL,
 metrics JSON NOT NULL, decisions JSON NOT NULL, execution_ms DECIMAL(12,4) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(experiment_id) REFERENCES experiments(id)
) ENGINE=InnoDB;
CREATE TABLE experiment_results (
 run_id BIGINT UNSIGNED NOT NULL, metric VARCHAR(100) NOT NULL, value DECIMAL(18,8) NULL, PRIMARY KEY(run_id,metric), FOREIGN KEY(run_id) REFERENCES experiment_runs(id)
) ENGINE=InnoDB;
CREATE TABLE platform_settings (
 setting_key VARCHAR(100) PRIMARY KEY, setting_value JSON NOT NULL, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE=InnoDB;
INSERT INTO platform_settings(setting_key,setting_value) VALUES ('meal_mass_kg','0.4');
ALTER TABLE mail_outbox ADD attempts INT UNSIGNED NOT NULL DEFAULT 0, ADD last_error VARCHAR(500) NULL;
