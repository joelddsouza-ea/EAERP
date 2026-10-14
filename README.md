# EAERP - Car Workshop ERP

A modular ERP system for Car Workshop Management built with Laravel 11.

## Features

- **Multi-Branch Support**: Manage multiple workshop locations
- **Role-Based Access Control**: Granular permissions for different user roles
- **Workshop Management**: Job cards, customers, vehicles, technicians
- **Billing & Invoicing**: GST-compliant invoicing with payment tracking
- **Service Packages**: Pre-defined service packages with pricing matrix
- **Checklist System**: Digital checklists for service quality
- **Follow-up System**: Automated follow-up call scheduling

## Tech Stack

- **Backend**: Laravel 11 + PHP 8.2
- **Database**: MySQL 8.0
- **Cache/Queue**: Redis
- **API Auth**: Laravel Sanctum

## Installation

1. Clone the repository
2. Copy `.env.example` to `.env` and configure your database
3. Install dependencies:
   ```bash
   composer install
   ```
4. Generate application key:
   ```bash
   php artisan key:generate
   ```
5. Run migrations:
   ```bash
   php artisan migrate
   ```
6. Seed the database:
   ```bash
   php artisan db:seed
   ```
7. Start the development server:
   ```bash
   php artisan serve
   ```

## Default Users

After seeding, you can login with:

- **Admin/Owner**: admin@expressautocare.com / password
- **Manager**: manager@expressautocare.com / password
- **Service Advisor**: advisor@expressautocare.com / password
- **Technician**: tech1@expressautocare.com / password

## API Documentation

### Authentication

- `POST /api/v1/auth/login` - Login
- `GET /api/v1/auth/me` - Get current user
- `POST /api/v1/auth/logout` - Logout

### Modules

- **User Admin**: `/api/v1/user-admin/*`
- **Workshop**: `/api/v1/workshop/*`
- **Billing**: `/api/v1/billing/*`

## Project Structure

```
app/
├── Core/                    # Core framework
│   ├── Models/             # Base models (User, Role, Permission, etc.)
│   ├── Services/           # Shared services
│   ├── Http/               # Controllers, Middleware
│   └── Providers/
│
└── Modules/                 # Pluggable modules
    ├── UserAdmin/          # User management
    ├── Workshop/           # Workshop operations
    └── Billing/            # Invoicing & payments
```

## License

Proprietary - Express Autocare
