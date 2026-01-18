#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Admin Setup Script
 * Run this command to set up the admin dashboard:
 * php vendor/m4rc/reut-admin/bin/admin-setup.php
 * 
 * Or if installed globally:
 * Reut admin:setup
 */

// Find project root (go up from vendor/m4rc/reut-admin/bin)
$projectRoot = dirname(dirname(dirname(dirname(__DIR__))));

// Check if we're in a vendor directory
if (!file_exists($projectRoot . '/composer.json')) {
    // Try alternative path (if called from project root)
    $projectRoot = getcwd();
    if (!file_exists($projectRoot . '/composer.json')) {
        echo "❌ Error: Could not find project root. Please run this command from your project directory.\n";
        exit(1);
    }
}

// Set project root constant
define('REUT_PROJECT_ROOT', $projectRoot);

// Change to project root
chdir($projectRoot);

// Load Composer autoloader
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    echo "❌ Error: Composer autoloader not found. Please run 'composer install' first.\n";
    exit(1);
}

require $autoloadPath;

// Check if admin package is installed
if (!class_exists(\Reut\Admin\Commands\AdminSetupCommand::class)) {
    echo "❌ Error: Admin package not found.\n\n";
    echo "Please install the admin package first:\n";
    echo "  composer require m4rc/reut-admin\n\n";
    echo "Then run this setup command again.\n";
    exit(1);
}

// Run the setup command
$command = new \Reut\Admin\Commands\AdminSetupCommand();
exit($command->execute());

