<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El propio tenant (id = slug) también es sujeto de auditoría en su BD ("Configuración"):
     * subject_id pasa a texto, igual que en la BD central.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('subject_id', 64)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_id')->nullable()->change();
        });
    }
};
