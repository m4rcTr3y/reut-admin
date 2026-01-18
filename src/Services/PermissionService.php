<?php
declare(strict_types=1);

namespace Reut\Admin\Services;

/**
 * Permission Service
 * Defines and checks permissions for admin roles
 */
class PermissionService
{
    // Role definitions
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    // Permission definitions
    public const PERMISSION_VIEW_DASHBOARD = 'view_dashboard';
    public const PERMISSION_VIEW_SCHEMA = 'view_schema';
    public const PERMISSION_VIEW_MODELS = 'view_models';
    public const PERMISSION_CREATE_MODEL = 'create_model';
    public const PERMISSION_EDIT_MODEL = 'edit_model';
    public const PERMISSION_DELETE_MODEL = 'delete_model';
    public const PERMISSION_VIEW_MIGRATIONS = 'view_migrations';
    public const PERMISSION_RUN_MIGRATIONS = 'run_migrations';
    public const PERMISSION_ROLLBACK_MIGRATIONS = 'rollback_migrations';
    public const PERMISSION_VIEW_DATA = 'view_data';
    public const PERMISSION_CREATE_DATA = 'create_data';
    public const PERMISSION_EDIT_DATA = 'edit_data';
    public const PERMISSION_DELETE_DATA = 'delete_data';
    public const PERMISSION_EXECUTE_QUERY = 'execute_query';
    public const PERMISSION_VIEW_LOGS = 'view_logs';
    public const PERMISSION_VIEW_ANALYTICS = 'view_analytics';
    public const PERMISSION_MANAGE_USERS = 'manage_users';
    public const PERMISSION_MANAGE_ENV = 'manage_env';
    public const PERMISSION_VIEW_DOCS = 'view_docs';

    /**
     * Role to permissions mapping
     */
    private static array $rolePermissions = [
        self::ROLE_SUPER_ADMIN => [
            // Super admin has all permissions
            '*'
        ],
        self::ROLE_ADMIN => [
            self::PERMISSION_VIEW_DASHBOARD,
            self::PERMISSION_VIEW_SCHEMA,
            self::PERMISSION_VIEW_MODELS,
            self::PERMISSION_CREATE_MODEL,
            self::PERMISSION_EDIT_MODEL,
            self::PERMISSION_DELETE_MODEL,
            self::PERMISSION_VIEW_MIGRATIONS,
            self::PERMISSION_RUN_MIGRATIONS,
            self::PERMISSION_ROLLBACK_MIGRATIONS,
            self::PERMISSION_VIEW_DATA,
            self::PERMISSION_CREATE_DATA,
            self::PERMISSION_EDIT_DATA,
            self::PERMISSION_DELETE_DATA,
            self::PERMISSION_EXECUTE_QUERY,
            self::PERMISSION_VIEW_LOGS,
            self::PERMISSION_VIEW_ANALYTICS,
            self::PERMISSION_MANAGE_USERS,
            self::PERMISSION_MANAGE_ENV,
            self::PERMISSION_VIEW_DOCS,
        ],
        self::ROLE_EDITOR => [
            self::PERMISSION_VIEW_DASHBOARD,
            self::PERMISSION_VIEW_SCHEMA,
            self::PERMISSION_VIEW_MODELS,
            self::PERMISSION_CREATE_MODEL,
            self::PERMISSION_EDIT_MODEL,
            self::PERMISSION_VIEW_MIGRATIONS,
            self::PERMISSION_VIEW_DATA,
            self::PERMISSION_CREATE_DATA,
            self::PERMISSION_EDIT_DATA,
            self::PERMISSION_EXECUTE_QUERY,
            self::PERMISSION_VIEW_ANALYTICS,
            self::PERMISSION_VIEW_DOCS,
        ],
        self::ROLE_VIEWER => [
            self::PERMISSION_VIEW_DASHBOARD,
            self::PERMISSION_VIEW_SCHEMA,
            self::PERMISSION_VIEW_MODELS,
            self::PERMISSION_VIEW_MIGRATIONS,
            self::PERMISSION_VIEW_DATA,
            self::PERMISSION_VIEW_ANALYTICS,
            self::PERMISSION_VIEW_DOCS,
        ],
    ];

    /**
     * Check if a role has a specific permission
     */
    public static function hasPermission(string $role, string $permission): bool
    {
        // Super admin has all permissions
        if ($role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        $permissions = self::$rolePermissions[$role] ?? [];
        
        // Check for wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    /**
     * Get all permissions for a role
     */
    public static function getPermissions(string $role): array
    {
        return self::$rolePermissions[$role] ?? [];
    }

    /**
     * Get default role for new users
     */
    public static function getDefaultRole(): string
    {
        return self::ROLE_VIEWER;
    }

    /**
     * Check if role is valid
     */
    public static function isValidRole(string $role): bool
    {
        return in_array($role, [
            self::ROLE_SUPER_ADMIN,
            self::ROLE_ADMIN,
            self::ROLE_EDITOR,
            self::ROLE_VIEWER
        ], true);
    }
}

