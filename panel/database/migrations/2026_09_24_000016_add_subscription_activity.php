<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::table('v2_order',function(Blueprint $t){
   $t->boolean('is_admin_created')->default(false)->index();
   $t->unsignedBigInteger('admin_actor_id')->nullable();
  });
  // A manual payment callback is positive evidence. Do not infer from zero price.
  DB::table('v2_order')->where('callback_no','manual_operation')->update(['is_admin_created'=>true]);
  Schema::create('dboard_subscription_activity',function(Blueprint $t){
   $t->bigIncrements('id');$t->unsignedBigInteger('user_id')->index();
   $t->unsignedBigInteger('subscription_user_id')->nullable();
   $t->unsignedBigInteger('plan_id')->nullable();
   $t->string('plan_name');$t->unsignedBigInteger('admin_actor_id')->nullable();
   $t->unsignedBigInteger('order_id')->nullable()->unique();
   $t->string('action',32);$t->text('summary');$t->json('changes');
   $t->unsignedInteger('created_at')->index();
  });
 }
 public function down():void {
  Schema::dropIfExists('dboard_subscription_activity');
  Schema::table('v2_order',fn(Blueprint $t)=>$t->dropColumn(['is_admin_created','admin_actor_id']));
 }
};
