-- Macrolab website - one time entry per person, project and day.
--
-- The timesheet is a grid with one hours cell per project for the chosen day,
-- so a cell has to map onto exactly one row. Until now nothing stopped two
-- entries for the same project on the same day.
--
-- Any such duplicates are folded into the oldest of them first: minutes are
-- summed, and the notes are joined in the order they were written. The joined
-- note is cut at 255 characters, which is the one place a note is ever
-- shortened, and only for rows that predate this rule.
--
-- Migrator::split() breaks this file on every semicolon, including one inside
-- a string, so the note separator below is deliberately not a semicolon.

UPDATE time_entries t
  JOIN (SELECT MIN(id) AS keep_id,
               SUM(minutes) AS total,
               LEFT(GROUP_CONCAT(note ORDER BY id SEPARATOR ' / '), 255) AS notes
          FROM time_entries
         GROUP BY user_id, project_id, worked_on
        HAVING COUNT(*) > 1) d
    ON t.id = d.keep_id
   SET t.minutes = LEAST(d.total, 65535),
       t.note = d.notes,
       t.updated_at = UTC_TIMESTAMP();

DELETE t
  FROM time_entries t
  JOIN (SELECT user_id, project_id, worked_on, MIN(id) AS keep_id
          FROM time_entries
         GROUP BY user_id, project_id, worked_on
        HAVING COUNT(*) > 1) d
    ON t.user_id = d.user_id
   AND t.project_id = d.project_id
   AND t.worked_on = d.worked_on
   AND t.id <> d.keep_id;

-- Day first, so the key also serves "this person's entries on this day", which
-- is what the timesheet asks for. That makes ix_time_entries_user_day, a
-- prefix of it, redundant.
ALTER TABLE time_entries
    ADD UNIQUE KEY uq_time_entries_user_day_project (user_id, worked_on, project_id),
    DROP KEY ix_time_entries_user_day;
