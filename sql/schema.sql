-- =====================================================================
-- EcoLink - FULL database schema (MySQL / MariaDB, utf8mb4)
-- Includes the Delivery Hub tables.
--
-- HOW TO IMPORT
--   * InfinityFree / hosting: open phpMyAdmin, SELECT your existing database
--     (e.g. if0_xxxxx_ecolink) in the left panel, then Import -> this file.
--   * Local XAMPP: create an empty database named ecolink_db first (or edit
--     config/db.php), select it in phpMyAdmin, then Import this file.
-- The file does not create/drop the database itself, so it is safe on shared hosting.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Users (donor / receiver / admin)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id             INT AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    phone               VARCHAR(40)  NOT NULL DEFAULT '',
    password_hash       VARCHAR(255) NOT NULL,
    role                ENUM('donor','receiver','admin','hub') NOT NULL,
    organization_name   VARCHAR(160) NULL,
    address             VARCHAR(255) NULL,
    pickup_area         VARCHAR(160) NULL,
    latitude            DOUBLE NULL,
    longitude           DOUBLE NULL,
    capacity_score      TINYINT NOT NULL DEFAULT 5,              -- receiver intake capacity 1-10
    is_verified         TINYINT(1) NOT NULL DEFAULT 0,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    email_verified      TINYINT(1) NOT NULL DEFAULT 0,
    status              ENUM('active','suspended') NOT NULL DEFAULT 'active',
    preferred_categories VARCHAR(255) NULL,                      -- comma separated category names
    typical_quantity    INT NULL,
    preferred_condition ENUM('any','new','like_new','good','fair') NOT NULL DEFAULT 'any',
    recurring_needs     VARCHAR(255) NULL,
    need_description    TEXT NULL,
    notify_matching     TINYINT(1) NOT NULL DEFAULT 1,
    notify_lifecycle    TINYINT(1) NOT NULL DEFAULT 1,
    notify_reminders    TINYINT(1) NOT NULL DEFAULT 1,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_verifications (
    verification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    code            VARCHAR(10) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used            TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ev_user (user_id, used),
    CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(128) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used        TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_token (token),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Resource categories + per-category matching weights
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS resource_categories (
    category_id     INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(60) NOT NULL,
    weight_urgency  DECIMAL(4,2) NOT NULL DEFAULT 0.25,
    weight_distance DECIMAL(4,2) NOT NULL DEFAULT 0.25,
    weight_demand   DECIMAL(4,2) NOT NULL DEFAULT 0.30,
    weight_capacity DECIMAL(4,2) NOT NULL DEFAULT 0.20,
    UNIQUE KEY uq_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO resource_categories (name, weight_urgency, weight_distance, weight_demand, weight_capacity) VALUES
 ('Food',        0.45, 0.25, 0.15, 0.15),
 ('Clothes',     0.15, 0.25, 0.35, 0.25),
 ('Books',       0.10, 0.20, 0.45, 0.25),
 ('Electronics', 0.10, 0.20, 0.45, 0.25),
 ('Furniture',   0.10, 0.35, 0.30, 0.25);

-- ---------------------------------------------------------------------
-- Donations + media + lifecycle
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS donations (
    donation_id         INT AUTO_INCREMENT PRIMARY KEY,
    donor_id            INT NOT NULL,
    category_id         INT NOT NULL,
    title               VARCHAR(200) NOT NULL,
    description         TEXT NULL,
    quantity            INT NOT NULL DEFAULT 1,
    unit                VARCHAR(30) NOT NULL DEFAULT 'pcs',
    condition_grade     ENUM('new','like_new','good','fair') NOT NULL DEFAULT 'good',
    pickup_address      VARCHAR(255) NOT NULL,
    latitude            DOUBLE NULL,
    longitude           DOUBLE NULL,
    expiry_datetime     DATETIME NULL,
    image_path          VARCHAR(255) NULL,
    status              ENUM('pending_approval','available','matched','accepted','pickup','delivered','completed','cancelled','rejected')
                        NOT NULL DEFAULT 'pending_approval',
    matched_receiver_id INT NULL,
    matched_score       DECIMAL(6,2) NULL,
    approved_by         INT NULL,
    approved_at         DATETIME NULL,
    rejection_reason    VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_don_status (status),
    INDEX idx_don_donor (donor_id),
    INDEX idx_don_receiver (matched_receiver_id),
    INDEX idx_don_cat (category_id),
    CONSTRAINT fk_don_donor FOREIGN KEY (donor_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_don_cat   FOREIGN KEY (category_id) REFERENCES resource_categories(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS donation_media (
    media_id    INT AUTO_INCREMENT PRIMARY KEY,
    donation_id INT NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    media_type  ENUM('image','video') NOT NULL DEFAULT 'image',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_media_don (donation_id),
    CONSTRAINT fk_media_don FOREIGN KEY (donation_id) REFERENCES donations(donation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lifecycle_log (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    donation_id INT NOT NULL,
    status      VARCHAR(30) NOT NULL,
    note        VARCHAR(255) NULL,
    changed_by  INT NULL,
    changed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ll_don (donation_id),
    INDEX idx_ll_status (status, changed_at),
    CONSTRAINT fk_ll_don FOREIGN KEY (donation_id) REFERENCES donations(donation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Receiver demand + requests + request/donation pairing
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS demand_profiles (
    demand_id       INT AUTO_INCREMENT PRIMARY KEY,
    receiver_id     INT NOT NULL,
    category_id     INT NOT NULL,
    needed_quantity INT NOT NULL DEFAULT 1,
    priority_level  ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demand (receiver_id, category_id),
    CONSTRAINT fk_dp_user FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_dp_cat  FOREIGN KEY (category_id) REFERENCES resource_categories(category_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS requests (
    request_id           INT AUTO_INCREMENT PRIMARY KEY,
    receiver_id          INT NOT NULL,
    category_id          INT NOT NULL,
    title                VARCHAR(200) NOT NULL,
    quantity_min         INT NOT NULL DEFAULT 1,
    quantity_max         INT NOT NULL DEFAULT 1,
    condition_preference ENUM('any','new','like_new','good','fair') NOT NULL DEFAULT 'any',
    priority             ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
    needed_by            DATETIME NULL,
    pickup_location      VARCHAR(255) NOT NULL,
    latitude             DOUBLE NULL,
    longitude            DOUBLE NULL,
    reason               TEXT NULL,
    additional_notes     TEXT NULL,
    status               VARCHAR(20) NOT NULL DEFAULT 'searching',   -- searching/matched/accepted/pickup/delivered/completed/cancelled/declined
    matched_donation_id  INT NULL,
    match_score          INT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_req_status (status),
    INDEX idx_req_recv (receiver_id),
    INDEX idx_req_don (matched_donation_id),
    CONSTRAINT fk_req_user FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_req_cat  FOREIGN KEY (category_id) REFERENCES resource_categories(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS donation_requests (
    donation_request_id INT AUTO_INCREMENT PRIMARY KEY,
    donation_id         INT NOT NULL,
    receiver_id         INT NOT NULL,
    request_id          INT NULL,
    message             VARCHAR(400) NULL,
    status              ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at        DATETIME NULL,
    UNIQUE KEY uq_dr (donation_id, receiver_id),
    INDEX idx_dr_status (status),
    CONSTRAINT fk_dr_don  FOREIGN KEY (donation_id) REFERENCES donations(donation_id) ON DELETE CASCADE,
    CONSTRAINT fk_dr_user FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Algorithm scores, notifications, impact, surveys, moderation
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS matches (
    match_id       INT AUTO_INCREMENT PRIMARY KEY,
    donation_id    INT NOT NULL,
    receiver_id    INT NOT NULL,
    urgency_score  DECIMAL(6,2) NOT NULL DEFAULT 0,
    distance_score DECIMAL(6,2) NOT NULL DEFAULT 0,
    demand_score   DECIMAL(6,2) NOT NULL DEFAULT 0,
    capacity_score DECIMAL(6,2) NOT NULL DEFAULT 0,
    total_score    DECIMAL(6,2) NOT NULL DEFAULT 0,
    is_selected    TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_match_don (donation_id),
    CONSTRAINT fk_m_don  FOREIGN KEY (donation_id) REFERENCES donations(donation_id) ON DELETE CASCADE,
    CONSTRAINT fk_m_user FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    type            ENUM('matching','lifecycle','reminder','request','system') NOT NULL DEFAULT 'system',
    title           VARCHAR(200) NOT NULL,
    message         TEXT NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, is_read),
    CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS impact_log (
    impact_id              INT AUTO_INCREMENT PRIMARY KEY,
    donation_id            INT NOT NULL,
    category_id            INT NOT NULL,
    quantity               INT NOT NULL DEFAULT 0,
    estimated_weight_kg    DECIMAL(10,2) NOT NULL DEFAULT 0,
    estimated_co2_saved_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
    beneficiary_id         INT NULL,
    completed_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_imp_cat (category_id),
    CONSTRAINT fk_imp_don FOREIGN KEY (donation_id) REFERENCES donations(donation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS donation_surveys (
    survey_id         INT AUTO_INCREMENT PRIMARY KEY,
    donation_id       INT NOT NULL,
    user_id           INT NOT NULL,
    role              VARCHAR(20) NOT NULL,
    rating            TINYINT NOT NULL,
    item_as_described TINYINT(1) NULL,
    on_time           TINYINT(1) NULL,
    communication     TINYINT NULL,
    comment           VARCHAR(500) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_survey (donation_id, user_id),
    CONSTRAINT fk_sv_don  FOREIGN KEY (donation_id) REFERENCES donations(donation_id) ON DELETE CASCADE,
    CONSTRAINT fk_sv_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS moderation_reports (
    report_id        INT AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(200) NOT NULL,
    detail           VARCHAR(255) NULL,
    type             ENUM('resource','user','verification') NOT NULL DEFAULT 'resource',
    priority         ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status           ENUM('open','resolved','rejected') NOT NULL DEFAULT 'open',
    reported_by      INT NULL,
    donation_id      INT NULL,
    reported_user_id INT NULL,
    reason           TEXT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at      DATETIME NULL,
    INDEX idx_mr_status (status, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Delivery Hub (area-based drop-off / pick-up points)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_hubs (
    hub_id      INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    area        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    phone       VARCHAR(40)  NULL,
    open_hours  VARCHAR(120) NULL,
    manager_user_id INT NULL,                 -- hub staff account (users.role = 'hub')
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

CREATE TABLE IF NOT EXISTS hub_staff (
    user_id     INT NOT NULL PRIMARY KEY,               -- users.role = 'hub'
    hub_id      INT NOT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hs_hub (hub_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
