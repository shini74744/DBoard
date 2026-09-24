<?php
namespace App\Services;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
class NodeOutboundTrafficService
{
 public static function attached(Server $node): void {
  $ids=array_values(array_unique(array_map('intval',$node->outbound_ids??[])));
  if(!$ids)return;
  $rows=DB::table('v2_server_outbound')->whereIn('id',$ids)->pluck('id')->map(fn($id)=>[
   'server_id'=>$node->id,'outbound_id'=>$id,'started_at'=>time(),'upload'=>0,'download'=>0
  ])->all();
  if($rows)DB::table('dboard_node_outbound_traffic')->insertOrIgnore($rows);
 }
 public static function add(Server $node,int $outboundId,int $up,int $down): void {
  $key=['server_id'=>$node->id,'outbound_id'=>$outboundId];
  DB::table('dboard_node_outbound_traffic')->insertOrIgnore($key+['started_at'=>time()]);
  $query=DB::table('dboard_node_outbound_traffic')->where($key);
  $row=$query->lockForUpdate()->first();
  if($up>PHP_INT_MAX-(int)$row->upload || $down>PHP_INT_MAX-(int)$row->download)return;
  $query->update(['upload'=>(int)$row->upload+$up,'download'=>(int)$row->download+$down,'updated_at'=>time()]);
 }
 public static function forNode(int $id): array {
  return DB::table('dboard_node_outbound_traffic')->where('server_id',$id)->get()->mapWithKeys(fn($r)=>[(int)$r->outbound_id=>[
   'upload'=>(int)$r->upload,'download'=>(int)$r->download,
   'started_at'=>$r->started_at?(int)$r->started_at:null,'updated_at'=>$r->updated_at?(int)$r->updated_at:null
  ]])->all();
 }
}
