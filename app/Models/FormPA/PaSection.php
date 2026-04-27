<?php
// app/Models/PaSection.php
namespace App\Models\FormPA;

use Illuminate\Database\Eloquent\Model;

class PaSection extends Model
{
    protected $table = 'pa_sections';
    protected $fillable = ['code', 'name', 'order_no', 'weight', 'is_active'];

    public function questions()
    {
        return $this->hasMany(PaQuestion::class, 'section_id')->orderBy('order_no');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', 1);
    }
}
