# RBAC System Documentation

## Overview
The Role-Based Access Control (RBAC) system provides a robust framework for managing user permissions, roles, and access levels within the Hotel Management System.

## Database Schema (Proposed)

### 1. Permissions Table (`permissions`)
| Column | Type | Description |
|--------|------|-------------|
| id | string (PK) | Unique permission key (e.g., `users_view`) |
| label | string | Human-readable name |
| category | string | Category for UI grouping |
| is_immutable | boolean | If true, cannot be removed from system roles |
| dependencies | JSON | Array of permission IDs that are required |

### 2. Roles Table (`roles`)
| Column | Type | Description |
|--------|------|-------------|
| id | string (PK) | UUID |
| name | string | Unique role name (2-50 chars) |
| description | string | Role purpose |
| is_system | boolean | If true, cannot be deleted |
| created_at | timestamp | Creation date |
| updated_at | timestamp | Last update date |

### 3. Role-Permissions Table (`role_permissions`)
| Column | Type | Description |
|--------|------|-------------|
| role_id | string (FK) | Reference to roles.id |
| permission_id | string (FK) | Reference to permissions.id |

### 4. User-Roles Table (`user_roles`)
| Column | Type | Description |
|--------|------|-------------|
| user_id | string (FK) | Reference to users table |
| role_id | string (FK) | Reference to roles.id |
| assigned_at | timestamp | When the role was assigned |
| assigned_by | string (FK) | Who assigned the role |

### 5. Audit Logs Table (`audit_logs`)
| Column | Type | Description |
|--------|------|-------------|
| id | string (PK) | UUID |
| timestamp | timestamp | When action occurred |
| action | string | Action type (e.g., `role_created`) |
| entity_type | string | e.g., `role`, `user` |
| entity_id | string | ID of affected entity |
| entity_name | string | Name of affected entity |
| performed_by | string | ID of performing user |
| details | text | Additional context |

## RESTful API Endpoints

### Permissions
- `GET /api/rbac/permissions`: List all available permissions.
- `GET /api/rbac/permissions/:id`: Get specific permission details.

### Roles
- `GET /api/rbac/roles`: List all roles.
- `POST /api/rbac/roles`: Create a new role.
- `GET /api/rbac/roles/:id`: Get role details.
- `PUT /api/rbac/roles/:id`: Update role permissions/details.
- `DELETE /api/rbac/roles/:id`: Delete a custom role.

### User Assignments
- `GET /api/rbac/users/:userId/roles`: Get roles for a specific user.
- `POST /api/rbac/users/bulk-assign`: Bulk assign a role to multiple users.
- `POST /api/rbac/users/:userId/assign/:roleId`: Assign a role to a single user.

### Audit
- `GET /api/rbac/audit-logs`: Retrieve system audit trail.
- `GET /api/rbac/audit-logs/export`: Download audit logs as CSV.

## Security & Validation
1. **Immutable Permissions**: System-level permissions cannot be unchecked during role creation/editing.
2. **Dependency Resolution**: Assigning a high-level permission (e.g., `bookings_cancel`) automatically includes required low-level permissions (e.g., `bookings_view`).
3. **Audit Trail**: All RBAC changes are logged with user context for accountability.
4. **Validation**: Role names must be unique and 2-50 characters long.
