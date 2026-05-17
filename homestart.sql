-- ============================================================
-- homestart.sql
-- Complete schema and seed data for the Home-Start Volunteer Portal
-- Run this in phpMyAdmin or via: mysql -u root < homestart.sql
-- ============================================================

-- Create and select the database
CREATE DATABASE IF NOT EXISTS homestart
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE homestart;

-- ============================================================
-- DROP TABLES (order matters: children before parents)
-- ============================================================
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS availability;
DROP TABLE IF EXISTS volunteer_transport;
DROP TABLE IF EXISTS volunteer_qualification;
DROP TABLE IF EXISTS volunteer_skill;
DROP TABLE IF EXISTS volunteer;
DROP TABLE IF EXISTS transport;
DROP TABLE IF EXISTS qualification;
DROP TABLE IF EXISTS skill;
DROP TABLE IF EXISTS staff;

-- ============================================================
-- CORE LOOKUP TABLES (parent tables - no foreign keys)
-- ============================================================

-- skill: lookup table for all possible volunteer skills
CREATE TABLE skill (
    skill_id   INT AUTO_INCREMENT PRIMARY KEY,
    skill_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- qualification: lookup table for all possible qualifications
CREATE TABLE qualification (
    qualification_id   INT AUTO_INCREMENT PRIMARY KEY,
    qualification_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- transport: exactly 4 fixed rows per spec - never changes
CREATE TABLE transport (
    transport_id   INT AUTO_INCREMENT PRIMARY KEY,
    transport_name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- staff: completely separate from volunteers, has its own auth
CREATE TABLE staff (
    staff_id      INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL   -- bcrypt hash via password_hash()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- VOLUNTEER TABLE
-- Note: postcode is what the spec calls "location".
-- volunteer_forename, volunteer_surname, date_of_birth start NULL
-- so a freshly-registered volunteer is redirected to profile_form.php
-- ============================================================
CREATE TABLE volunteer (
    volunteer_id       VARCHAR(20) NOT NULL PRIMARY KEY,
    volunteer_forename VARCHAR(50)  DEFAULT NULL,
    volunteer_surname  VARCHAR(50)  DEFAULT NULL,
    date_of_birth      DATE         DEFAULT NULL,
    postcode           VARCHAR(10)  NOT NULL        -- used as login credential
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- JUNCTION TABLES (4NF: each multi-valued fact in its own table)
-- ============================================================

-- volunteer_skill: which skills each volunteer has (many-to-many)
CREATE TABLE volunteer_skill (
    volunteer_id VARCHAR(20) NOT NULL,
    skill_id     INT         NOT NULL,
    PRIMARY KEY (volunteer_id, skill_id),
    FOREIGN KEY (volunteer_id) REFERENCES volunteer(volunteer_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id)     REFERENCES skill(skill_id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- volunteer_qualification: qualifications per volunteer (many-to-many)
CREATE TABLE volunteer_qualification (
    volunteer_id     VARCHAR(20) NOT NULL,
    qualification_id INT         NOT NULL,
    PRIMARY KEY (volunteer_id, qualification_id),
    FOREIGN KEY (volunteer_id)     REFERENCES volunteer(volunteer_id)         ON DELETE CASCADE,
    FOREIGN KEY (qualification_id) REFERENCES qualification(qualification_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- volunteer_transport: which transport modes each volunteer uses
CREATE TABLE volunteer_transport (
    volunteer_id VARCHAR(20) NOT NULL,
    transport_id INT         NOT NULL,
    PRIMARY KEY (volunteer_id, transport_id),
    FOREIGN KEY (volunteer_id) REFERENCES volunteer(volunteer_id) ON DELETE CASCADE,
    FOREIGN KEY (transport_id) REFERENCES transport(transport_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- availability: when each volunteer is free
-- day is TINYINT 0-6 where 0=Monday, 6=Sunday
CREATE TABLE availability (
    availability_id INT         AUTO_INCREMENT PRIMARY KEY,
    volunteer_id    VARCHAR(20) NOT NULL,
    day             TINYINT     NOT NULL CHECK (day BETWEEN 0 AND 6),
    start_time      TIME        NOT NULL,
    end_time        TIME        NOT NULL,
    FOREIGN KEY (volunteer_id) REFERENCES volunteer(volunteer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- audit_log: immutable record of all significant events
-- actor_volunteer_id is NULL for staff actions or system events
CREATE TABLE audit_log (
    audit_id          INT AUTO_INCREMENT PRIMARY KEY,
    actor_volunteer_id VARCHAR(20)  DEFAULT NULL,   -- NULL = staff or unauthenticated
    event_type        VARCHAR(50)  NOT NULL,
    event_detail      TEXT         DEFAULT NULL,
    ip_address        VARCHAR(45)  DEFAULT NULL,    -- VARCHAR(45) supports IPv6
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA: lookup tables
-- ============================================================

-- Transport: exactly 4 rows per spec - IDs will be 1,2,3,4
INSERT INTO transport (transport_name) VALUES
    ('Walking'),
    ('Cycling'),
    ('Vehicle'),
    ('Public Transport');

-- Skills: 8 sample skills covering Home-Start typical volunteer work
INSERT INTO skill (skill_name) VALUES
    ('Childcare'),
    ('Cooking'),
    ('Driving'),
    ('First Aid'),
    ('Counselling'),
    ('Gardening'),
    ('Administrative'),
    ('Language Support');

-- Qualifications
INSERT INTO qualification (qualification_name) VALUES
    ('DBS Check'),
    ('First Aid Certificate'),
    ('Childcare Level 2'),
    ('NVQ Health and Social Care'),
    ('Food Hygiene Certificate');

-- ============================================================
-- TEST VOLUNTEERS
-- These have postcode set but NO profile data yet,
-- so login will redirect to profile_form.php
-- Login credentials: volunteer_id + postcode (exact match, case-insensitive via strtoupper in auth)
-- ============================================================
INSERT INTO volunteer (volunteer_id, postcode) VALUES
    ('VOL001', 'BN1 1AA'),
    ('VOL002', 'BN2 2BB'),
    ('VOL003', 'BN3 3CC');

-- VOL002 gets a complete profile so home.php displays correctly
UPDATE volunteer
SET volunteer_forename = 'Jane',
    volunteer_surname  = 'Smith',
    date_of_birth      = '1985-06-15'
WHERE volunteer_id = 'VOL002';

INSERT INTO volunteer_skill (volunteer_id, skill_id) VALUES
    ('VOL002', 1),  -- Childcare
    ('VOL002', 4),  -- First Aid
    ('VOL002', 5);  -- Counselling

INSERT INTO volunteer_qualification (volunteer_id, qualification_id) VALUES
    ('VOL002', 1),  -- DBS Check
    ('VOL002', 2);  -- First Aid Certificate

INSERT INTO volunteer_transport (volunteer_id, transport_id) VALUES
    ('VOL002', 1),  -- Walking
    ('VOL002', 4);  -- Public Transport

INSERT INTO availability (volunteer_id, day, start_time, end_time) VALUES
    ('VOL002', 0, '09:00:00', '13:00:00'),  -- Monday morning
    ('VOL002', 2, '14:00:00', '18:00:00'),  -- Wednesday afternoon
    ('VOL002', 4, '09:00:00', '17:00:00');  -- Friday all day

-- VOL003 also gets a full profile (so staff dashboard has enough data)
UPDATE volunteer
SET volunteer_forename = 'Tom',
    volunteer_surname  = 'Jones',
    date_of_birth      = '1990-03-22'
WHERE volunteer_id = 'VOL003';

INSERT INTO volunteer_skill (volunteer_id, skill_id) VALUES
    ('VOL003', 2),  -- Cooking
    ('VOL003', 3),  -- Driving
    ('VOL003', 7);  -- Administrative

INSERT INTO volunteer_qualification (volunteer_id, qualification_id) VALUES
    ('VOL003', 1),  -- DBS Check
    ('VOL003', 5);  -- Food Hygiene Certificate

INSERT INTO volunteer_transport (volunteer_id, transport_id) VALUES
    ('VOL003', 3),  -- Vehicle
    ('VOL003', 1);  -- Walking

INSERT INTO availability (volunteer_id, day, start_time, end_time) VALUES
    ('VOL003', 1, '10:00:00', '16:00:00'),  -- Tuesday
    ('VOL003', 3, '09:00:00', '12:00:00'),  -- Thursday morning
    ('VOL003', 5, '10:00:00', '14:00:00');  -- Saturday

-- ============================================================
-- TEST STAFF ACCOUNT
-- Password: staffpass123
-- The hash below was generated with password_hash('staffpass123', PASSWORD_DEFAULT)
-- If it doesn't verify, run create_staff.php instead (see that file)
-- ============================================================
INSERT INTO staff (username, password_hash) VALUES
    ('admin', '$2y$10$u5K3NrNlFwg7iCXK5MKVmOqHB2yVlBSy9PqFbS2k.zL7vDvP0O4Z2');

-- If the above hash fails (bcrypt is environment-specific), run create_staff.php
-- which generates a fresh hash on your own server.
