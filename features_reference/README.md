# 🏨 Grand Vista Hotel PMS
## Hotel Property Management System
### Built with PHP + MySQL (XAMPP)

---

## 📋 REQUIREMENTS
- XAMPP (PHP 7.4+ / MySQL 5.7+)
- Browser (Chrome, Firefox, Edge)
- No internet required (runs offline)

---

## 🚀 INSTALLATION GUIDE

### Step 1 — Copy to XAMPP
Place the `hotel_pms` folder inside:
```
C:\xampp\htdocs\hotel_pms\
```
*(Mac: /Applications/XAMPP/htdocs/hotel_pms/)*

### Step 2 — Start XAMPP Services
Open XAMPP Control Panel and start:
- ✅ **Apache**
- ✅ **MySQL**

### Step 3 — Create Database
1. Open your browser: `http://localhost/phpmyadmin`
2. Click **"New"** on the left sidebar
3. Create database named: `hotel_pms`
4. Click **Import** tab
5. Choose file: `hotel_pms/sql/hotel_pms.sql`
6. Click **Go / Execute**

### Step 4 — Access the System
Open browser and go to:
```
http://localhost/hotel_pms/
```

---

## 🔐 DEFAULT LOGIN ACCOUNTS

| Username     | Password   | Role       | Access Level |
|-------------|-----------|-----------|-------------|
| `admin`      | `password` | Admin      | Full access |
| `frontdesk1` | `password` | Front Desk | Check-in, rooms, guests |
| `cashier1`   | `password` | Cashier    | Check-out, reports |
| `housekeeping1` | `password` | Housekeeping | Room status only |

> **IMPORTANT:** Change all passwords after first login!

---

## 📁 SYSTEM MODULES

### 1. 🏠 Dashboard
- Occupancy overview (vacant/occupied/cleaning/OOO)
- Today's revenue (cash + GCash)
- Recent bookings
- Quick action buttons
- Low stock alerts

### 2. 🚪 Room Status Board
- Color-coded room grid (vacant=green, occupied=red, cleaning=yellow, OOO=gray)
- Filter by status and floor
- Click any room to view details or change status
- Cannot manually set to "Occupied" — done through Check-In only

### 3. ✅ Check-In
- Guest info (name, contact, ID type/number)
- Room selection (only shows vacant rooms)
- Booking type: Overnight or Hourly
- Auto-detects peak dates → adds surcharge automatically
- Discount control: Senior/PWD (20% auto), Promo, Staff Override (Admin only)
- Real-time billing summary with breakdown
- VIP auto-fill banner when guest detected from history

### 4. 📋 Bookings & Check-Out
- View all bookings (active, checked-out, cancelled)
- Search by guest name, booking ref, room number
- Check-Out with payment: Cash, GCash, or Split
- Extension fee and late check-out fee fields
- Cancel booking (requires reason)
- View booking details modal

### 5. 👤 Guest History
- Full guest profile list
- VIP flagging with notes/preferences (Admin only)
- Search by name, contact, ID number
- Detailed stay history per guest
- Quick Check-In button → auto-fills check-in form
- Sync from Bookings → builds profiles from existing data

### 6. 📊 Sales Report
- Date range filter (today / this week / this month)
- Summary: total bookings, cash, GCash, total revenue
- Revenue breakdown (base, surcharge, extension, discounts)
- By room type revenue
- Cashier remittance summary per staff
- Full transaction detail table
- Print-friendly layout

### 7. 📦 Inventory
- Categories: Minibar, Toiletries, Laundry, Amenities, Supplies
- Low stock alerts (highlighted in orange)
- Stock adjustment (add/subtract/set) with reason
- Record item usage per booking (adds to billing)
- Admin-only: add/deactivate items

### 8. 📜 Audit Trail (Admin Only)
- Every system action logged
- Filter by date, action type, staff member
- Shows old and new values for changes
- IP address tracking
- Cannot be modified or deleted

### 9. 🏷️ Room Rates (Admin Only)
- View all room types with current rates
- Edit base rate, hourly rate, max occupancy, amenities
- All rate changes logged in audit trail

### 10. 📅 Peak Dates (Admin Only)
- Set peak date ranges with labels
- Fixed amount or percentage surcharge
- Enable/disable without deleting
- Visual indicator for past/current/upcoming

### 11. 👥 User Management (Admin Only)
- Add/edit staff accounts
- Role assignment: Admin / Front Desk / Cashier / Housekeeping
- Activate/deactivate accounts
- Change password (admin can change any)

---

## 🔒 ACCESS CONTROL MATRIX

| Feature                  | Admin | Front Desk | Cashier |
|--------------------------|-------|-----------|---------|
| Dashboard                | ✅    | ✅         | ✅      |
| Room Status Board        | ✅    | ✅         | ✅      |
| Check-In                 | ✅    | ✅         | ❌      |
| Check-Out                | ✅    | ✅         | ✅      |
| Guest History            | ✅    | ✅         | ✅      |
| Mark VIP                 | ✅    | ❌         | ❌      |
| Sales Report             | ✅    | ❌         | ✅      |
| Inventory                | ✅    | ✅         | ❌      |
| Audit Trail              | ✅    | ❌         | ❌      |
| Room Rates               | ✅    | ❌         | ❌      |
| Peak Dates               | ✅    | ❌         | ❌      |
| User Management          | ✅    | ❌         | ❌      |
| Change Prices            | ✅    | ❌         | ❌      |

---

## 🗄️ DATABASE TABLES (10 Tables)

1. `users` — Staff accounts and roles
2. `room_types` — Room categories and rates
3. `rooms` — Individual rooms and status
4. `peak_dates` — Peak pricing configurations
5. `guest_profiles` — Guest history and VIP data
6. `bookings` — All booking records
7. `transactions` — Transaction log entries
8. `audit_logs` — Full system audit trail
9. `inventory_items` — Inventory catalog
10. `inventory_usage` — Item usage per booking

---

## 💡 USAGE TIPS

### Check-In Workflow:
1. Go to **Check-In** module
2. Fill guest name, contact, ID
3. Select vacant room
4. Choose overnight or hourly
5. Set dates — surcharge auto-adds if peak date
6. Apply discount if applicable
7. Review billing summary
8. Click **Confirm Check-In**

### Check-Out Workflow:
1. Go to **Bookings** → find active booking
2. Click the ✅ check-out button
3. Add extension/late fees if any
4. Select payment method (Cash/GCash/Split)
5. For GCash: enter reference number
6. Click **Confirm Check-Out**

### Daily Remittance Check:
1. Go to **Sales Report**
2. Set date to today
3. Check **Cashier Remittance Summary**
4. Compare cash + GCash totals per staff
5. Print if needed

---

## ⚠️ SECURITY NOTES
- All passwords are hashed (bcrypt)
- SQL injection protected (PDO prepared statements)
- Role-based access enforcement on every page
- All sensitive actions logged in audit trail
- Session-based authentication

---

## 📞 SUPPORT
This system is built for educational/capstone research purposes.
For setup help, check the XAMPP documentation or consult your instructor.
