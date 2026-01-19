<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Timestamp;
use Reut\DB\Types\DateTimeType;

/**
 * LoginAttempt Model
 * Tracks failed login attempts for account lockout protection
 */
class LoginAttempt extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'admin_login_attempts',
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

        $this->addColumn('email', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('ip_address', new Varchar(
            45,
            false,
            null
        ));

        $this->addColumn('attempts', new Integer(
            false,
            false,
            false,
            1
        ));

        // Use DATETIME instead of TIMESTAMP for nullable columns to avoid MySQL restrictions
        $this->addColumn('locked_until', new DateTimeType(
            true,   // Nullable
            null    // No default
        ));

        $this->addColumn('created_at', new Timestamp(
            false,
            true   // DEFAULT CURRENT_TIMESTAMP
        ));
    }
}

