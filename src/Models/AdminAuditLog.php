<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Text;
use Reut\DB\Types\Timestamp;

/**
 * AdminAuditLog Model
 * Stores audit logs for critical admin actions
 */
class AdminAuditLog extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'admin_audit_logs',
            false,
            [],
            [],
            [],
            ['created_at'],
            null,
            [],
            false
        );

        $this->addColumn('id', new Integer(
            false,
            true,
            true,
            null
        ));

        $this->addColumn('user_id', new Integer(
            false,
            false,
            false,
            null
        ));

        $this->addColumn('action', new Varchar(
            100,
            false,
            null
        ));

        $this->addColumn('resource_type', new Varchar(
            50,
            false,
            null
        ));

        $this->addColumn('resource_id', new Varchar(
            255,
            true,
            null
        ));

        $this->addColumn('details', new Text(
            true,
            null
        ));

        $this->addColumn('ip_address', new Varchar(
            45,
            true,
            null
        ));

        $this->addColumn('created_at', new Timestamp(
            false,
            false
        ));
    }
}

