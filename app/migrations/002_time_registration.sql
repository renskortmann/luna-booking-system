-- Macrolab website - time registration.
--
-- Independent of the booking system. A time entry names a project, never a
-- machine, and nothing in this migration references `resources` or `bookings`.
-- The two halves of the site share only the user account that signs in.
--
-- `worked_on` is a DATE and deliberately NOT a UTC instant. It is the calendar
-- day the employee says they worked, so it must never be shifted by a timezone
-- conversion. Every other timestamp here is UTC, as everywhere else.

CREATE TABLE projects (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(128) NOT NULL,
    code        VARCHAR(32)  NULL,
    description TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One employee, one project, one calendar day, one duration in whole minutes.
--
-- Minutes rather than hours: 3.5 h is 210, exactly, with no float anywhere near
-- the storage. The form accepts hours in several shapes and converts once, in
-- TimeRules::parseHours().
--
-- Both foreign keys RESTRICT, mirroring the bookings table, so "retire it,
-- don't delete it" is true at the database level and not only in a controller.
CREATE TABLE time_entries (
    id         INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED      NOT NULL,
    project_id INT UNSIGNED      NOT NULL,
    worked_on  DATE              NOT NULL,
    minutes    SMALLINT UNSIGNED NOT NULL,
    note       VARCHAR(255)      NULL,
    created_at DATETIME          NOT NULL,
    updated_at DATETIME          NOT NULL,
    PRIMARY KEY (id),
    KEY ix_time_entries_user_day (user_id, worked_on),
    KEY ix_time_entries_project_day (project_id, worked_on),
    KEY ix_time_entries_day (worked_on),
    CONSTRAINT fk_time_entries_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_time_entries_project FOREIGN KEY (project_id)
        REFERENCES projects (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
