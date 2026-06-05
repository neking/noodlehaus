-- ════════════════════════════════════════════════════════════════
--  NoodleHaus — Phase 6C Migration: Delivery Platform
--  Additive only — orders table မထိ
-- ════════════════════════════════════════════════════════════════

USE noodlehaus;

-- ────────────────────────────────────────────────────────────────
--  1. DRIVERS
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS drivers (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120)    NOT NULL,
    phone           VARCHAR(30)     NOT NULL,
    pin             VARCHAR(10)     NOT NULL,
    vehicle         ENUM('motorbike','bicycle','car','foot') NOT NULL DEFAULT 'motorbike',
    status          ENUM('available','busy','offline') NOT NULL DEFAULT 'offline',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    total_deliveries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_phone (phone),
    UNIQUE KEY uq_pin   (pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────────────────────────────────────────────────────────────
--  2. DELIVERY_ZONES (fee by township/area)
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS delivery_zones (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    zone_name       VARCHAR(80)     NOT NULL,
    fee             INT UNSIGNED    NOT NULL DEFAULT 1500 COMMENT 'MMK',
    estimated_min   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zone (zone_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed common Yangon townships
INSERT IGNORE INTO delivery_zones (zone_name, fee, estimated_min) VALUES
    ('Hlaing',       1500, 20),
    ('Kamayut',      1500, 25),
    ('Sanchaung',    1500, 20),
    ('Bahan',        2000, 30),
    ('Dagon',        2000, 30),
    ('Tamwe',        2000, 25),
    ('Yankin',       2500, 35),
    ('Insein',       3000, 40),
    ('North Okkalapa', 3000, 45),
    ('South Okkalapa', 3000, 45);

-- ────────────────────────────────────────────────────────────────
--  3. DELIVERY_ASSIGNMENTS (link driver to order)
-- ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS delivery_assignments (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    order_id        INT UNSIGNED    NOT NULL,
    driver_id       INT UNSIGNED    NOT NULL,
    status          ENUM('assigned','picked_up','on_the_way','delivered','failed')
                                    NOT NULL DEFAULT 'assigned',
    assigned_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    picked_up_at    DATETIME        DEFAULT NULL,
    delivered_at    DATETIME        DEFAULT NULL,
    notes           VARCHAR(255)    DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order (order_id),
    KEY idx_driver (driver_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed sample drivers
INSERT IGNORE INTO drivers (name, phone, pin, vehicle) VALUES
    ('Ko Thet',  '09300111222', '3001', 'motorbike'),
    ('Ma Nwe',   '09300222333', '3002', 'motorbike'),
    ('Ko Phyo',  '09300333444', '3003', 'bicycle');

-- ────────────────────────────────────────────────────────────────
--  4. Verify
-- ────────────────────────────────────────────────────────────────
SELECT 'drivers' AS tbl, COUNT(*) AS total FROM drivers
UNION ALL SELECT 'delivery_zones', COUNT(*) FROM delivery_zones
UNION ALL SELECT 'delivery_assignments', COUNT(*) FROM delivery_assignments;
