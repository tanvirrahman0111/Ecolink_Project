-- EcoLink: Delivery Hub feature (additive only - no existing table is changed).
-- The app also creates these automatically on first use (includes/hub.php), so
-- importing this file is optional.

CREATE TABLE IF NOT EXISTS delivery_hubs (
    hub_id      INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    area        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    phone       VARCHAR(40)  NULL,
    open_hours  VARCHAR(120) NULL,
    manager_user_id INT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hub_area (area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hub_items (
    hub_item_id  INT AUTO_INCREMENT PRIMARY KEY,
    hub_id       INT NOT NULL,
    donation_id  INT NOT NULL,
    donor_id     INT NOT NULL,
    receiver_id  INT NULL,                 -- filled in once a receiver is confirmed for the donation
    pickup_code  VARCHAR(16) NULL,         -- receiver's Pickup ID, created together with receiver_id
    status       ENUM('awaiting_dropoff','stored','collected','cancelled') NOT NULL DEFAULT 'awaiting_dropoff',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    stored_at    DATETIME NULL,
    collected_at DATETIME NULL,
    UNIQUE KEY uq_hub_donation (donation_id),
    UNIQUE KEY uq_hub_code (pickup_code),
    INDEX idx_hub_status (hub_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hub staff login role (run once if your users table already exists):
ALTER TABLE users MODIFY role ENUM('donor','receiver','admin','hub') NOT NULL;

CREATE TABLE IF NOT EXISTS hub_staff (
    user_id     INT NOT NULL PRIMARY KEY,
    hub_id      INT NOT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hs_hub (hub_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hub_items MODIFY receiver_id INT NULL, MODIFY pickup_code VARCHAR(16) NULL;
