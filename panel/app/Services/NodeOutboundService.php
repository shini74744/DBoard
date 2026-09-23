<?php
namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\{Server, ServerOutbound, User};
use Illuminate\Support\Collection;

class NodeOutboundService
{
    public static function unavailableReason(Server $target): ?string
    {
        if ($target->parent_id) return '请使用该节点的主节点作为出口';
        if ($target->enabled === false) return '节点已停用';
        if (!in_array($target->type, ['vmess','vless','trojan','shadowsocks','socks','http'], true)) return '当前出站内核暂不支持此协议';
        if (!filter_var($target->port, FILTER_VALIDATE_INT) || (int)$target->port < 1 || (int)$target->port > 65535 || !trim((string)$target->host)) return '节点需要有效地址和固定连接端口';
        $settings = $target->protocol_settings;
        $network = data_get($settings, 'network', 'tcp') ?: 'tcp';
        if (!in_array($network, ['tcp','ws','grpc','httpupgrade'], true)) return '当前出站暂不支持该传输方式';
        if (data_get($settings, 'encryption.enabled') && !in_array(trim((string)data_get($settings, 'encryption.encryption', 'none')), ['', 'none'], true)) return '当前出站暂不支持 VLESS Encryption';
        if (data_get($settings, 'network_settings.header.type', 'none') !== 'none') return '当前出站暂不支持 TCP HTTP 伪装';
        if (data_get($settings, 'tls_settings.ech.enabled')) return '当前出站暂不支持 ECH';
        if (data_get($settings,'tls_settings.allow_insecure') && (int)data_get($settings,'tls')===1) return '当前自动出站需要有效的 TLS 证书';
        if ((int)data_get($settings, 'tls') === 2 && !data_get($settings, 'reality_settings.public_key')) return 'Reality 公钥未配置';
        return null;
    }

    public static function uuid(Server $target): string
    {
        $hex = hash_hmac('sha256', 'dboard-node-relay:'.$target->id.':'.$target->created_at, (string)config('app.key'));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);
    }

    public static function reference(Server $target): ServerOutbound
    {
        if ($reason = self::unavailableReason($target)) throw new ApiException($reason);
        return ServerOutbound::firstOrCreate(['target_server_id'=>$target->id], [
            'name'=>$target->name,
            'tag'=>'node-'.$target->id.'-'.substr(hash('sha256', (string)$target->id),0,6),
            'protocol'=>$target->type,
            'settings'=>['server'=>$target->host,'server_port'=>(int)$target->port],
            'enabled'=>true,
            'sort'=>((int)ServerOutbound::max('sort'))+1,
        ]);
    }

    public static function config(ServerOutbound $outbound): array
    {
        $target = Server::find($outbound->target_server_id);
        // Keep the tag present and fail closed if an exit is removed or disabled.
        if (!$target || self::unavailableReason($target)) return ['tag'=>$outbound->tag,'protocol'=>'socks','settings'=>['server'=>'node-unavailable.invalid','server_port'=>1]];
        $p = $target->protocol_settings;
        $uuid = self::uuid($target);
        $mode = (int)data_get($p,'tls',0);
        $settings = [
            'server'=>$target->host, 'server_port'=>(int)$target->port,
            'network'=>data_get($p,'network','tcp') ?: 'tcp',
            'tls_mode'=>[1=>'tls',2=>'reality'][$mode] ?? 'none',
            'server_name'=>data_get($p,$mode===2?'reality_settings.server_name':'tls_settings.server_name',''),
            'allow_insecure'=>(bool)data_get($p,'tls_settings.allow_insecure',false),
            'host'=>data_get($p,'network_settings.headers.Host',data_get($p,'network_settings.host','')),
            'path'=>data_get($p,'network_settings.path',''),
            'service_name'=>data_get($p,'network_settings.serviceName',''),
            'fingerprint'=>\App\Utils\Helper::getTlsFingerprint(data_get($p,'utls')) ?: 'chrome',
            'public_key'=>data_get($p,'reality_settings.public_key',''),
            'short_id'=>data_get($p,'reality_settings.short_id',''),
            'flow'=>data_get($p,'flow',''),
        ];
        if (in_array($target->type,['vmess','vless'],true)) $settings['uuid']=$uuid;
        if ($target->type==='vmess') { $settings['security']='auto'; $settings['alter_id']=0; }
        if ($target->type==='trojan') $settings['password']=$uuid;
        if ($target->type==='shadowsocks') {
            $identity=new User(); $identity->uuid=$uuid;
            $settings['method']=data_get($p,'cipher');
            $settings['password']=$target->generateServerPassword($identity);
            $settings['plugin']=data_get($p,'plugin');
            $settings['plugin_opts']=data_get($p,'plugin_opts');
        }
        if (in_array($target->type,['socks','http'],true)) {
            $settings['username']=$uuid; $settings['password']=$uuid;
        }
        return ['tag'=>$outbound->tag,'protocol'=>$target->type,'settings'=>$settings];
    }

    /** Resolve node exits including ordinary outbounds that use them as a proxy. */
    public static function targets(array $ids, ?Collection $outbounds=null): array
    {
        $outbounds ??= ServerOutbound::all();
        $byId=$outbounds->keyBy('id'); $byTag=$outbounds->keyBy('tag');
        $targets=[]; $seen=[];
        foreach ($ids as $id) {
            $entry=$byId->get((int)$id);
            while ($entry && $entry->enabled && !isset($seen[$entry->id])) {
                $seen[$entry->id]=true;
                if ($entry->target_server_id) $targets[]=(int)$entry->target_server_id;
                $entry=$entry->proxy_tag ? $byTag->get($entry->proxy_tag) : null;
            }
        }
        return array_values(array_unique($targets));
    }

    public static function users(Server $target): Collection
    {
        if (self::unavailableReason($target) || !ServerOutbound::where('target_server_id',$target->id)->where('enabled',true)->exists()) return collect();
        $outbounds=ServerOutbound::all();
        foreach (Server::where('enabled',true)->get(['id','outbound_ids']) as $source) {
            if (in_array((int)$target->id,self::targets($source->outbound_ids ?? [],$outbounds),true)) {
                return collect([(object)['id'=>-(int)$target->id,'uuid'=>self::uuid($target),'speed_limit'=>0,'device_limit'=>0]]);
            }
        }
        return collect();
    }

    public static function validateSelection(int $sourceId, array $ids, array $proposed=[]): void
    {
        $outbounds=ServerOutbound::all();
        $targets=self::targets($ids,$outbounds);
        if (!$targets) return;
        $nodes=Server::all()->keyBy('id');
        foreach ($targets as $id) {
            $target=$nodes->get($id);
            if (!$target) throw new ApiException('所选出口节点不存在');
            if ($reason=self::unavailableReason($target)) throw new ApiException($target->name.'：'.$reason);
            if ($id===$sourceId || (!empty($proposed['host']) && $proposed['host']===$target->host && (string)($proposed['port']??'')===(string)$target->port)
                || (!empty($proposed['machine_id']) && (int)$proposed['machine_id']===(int)$target->machine_id && (int)($proposed['server_port']??0)===(int)$target->server_port)) {
                throw new ApiException('不能将当前节点或同一监听端口选为自身出口');
            }
        }
        $visited=[];
        $visit=function(int $id) use (&$visit,&$visited,$sourceId,$nodes,$outbounds): void {
            if ($sourceId && $id===$sourceId) throw new ApiException('节点出口存在循环引用，请调整选择');
            if (isset($visited[$id])) return;
            $visited[$id]=true;
            $node=$nodes->get($id);
            if ($node) foreach(self::targets($node->outbound_ids ?? [],$outbounds) as $next) $visit($next);
        };
        foreach($targets as $target) $visit($target);
    }

    public static function notifyChanged(Server $node): void
    {
        if (!ServerOutbound::whereNotNull('target_server_id')->exists()) return;
        $ids=array_unique(array_merge((array)$node->getOriginal('outbound_ids'),$node->outbound_ids??[]));
        foreach(self::targets($ids) as $target) NodeSyncService::notifyFullSync($target);
        $outbounds=ServerOutbound::all();
        foreach(Server::all(['id','outbound_ids']) as $source) {
            if ($source->id!==$node->id && in_array((int)$node->id,self::targets($source->outbound_ids??[],$outbounds),true))
                NodeSyncService::notifyConfigUpdated($source->id);
        }
    }
}
