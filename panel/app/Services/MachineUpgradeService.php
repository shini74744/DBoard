<?php
namespace App\Services;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;

class MachineUpgradeService
{
    public const ACTIVE = ['queued','accepted','downloading','validating','restarting','verifying','rolling_back'];
    public static function atTarget(string $version, array $status): bool
    {
        $target=(string)($status['target_version']??'');
        return preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+/', $version) && $target!==''
            && version_compare(ltrim($version,'v'),ltrim($target,'v'),'>=');
    }
    public static function busy(?array $s): bool
    {
        return is_array($s) && (in_array($s['state']??'',self::ACTIVE,true)
            || (($s['state']??'')==='unknown' && time()-(int)($s['created_at']??0)<3300));
    }
    private static function mutate(int $id, callable $fn): ?array
    {
        try {
        return Cache::lock("dboard_machine_upgrade_lock:{$id}",10)->block(2,function()use($id,$fn){
            $key="dboard_machine_upgrade:{$id}";$s=Cache::get($key);
            if(!is_array($s))return null;
            $s=$fn($s);Cache::put($key,$s,86400);return $s;
        });
        } catch (LockTimeoutException $e) {
            return Cache::get("dboard_machine_upgrade:{$id}");
        }
    }
    public static function start(int $id, array $status): void
    {
        Cache::lock("dboard_machine_upgrade_lock:{$id}", 10)->block(2, function () use ($id, $status) {
            Cache::put("dboard_machine_upgrade:{$id}", $status, 86400);
        });
    }
    private static function confirm(array $s): array
    {
        $s['state']='success';$s['message']='已确认目标版本重新连接，升级成功';$s['updated_at']=time();return $s;
    }
    public static function version(int $id,string $version): void
    {
        Cache::put("dboard_machine_version_seen_at:{$id}",time(),86400);
        self::mutate($id,function($s)use($version){
            if(!self::atTarget($version,$s))return $s;
            if((int)($s['protocol']??1)<2)return self::confirm($s);
            if(in_array($s['state']??'',self::ACTIVE,true)||($s['state']??'')==='unknown'){
                $s['state']='verifying';$s['message']='目标版本已连接，等待升级任务健康检查结果';$s['updated_at']=time();
            }
            return $s;
        });
    }
    public static function result(int $id,array $data): void
    {
        self::mutate($id,function($s)use($id,$data){
            if(!hash_equals((string)($s['request_id']??''),(string)($data['request_id']??'')))return $s;
            $state=$data['state']??'';
            if(!in_array($state,array_merge(self::ACTIVE,['success','failed','rolled_back']),true))return $s;
            if(($s['state']??'')==='success')return $s;
            if($state==='accepted'&&($s['state']??'')!=='queued')return $s;
            $timestamp=(int)($data['updated_at']??0);
            if($timestamp && $timestamp<(int)($s['report_updated_at']??0))return $s;
            if(in_array($s['state']??'',['failed','rolled_back'],true)&&in_array($state,self::ACTIVE,true))return $s;
            $message=mb_substr((string)($data['message']??''),0,400);
            $version=(string)Cache::get("dboard_machine_version:{$id}",'');
            if((int)($s['protocol']??1)<2 && self::atTarget($version,$s)
                && (int)Cache::get("dboard_machine_version_seen_at:{$id}",0)>=(int)($s['created_at']??PHP_INT_MAX))return self::confirm($s);
            if($state==='failed'&&(str_contains($message,'signal: terminated')||str_contains($message,'signal: killed'))){
                $state='verifying';$message='升级启动进程被重启中断，正在核对服务器实际版本';
            }
            if($state==='success'&&!self::atTarget($version,$s)){$state='verifying';$message='升级任务已完成，等待目标版本重新连接';}
            $s['state']=$state;$s['message']=$message;$s['updated_at']=time();
            if($timestamp)$s['report_updated_at']=$timestamp;
            return $s;
        });
    }
    public static function state(int $id): ?array
    {
        return self::mutate($id,function($s)use($id){
            $version=(string)Cache::get("dboard_machine_version:{$id}",'');
            if((int)($s['protocol']??1)<2 && self::atTarget($version,$s)
                && (int)Cache::get("dboard_machine_version_seen_at:{$id}",0)>=(int)($s['created_at']??PHP_INT_MAX))return self::confirm($s);
            if(in_array($s['state']??'',self::ACTIVE,true) && time()-(int)($s['created_at']??0)>3000){
                $s['state']='unknown';$s['message']='暂未收到最终结果，请核对实际版本或升级日志；不会直接判定失败';$s['updated_at']=time();
            }
            return $s;
        });
    }
}
