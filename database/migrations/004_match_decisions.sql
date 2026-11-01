CREATE TABLE match_decisions (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, match_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, decision ENUM('proposed','declined','accepted') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(match_id) REFERENCES matches(id), FOREIGN KEY(actor_id) REFERENCES users(id), INDEX(match_id,decision)
) ENGINE=InnoDB;
