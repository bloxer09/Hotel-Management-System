# 🏨 Uptown Pension House — Hotel Management System

A full-featured, web-based **Property Management System (PMS)** built for Uptown Pension House (Bacolod City). Designed for front desk operations, cashiering, housekeeping coordination, and hotel administration from a single unified dashboard.

---

## ✨ Key Features

### 🛎️ Check-In & Stay Management
- Walk-in guest check-in wizard with real-time room availability validation
- Overnight and short-time (hourly) stay modes
- Automatic rate calculation with peak-date surcharges
- Discount support: Senior Citizen, PWD, Loyalty, Staff Override, Promo Codes, Complimentary
- Split payment (Cash + GCash) with reference number tracking
- Guest ID capture and guest profile linking
- Room extension, room reassignment / transfer, and stay cancellation

### 📅 Bookings & Future Reservations
- Future reservation creation with deposit payment
- Real-time double-booking conflict detection
- Automatic rate calculation and checkout time estimation
- Calendar-integrated room availability view (blocked, reserved, occupied)
- One-click reservation-to-check-in conversion
- Reservation cancellation with audit trail

### 📋 Stay History
- Filterable stay log: Active, Checked-Out, Cancelled
- Live search by guest name, booking reference, or room number
- Full booking detail view: guest info, billing summary, transaction timeline, minibar orders
- Minibar / room order tracking per stay
- Printable thermal receipt (POS preview) and print-ready receipt page

### 🛏️ Room Management
- Visual room board with color-coded status indicators (Vacant, Occupied, Cleaning, Maintenance)
- Room status updates (per room or bulk clean)
- Admin room CRUD (add/edit/delete rooms and room types)
- Hourly rate and base rate configuration per room type

### 🧴 Inventory & Minibar
- Inventory item management with categories (Minibar, Toiletries, Amenities, Cleaning Supplies)
- Stock adjustment (add / reduce) with reason tracking
- Low-stock alerts with real-time notification system
- Minibar usage logging per booking or walk-in sale
- Bulk usage module for direct non-booking sales

### 👥 Guest Directory
- Auto-populated guest profiles from check-in history
- VIP flag and notes management
- Loyalty tracking (stay count, total spend)
- Guest profile search with autocomplete

### 📊 Sales & Reports
- Daily revenue breakdown: Room Lodging vs. Inventory/Product Sales
- RevPAR (Revenue Per Available Room) — 30-day rolling average
- Today's occupancy rate with sparkline charts
- End-of-Day (EOD) shift summary modal
- Filterable date-range sales reports
- CSV export of financial data
- Room availability calendar (by month/week)

### ⏱️ Shift Register
- Shift open/close workflow for front desk and cashier roles
- Shift-based access control (operations locked without active shift)
- Per-shift revenue and transaction summaries

### 🔔 Real-Time Notifications
- Live notification bell (polls every 30 seconds)
- Overdue checkout alerts with chime sound
- Low-stock and out-of-stock inventory alerts
- Auto-dismiss toast notification cards

### 🔧 Maintenance Tickets
- Submit, track, and resolve maintenance requests
- Status flow: Open → In Progress → Resolved
- Accessible by all roles including housekeeping

### ⚙️ Admin Configurations
- General settings (hotel name, OR prefix, tax rate, etc.)
- Room rate configuration per room type
- Peak date / holiday surcharge management
- Promo & discount code CRUD
- Staff user management (create, edit, activate/deactivate)
- Audit trail logs (all system actions)

---

## 🧑‍💼 User Roles & Access

| Role           | Access Level |
|----------------|-------------|
| `admin`        | Full system access including configurations |
| `front_desk`   | Check-in, reservations, bookings, guests, inventory |
| `cashier`      | Bookings, stay history, reports, guest directory |
| `housekeeping` | Room board only (status updates) |

---

## 🛠️ Tech Stack

| Layer       | Technology |
|-------------|-----------|
| Backend     | **Laravel 12** (PHP 8.2) |
| Frontend    | **React 18** + **Inertia.js** |
| Styling     | **Tailwind CSS v3** + Vanilla CSS |
| Animations  | **Framer Motion** |
| Charts      | **Recharts** |
| Icons       | **Lucide React** |
| Build Tool  | **Vite 6** |
| Database    | **MySQL** (via XAMPP) |
| Auth        | **Laravel Breeze** (Inertia adapter) |

---

## 🚀 Local Setup (XAMPP)

### Prerequisites
- XAMPP with PHP 8.2+ and MySQL
- Composer
- Node.js 18+ and npm

### Installation

```bash
# 1. Clone the repository
git clone <repo-url> hotel_management
cd hotel_management

# 2. Install PHP dependencies
composer install

# 3. Install Node dependencies
npm install

# 4. Copy environment config
cp .env.example .env

# 5. Generate application key
php artisan key:generate
```

### Database Setup

```bash
# 6. Create a MySQL database named 'hotel_management'
#    (via phpMyAdmin or MySQL CLI)

# 7. Configure .env database credentials
DB_DATABASE=hotel_management
DB_USERNAME=root
DB_PASSWORD=

# 8. Run migrations and seed
php artisan migrate --seed
```

### Running the App

```bash
# Terminal 1: Start Laravel dev server
php artisan serve

# Terminal 2: Start Vite asset compiler
npm run dev
```

Then visit: **http://127.0.0.1:8000**

### Production Build

```bash
npm run build
php artisan serve
```

---

## 🗂️ Project Structure

```
hotel_management/
├── app/
│   ├── Http/
│   │   ├── Controllers/       # All route controllers
│   │   └── Middleware/        # Role & shift access guards
│   ├── Models/                # Eloquent models
│   └── Services/              # BookingService (rate/fee logic)
├── database/
│   ├── migrations/            # Schema definitions
│   └── seeders/               # Sample data
├── resources/
│   └── js/
│       ├── Layouts/           # AuthenticatedLayout (sidebar, nav)
│       └── Pages/             # Inertia page components
│           ├── Auth/          # Login
│           ├── Bookings/      # Stay history, detail view
│           ├── CheckIn/       # Walk-in check-in wizard
│           ├── Dashboard.jsx  # KPI dashboard
│           ├── Guests/        # Guest directory
│           ├── Inventory/     # Stock management
│           ├── Maintenance/   # Ticket tracker
│           ├── Reports/       # Sales & analytics
│           ├── Reservations/  # Future booking management
│           ├── Rooms/         # Room board
│           ├── Settings/      # Admin configurations
│           └── Shifts/        # Shift register
└── routes/
    └── web.php                # All application routes
```

---

## 🔐 Default Admin Credentials

> Set in `database/seeders/UserSeeder.php`

| Field    | Value     |
|----------|-----------|
| Username | `admin`   |
| Password | `password` |

> ⚠️ Change these immediately in a production environment.

---

## 📄 License

This project is proprietary software developed for **Uptown Pension House**, Mansilingan, Bacolod City. All rights reserved.
