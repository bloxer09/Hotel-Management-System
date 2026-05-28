-- Apply only if you want a manual SQL patch in phpMyAdmin.
-- The updated PHP code also auto-applies these columns at runtime.

ALTER TABLE room_types ADD COLUMN short_time_3h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE room_types ADD COLUMN short_time_6h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE room_types ADD COLUMN short_time_12h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE room_types ADD COLUMN short_time_24h_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00;

ALTER TABLE bookings MODIFY COLUMN booking_type ENUM('overnight','hourly','short_time') DEFAULT 'overnight';
ALTER TABLE bookings ADD COLUMN short_time_hours INT DEFAULT NULL AFTER booking_type;
ALTER TABLE bookings ADD COLUMN payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid' AFTER amount_paid;
ALTER TABLE bookings ADD COLUMN late_hours INT NOT NULL DEFAULT 0 AFTER late_checkout_fee;

UPDATE room_types
SET short_time_3h_rate = CASE WHEN IFNULL(short_time_3h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 3, 2) ELSE short_time_3h_rate END,
    short_time_6h_rate = CASE WHEN IFNULL(short_time_6h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 6, 2) ELSE short_time_6h_rate END,
    short_time_12h_rate = CASE WHEN IFNULL(short_time_12h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 12, 2) ELSE short_time_12h_rate END,
    short_time_24h_rate = CASE WHEN IFNULL(short_time_24h_rate,0) = 0 THEN ROUND(IFNULL(hourly_rate,0) * 24, 2) ELSE short_time_24h_rate END;

UPDATE bookings
SET payment_status = CASE
    WHEN IFNULL(amount_paid,0) <= 0 THEN 'unpaid'
    WHEN IFNULL(amount_paid,0) + 0.009 >= IFNULL(total_amount,0) THEN 'paid'
    ELSE 'partial'
END;
