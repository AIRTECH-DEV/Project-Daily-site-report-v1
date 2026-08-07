-- Migration: allow the pre-commissioning pipeline step + attachment kind.
--
-- Cause (prod outage 2026-08-07): SubmitService::runCore() now opens a
-- 'precommissioning_report' step and files a 'pre_commissioning_report'
-- attachment, but `process_log`.`step` and `attachments`.`kind` are ENUMs that
-- never learned those values. On MySQL 8 (STRICT_TRANS_TABLES) the INSERT dies
-- with 1265 "Data truncated for column 'step'". That stepStart() sat OUTSIDE
-- the step's try/catch, so runCore() aborted BEFORE the response-sheet write --
-- killing the whole submission: no sheet row, no PMS stamp, no PDF, no email,
-- no WhatsApp. Every submission after the deploy stayed 'queued' in
-- storage/queue/ and failed again once a minute.
--
-- Idempotent: re-running MODIFY to the same type is a no-op.

USE `pms`;

ALTER TABLE `process_log`
  MODIFY `step` ENUM(
    'sheet_write','photo_save','pms_update','pdf','email','whatsapp',
    'precommissioning_report'
  ) NOT NULL;

ALTER TABLE `attachments`
  MODIFY `kind` ENUM(
    'site_photo','drawing','measurement','pdf',
    'pre_commissioning_report'
  ) NOT NULL;
