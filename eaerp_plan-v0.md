# EAERP - Car Workshop ERP Architecture Plan

## Overview
A modular ERP system for Car Workshop Management with Core + Pluggable Module architecture (similar to Odoo). Supports multi-location operations with role-based access control.

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 11 + PHP 8.2 |
| Database | MySQL 8.0 |
| Cache/Queue | Redis |
| Mobile App | Flutter (iOS + Android) |
| Web Dashboard | Vue.js 3 / Inertia.js (with Laravel) |
| API | REST with Laravel Sanctum |
| File Storage | S3-compatible (local for dev) |

---

## Architecture: Core + Pluggable Modules

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  ┌─────────────────┐  ┌─────────────────────────────────┐   │
│  │  Flutter App    │  │  Vue.js Web Dashboard           │   │
│  │  (Mobile)       │  │  (Admin/Owner)                  │   │
│  └─────────────────┘  └─────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                              │
                    REST API (Sanctum Auth)
                              │
┌─────────────────────────────────────────────────────────────┐
│                      CORE FRAMEWORK                          │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │Multi-Tenancy │ │ RBAC + Auth  │ │ Module Loader        │ │
│  └──────────────┘ └──────────────┘ └──────────────────────┘ │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────────────┐ │
│  │ Event Bus    │ │ Notification │ │ Audit Logging        │ │
│  └──────────────┘ └──────────────┘ └──────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┼─────────────────────┐
        │                     │                     │
┌───────▼───────┐   ┌─────────▼───────┐   ┌────────▼────────┐
│  User Admin   │   │   Workshop      │   │    Billing      │
│  Module       │   │   Floor Mgmt    │   │    Module       │
└───────────────┘   └─────────────────┘   └─────────────────┘
        │                     │                     │
┌───────▼───────┐   ┌─────────▼───────┐   ┌────────▼────────┐
│  Lead CRM     │   │   Spare Parts   │   │   Attendance    │
│  (Phase 2)    │   │   (Phase 2)     │   │   (Phase 3)     │
└───────────────┘   └─────────────────┘   └─────────────────┘
```

---

## What Goes in CORE

### Authentication & Branch Management
- Laravel Sanctum for API tokens (mobile app)
- Session auth for web dashboard
- Single organization settings
- Branch (Location) management
- Branch-scoped queries for operational data (job_cards, invoices, etc.)
- Org-wide data for customers/vehicles (no branch restriction)

### RBAC Security
- Permission definitions (e.g., `workshop.job-cards.create`)
- Role templates (Owner, Manager, Service Advisor, Technician, etc.)
- Role-Permission assignments
- User-Role assignments (per location)

### Shared Services
- Notification Service (Email, SMS, Push)
- File Storage Service (S3/local abstraction)
- Audit Logging (all changes tracked)
- Settings/Configuration Service
- Export Service (PDF, Excel)

### Base Infrastructure
- `BaseModel` with automatic tenant scoping
- Base Repository pattern
- Base API Controller with standard responses
- Event Bus for cross-module communication
- Service Contracts (interfaces for module interop)

### Master Data
- Vehicle Makes & Models
- Service Categories
- Tax configurations (GST rates)

---

## MVP Modules (Phase 1)

### 1. User Admin Module
- User CRUD with role assignment
- Role management with permission templates
- Location assignment for users
- Password reset flow
- 4 MVP roles: Owner, Manager, Service Advisor, Technician

### 2. Workshop Floor Management Module

**Customer & Vehicle Management:**
- Customer registration (name, phone, email, address)
- Vehicle registration with category classification
- Service history per vehicle

**Vehicle Categories (for pricing):**
| Category | Examples |
|----------|----------|
| Hatchback | Swift, i10, Nano, Figo |
| Premium Hatchback | Jazz, i20, Polo |
| Sedan / Compact SUV | City, Verna, Creta, Venue |
| SUV / MUV | Innova, XUV, Safari |
| Premium SUV | Fortuner, Endeavour |
| Luxury Class 3 | Audi A4, BMW 3 Series, Mercedes C-Class |
| Luxury Class 2 | Audi A6, BMW 5 Series, Mercedes E-Class |
| Luxury Class 1 | Audi A8, BMW 7 Series, Mercedes S-Class, Range Rover |

**Service Packages (with Activity Points):**
| Package | Points | Description |
|---------|--------|-------------|
| EA Refresh | 18 | Quick top-up & clean-up between services |
| EA Essentials | 25 | Regular oil-change with essentials |
| EA Pro | 45 | Full mechanical + engine, brakes, wheels, cabin |
| EA 360 | 70 | Deep health check - every system inspected |
| EA Elite | 80 | Full service + full interior/exterior detailing |
| AC Service | - | Specialized HVAC service and repairs |
| Body Shop | - | Collision repair and painting |
| General Repair | - | Ad-hoc mechanical repairs |

**Job Card Workflow:**
```
┌─────────────┐   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐
│   DRAFT     │ → │  RECEIVED   │ → │ IN PROGRESS │ → │     QC      │
│             │   │ (Pre-check) │   │ (Technician)│   │ (Checklist) │
└─────────────┘   └─────────────┘   └─────────────┘   └─────────────┘
                                                              │
┌─────────────┐   ┌─────────────┐                             │
│  DELIVERED  │ ← │    READY    │ ←───────────────────────────┘
│ (Follow-up) │   │ (Billing)   │
└─────────────┘   └─────────────┘
```

**Activity Checklist System (digitized from Excel sheets):**
- Pre-defined checklists per service package
- Categories: Engine & Fluids, Cabin & Interior, Wheels & Brakes, Suspension, Detailing
- Checkbox completion with remarks field
- Technician sign-off required

**Quality Control - Pre-Delivery Checklist:**
*(Based on updated `Pre Delivery Checklist.xlsx`)*
- Digitized checklist items from the updated Excel file
- Completion tracking with timestamps
- Technician/Advisor sign-off
- Photo verification where applicable

**Spare Parts Requisition:**
- Parts request linked to job card
- Standard parts list per service type
- Requisition → Issued → Received workflow
- Sign-off: Parts Coordinator → Technician

**Oil Type Options:**
- Semi-Synthetic (base price)
- Fully Synthetic (10-20% premium)
- Top-Tier Synthetic (for luxury cars)

**Add-ons:**
- Wheel Alignment & Balancing (+₹1,000)
- Additional labor/parts as line items

**Views**: Daily job list, technician workload, bay status, pending QC

### 3. Billing Module (Basic)

**Invoice Generation:**
- Auto-generate from job card (package price + add-ons + additional parts/labor)
- Line items: Package service, labor, parts, third-party services, add-ons
- Apply package pricing based on vehicle category + oil type

**Pricing Calculation:**
```
Base Price = package_pricing[package_id][category_id][oil_type]
+ Add-ons (Wheel Alignment: ₹1,000, etc.)
+ Additional Parts (at actual + markup)
+ Additional Labor
- Home Service Adjustment (body wash excluded)
= Subtotal
+ GST (CGST + SGST or IGST)
= Invoice Total
```

**GST Handling:**
- CGST + SGST for intra-state (Maharashtra)
- IGST for inter-state (if applicable)
- Per-branch GSTIN configuration

**Payment Recording:**
- Methods: Cash, Card, UPI, Bank Transfer, Cheque
- Partial payment support with balance tracking
- Payment receipt generation

**Reports:**
- Daily collection report (by branch, consolidated)
- Outstanding amount tracking
- Invoice register

---

## Phase 2 Modules (Post-MVP)

### Lead Management CRM
- Lead capture, scoring, pipeline, follow-ups
- Marketing campaign tracking

### Spare Parts Management
- Inventory per branch
- Purchase orders, vendor management
- Stock alerts, reorder levels
- Part catalog with pricing

### Body Shop Module (separate workflow)
- Body shop job sheet (distinct from service job cards)
- Panel-by-panel damage tracking (bumpers, doors, fenders, bonnet, quarter panels)
- Repair vs. Replace decisions per panel
- Painting options (partial/full)
- **Insurance Integration:**
  - Insurance company selection (12+ partners: Chola MS, Digit, HDFC ERGO, etc.)
  - Claim number tracking
  - Surveyor details (name, date, status)
  - Cashless claim processing
- Photo/video documentation (before/after)
- Body shop technician assignment (separate role)

## Phase 3 Modules
- **Attendance & Payroll**: Check-in/out, leave, shifts, salary calculation
- **Advanced Reporting & Analytics**

---

## Database Design

### Single Organization + Multi-Branch Model
**Simple branch-based scoping** (no multi-tenancy overhead)

```
┌─────────────────────────────────────────────────────────────┐
│  ORGANIZATION: Express Autocare (single org, no tenant_id)  │
├─────────────────────────────────────────────────────────────┤
│  Branch 1        │  Branch 2        │  Branch 3 ...         │
│  (branch_id=1)   │  (branch_id=2)   │  (branch_id=3)        │
└─────────────────────────────────────────────────────────────┘
```

**Data Scoping Rules:**
- `branch_id` ONLY on operational data (job_cards, invoices, inventory)
- Customers/Vehicles are org-wide (can visit any branch)
- Users assigned to branches via `user_branches` pivot table
- Owner sees all branches (no filter), others filtered by assignment

### Core Tables
```
organization    - id, name, logo, gstin, settings (single row)
branches        - id, name, code, address, city, gstin, phone, is_active
users           - id, name, email, phone, password, is_active
roles           - id, name, slug, is_system
permissions     - id, name, slug, module
role_permissions, user_roles
user_branches   - user_id, branch_id (pivot: user can access multiple branches)
audit_logs, settings
```

### Workshop Tables (Org-wide - Master Data)
```
customers           - id, name, phone, email, address
vehicles            - id, customer_id, make_id, model_id, registration_no, vin, category_id
vehicle_makes       - id, name, logo
vehicle_models      - id, make_id, name, default_category_id
vehicle_categories  - id, name, slug (hatchback, sedan, suv, luxury_class_1, etc.)

service_packages    - id, name, slug, activity_points, description, home_service_available
package_pricing     - id, package_id, category_id, oil_type, price
oil_types           - id, name (semi_synthetic, fully_synthetic, top_tier_synthetic)

checklist_templates     - id, package_id, name, sort_order
checklist_items         - id, template_id, section, item_name, sort_order
```

### Workshop Tables (Branch-specific - Operational)
```
bays                - id, branch_id, name, is_active
job_cards           - id, branch_id, job_number, customer_id, vehicle_id,
                      package_id, oil_type_id, status, technician_id, bay_id,
                      time_in, time_out, fuel_level, km_reading,
                      customer_issues (text), observations (text)
job_card_items      - id, job_card_id, type (labor/part/service), description, qty, rate, amount
job_card_checklist  - id, job_card_id, checklist_item_id, is_completed, remarks, completed_at
job_card_photos     - id, job_card_id, photo_type (before/after/parts), file_path
job_card_addons     - id, job_card_id, addon_name, price (e.g., wheel alignment)

spare_part_requests - id, job_card_id, part_name, qty, status (requested/issued/received),
                      requested_by, issued_by, received_by, timestamps
```

### Quality Control Tables
```
qc_checklists       - id, job_card_id, phase (pre_service/while_service/post_delivery)
qc_checklist_items  - id, qc_checklist_id, item_name, is_completed, completed_by, completed_at
follow_up_calls     - id, job_card_id, call_date, call_type (courtesy_2day/feedback),
                      status, notes, called_by
```

### Billing Tables (Branch-specific)
```
invoices        - id, branch_id, job_card_id, invoice_no, subtotal, tax, total, status
invoice_items   - id, invoice_id, description, qty, rate, tax_rate, amount
payments        - id, invoice_id, amount, method, reference, payment_date
```

### Access Control by Role
```
Owner         → SELECT * FROM job_cards (no branch filter)
Manager       → SELECT * FROM job_cards WHERE branch_id IN (assigned_branches)
Advisor/Tech  → SELECT * FROM job_cards WHERE branch_id = user.current_branch
```

---

## Folder Structure

```
eaerp/
├── app/
│   ├── Core/                          # CORE PACKAGE
│   │   ├── Contracts/                 # Service interfaces
│   │   ├── DTOs/                      # Data transfer objects
│   │   ├── Events/                    # Domain events
│   │   ├── Http/
│   │   │   ├── Controllers/Api/
│   │   │   └── Middleware/            # BranchContext, CheckPermission
│   │   ├── Models/                    # BaseModel, Tenant, User, Role
│   │   ├── Services/                  # Auth, Notification, Module
│   │   └── Providers/
│   │
│   └── Modules/                       # PLUGGABLE MODULES
│       ├── UserAdmin/
│       │   ├── Config/, Database/, Http/, Models/, Services/, Routes/
│       │   ├── Providers/UserAdminServiceProvider.php
│       │   └── module.json
│       ├── Workshop/
│       │   ├── Config/, Database/, Http/, Models/, Services/, Routes/
│       │   ├── Listeners/, Jobs/
│       │   └── module.json
│       └── Billing/
│           └── ... (same structure)
│
├── config/
│   ├── modules.php, tenancy.php, sanctum.php
├── database/migrations/               # Core migrations only
├── routes/
│   ├── api.php                        # API routes bootstrap
│   └── web.php                        # Web dashboard routes
└── resources/views/                   # Vue components, email templates
```

---

## Module Communication Pattern

Modules communicate via **Events** (not direct dependencies):

```
[Workshop] --JobCardCompleted Event--> [Event Bus] --> [Billing Listener]
                                                            │
                                                    Creates Invoice
```

Cross-module data access via **Service Contracts**:
```php
// Core defines interface
interface InventoryServiceInterface {
    public function checkAvailability(int $partId, int $qty): bool;
}

// SpareParts module implements it
// Workshop module uses it (injected via interface)
```

---

## API Design

**Base URL**: `https://api.eaerp.com/v1/{module}/{resource}`

**Key Endpoints**:
```
# Authentication
POST   /v1/auth/login                    # Returns Sanctum token
GET    /v1/auth/me                       # Current user + permissions

# Customers & Vehicles
GET    /v1/workshop/customers
POST   /v1/workshop/customers
GET    /v1/workshop/customers/{id}/vehicles
POST   /v1/workshop/vehicles
GET    /v1/workshop/vehicle-categories   # For pricing lookup

# Service Packages & Pricing
GET    /v1/workshop/service-packages
GET    /v1/workshop/packages/{id}/pricing?category_id=&oil_type_id=

# Job Cards
GET    /v1/workshop/job-cards            # Filtered by branch/status
POST   /v1/workshop/job-cards            # Create with package selection
GET    /v1/workshop/job-cards/{id}
PATCH  /v1/workshop/job-cards/{id}/status
GET    /v1/workshop/job-cards/{id}/checklist
PATCH  /v1/workshop/job-cards/{id}/checklist/{itemId}  # Mark item complete
POST   /v1/workshop/job-cards/{id}/photos
POST   /v1/workshop/job-cards/{id}/addons
POST   /v1/workshop/job-cards/{id}/spare-request

# Technician & Bay
GET    /v1/workshop/technicians/{id}/workload
GET    /v1/workshop/bays                 # Bay availability
POST   /v1/workshop/job-cards/{id}/assign-bay
POST   /v1/workshop/job-cards/{id}/assign-technician

# QC & Follow-up
GET    /v1/workshop/job-cards/{id}/qc-checklist
PATCH  /v1/workshop/job-cards/{id}/qc-checklist
POST   /v1/workshop/job-cards/{id}/follow-up-call

# Billing
POST   /v1/billing/invoices/from-job-card/{id}
GET    /v1/billing/invoices/{id}/pdf
POST   /v1/billing/invoices/{id}/payments
GET    /v1/billing/invoices/{id}/payment-history

# Dashboard
GET    /v1/dashboard/owner               # All branches consolidated
GET    /v1/dashboard/manager             # Assigned branches
GET    /v1/dashboard/advisor             # Single branch
```

**Response Format**:
```json
{
  "success": true,
  "data": { ... },
  "meta": { "pagination": { ... } }
}
```

---

## RBAC - Default Roles

| Role | Branch Scope | Key Permissions |
|------|--------------|-----------------|
| Owner | ALL branches (no filter) | Full access, consolidated reports, settings |
| Manager | Assigned branches | Operations across assigned branches, no system config |
| Service Advisor | Single branch | Workshop full, billing view/create |
| Technician | Single branch + own jobs | View assigned jobs, update progress |
| Backoffice | Single branch | Billing full, inventory view |
| Marketing | All branches (leads) | CRM full, customer view |

**Branch Assignment:**
- Users linked to branches via `user_branches` pivot table
- Owner role: bypasses branch filtering entirely
- Manager role: can be assigned to multiple branches
- Others: typically assigned to single branch

---

## Implementation Order

### Phase 1: Core Foundation
1. Set up Laravel project with modular structure
2. Implement Core: BaseModel, BranchContext middleware
3. Implement Core: RBAC (users, roles, permissions, user-branch assignments)
4. Implement Core: Event bus, notification service
5. Set up API auth with Sanctum

### Phase 2: User Admin Module
1. User CRUD with role assignment
2. Location management
3. Role templates and permission assignment

### Phase 3: Workshop Module
1. Customer and vehicle management
2. Job card CRUD and workflow
3. Technician assignment and bay management
4. Job card status tracking

### Phase 4: Billing Module
1. Invoice generation from job card
2. GST calculation
3. Payment recording
4. Basic reports

### Phase 5: Mobile App (Flutter)
1. Authentication flow
2. Service Advisor features (job cards, assignments)
3. Technician features (view jobs, update status)
4. Push notifications

### Phase 6: Web Dashboard
1. Owner/Manager dashboard
2. User administration
3. Reports and analytics

---

## Verification Plan

### Unit Testing
- Test branch scoping on operational models (job_cards, invoices)
- Test org-wide access on shared models (customers, vehicles)
- Test RBAC permission checks
- Test job card workflow transitions

### Integration Testing
- Test API endpoints with authentication
- Test cross-module events (job completion → invoice)
- Test branch-based access controls (Owner vs Manager vs Advisor)

### Manual Testing
- Create organization with 2 branches
- Create users with different roles and branch assignments
- Full job card lifecycle: create → assign → complete → invoice → payment
- Verify Owner dashboard shows consolidated data across all branches
- Verify Manager sees only assigned branches
- Verify Service Advisor sees only their branch

---

## Files to Create First

1. `app/Core/Models/BaseModel.php` - Foundation model
2. `app/Core/Models/BranchScopedModel.php` - Model with automatic branch scoping (extends BaseModel)
3. `app/Core/Http/Middleware/BranchContext.php` - Sets current branch context for user
4. `app/Core/Services/Auth/RbacService.php` - Permission + branch access checking
5. `app/Core/Providers/CoreServiceProvider.php` - Core bootstrapping
6. `config/modules.php` - Module configuration
7. `database/migrations/core_tables.php` - Organization, branches, users, roles, permissions
8. `database/seeders/ServicePackagesSeeder.php` - Pre-populate service packages and pricing
9. `database/seeders/VehicleCategoriesSeeder.php` - Pre-populate vehicle categories
10. `database/seeders/ChecklistTemplatesSeeder.php` - Pre-populate activity checklists per package

---

## Reference Documents Used

**Location:** `C:\Users\JOEL\EAERP\Design\`

| Folder | Contents |
|--------|----------|
| `EA Prices/` | Service package pricing, labor charges, car categories, detailing prices |
| `Internal Process Sheets/` | Technician job sheets, QC checklists, spare parts requisition |

**Key Files:**
- `EA Prices/All Cars Service.pdf` - Complete pricing catalog
- `EA Prices/Labor Charges.jpg` - Labor rate table
- `Internal Process Sheets/PRO Service 45 Technician Job Sheet.xlsx` - Sample job sheet structure
- `Internal Process Sheets/Pre Delivery Checklist.xlsx` - QC workflow (UPDATED - use this)
- `Internal Process Sheets/Spare Part Requistion Sheet.xlsx` - Parts workflow
- `Internal Process Sheets/Body Shop Job Sheet.xlsx` - Body shop workflow
