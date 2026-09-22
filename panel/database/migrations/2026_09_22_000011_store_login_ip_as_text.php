<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->string('last_login_ip', 45)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve recorded IPv6 addresses; older code can read the text column.
    }
};
