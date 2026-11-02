<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacto, región y marca de cada tenant (BD central). Los archivos de marca viven en el
     * disco `public` del tenant; aquí solo su ruta.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('name');
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
            // Nombre IANA (America/Bogota); null = config('core.tenant.default_timezone')
            $table->string('timezone', 64)->nullable()->after('contact_phone');
            $table->string('primary_color', 7)->nullable()->after('timezone');
            $table->string('logo_path')->nullable()->after('primary_color');
            $table->string('logo_dark_path')->nullable()->after('logo_path');
            $table->string('favicon_path')->nullable()->after('logo_dark_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone', 'timezone', 'primary_color', 'logo_path', 'logo_dark_path', 'favicon_path']);
        });
    }
};
