-- ---------------------------------------------------------------------------
-- Clean up attendance rows duplicated by a terminal that re-uploads its log.
-- ---------------------------------------------------------------------------
--
-- WHY THIS EXISTS
--
-- The terminal cannot advance its upload watermark, so it re-sends its whole
-- log. Ingest used to insert unconditionally, so every replay added a fresh row
-- for a punch the server already had.
--
-- Measured on 2026-09-22 for SN 6339151200543:
--   10,389 rows stored for a single day, of which 9,559 were distinct punches
--   -> 830 duplicate rows, and every one of them was counted by
--      Device::getTimezoneDiscrepancyCount() as a "time discrepancy".
--
-- Ingest now drops rows whose (sn, employee_id, timestamp) is already stored,
-- so this script only has to clean up what accumulated before that fix landed.
-- It does NOT stop the terminal from re-uploading; see "STILL OPEN" at the end.
--
-- A punch is identified by (sn, employee_id, timestamp) — who punched, on which
-- terminal, and when. Two rows sharing that triple are the same event.
--
-- Database: absensiv2
--
-- ---------------------------------------------------------------------------
-- RUNNING THIS IN DBEAVER
-- ---------------------------------------------------------------------------
--
-- 1. Connect to absensiv2, open this file, then use "Execute SQL Script"
--    (Alt+X), NOT "Execute SQL Statement" (Ctrl+Enter).
--
--    Everything that deletes is commented out, so running the whole file only
--    runs STEP 0 and touches nothing. That is deliberate — do it first and read
--    the numbers.
--
-- 2. STEP 0 returns three result sets; DBeaver shows them as separate tabs.
--    Switch to the "Script" results tab to see all of them.
--
-- 3. Before STEP 1/STEP 2, turn Auto-commit OFF (the toolbar toggle, or
--    Connection settings). With Auto-commit ON a mistake is already permanent
--    by the time you notice it.
--
--    Caveat: CREATE TABLE causes an implicit commit in MySQL/MariaDB, so the
--    backup from STEP 1 is committed no matter what. Only the DELETE in STEP 2
--    is actually rollbackable.
--
-- 4. Uncomment ONE step at a time, run it, read the result, then move on. Check
--    that the DELETE's reported row count is in the same ballpark as
--    "duplicate rows (deletable)" from STEP 0 before you COMMIT.
--
-- 5. DBeaver will offer to commit after a script run. If the count looks wrong,
--    choose Rollback instead.
--
-- CLI equivalent (if you ever prefer it):
--   mysql -u <user> -p absensiv2 < scripts/dedupe-attendances.sql   # STEP 0 only

-- ---------------------------------------------------------------------------
-- STEP 0 — read-only. Run this alone and read the numbers.
-- ---------------------------------------------------------------------------

SELECT 'rows in table' AS metric, COUNT(*) AS value
FROM attendances
UNION ALL
SELECT 'distinct punches',
       (SELECT COUNT(*) FROM (
            SELECT sn, employee_id, timestamp
            FROM attendances
            GROUP BY sn, employee_id, timestamp
        ) d)
UNION ALL
SELECT 'duplicate rows (deletable)',
       COUNT(*) - COUNT(DISTINCT sn, employee_id, timestamp)
FROM attendances;

-- Worst offenders first. Sanity-check a few by eye before deleting: rows with
-- the same punch time should differ only in `stamp`/`created_at`, never in the
-- status columns.
SELECT sn,
       employee_id,
       timestamp,
       COUNT(*)        AS copies,
       MIN(id)         AS keep_id,
       MAX(id)         AS last_copy_id,
       MIN(created_at) AS first_seen,
       MAX(created_at) AS last_seen
FROM attendances
GROUP BY sn, employee_id, timestamp
HAVING COUNT(*) > 1
ORDER BY copies DESC, timestamp DESC
LIMIT 25;

-- If the DELETE is going to be chunked (see STEP 2), you need the id span.
SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS total_rows
FROM attendances;

-- ---------------------------------------------------------------------------
-- STEP 1 — back up. Must be run and verified before STEP 2.
-- ---------------------------------------------------------------------------

-- CREATE TABLE attendances_backup_20260922 LIKE attendances;
-- INSERT INTO attendances_backup_20260922 SELECT * FROM attendances;
-- SELECT COUNT(*) AS backup_rows FROM attendances_backup_20260922;
--   ^ must equal "rows in table" from STEP 0

-- ---------------------------------------------------------------------------
-- STEP 2 — delete the later copies, keeping the earliest row of each punch.
--
-- Keeps MIN(id): that is the row the terminal actually pushed first, so
-- `created_at` still reflects when the punch genuinely arrived.
--
-- Run it inside a transaction so you can roll back if the count surprises you.
--
-- CHUNKING: do NOT reach for LIMIT. MySQL/MariaDB reject it on the multiple-
-- table DELETE syntax ("ERROR 1064 ... near 'LIMIT 5000'"), and the single-table
-- form that would allow it cannot reference `attendances` in a subquery (error
-- 1093). Narrow the range with an `a.id BETWEEN` window instead and raise the
-- upper bound each pass, until STEP 0 reports 0 duplicate rows.
--
-- Verified on MariaDB 11.0.3: the statement below turned 6 rows into 3 and kept
-- exactly the earliest row of each punch.
-- ---------------------------------------------------------------------------

-- START TRANSACTION;

-- DELETE a
-- FROM attendances a
-- JOIN (
--     SELECT sn, employee_id, timestamp, MIN(id) AS keep_id
--     FROM attendances
--     GROUP BY sn, employee_id, timestamp
--     HAVING COUNT(*) > 1
-- ) k
--   ON a.sn           = k.sn
--   AND a.employee_id = k.employee_id
--   AND a.timestamp   = k.timestamp
--   AND a.id         <> k.keep_id
-- WHERE a.id BETWEEN 1 AND 5000;   -- chunk window; drop this line for one pass

-- SELECT ROW_COUNT() AS deleted_this_pass;

-- Read `deleted_this_pass`, then pick one:
-- COMMIT;
-- ROLLBACK;

-- ---------------------------------------------------------------------------
-- STEP 3 — verify, then re-run STEP 0. "duplicate rows" must be 0.
-- ---------------------------------------------------------------------------

-- SELECT COUNT(*) AS rows_after,
--        COUNT(DISTINCT sn, employee_id, timestamp) AS punches_after
-- FROM attendances;
--   ^ the two numbers must now be equal

-- ---------------------------------------------------------------------------
-- OPTIONAL — enforce it in the schema
-- ---------------------------------------------------------------------------
--
-- A unique index on (sn, employee_id, timestamp) makes duplicates impossible
-- instead of merely unlikely. Two things must happen together, or it will make
-- things worse:
--
--   1. STEP 2 must have removed every existing duplicate. CREATE UNIQUE INDEX
--      fails while duplicates remain, which is the correct behaviour.
--   2. iclockController::receiveAttendanceRecords() must switch its
--      DB::table('attendances')->insert($chunk) to insertOrIgnore($chunk).
--      With a plain insert, a duplicate that slips past the application-level
--      check now raises SQLSTATE 23000 and the terminal gets a 500.
--
-- The trade-off: a terminal whose clock is wrong can legitimately emit two
-- punches for one employee in the same second. The unique index would reject
-- the second one. Fix the office timezone first (see STILL OPEN).
--
-- ALTER TABLE attendances
--   ADD UNIQUE INDEX attendances_punch_unique (sn, employee_id, timestamp);
--
-- The index also speeds up Device::getTimezoneDiscrepancyCount(), which
-- currently filters on sn + timestamp with no index to help it.

-- ---------------------------------------------------------------------------
-- STILL OPEN — the root cause, not the symptom
-- ---------------------------------------------------------------------------
--
-- 1. oficinas.timezone is 'UTC' for an Indonesian office. Besides showing the
--    wrong clock on the monitor, getrequest() sends
--    `SET OPTIONS DateTime=` built from that timezone, so the server tells the
--    terminal to move its clock 7 hours. It should be 'Asia/Jakarta'.
--
--    Check first, the correct id differs per deployment:
--      SELECT idoficina, idempresa, ubicacion, timezone FROM oficinas;
--    Then:
--      UPDATE oficinas SET timezone = 'Asia/Jakarta' WHERE idoficina = <id>;
--
-- 2. The terminal re-uploads because it never gets a usable upload watermark
--    (handshake replies OpStamp=<current unix time>). Until that is fixed,
--    dedup is what keeps the table from growing without bound.
