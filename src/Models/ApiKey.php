<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Timestamp;
use Reut\DB\Types\Text;

/**
 * ApiKey Model
 * Represents API keys for API access management
 */
class ApiKey extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'api_keys',
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

        $this->addColumn('name', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('key', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('secret', new Varchar(
            255,
            false,
            null
        ));

        $this->addColumn('permissions', new Text(
            false
        ));

        $this->addColumn('allowed_ips', new Text(
            true
        ));

        $this->addColumn('rate_limit', new Integer(
            true,
            false,
            false,
            1000
        ));

        $this->addColumn('is_active', new Integer(
            false,
            false,
            false,
            1
        ));

        $this->addColumn('last_used_at', new Timestamp(
            true,
            false
        ));

        $this->addColumn('expires_at', new Timestamp(
            true,
            false
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



