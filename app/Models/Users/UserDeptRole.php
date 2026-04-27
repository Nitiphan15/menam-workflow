<?php

namespace App\Models\Users;

use App\Models\Concerns\UsesWorkflowConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDeptRole extends Model
{
    use HasFactory;
    use UsesWorkflowConnection;
}
