-- Macrolab website - initial schema.
--
-- All DATETIME columns store UTC. Nothing in the database is in local time;
-- conversion to Europe/Amsterdam happens only at display. This keeps bookings
-- unambiguous across the March and October DST transitions.
--
-- The SAML columns (users.saml_name_id, saml_assertion_ids) are created here
-- even though stage 1 does not use them, so that enabling TU Delft SSO later
-- needs no schema change on a live database.

CREATE TABLE resources (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(128) NOT NULL,
    slug        VARCHAR(64)  NOT NULL,
    description TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resources_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- This table IS the access allowlist, in both authentication stages. A netID
-- with no row here cannot log in, by either method.
CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    netid               VARCHAR(64)  NOT NULL,
    display_name        VARCHAR(191) NULL,
    email               VARCHAR(191) NULL,
    status              ENUM('approved','suspended') NOT NULL DEFAULT 'approved',
    -- Stage 1 local login. NULL until the user completes an invite link.
    password_hash       VARCHAR(255) NULL,
    password_changed_at DATETIME     NULL,
    -- Stage 2. Persistent SAML NameID, recorded on first SSO login for logout.
    saml_name_id        VARCHAR(255) NULL,
    note                VARCHAR(255) NULL,
    created_at          DATETIME     NOT NULL,
    first_login_at      DATETIME     NULL,
    last_login_at       DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_netid (netid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-use links that let a user set their own password. The admin never
-- learns the password. Tokens are stored hashed; the plaintext exists only in
-- the link handed to the user.
CREATE TABLE user_invites (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    purpose    ENUM('setup','reset') NOT NULL DEFAULT 'setup',
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_invites_token (token_hash),
    KEY ix_user_invites_user (user_id, used_at),
    CONSTRAINT fk_user_invites_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Intervals are half-open: [starts_at, ends_at). A booking ending at 10:00 and
-- one starting at 10:00 do not overlap.
CREATE TABLE bookings (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id      INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    starts_at        DATETIME     NOT NULL,
    ends_at          DATETIME     NOT NULL,
    purpose          VARCHAR(255) NULL,
    status           ENUM('confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
    created_by_admin TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL,
    updated_at       DATETIME     NOT NULL,
    cancelled_at     DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_bookings_resource_window (resource_id, status, starts_at, ends_at),
    KEY ix_bookings_user (user_id, starts_at),
    CONSTRAINT fk_bookings_resource FOREIGN KEY (resource_id)
        REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The single local administrator. Password + mandatory TOTP; unaffected by
-- auth_mode, so the admin can always get in without TU Delft SSO.
CREATE TABLE admin_account (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username            VARCHAR(64)  NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    -- TOTP secret encrypted with app.key, so a database dump alone does not
    -- yield the second factor.
    totp_secret_enc     BLOB         NULL,
    totp_last_counter   BIGINT       NULL,
    totp_confirmed_at   DATETIME     NULL,
    password_changed_at DATETIME     NOT NULL,
    created_at          DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE admin_recovery_codes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id   INT UNSIGNED NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_recovery_admin (admin_id, used_at),
    CONSTRAINT fk_recovery_admin FOREIGN KEY (admin_id)
        REFERENCES admin_account (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Booking rules and auth_mode. Defaults live in Settings::DEFAULTS; a row here
-- overrides the default.
CREATE TABLE settings (
    `key`       VARCHAR(64) NOT NULL,
    `value`     TEXT        NULL,
    updated_at  DATETIME    NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only. Never updated or deleted except by the retention prune.
CREATE TABLE audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type  ENUM('user','admin','system','anonymous') NOT NULL,
    actor_id    INT UNSIGNED    NULL,
    actor_label VARCHAR(128)    NOT NULL,
    action      VARCHAR(64)     NOT NULL,
    target_type VARCHAR(32)     NULL,
    target_id   INT UNSIGNED    NULL,
    details     TEXT            NULL,
    ip          VARBINARY(16)   NULL,
    user_agent  VARCHAR(255)    NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_audit_created (created_at),
    KEY ix_audit_action (action, created_at),
    KEY ix_audit_actor (actor_type, actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Login throttling. Written for both user and admin attempts; `identifier` is
-- the submitted netID or admin username, lowercased.
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(128)    NOT NULL,
    ip         VARBINARY(16)   NULL,
    success    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_attempts_identifier (identifier, created_at),
    KEY ix_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stage 2 replay protection: php-saml validates an assertion but does not
-- remember that it has seen it, so we do.
CREATE TABLE saml_assertion_ids (
    assertion_id VARCHAR(255) NOT NULL,
    expires_at   DATETIME     NOT NULL,
    PRIMARY KEY (assertion_id),
    KEY ix_assertion_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A first machine, so a fresh installation has something bookable. The lab runs
-- several; the rest are added in the administration pages.
INSERT INTO resources (name, slug, description, is_active, created_at)
VALUES ('LUNA OD6', 'luna-od6', 'LUNA OD6 machine', 1, UTC_TIMESTAMP());
