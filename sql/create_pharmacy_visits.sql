CREATE TABLE IF NOT EXISTS pharmacy_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    pharmacy_id INT UNSIGNED NOT NULL,
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pharmacy_visits_user_recent (user_id, visited_at),
    KEY idx_pharmacy_visits_pharmacy (pharmacy_id),
    CONSTRAINT fk_pharmacy_visits_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_pharmacy_visits_pharmacy
        FOREIGN KEY (pharmacy_id) REFERENCES pharmacies (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
