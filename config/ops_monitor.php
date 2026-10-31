<?php

return [
    'erp_connections' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPS_ERP_CONNECTIONS', 'pgsqlw,pgsqlp'))
    ))),
];
