<?php

namespace App\Models\FormWOCR;

use Illuminate\Database\Eloquent\Model;
use app\Models\FormWOCR\WocrData;
use app\Models\WF\WfForm;


class WocrDataFile extends Model
{
    protected $table = 'wocr_data_files';
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

    // ทางลัดกลับไป WOCR (ผ่าน form_id เหมือนกัน)
    public function wocr()
    {
        return $this->hasOne(WocrData::class, 'form_id', 'form_id');
    }
}
