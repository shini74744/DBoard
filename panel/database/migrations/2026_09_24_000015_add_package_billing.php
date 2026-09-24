<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('v2_user',function(Blueprint $t){
   $t->string('billing_period',32)->nullable();
   $t->json('billing_prices')->nullable();
  });
  Schema::table('v2_order',fn(Blueprint $t)=>$t->unsignedInteger('renewal_price')->nullable());
 }
 public function down(): void {
  Schema::table('v2_user',fn(Blueprint $t)=>$t->dropColumn(['billing_period','billing_prices']));
  Schema::table('v2_order',fn(Blueprint $t)=>$t->dropColumn('renewal_price'));
 }
};
