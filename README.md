# 🍜 NoodleHaus POS

**Myanmar's F&B Operating System** — A complete, self-hosted Point-of-Sale system built for restaurants, noodle shops, cafés, and food businesses in Myanmar.

> Competing with Koomi (SG) / MYR (Canada) — built with zero framework bloat.

---

## 🎯 Overview

NoodleHaus POS is a full-stack restaurant management platform covering every role in an F&B operation — from customer ordering to kitchen display, delivery tracking, stock management, and multi-branch analytics.

**Stack:** PHP 8.5 · MySQL · nginx · Vanilla JS · PWA · Let's Encrypt SSL · Ubuntu 24

---

## 📱 Pages & Apps

| Page | URL | Purpose |
|------|-----|---------|
| **Customer Ordering** | `/index.html` | PWA, Myanmar/English, loyalty, KPay QR, one-click reorder |
| **Admin Dashboard** | `/admin.php` | 12-tab management center (orders, analytics, menu, staff, CRM, stock, shifts, delivery, branches, reservations, settings) |
| **Kitchen Display** | `/kds.html` | Real-time SSE, sound alerts, modifier display, OVERDUE tracking |
| **Waiter Tablet** | `/waiter.html` | PIN login, table management, order taking |
| **Self-Service Kiosk** | `/kiosk.html` | Touch-friendly, table QR detection, modifier support, dine-in ordering |
| **Queue Display (TV)** | `/queue.html` | Customer-facing TV screen — Now Serving / Preparing / Completed |
| **Driver App** | `/driver.html` | PIN login, order pickup → deliver → complete flow |
| **Setup Wizard** | `/setup.html` | 4 business types, 10 configurable modules |
| **Order Tracker** | `/track_orders.php` | Customer order status tracking |
| **Receipt** | `/receipt.php` | 80mm thermal receipt generator |
| **Daily Report** | `/daily_report.php` | End-of-day closing report |

---

## ⚡ Features by Phase

### Phase 1–3: Core POS
- ✅ Menu management with categories, images, emoji, modifiers
- ✅ Online ordering (delivery + dine-in) with real-time KDS
- ✅ Payment methods: KPay, Wave, CB Pay, AYA Pay, Cash, Card, COD
- ✅ Loyalty stamp system with auto-rewards
- ✅ Analytics dashboard (revenue charts, top items, peak hours, payment split)
- ✅ Table management with QR codes
- ✅ Myanmar + English bilingual support

### Phase 4: Extended
- ✅ Waiter tablet app with PIN authentication
- ✅ Split bill support
- ✅ Business setup wizard (4 business types)
- ✅ Offline PWA with order queueing

### Phase 5: Operations
- ✅ **5A — Customer CRM**: Auto-profile from orders, favourite items, one-click reorder, VIP tagging
- ✅ **5B — Shift Management**: Open/close shifts, cash drawer reconciliation, per-shift analytics
- ✅ **5C — Queue Display**: TV screen for customers, real-time polling, sound notifications
- ✅ **5D — Table Reservations**: Date/time booking, party size, double-booking prevention, status flow
- ✅ **5E — Stock Management**: Auto-deduct on order, restock, waste tracking, audit log, low-stock alerts

### Phase 6: Scale
- ✅ **6A — Self-Service Kiosk**: Touch UI, table detection via URL, modifier support, payment selection
- ✅ **6B — Multi-Branch**: Branch profiles, cross-branch dashboard, per-branch filtering, branch selector
- ✅ **6C — Delivery Platform**: Driver management, delivery zones, order assignment, driver app, external webhook API

---

## 🏗️ Architecture

```
├── index.html              # Customer ordering (PWA)
├── admin.php               # Admin dashboard (12 tabs)
├── admin_modules.js        # Phase 5-6 JS modules (split from admin.php)
├── kds.html                # Kitchen Display (SSE)
├── waiter.html             # Waiter tablet app
├── kiosk.html              # Self-service kiosk
├── queue.html              # TV queue display
├── driver.html             # Delivery driver app
├── setup.html              # Business setup wizard
│
├── order_handler.php       # Order creation + hooks
├── order_hooks.php         # Post-order hooks (CRM, shift, stock, delivery)
├── order_cancel_hooks.php  # Cancel hooks (stock restore, CRM adjust)
├── menu_api.php            # Menu CRUD API
├── modifier_api.php        # Modifier groups/options API
├── analytics.php           # Dashboard analytics
├── loyalty.php             # Loyalty stamp system
├── loyalty_admin.php       # Loyalty admin + bulk operations
├── table_api.php           # Table management API
├── waiter_api.php          # Waiter app API
├── site_settings.php       # CMS settings API
├── kds_stream.php          # KDS Server-Sent Events
├── kds_update.php          # KDS status updates
│
├── crm_api.php             # Customer CRM API
├── shift_api.php           # Shift management API
├── stock_api.php           # Stock management API
├── reservation_api.php     # Table reservation API
├── queue_api.php           # Queue display API
├── branch_api.php          # Multi-branch API
├── delivery_api.php        # Delivery platform API
│
├── auth_helper.php         # CSRF token + auth utilities
├── db_connect.php          # Database connection
├── track.php               # Order tracking API
├── track_orders.php        # Customer tracking page
├── receipt.php             # 80mm thermal receipt
├── daily_report.php        # Daily closing report
├── customer_history.php    # Customer order history
│
├── sw.js                   # Service Worker (offline PWA)
├── manifest.json           # PWA manifest
└── uploads/                # Menu images
```

---

## 🗄️ Database

**25 tables** — all `utf8mb4_unicode_ci`

| Table | Purpose |
|-------|---------|
| `orders` | All orders (soft-delete) |
| `order_items` | Order line items |
| `order_item_modifiers` | Modifier selections per item |
| `menu_items` | Menu catalog |
| `modifier_groups` | Modifier group definitions |
| `modifier_options` | Modifier options with pricing |
| `restaurant_tables` | Table layout |
| `staff` | Staff with PIN auth |
| `site_settings` | CMS key-value settings |
| `loyalty_cards` | Loyalty stamp tracking |
| `kds_queue` | Kitchen display queue |
| `deleted_orders_log` | Soft-deleted order archive |
| `customers` | CRM profiles (auto-populated) |
| `customer_favourite_items` | Top ordered items per customer |
| `reorder_templates` | Saved reorder carts |
| `shifts` | Shift open/close with cash reconciliation |
| `shift_orders` | Orders linked to shifts |
| `stock_log` | Stock change audit trail |
| `reservations` | Table reservations |
| `branches` | Multi-branch profiles |
| `drivers` | Delivery drivers |
| `delivery_zones` | Delivery fee zones |
| `delivery_tracking` | Order delivery status tracking |

---

## 🔧 Setup

### Requirements
- Ubuntu 24 / Debian
- PHP 8.x + php-fpm
- MySQL 8.x
- nginx
- SSL certificate (Let's Encrypt)

### Quick Start

```bash
# Clone
git clone https://github.com/neking/noodlehaus.git /var/www/html

# Database
mysql -u root -e "CREATE DATABASE noodlehaus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root noodlehaus < schema.sql
mysql -u root noodlehaus < seed_menu.sql

# Run migrations (in order)
mysql -u root noodlehaus < migration_phase1a.sql
mysql -u root noodlehaus < migration_phase2b.sql
mysql -u root noodlehaus < migration_phase5a.sql
mysql -u root noodlehaus < migration_phase5b.sql
mysql -u root noodlehaus < migration_phase5d.sql
mysql -u root noodlehaus < migration_phase5e.sql
mysql -u root noodlehaus < migration_phase6b.sql
mysql -u root noodlehaus < migration_phase6c.sql
mysql -u root noodlehaus < fix_collation.sql

# nginx config → point root to /var/www/html
# PHP-FPM → ensure socket matches nginx config
```

### Staff PINs (default)
| Staff | PIN | Role |
|-------|-----|------|
| Ko Aung | 1234 | Waiter |
| Ma Aye | 5678 | Waiter |
| Manager | 0000 | Manager |

### Driver PINs (default)
| Driver | PIN |
|--------|-----|
| Ko Thura | 1111 |
| Mg Zaw | 2222 |
| Ko Htet | 3333 |

---

## 🛡️ Security

- CSRF token protection on all admin POST requests
- PHP session-based admin authentication
- Staff/driver PIN-based authentication
- Delivery webhook API key validation
- PDO prepared statements (SQL injection safe)
- CORS headers configured
- Soft-delete with audit logging

---

## 📊 Performance

- **Order placement: ~280ms** (4 hooks fire synchronously via direct PHP calls)
- Hook pipeline: CRM sync → Shift assign → Stock deduct → Delivery track
- Cancel flow: Stock restore → CRM adjust → Delivery cancel → Shift unlink
- Queue display: 5s polling with 2-hour stale filter
- KDS: Server-Sent Events (real-time)

---

## 🔌 API Endpoints

| Endpoint | Actions |
|----------|---------|
| `menu_api.php` | Menu CRUD, batch upload |
| `order_handler.php` | Place order, cancel order |
| `modifier_api.php` | Modifier groups & options |
| `loyalty.php` | Check, stamp, redeem |
| `analytics.php` | Summary, revenue, top items |
| `table_api.php` | List, create, reset tables |
| `waiter_api.php` | Login, table orders, place order |
| `site_settings.php` | Get/save settings |
| `crm_api.php` | Profile, list, reorder, tag |
| `shift_api.php` | Open, close, current, history |
| `stock_api.php` | Overview, adjust, log |
| `reservation_api.php` | Create, list, availability, status |
| `branch_api.php` | List, create, dashboard, compare |
| `delivery_api.php` | Drivers, zones, assign, track, webhook |
| `queue_api.php` | Queue data for TV display |

---

## 🌏 Supported Payment Methods

`kpay` · `wave` · `cb` · `aya` · `cash` · `card` · `cod`

---

## 📋 License

Private — NoodleHaus © 2025-2026

---

## 🚀 Vision

Building the **Myanmar F&B Operating System** — a complete, affordable, self-hosted alternative to Koomi (Singapore) and MYR POS (Canada), designed specifically for Myanmar's restaurant industry.
