# Reut Admin Dashboard

A comprehensive admin dashboard package for the Reut framework. Provides a modern React-based UI for managing your Reut application, including schema viewing, model editing, migration management, data CRUD operations, and more.

## Features

- **Authentication**: Separate admin user system with JWT authentication
- **Schema Management**: View and explore your database schema
- **Model Editor**: Create and edit models via UI
- **Migration Manager**: View status, apply, and rollback migrations
- **Data Management**: Full CRUD operations on your tables
- **Query Builder**: Execute SQL queries safely
- **Logs Viewer**: View application logs
- **Analytics Dashboard**: Monitor your application statistics
- **User Management**: Manage application users (if auth enabled)

## Installation

```bash
composer require m4rc/reut-admin
```

## Setup

1. **Register the admin routes** in your Reut project's `index.php` or main entry point:

```php
<?php
require 'vendor/autoload.php';

use Slim\Factory\AppFactory;
use Reut\Admin\AdminController;

$app = AppFactory::create();

// Your existing routes...

// Register admin dashboard
$adminController = new AdminController($app, $config, '/admin');
$adminController->register();

$app->run();
```

## API Endpoints

All admin API endpoints are prefixed with `/admin/api/`:

- `POST /admin/api/auth/login` - Admin login
- `POST /admin/api/auth/register` - Admin registration
- `POST /admin/api/auth/refresh` - Refresh token
- `GET /admin/api/schema` - Get schema data
- `GET /admin/api/models` - List models
- `POST /admin/api/models` - Create model
- `PUT /admin/api/models/{name}` - Update model
- `DELETE /admin/api/models/{name}` - Delete model
- `GET /admin/api/migrations/status` - Migration status
- `POST /admin/api/migrations/apply` - Apply migrations
- `POST /admin/api/migrations/rollback` - Rollback migrations
- `GET /admin/api/data/{table}` - Get table data
- `POST /admin/api/data/{table}` - Create record
- `PUT /admin/api/data/{table}/{id}` - Update record
- `DELETE /admin/api/data/{table}/{id}` - Delete record
- `POST /admin/api/query` - Execute query
- `GET /admin/api/logs` - Get logs
- `GET /admin/api/analytics` - Get analytics
- `GET /admin/api/users` - Get users

## Security

- All admin routes (except auth) are protected by JWT middleware
- Admin users are stored in a separate `admin_users` table
- Only SELECT queries are allowed in the query builder
- Input validation and sanitization on all endpoints

## Requirements

- PHP >= 7.4
- Reut Core >= 1.4
- Node.js >= 18 (for building the UI)

## License

MIT



