<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Text;
use Reut\DB\Types\Timestamp;

/**
 * AdminLog Model
 * Stores admin dashboard activity logs
 */
class AdminLog extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'admin_logs',
            false,
            [],
            [],
            [],
            ['created_at'],
            null,
            [],
            false
        );

        // Primary key
        $this->addColumn('id', new Integer(
            false,
            true,
            true,
            null
        ));

        // Log type: 'request', 'error', 'query', 'migration', 'action'
        $this->addColumn('type', new Varchar(
            50,
            false,
            null
        ));

        // Log level: 'info', 'warning', 'error', 'critical'
        $this->addColumn('level', new Varchar(
            20,
            false,
            'info'
        ));

        // Log message
        $this->addColumn('message', new Text(
            false,
            null
        ));

        // Additional context data (JSON)
        $this->addColumn('context', new Text(
            true,
            null
        ));

        // Admin user ID (FK to admin_users)
        $this->addColumn('user_id', new Integer(
            true,
            false,
            false,
            null
        ));

        // IP address
        $this->addColumn('ip_address', new Varchar(
            45,
            true,
            null
        ));

        // User agent
        $this->addColumn('user_agent', new Text(
            true,
            null
        ));

        // Timestamp
        $this->addColumn('created_at', new Timestamp(
            false,
            true   // DEFAULT CURRENT_TIMESTAMP
        ));
    }
}



