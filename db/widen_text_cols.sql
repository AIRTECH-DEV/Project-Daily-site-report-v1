-- Migration: widen submission summary columns that overflow VARCHAR(190).
-- Cause: work_done_by (team members + works) and current_status (per-step
-- status summary) grow with team size / step count and crashed the INSERT with
-- SQLSTATE[22001] 1406 "Data too long". TEXT removes the ceiling.
-- Idempotent: re-running MODIFY to the same type is a no-op.

USE `pms`;

ALTER TABLE `submissions`
  MODIFY `work_done_by`   TEXT NULL,
  MODIFY `current_status` TEXT NULL;
