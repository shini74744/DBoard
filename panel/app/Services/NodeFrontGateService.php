<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Server,ServerGroup};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{Cache,Crypt,DB,Log};

class NodeFrontGateService {
 public const FIELDS=['front_gate_enabled','front_gate_node_ids','front_gate_group_ids'];
 public static function capable(Server $node):bool {
  return (bool)Cache::get('dboard_front_gate_capable:'.$node->id,false);
 }
 public static function allowed(Server $target):Collection {
  $ids=array_map('intval',$target->front_gate_node_ids??[]);
  $groups=array_map('intval',$target->front_gate_group_ids??[]);
  return Server::where(fn($q)=>$q->whereNull('parent_id')->orWhere('parent_id',0))->where('id','!=',$target->id)
   ->where(fn($q)=>$q->where('enabled',true)->orWhereNull('enabled'))->get()
   ->filter(fn($n)=>in_array((int)$n->id,$ids,true)||array_intersect(array_map('intval',$n->group_ids??[]),$groups))
   ->sortBy('id')->values();
 }
 public static function validate(Server $node, bool $wasEnabled=false):void {
  if (!$node->front_gate_enabled)return;
  if ($node->parent_id)throw new ApiException('指定前置请在实际监听的主节点上设置',422);
  if (!$node->id)throw new ApiException('请先保存节点并升级节点程序，再开启指定前置',422);
  if (!$wasEnabled&&!self::capable($node))throw new ApiException('该落地尚未上报前置认证能力，请先升级节点程序并等待上线',422);
  if (in_array((int)$node->id,array_map('intval',$node->front_gate_node_ids??[]),true))throw new ApiException('不能将落地自身选为前置',422);
  if (!($node->front_gate_node_ids??[])&&!($node->front_gate_group_ids??[]))throw new ApiException('请至少选择一个前置节点或套餐权限组',422);
  if (!filter_var($node->port,FILTER_VALIDATE_INT)||$node->port<1||$node->port>65535||!trim((string)$node->host))
   throw new ApiException('指定前置需要有效地址和固定连接端口',422);
 }
 public static function identity(Server $node):array {
  return DB::transaction(function()use($node){
   // Serialise first creation on the node row; never return another node's key.
   Server::whereKey($node->id)->lockForUpdate()->firstOrFail();
   $row=DB::table('dboard_node_identity')->where('node_id',$node->id)->first();
   if (!$row || $row->expires_at<time()+30*86400) {
    $name='node-'.$node->id.'.front.dboard.invalid';
    $path=tempnam(sys_get_temp_dir(),'dboard-pki-');
    if (!$path)throw new \RuntimeException('Cannot create identity configuration');
    try {
     chmod($path,0600);
     file_put_contents($path,"[req]\ndistinguished_name=dn\nx509_extensions=identity\n[dn]\n[identity]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth,clientAuth\nsubjectAltName=DNS:".$name."\n");
     $options=['config'=>$path,'private_key_type'=>OPENSSL_KEYTYPE_EC,'private_key_bits'=>2048,'curve_name'=>'prime256v1','digest_alg'=>'sha256','x509_extensions'=>'identity'];
     $key=openssl_pkey_new($options);
     $csr=$key?openssl_csr_new(['commonName'=>$name],$key,$options):false;
     $cert=$csr?openssl_csr_sign($csr,null,$key,1095,$options,random_int(1,2147483647)):false;
     if (!$cert||!openssl_x509_export($cert,$certificate)||!openssl_pkey_export($key,$privateKey,null,$options))
      throw new \RuntimeException('Cannot create node identity');
     $expires=(int)openssl_x509_parse($certificate)['validTo_time_t'];
     DB::table('dboard_node_identity')->updateOrInsert(['node_id'=>$node->id],['certificate'=>$certificate,'encrypted_key'=>Crypt::encryptString($privateKey),'expires_at'=>$expires,'created_at'=>time()]);
     $row=DB::table('dboard_node_identity')->where('node_id',$node->id)->first();
    } finally {unlink($path);}
   }
   return ['certificate'=>$row->certificate,'private_key'=>Crypt::decryptString($row->encrypted_key),'server_name'=>'node-'.$node->id.'.front.dboard.invalid'];
  });
 }
 public static function inbound(Server $node):array {
  $own=self::identity($node);$trusted=[];
  foreach(self::allowed($node) as $front)$trusted[]=self::identity($front)['certificate'];
  $revision=hash('sha256',$own['certificate'].implode('', $trusted));
  return ['version'=>1,'original_protocol'=>$node->type,'certificate'=>$own['certificate'],'private_key'=>$own['private_key'],'trusted_clients'=>$trusted,'revision'=>$revision];
 }
 public static function outbound(Server $target,?Server $source,string $tag,?string $proxyTag):array {
  if (!$source || !self::allowed($target)->contains('id',$source->id)) {
   return ['tag'=>$tag,'protocol'=>'socks','settings'=>['server'=>'node-not-authorized.invalid','server_port'=>1]]+($proxyTag?['proxy_tag'=>$proxyTag]:[]);
  }
  $client=self::identity($source);$server=self::identity($target);
  return ['tag'=>$tag,'protocol'=>'vless','settings'=>[
   'server'=>$target->host,'server_port'=>(int)$target->port,'uuid'=>NodeOutboundService::uuid($target),
   'network'=>'tcp','tls_mode'=>'tls','server_name'=>$server['server_name'],
   'certificate_pem'=>$server['certificate'],'client_certificate_pem'=>$client['certificate'],
   'client_key_pem'=>$client['private_key'],'front_gate_version'=>1,
  ]]+($proxyTag?['proxy_tag'=>$proxyTag]:[]);
 }
 public static function summary(Server $node):array {
  $allowed=$node->front_gate_enabled?self::allowed($node):collect();
  $applied=Cache::get('dboard_front_gate_applied:'.$node->id);
  // Summary reads only public certificates; do not mint credentials on an admin list read.
  $certs=DB::table('dboard_node_identity')->whereIn('node_id',$allowed->pluck('id')->push($node->id))->pluck('certificate','node_id');
  $revision=isset($certs[$node->id])&&$allowed->every(fn($n)=>isset($certs[$n->id]))
   ?hash('sha256',$certs[$node->id].$allowed->map(fn($n)=>$certs[$n->id])->implode('')):null;
  return ['capable'=>self::capable($node),'applied'=>$node->front_gate_enabled&&$revision&&hash_equals($revision,(string)$applied),'allowed_count'=>$allowed->count(),'allowed_nodes'=>$allowed->map(fn($n)=>['id'=>$n->id,'name'=>$n->name,'machine_id'=>$n->machine_id])->all()];
 }
 public static function notifyChanged(Server $changed):void {
  try {
   foreach(Server::where('front_gate_enabled',true)->get() as $target)NodeSyncService::notifyConfigUpdated($target->id);
   if ($changed->wasChanged(self::FIELDS))NodeOutboundService::notifyChanged($changed);
  }catch(\Throwable $e){Log::warning('Front gate sync will retry on node polling',['node_id'=>$changed->id]);}
 }
}
