<?php

use App\Support\SqlServerDb;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(SqlServerDb::connectionName());

        if ($schema->hasTable('grating_delete_logs')) {
            return;
        }

        $schema->create('grating_delete_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 80)->index();
            $table->string('source_id', 80)->nullable()->index();
            $table->unsignedBigInteger('entry_id')->nullable()->index();
            $table->string('action', 40)->default('DELETE');
            $table->text('payload_json');
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->dateTime('deleted_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(SqlServerDb::connectionName())->dropIfExists('grating_delete_logs');
    }
};
