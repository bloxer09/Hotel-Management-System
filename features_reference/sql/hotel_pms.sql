-- Hotel Property Management System Database
-- For XAMPP / MySQL
-- Version 1.0

CREATE DATABASE IF NOT EXISTS hotel_pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hotel_pms;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin','front_desk','cashier','housekeeping') NOT NULL DEFAULT 'cashier',
    email VARCHAR(100),
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: room_types
-- ============================================================
CREATE TABLE IF NOT EXISTS room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL,
    description TEXT,
    base_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    hourly_rate DECIMAL(10,2) DEFAULT 0.00,
    max_occupancy INT DEFAULT 2,
    amenities TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: rooms
-- ============================================================
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    room_type_id INT NOT NULL,
    floor INT DEFAULT 1,
    status ENUM('vacant','occupied','cleaning','out_of_order') DEFAULT 'vacant',
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_type_id) REFERENCES room_types(id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: peak_dates
-- ============================================================
CREATE TABLE IF NOT EXISTS peak_dates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    label VARCHAR(100),
    surcharge_amount DECIMAL(10,2) DEFAULT 100.00,
    surcharge_type ENUM('fixed','percent') DEFAULT 'fixed',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: guest_profiles
-- ============================================================
CREATE TABLE IF NOT EXISTS guest_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20),
    id_type VARCHAR(50),
    id_number VARCHAR(50),
    email VARCHAR(100),
    address TEXT,
    is_vip TINYINT(1) DEFAULT 0,
    vip_notes TEXT,
    total_stays INT DEFAULT 0,
    total_spent DECIMAL(12,2) DEFAULT 0.00,
    last_visit DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: bookings
-- ============================================================
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_ref VARCHAR(20) NOT NULL UNIQUE,
    room_id INT NOT NULL,
    guest_profile_id INT,
    guest_name VARCHAR(100) NOT NULL,
    guest_contact VARCHAR(20),
    guest_id_type VARCHAR(50),
    guest_id_number VARCHAR(50),
    num_guests INT DEFAULT 1,
    booking_type ENUM('overnight','hourly') DEFAULT 'overnight',
    check_in DATETIME NOT NULL,
    check_out DATETIME,
    expected_check_out DATETIME,
    status ENUM('active','checked_out','cancelled','no_show') DEFAULT 'active',
    base_amount DECIMAL(10,2) DEFAULT 0.00,
    peak_surcharge DECIMAL(10,2) DEFAULT 0.00,
    discount_type VARCHAR(50),
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    extension_fee DECIMAL(10,2) DEFAULT 0.00,
    late_checkout_fee DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) DEFAULT 0.00,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    payment_method ENUM('cash','gcash','split') DEFAULT 'cash',
    cash_amount DECIMAL(10,2) DEFAULT 0.00,
    gcash_amount DECIMAL(10,2) DEFAULT 0.00,
    gcash_ref VARCHAR(50),
    is_peak TINYINT(1) DEFAULT 0,
    notes TEXT,
    checked_in_by INT,
    checked_out_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id),
    FOREIGN KEY (checked_in_by) REFERENCES users(id),
    FOREIGN KEY (checked_out_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT,
    transaction_type ENUM('check_in','check_out','extension','adjustment','inventory') DEFAULT 'check_in',
    description TEXT,
    amount DECIMAL(10,2) DEFAULT 0.00,
    payment_method ENUM('cash','gcash','split','na') DEFAULT 'cash',
    cash_amount DECIMAL(10,2) DEFAULT 0.00,
    gcash_amount DECIMAL(10,2) DEFAULT 0.00,
    gcash_ref VARCHAR(50),
    processed_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (processed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: audit_logs
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    module VARCHAR(50),
    record_id INT,
    old_value TEXT,
    new_value TEXT,
    reason TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;


-- ============================================================
-- TABLE: inventory_items
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(100) NOT NULL,
    category ENUM('minibar','toiletries','laundry','amenities','supplies') DEFAULT 'amenities',
    unit VARCHAR(20) DEFAULT 'pcs',
    current_stock INT DEFAULT 0,
    minimum_stock INT DEFAULT 5,
    unit_cost DECIMAL(10,2) DEFAULT 0.00,
    selling_price DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: inventory_usage
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT,
    item_id INT,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(10,2) DEFAULT 0.00,
    total_price DECIMAL(10,2) DEFAULT 0.00,
    recorded_by INT,
    notes VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (item_id) REFERENCES inventory_items(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: shift_sessions (for Start Shift / End Shift reporting)
-- ============================================================
CREATE TABLE IF NOT EXISTS shift_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_code ENUM('morning','evening','night') NOT NULL,
    scheduled_start TIME,
    scheduled_end TIME,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    opening_cash DECIMAL(10,2) DEFAULT 0.00,
    closing_cash DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Users (default password for all seeded accounts: password)
INSERT INTO users (username, password, full_name, role, email) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 'admin@hotel.com'),
('frontdesk1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos', 'front_desk', 'maria@hotel.com'),
('cashier1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Juan Dela Cruz', 'cashier', 'juan@hotel.com'),
('housekeeping1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ana Housekeeping', 'housekeeping', 'housekeeping@hotel.com');

-- NOTE: Default password for all accounts is: password
-- The hash above is for 'password' (Laravel default test hash)

-- Room Types
INSERT INTO room_types (type_name, description, base_rate, hourly_rate, max_occupancy, amenities) VALUES
('Standard Single', 'Comfortable single room with basic amenities', 800.00, 150.00, 1, 'AC, TV, Private Bathroom, WiFi'),
('Standard Double', 'Spacious double room for couples', 1200.00, 200.00, 2, 'AC, TV, Private Bathroom, WiFi, Double Bed'),
('Deluxe Double', 'Premium double room with city view', 1800.00, 300.00, 2, 'AC, Smart TV, Private Bathroom, WiFi, Minibar, City View'),
('Family Room', 'Large room for families up to 4 persons', 2500.00, 400.00, 4, 'AC, TV, Private Bathroom, WiFi, 2 Double Beds'),
('Suite', 'Luxury suite with separate living area', 4500.00, 700.00, 2, 'AC, Smart TV, Jacuzzi, Kitchenette, WiFi, Minibar, Balcony'),
('Budget Room', 'Affordable room for budget travelers', 500.00, 100.00, 1, 'Fan, TV, Shared Bathroom, WiFi');

-- Rooms
INSERT INTO rooms (room_number, room_type_id, floor, status) VALUES
('101', 6, 1, 'vacant'), ('102', 6, 1, 'vacant'),
('103', 1, 1, 'vacant'), ('104', 1, 1, 'vacant'),
('201', 2, 2, 'vacant'), ('202', 2, 2, 'vacant'),
('203', 3, 2, 'vacant'), ('204', 3, 2, 'vacant'),
('301', 4, 3, 'vacant'), ('302', 4, 3, 'vacant'),
('401', 5, 4, 'vacant'), ('402', 5, 4, 'vacant');

-- Peak Dates
INSERT INTO peak_dates (date_from, date_to, label, surcharge_amount, surcharge_type, is_active, created_by) VALUES
('2025-12-24', '2025-12-26', 'Christmas Eve & Day', 500.00, 'fixed', 1, 1),
('2025-12-31', '2026-01-01', 'New Year', 500.00, 'fixed', 1, 1),
('2026-02-14', '2026-02-14', "Valentine's Day", 300.00, 'fixed', 1, 1),
('2026-04-09', '2026-04-10', 'Holy Week', 200.00, 'fixed', 1, 1),
('2026-06-12', '2026-06-12', 'Independence Day', 150.00, 'fixed', 1, 1);

-- Inventory Items
INSERT INTO inventory_items (item_name, category, unit, current_stock, minimum_stock, unit_cost, selling_price) VALUES
('Mineral Water 500ml', 'minibar', 'bottle', 50, 10, 15.00, 35.00),
('Soft Drink (Cola)', 'minibar', 'can', 40, 10, 25.00, 50.00),
('Beer (Local)', 'minibar', 'can', 30, 8, 40.00, 80.00),
('Peanuts (Small)', 'minibar', 'pack', 25, 8, 20.00, 45.00),
('Chocolate Bar', 'minibar', 'pc', 20, 5, 30.00, 60.00),
('Shampoo Sachet', 'toiletries', 'pc', 100, 20, 5.00, 0.00),
('Conditioner Sachet', 'toiletries', 'pc', 100, 20, 5.00, 0.00),
('Soap Bar', 'toiletries', 'pc', 80, 15, 8.00, 0.00),
('Toothbrush & Paste', 'toiletries', 'set', 50, 10, 25.00, 0.00),
('Shower Cap', 'toiletries', 'pc', 60, 10, 5.00, 0.00),
('Laundry - Regular', 'laundry', 'kg', 0, 0, 0.00, 80.00),
('Laundry - Express', 'laundry', 'kg', 0, 0, 0.00, 150.00),
('Dry Cleaning - Shirt', 'laundry', 'pc', 0, 0, 0.00, 120.00),
('Extra Towel', 'amenities', 'pc', 30, 5, 0.00, 50.00),
('Extra Pillow', 'amenities', 'pc', 20, 3, 0.00, 50.00),
('Extra Blanket', 'amenities', 'pc', 15, 3, 0.00, 75.00),
('Tissue Box', 'supplies', 'box', 40, 10, 20.00, 0.00),
('Coffee Sachet', 'minibar', 'pack', 60, 15, 10.00, 25.00);
