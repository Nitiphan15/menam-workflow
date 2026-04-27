<?php


namespace App\Models\FormLIS;

use Illuminate\Database\Eloquent\Model;

class InquiryFile extends Model
{

    protected $table = 'lis_data_files';

    protected $fillable = ['lis_data_id', 'original_name', 'path', 'mime', 'size'];

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class, 'lis_data_id');
    }
}
