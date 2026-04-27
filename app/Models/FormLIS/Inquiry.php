<?php


namespace App\Models\FormLIS;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{

    protected $table = 'lis_data';

    protected $fillable = [
        'customer',
        'partnumber',
        'qty',
        'delivery_date',
        'priority',
        'remark'
    ];

    protected $casts = [
        'delivery_date' => 'date',
    ];

    public function files()
    {
        return $this->hasMany(InquiryFile::class, 'lis_data_id');
    }

    public function getPriorityTextAttribute(): string
    {
        return match ((int)$this->priority) {
            1 => 'High',
            2 => 'Medium',
            default => 'Low',
        };
    }
}
