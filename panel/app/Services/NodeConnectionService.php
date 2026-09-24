<?php
namespace App\Services;

use App\Models\{Server, User};
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Cache;

class NodeConnectionService
{
    private static function key(Server $node): string { return 'dboard_node_connections:'.$node->id; }

    public static function record(Server $node, mixed $snapshot): void
    {
        if (!is_array($snapshot) || !in_array($snapshot['version'] ?? null, [1,2], true)) return;
        $data = [
            'version'=>$snapshot['version'], 'updated_at'=>time(),
            'user_since'=>max(time()-86400,min(time(),(int)($snapshot['user_since']??time()))),
            'since'=>max(time()-86400, min(time(), (int)($snapshot['since'] ?? time()))),
            'source_ips'=>max(0,(int)($snapshot['source_ips'] ?? 0)),
            'tcp'=>max(0,(int)($snapshot['tcp'] ?? 0)),
            'udp'=>max(0,(int)($snapshot['udp'] ?? 0)),
            'resolution_seconds'=>60,
            'truncated'=>(bool)($snapshot['truncated'] ?? false),
        ];
        foreach (['sources','tcp_rows','udp_rows'] as $field) {
            $rows = is_array($snapshot[$field] ?? null) ? $snapshot[$field] : [];
            $limit=$snapshot['version']===2?1000:300;
            if (count($rows)>$limit) $data['truncated']=true;
            $data[$field] = [];
            foreach (array_slice($rows,0,$limit) as $row) {
                if (!is_array($row) || !is_string($row['value'] ?? null)) continue;
                $value = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','', $row['value']) ?? '',0,512);
                if ($field==='sources' && $value!=='未知' && !filter_var($value,FILTER_VALIDATE_IP)) continue;
                $data[$field][] = [
                    'user_id'=>$snapshot['version']===2?(int)($row['user_id']??0):0,
                    'value'=>$value, 'active'=>max(0,(int)($row['active'] ?? 0)),
                    'count'=>max(0,(int)($row['count'] ?? 0)),
                    'seconds'=>max(0,min(1e15,(float)($row['seconds'] ?? 0))),
                ];
            }
        }
        Cache::put(self::key($node),$data,90000);
    }

    public static function summary(Server $node): ?array
    {
        $data=Cache::get(self::key($node));
        if (!is_array($data)) return null;
        return array_intersect_key($data,array_flip(['updated_at','since','source_ips','tcp','udp','truncated']))
            + ['stale'=>time()-$data['updated_at']>($data['version']===2?30:max(180,(int)admin_setting('server_push_interval',60)*3))];
    }

    // Use exactly the identities admitted by this node, then collapse package
    // identities to their owning account for the admin picker.
    public static function owners(Server $node): array
    {
        $ids=ServerService::getAvailableUsers($node)->pluck('id')->filter(fn($id)=>(int)$id>0)->all();
        $identities=User::whereIn('id',$ids)->get(['id','parent_id','email']);
        $parents=User::whereIn('id',$identities->pluck('parent_id')->filter()->all())->get(['id','email'])->keyBy('id');
        $owners=[];
        foreach($identities as $identity){
            $account=$identity->parent_id ? $parents->get($identity->parent_id) : $identity;
            if(!$account)continue;
            $id=(int)$account->id;
            if(!isset($owners[$id]))$owners[$id]=['id'=>$id,'email'=>$account->email,'identity_ids'=>[]];
            $owners[$id]['identity_ids'][]=(int)$identity->id;
        }
        uasort($owners,fn($a,$b)=>strnatcasecmp($a['email'],$b['email']));
        return $owners;
    }

    public static function details(Server $node, ?int $userId=null): array
    {
        $owners=self::owners($node);
        if($userId!==null && !isset($owners[$userId]))
            throw ValidationException::withMessages(['user_id'=>'该用户当前没有此节点的使用权限，请重新选择。']);
        $users=array_values(array_map(fn($u)=>['id'=>$u['id'],'email'=>$u['email']],$owners));
        $data=Cache::get(self::key($node));
        if (!is_array($data)) return ['supported'=>false,'users'=>$users,'message'=>'节点尚未上报连接明细，请升级节点后等待首次上报。'];
        $data['users']=$users;
        $data['selected_user_id']=$userId;
        $data['user_filter_supported']=$data['version']===2;
        $data['supported']=true;
        $data['stale']=self::summary($node)['stale'];
        if($userId!==null && !$data['user_filter_supported']){
            $data['supported']=false;
            $data['message']='此节点尚未支持按用户统计，请升级节点。旧记录无法追溯用户。';
            foreach(['sources','tcp_rows','udp_rows'] as $field)$data[$field]=[];
            $data['source_ips']=$data['tcp']=$data['udp']=0;
            return $data;
        }
        $scope=$userId!==null ? array_flip($owners[$userId]['identity_ids']) : null;
        foreach(['sources','tcp_rows','udp_rows'] as $field){
            $merged=[];
            foreach($data[$field] as $row){
                if($scope!==null && !isset($scope[$row['user_id']??0]))continue;
                $key=$row['value'];
                if(!isset($merged[$key]))$merged[$key]=['value'=>$key,'active'=>0,'count'=>0,'seconds'=>0.0];
                foreach(['active','count','seconds'] as $metric)$merged[$key][$metric]+=$row[$metric];
            }
            $data[$field]=array_values($merged);
            usort($data[$field],fn($a,$b)=>($b['active']<=>$a['active'])?:($b['count']<=>$a['count'])?:strcmp($a['value'],$b['value']));
        }
        if($scope!==null){
            $data['since']=max($data['since'],$data['user_since']);
            $data['source_ips']=count(array_filter($data['sources'],fn($r)=>$r['active']>0 && $r['value']!=='未知'));
            $data['tcp']=array_sum(array_column($data['tcp_rows'],'active'));
            $data['udp']=array_sum(array_column($data['udp_rows'],'active'));
        }
        foreach ($data['sources'] as &$row) $row += self::operator($row['value']);
        return $data;
    }

    // An offline IP-to-ASN database: no external requests containing client IPs.
    public static function operator(string $ip): array
    {
        $empty=['operator'=>'未知','asn'=>null];
        if (!filter_var($ip,FILTER_VALIDATE_IP)) return $empty;
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
            return ['operator'=>'内网 / 保留地址','asn'=>null];
        $file=config('dboard.asn_database', storage_path('app/ip-asn.sqlite'));
        if (!is_file($file)) return $empty;
        try {
            return Cache::remember('dboard_ip_asn:'.filemtime($file).':'.$ip,86400,function () use ($file,$ip,$empty) {
                $packed=inet_pton($ip);
                $db=new \PDO('sqlite:'.$file,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                $db->exec('PRAGMA query_only = ON');
                $q=$db->prepare('SELECT end, asn, name FROM ranges WHERE version=? AND start<=? ORDER BY start DESC LIMIT 1');
                $hex=bin2hex($packed);
                $q->execute([strlen($packed)===4?4:6,$hex]);
                $row=$q->fetch(\PDO::FETCH_ASSOC);
                return $row && strcmp($hex,$row['end'])<=0 && $row['asn']>0
                    ? ['operator'=>$row['name'],'asn'=>(int)$row['asn']] : $empty;
            });
        } catch (\Throwable $e) { return $empty; }
    }
}
