<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  foreach(['v2_plan','v2_user'] as $name) Schema::table($name,function(Blueprint $t){$t->unsignedInteger('connection_limit')->nullable();});
 }
 public function down(): void {
  foreach(['v2_plan','v2_user'] as $name) Schema::table($name,function(Blueprint $t){$t->dropColumn('connection_limit');});
 }
};
