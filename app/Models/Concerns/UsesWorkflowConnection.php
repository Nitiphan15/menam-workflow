<?php

namespace App\Models\Concerns;

trait UsesWorkflowConnection
{
    public function getConnectionName()
    {
        return config('database.workflow_connection', 'sqlsrv_menam');
    }
}
