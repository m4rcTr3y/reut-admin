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
class Sessions extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'sessions',
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

          $this->addColumn('user_id', new Integer(
            false,
            false,
            false,
            null
        ));

        $this->addColumn('refresh_token', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('expires_at', new Timestamp(
            true,
            false  // DEFAULT CURRENT_TIMESTAMP
        ));
      
        // Timestamp
        $this->addColumn('created_at', new Timestamp(
            false,
            true   // DEFAULT CURRENT_TIMESTAMP
        ));
    }
}



