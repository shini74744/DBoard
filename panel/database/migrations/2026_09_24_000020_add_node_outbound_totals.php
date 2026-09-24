<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {
  Schema::create('dboard_node_outbound_traffic',function(Blueprint $t){
   $t->unsignedBigInteger('server_id');$t->unsignedBigInteger('outbound_id');
   $t->unsignedBigInteger('upload')->default(0);$t->unsignedBigInteger('download')->default(0);
   $t->unsignedInteger('started_at')->nullable();$t->unsignedInteger('updated_at')->nullable();
   $t->primary(['server_id','outbound_id']);
   $t->foreign('server_id')->references('id')->on('v2_server')->onDelete('cascade');
   $t->foreign('outbound_id')->references('id')->on('v2_server_outbound')->onDelete('cascade');
  });
  DB::transaction(function(){
   $rows=DB::table('v2_outbound_traffic_cursor as c')->join('v2_server as s','s.id','=','c.server_id')
    ->selectRaw('c.server_id,c.outbound_id,SUM(c.upload) as upload,SUM(c.download) as download')->groupBy('c.server_id','c.outbound_id')->get();
   foreach($rows as $row)DB::table('dboard_node_outbound_traffic')->insert((array)$row);
   $outbounds=DB::table('v2_server_outbound')->pluck('id')->flip();
   foreach(DB::table('v2_server')->get(['id','outbound_ids']) as $node)
    foreach(json_decode($node->outbound_ids??'[]',true)?:[] as $id)
     if(isset($outbounds[$id]))DB::table('dboard_node_outbound_traffic')->insertOrIgnore(['server_id'=>$node->id,'outbound_id'=>$id]);
  });
 }
 public function down(): void {Schema::dropIfExists('dboard_node_outbound_traffic');}
};
