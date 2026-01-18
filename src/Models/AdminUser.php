<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Timestamp;

/**
 * AdminUser Model
 * Represents admin users for the admin dashboard
 */
class AdminUser extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'admin_users',
            false,
            [],
            [],
            [],
            ['created_at', 'updated_at'],
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

        $this->addColumn('username', new Varchar(
            100,
            false,
            null
        ));

        $this->addColumn('email', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('password', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('role', new Varchar(
            50,
            false,
            'admin'
        ));

        $this->addColumn('created_at', new Timestamp(
            false,
            false
        ));

        $this->addColumn('updated_at', new Timestamp(
            false,
            false
        ));
    }
}



