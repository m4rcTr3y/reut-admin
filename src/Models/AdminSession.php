<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Timestamp;
use Reut\DB\Types\DateTimeType;

/**
 * AdminSession Model
 * Stores active admin sessions for token revocation
 */
class AdminSession extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'admin_sessions', // Table name
            false,
            [],
            [],
            [],
            ['created_at', 'last_activity'], // Timestamps
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

        $this->addColumn('token_hash', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('refresh_token_hash', new Varchar(
            255,
            true,
            null
        ));

        $this->addColumn('ip_address', new Varchar(
            45, // IPv4 or IPv6
            true,
            null
        ));

        $this->addColumn('user_agent', new Varchar(
            500,
            true,
            null
        ));

        $this->addColumn('created_at', new Timestamp(
            false,
            true   // DEFAULT CURRENT_TIMESTAMP
        ));

        $this->addColumn('last_activity', new Timestamp(
            false,
            true   // DEFAULT CURRENT_TIMESTAMP
        ));

        // Use DATETIME instead of TIMESTAMP for nullable columns to avoid MySQL restrictions
        $this->addColumn('expires_at', new DateTimeType(
            true,   // Nullable (expiration is optional)
            null    // No default (set when session is created)
        ));
    }
}



