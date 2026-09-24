<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  Schema::table('v2_server',function(Blueprint $t){
   $t->string('admin_scope',16)->default('node')->index();
   $t->dropUnique('server_admin_group_number_unique');
   $t->unique(['admin_scope','admin_group','admin_group_number'],'server_scope_group_number_unique');
  });
  Schema::create('dboard_node_scope_sequences',function(Blueprint $t){
   $t->string('scope',16);$t->string('group_name',64);$t->unsignedBigInteger('last_number')->default(0);
   $t->primary(['scope','group_name']);
  });
  foreach(DB::table('dboard_node_group_sequences')->get() as $row)
   DB::table('dboard_node_scope_sequences')->insert(['scope'=>'node','group_name'=>$row->group_name,'last_number'=>$row->last_number]);
 }
 public function down(): void {
  // Landing nodes may reuse group numbers, so restore unique ordinary numbering.
  Schema::table('v2_server',function(Blueprint $t){$t->dropUnique('server_scope_group_number_unique');$t->dropIndex(['admin_scope']);$t->dropColumn('admin_scope');});
  $counts=[];foreach(DB::table('v2_server')->orderBy('id')->get(['id','admin_group']) as $n){$key=(string)$n->admin_group;$counts[$key]=($counts[$key]??0)+1;DB::table('v2_server')->where('id',$n->id)->update(['admin_group_number'=>$counts[$key]]);}
  foreach($counts as $name=>$last)DB::table('dboard_node_group_sequences')->updateOrInsert(['group_name'=>(string)$name],['last_number'=>$last]);
  Schema::table('v2_server',fn(Blueprint $t)=>$t->unique(['admin_group','admin_group_number'],'server_admin_group_number_unique'));
  DB::table('dboard_admin_groups')->where('kind','node_landing')->delete();
  Schema::dropIfExists('dboard_node_scope_sequences');
 }
};
