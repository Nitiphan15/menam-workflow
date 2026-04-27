<?php

namespace App\Models\Users;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\UsesWorkflowConnection;

class DepartmentManager extends Model
{
    use HasFactory;
    use UsesWorkflowConnection;
}
