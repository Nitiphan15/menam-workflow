<?php

namespace App\Models\FormPR;

use Illuminate\Database\Eloquent\Model;
use app\Models\FormPR\PrData;
use app\Models\WF\WfForm;


class PrDataFile extends Model
{
    protected $table = 'pr_data_files';
    public $timestamps = false;

    protected $fillable = [
        'form_id',
        'file_name',
        'original_name',
        'file_path',
        'file_type',
        'file_size',
    ];

    public function wfForm()
    {
        return $this->belongsTo(WfForm::class, 'form_id');
    }

    // ทางลัดกลับไป PR (ผ่าน form_id เหมือนกัน)
    public function pr()
    {
        return $this->hasOne(PrData::class, 'form_id', 'form_id');
    }
}
