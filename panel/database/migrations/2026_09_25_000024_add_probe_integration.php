<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('dboard_probe_settings',function(Blueprint $t){$t->id();$t->boolean('enabled')->default(false);$t->string('endpoint');$t->json('backups')->nullable();$t->text('control_key');$t->text('connector_key');$t->string('agent_version')->default('v0.2.0');$t->timestamps();});
  Schema::table('v2_server_machine',function(Blueprint $t){$t->uuid('probe_uuid')->nullable()->unique();$t->unsignedBigInteger('probe_server_id')->nullable();$t->string('probe_endpoint')->nullable();$t->text('probe_migration')->nullable();});
  Schema::create('dboard_probe_receipts',function(Blueprint $t){$t->unsignedBigInteger('machine_id');$t->string('batch_id',64);$t->string('body_hash',64);$t->timestamp('created_at');$t->primary(['machine_id','batch_id']);});
 }
 public function down(): void {
  Schema::dropIfExists('dboard_probe_receipts');
  Schema::table('v2_server_machine',function(Blueprint $t){$t->dropColumn(['probe_uuid','probe_server_id','probe_endpoint','probe_migration']);});
  Schema::dropIfExists('dboard_probe_settings');
 }
};
