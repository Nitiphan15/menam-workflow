<?php

namespace App\Models\FormDIE;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Model;

class DieMasterExtra extends Model
{
    use UsesWorkflowConnection;

    protected $guarded = ['id'];

    protected $casts = ['received_date' => 'date'];
}
