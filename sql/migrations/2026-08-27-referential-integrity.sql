-- Migration: referential integrity + redundant index removal
-- Target: existing installations. Fresh installs already get this from schema.sql.
-- Status: NOT APPLIED. Nothing in the application runs this file; run it by hand.
--
-- Order of work:
--   1. Take a backup.
--   2. Run section A and confirm every count is 0.
--   3. Run section B.
--   4. Read section C and D and decide; they are intentionally not executable.
--   5. Keep section E to hand in case of rollback.
--
-- Backup first (adjust names):
--   mysqldump --single-transaction --routines --triggers DBNAME > backup-before-fk.sql


-- =====================================================================
-- A. PREFLIGHT - all three MUST return 0 before section B is run.
--    ALTER TABLE ... ADD CONSTRAINT fails on pre-existing orphans, and a
--    failed ALTER on a large InnoDB table is an expensive way to find out.
-- =====================================================================

-- A1. Transactions pointing at a sender row that no longer exists.
SELECT COUNT(*) AS orphan_transactions
FROM payment_transactions pt
LEFT JOIN team_members tm ON tm.id = pt.team_member_id
WHERE pt.team_member_id IS NOT NULL AND tm.id IS NULL;

-- A2. Obligations pointing at a transaction that no longer exists.
SELECT COUNT(*) AS orphan_obligation_transactions
FROM weekly_payment_obligations w
LEFT JOIN payment_transactions pt ON pt.id = w.payment_transaction_id
WHERE w.payment_transaction_id IS NOT NULL AND pt.id IS NULL;

-- A3. Obligations pointing at a sender row that no longer exists.
--     B4 cannot be created while any of these exist.
SELECT COUNT(*) AS orphan_obligation_members
FROM weekly_payment_obligations w
LEFT JOIN team_members tm ON tm.id = w.team_member_id
WHERE tm.id IS NULL;

-- A4. Read this before running B4. It lists the settled weeks that a future
--     sender deletion will silently take with it once CASCADE is in place.
--     A zero here means CASCADE changes nothing about existing data.
SELECT tm.id AS sender_id, tm.display_name, COUNT(*) AS paid_weeks_at_risk
FROM weekly_payment_obligations w
JOIN team_members tm ON tm.id = w.team_member_id
WHERE w.status = 'paid'
GROUP BY tm.id, tm.display_name
ORDER BY paid_weeks_at_risk DESC;

-- If A1 is non-zero, this detaches those rows without losing the transaction.
-- The readers already fall back to payment_transactions.sender_alias.
--   UPDATE payment_transactions pt
--   LEFT JOIN team_members tm ON tm.id = pt.team_member_id
--   SET pt.team_member_id = NULL
--   WHERE pt.team_member_id IS NOT NULL AND tm.id IS NULL;

-- If A2 is non-zero, clear the dangling evidence pointer.
--   UPDATE weekly_payment_obligations w
--   LEFT JOIN payment_transactions pt ON pt.id = w.payment_transaction_id
--   SET w.payment_transaction_id = NULL
--   WHERE w.payment_transaction_id IS NOT NULL AND pt.id IS NULL;


-- =====================================================================
-- B. APPLY - safe, behaviour-preserving changes.
-- =====================================================================

-- B1. Drop the redundant index.
--     uq_team_members_normalized_alias already makes normalized_alias unique, so
--     a lookup on it matches at most one row; the trailing is_active column in
--     idx_team_members_alias_active cannot narrow that any further. The index is
--     pure write amplification on every INSERT/UPDATE of team_members.
--     Confirm with:
--       EXPLAIN SELECT id FROM team_members WHERE normalized_alias = 'x' AND is_active = 1;
--     before and after - the chosen key should stay uq_team_members_normalized_alias.
ALTER TABLE team_members
    DROP INDEX idx_team_members_alias_active;

-- B2. payment_transactions.team_member_id -> team_members.id
--     ON DELETE SET NULL matches what the application already permits: a sender
--     can be deleted while its transactions remain, and readers LEFT JOIN and
--     fall back to the denormalised sender_alias. The existing index
--     idx_payment_transactions_member_created supplies the required prefix, so
--     no extra index is created.
ALTER TABLE payment_transactions
    ADD CONSTRAINT fk_payment_transactions_member
    FOREIGN KEY (team_member_id) REFERENCES team_members (id)
    ON DELETE SET NULL;

-- B3. weekly_payment_obligations.payment_transaction_id -> payment_transactions.id
--     RESTRICT costs nothing today because no code path deletes a transaction.
--     It exists so a future delete path cannot strip the evidence from an
--     obligation already marked paid. uq_weekly_payment_transaction supplies the
--     required index.
ALTER TABLE weekly_payment_obligations
    ADD CONSTRAINT fk_weekly_payment_transaction
    FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions (id)
    ON DELETE RESTRICT;

-- B4. weekly_payment_obligations.team_member_id -> team_members.id
--     CASCADE, chosen deliberately (see section C for what was weighed).
--     Requires A3 = 0. Read A4 first: this is the statement that makes a sender
--     deletion also erase that sender's settled weekly ledger.
--     TeamRepository::delete() still blocks while 'pending' or 'unpaid' rows
--     exist, so only 'paid' rows can reach the cascade. payment_transactions is
--     untouched by it - that FK is SET NULL - so the money history survives.
--     uq_weekly_payment_member_week supplies the required leftmost prefix.
ALTER TABLE weekly_payment_obligations
    ADD CONSTRAINT fk_weekly_payment_member
    FOREIGN KEY (team_member_id) REFERENCES team_members (id)
    ON DELETE CASCADE;

-- B5. Scope reference_no uniqueness to the sender (see section D).
--     No preflight is needed: the new constraint is strictly weaker than the one
--     it replaces. Any dataset that satisfied a global unique on reference_no
--     necessarily satisfies a per-sender unique on it, so this ALTER cannot fail
--     on existing data. Both operations are in one statement so the table is
--     never left without a reference_no constraint.
ALTER TABLE payment_transactions
    DROP INDEX uq_payment_transactions_reference_no,
    ADD UNIQUE KEY uq_payment_transactions_member_reference (team_member_id, reference_no);


-- =====================================================================
-- C. DECIDED - weekly_payment_obligations.team_member_id -> CASCADE
--     Applied as B4. Recorded here so the reasoning is not lost.
--
--     Before this migration the column had no constraint at all.
--     TeamRepository::delete() refuses to delete a sender while 'pending' or
--     'unpaid' obligations exist, but never checked 'paid' rows, so a delete
--     could leave paid obligations behind. Those orphans were already
--     unreadable: the dashboard drives its query from team_members, so nothing
--     ever joined back to them.
--
--     Chosen - CASCADE. A deleted sender takes its weekly ledger with it,
--     settled weeks included. The table stays consistent and the previously
--     unreachable rows stop accumulating. The cost is that the per-week
--     settlement record for that sender is gone; payment_transactions rows are
--     NOT deleted (that FK is SET NULL), so the underlying payments remain
--     queryable by sender_name and sender_alias.
--
--     Rejected - RESTRICT. It would have preserved every settled week, but any
--     sender with even one paid obligation would become undeletable, turning a
--     currently working operation into a hard failure and requiring the error
--     message in TeamRepository::delete() to change with it.
--
--     Operational consequence: if a sender must be removed while its settled
--     history matters, disable it instead of deleting it. Disabling already
--     keeps every past obligation row intact.
-- =====================================================================


-- =====================================================================
-- D. DECIDED - reference_no uniqueness scoped per sender
--     Applied as B5. Recorded here so the reasoning is not lost.
--
--     reference_no was UNIQUE across the whole table. A reference number is only
--     unique within the issuing bank's own numbering, so two banks can mint the
--     same string legitimately - and the second receipt was then rejected as a
--     duplicate it never was. Scoping to (team_member_id, reference_no) keeps the
--     protection that matters (the same sender cannot file one reference twice)
--     and drops the false rejection across senders.
--
--     Unchanged by this: receipts whose reference could not be parsed store NULL,
--     and MySQL treats NULLs in a unique index as distinct, so those rows never
--     constrained each other and still rely on uq_payment_transactions_image_sha256.
--
--     Read side is unaffected - reference_no appears only in INSERT and in SELECT
--     projections, never in a WHERE clause, so the dropped index served no lookup.
-- =====================================================================


-- =====================================================================
-- E. ROLLBACK for section B.
-- =====================================================================
-- B5 first, and unlike the others this one can FAIL. Going back to a global
-- unique is a tightening, so any cross-sender duplicate reference recorded since
-- B5 will block it. Find them before attempting the revert:
--   SELECT reference_no, COUNT(*) AS hits
--   FROM payment_transactions
--   WHERE reference_no IS NOT NULL
--   GROUP BY reference_no HAVING hits > 1;
--
--   ALTER TABLE payment_transactions
--       DROP INDEX uq_payment_transactions_member_reference,
--       ADD UNIQUE KEY uq_payment_transactions_reference_no (reference_no);
--
--   ALTER TABLE weekly_payment_obligations DROP FOREIGN KEY fk_weekly_payment_member;
--   ALTER TABLE weekly_payment_obligations DROP FOREIGN KEY fk_weekly_payment_transaction;
--   ALTER TABLE payment_transactions       DROP FOREIGN KEY fk_payment_transactions_member;
--   ALTER TABLE team_members
--       ADD KEY idx_team_members_alias_active (normalized_alias, is_active);
--
-- Dropping fk_weekly_payment_member restores the old permissiveness, but it does
-- NOT bring back rows a cascade already removed. Only the pre-migration backup
-- can do that.
--
-- Verify after rollback:
--   SHOW CREATE TABLE payment_transactions;
--   SHOW CREATE TABLE weekly_payment_obligations;
--   SHOW INDEX FROM team_members;
