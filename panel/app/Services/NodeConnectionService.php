<?php
namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

class NodeConnectionService
{
    private static function key(Server $node): string { return 'dboard_node_connections:'.$node->id; }

    public static function record(Server $node, mixed $snapshot): void
    {
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1) return;
        $data = [
            'version'=>1, 'updated_at'=>time(),
            'since'=>max(time()-86400, min(time(), (int)($snapshot['since'] ?? time()))),
            'source_ips'=>max(0,(int)($snapshot['source_ips'] ?? 0)),
            'tcp'=>max(0,(int)($snapshot['tcp'] ?? 0)),
            'udp'=>max(0,(int)($snapshot['udp'] ?? 0)),
            'resolution_seconds'=>60,
            'truncated'=>(bool)($snapshot['truncated'] ?? false),
        ];
        foreach (['sources','tcp_rows','udp_rows'] as $field) {
            $rows = is_array($snapshot[$field] ?? null) ? $snapshot[$field] : [];
            if (count($rows)>300) $data['truncated']=true;
            $data[$field] = [];
            foreach (array_slice($rows,0,300) as $row) {
                if (!is_array($row) || !is_string($row['value'] ?? null)) continue;
                $value = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','', $row['value']) ?? '',0,512);
                if ($field==='sources' && $value!=='未知' && !filter_var($value,FILTER_VALIDATE_IP)) continue;
                $data[$field][] = [
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
            + ['stale'=>time()-$data['updated_at']>max(180,(int)admin_setting('server_push_interval',60)*3)];
    }

    public static function details(Server $node): array
    {
        $data=Cache::get(self::key($node));
        if (!is_array($data)) return ['supported'=>false,'message'=>'节点尚未上报连接明细，请升级节点后等待首次上报。'];
        $data['supported']=true;
        $data['stale']=self::summary($node)['stale'];
        foreach ($data['sources'] as &$row) {
            $row += self::operator($row['value']);
        }
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
