<?php
declare(strict_types=1);

namespace Reut\Admin\Models;

use Reut\DB\DataBase;
use Reut\DB\Types\Varchar;
use Reut\DB\Types\Integer;
use Reut\DB\Types\Text;
use Reut\DB\Types\Timestamp;

/**
 * FunctionModel
 * Represents custom functions stored in the functions folder
 */
class FunctionModel extends DataBase
{
    public function __construct(array $config)
    {
        parent::__construct(
            $config,
            [],
            'functions',
            false,
            [],
            [],
            [],
            ['created_at', 'updated_at'],
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

        // Function name/endpoint
        $this->addColumn('name', new Varchar(
            255,
            false,
            null
        ));

        // File path
        $this->addColumn('file_path', new Varchar(
            500,
            false,
            null
        ));

        // Description
        $this->addColumn('description', new Text(
            true,
            null
        ));

        // Requires authentication
        $this->addColumn('requires_auth', new Integer(
            false,
            false,
            false,
            0
        ));

        // Parameter schema (JSON)
        $this->addColumn('params_schema', new Text(
            true,
            null
        ));

        // Allowed HTTP methods (comma-separated)
        $this->addColumn('http_methods', new Varchar(
            50,
            false,
            'GET,POST'
        ));

        // Is active
        $this->addColumn('is_active', new Integer(
            false,
            false,
            false,
            1
        ));

        // Timestamps
        $this->addColumn('created_at', new Timestamp(
            false,
            true   // DEFAULT CURRENT_TIMESTAMP
        ));

        $this->addColumn('updated_at', new Timestamp(
            false,
            true,  // DEFAULT CURRENT_TIMESTAMP
            true   // ON UPDATE CURRENT_TIMESTAMP
        ));
    }
}

