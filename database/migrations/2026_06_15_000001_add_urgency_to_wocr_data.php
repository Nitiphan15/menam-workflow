<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ตาราง wocr_data อยู่บนคอนเนคชัน mysql */
    private function schema()
    {
        return Schema::connection('mysql');
    }

    public function up(): void
    {
        $schema = $this->schema();

        if ($schema->hasTable('wocr_data') && !$schema->hasColumn('wocr_data', 'urgency')) {
            $schema->table('wocr_data', function (Blueprint $table) {
                // ระดับความเร่งด่วน: 1=น้อย, 2=ปานกลาง, 3=มาก (default ปานกลาง)
                $table->unsignedTinyInteger('urgency')->default(2)->after('req_type');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->schema();

        if ($schema->hasTable('wocr_data') && $schema->hasColumn('wocr_data', 'urgency')) {
            $schema->table('wocr_data', function (Blueprint $table) {
                $table->dropColumn('urgency');
            });
        }
    }
};
