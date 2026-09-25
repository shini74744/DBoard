<?php
namespace App\Services;
use App\Models\{ProbeSetting,ServerMachine};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class ProbeService {
 public static function settings(): ?ProbeSetting { return ProbeSetting::find(1); }
 public static function enabled(): bool { return (bool) static::settings()?->enabled; }
 public static function api(string $path,?array $data=null): array {
  $s=static::settings();
  if (!$s || !$s->enabled) throw ValidationException::withMessages(['probe'=>'请先在探针管理中配置并启用探针']);
  $req=Http::withToken($s->control_key)->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
  $r=$data===null?$req->get($s->endpoint.'/bridge/v1/control/'.$path):$req->post($s->endpoint.'/bridge/v1/control/'.$path,$data);
  if (!$r->successful()) throw ValidationException::withMessages(['probe'=>'探针服务连接失败，请检查地址和对接密钥']);
  return $r->json()??[];
 }
 public static function sync(ServerMachine $m,?string $code=null): void {
  if (!$m->probe_uuid) return;
  $reply=static::api('device',['uuid'=>$m->probe_uuid,'name'=>$m->name,'enabled'=>(bool)$m->is_active,'code'=>$code??'']);
  $m->forceFill(['probe_server_id'=>$reply['server_id']??null])->save();
 }
 public static function installCommand(ServerMachine $m): string {
  if (!$m->probe_uuid) $m->forceFill(['probe_uuid'=>(string)Str::uuid()])->save();
  $code=bin2hex(random_bytes(24));static::sync($m,$code);$s=static::settings();
  return 'curl -fsSL '.escapeshellarg($s->endpoint.'/bridge/v1/install.sh').' | sudo bash -s -- --endpoint '.escapeshellarg($s->endpoint).' --uuid '.escapeshellarg($m->probe_uuid).' --enrollment '.escapeshellarg($code).' --version '.escapeshellarg($s->agent_version);
 }
}
