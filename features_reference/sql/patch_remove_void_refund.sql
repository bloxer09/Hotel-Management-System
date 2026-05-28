-- Remove Void / Refund module data and schema
USE hotel_pms;

SET FOREIGN_KEY_CHECKS = 0;

-- Remove existing void/refund requests table entirely
DROP TABLE IF EXISTS void_refunds;

-- Remove audit trail entries created by the old module
DELETE FROM audit_logs
WHERE module IN ('void_refunds', 'voidrefund')
   OR action IN ('VOID_REQUESTED', 'VOID_APPROVED', 'VOID_REJECTED');

-- Remove old transaction rows that used refund/void types
DELETE FROM transactions
WHERE transaction_type IN ('refund', 'void');

SET FOREIGN_KEY_CHECKS = 1;

-- Rebuild transactions.transaction_type enum without refund/void
ALTER TABLE transactions
MODIFY COLUMN transaction_type ENUM('check_in','check_out','extension','adjustment','inventory') DEFAULT 'check_in';
