<?php
// Isolated, dependency-free Laravel regression checks. Never touches the panel DB.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;
use App\Http\Requests\Admin\ServerSave;
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite.database', ':memory:');
DB::purge('sqlite');
Model::unsetEventDispatcher();
Schema::create('v2_server_outbound', function (Blueprint $t) {
 $t->id(); $t->string('name'); $t->string('tag'); $t->string('protocol');
 $t->text('settings'); $t->boolean('enabled'); $t->string('proxy_tag')->nullable(); $t->timestamps();
});
Schema::create('v2_server', function (Blueprint $t) {
 $t->id(); $t->string('name'); $t->text('outbound_ids'); $t->text('custom_route_rules');
 $t->text('custom_balancers'); $t->timestamps();
});
foreach (['a','b','backup'] as $tag) {
 DB::table('v2_server_outbound')->insert(['name'=>$tag,'tag'=>$tag,'protocol'=>'socks','settings'=>'{}','enabled'=>1]);
}
class UniversalTestForm extends ServerSave { public function prepareTest(): void {$this->prepareForValidation();} }
$count=0;
function check(bool $ok, string $name): void { global $count; if (!$ok) {throw new RuntimeException($name);} $count++; echo "PASS $name\n"; }
function requestValidator(array $data): array {
 $req=UniversalTestForm::create('/','POST',array_merge(['type'=>'socks'],$data)); $req->prepareTest(); $rules=[];
 foreach ($req->rules() as $name=>$rule) {
  if (str_starts_with($name,'custom_balancers')||str_starts_with($name,'custom_route_rules')||str_starts_with($name,'outbound_ids')) {$rules[$name]=$rule;}
 }
 $v=Validator::make($req->all(),$rules);$req->withValidator($v);return [$req,$v];
}
$base=['outbound_ids'=>[], 'custom_balancers'=>[['tag'=>'group','strategy'=>'leastPing','selector'=>['a','b'],'fallback_tag'=>'backup']], 'custom_route_rules'=>[['match'=>['ports'=>['443'],'domain_suffixes'=>['example.com']], 'action'=>['type'=>'balancer','target'=>'group']]]];
[$req,$v]=requestValidator($base);
check($v->passes(), 'BALANCER_AUTO_DEPENDENCIES_VALID');
$ids=$req->input('outbound_ids');sort($ids);
check($ids===[1,2,3], 'MEMBERS_AND_FALLBACK_AUTO_INCLUDED');
check(data_get($v->validated(), 'custom_route_rules.0.match.ports.0')==='443','LEGACY_TARGET_PORT_PRESERVED');
foreach (['random','roundRobin','round_robin','leastPing','latency','leastLoad','least_load'] as $strategy) {
 $data=$base;$data['custom_balancers'][0]['strategy']=$strategy;[, $v]=requestValidator($data);check($v->passes(), 'STRATEGY_'.$strategy);
}
$data=$base;$data['custom_balancers'][0]['selector']=['a'];[, $v]=requestValidator($data);check($v->passes(),'LEGACY_SINGLE_MEMBER_PRESERVED');
$data=$base;$data['custom_balancers'][0]['selector']=['a','a'];[, $v]=requestValidator($data);check($v->fails(),'DUPLICATE_MEMBER_REJECTED');
$data=$base;$data['custom_balancers'][0]['fallback_tag']='missing';[, $v]=requestValidator($data);check($v->fails(),'MISSING_FALLBACK_REJECTED');
$data=$base;$data['custom_route_rules'][0]['action']['target']='missing-group';[, $v]=requestValidator($data);check($v->fails(),'MISSING_GROUP_REJECTED');
$data=$base;$data['custom_balancers'][0]['probe_url']='file:///etc/passwd';[, $v]=requestValidator($data);check($v->fails(),'NON_HTTP_PROBE_REJECTED');
$data=$base;$data['custom_balancers'][0]['probe_url']='https://example.com/health';$data['custom_balancers'][0]['probe_interval_seconds']=30;$data['custom_balancers'][0]['probe_timeout_seconds']=5;[, $v]=requestValidator($data);check($v->passes(),'OPTIONAL_PROBE_CONFIG_VALID');
DB::table('v2_server')->insert(['id'=>1,'name'=>'SELFTEST','outbound_ids'=>'[1,2,3]','custom_route_rules'=>json_encode([['action'=>['type'=>'route','target'=>'a']]]),'custom_balancers'=>json_encode([['tag'=>'group','strategy'=>'random','selector'=>['a','b'],'fallback_tag'=>'a']])]);
$method=new ReflectionMethod(App\Http\Controllers\V2\Admin\Server\RouteController::class,'syncTagReferences');
$method->invoke(new App\Http\Controllers\V2\Admin\Server\RouteController(),1,'a','renamed');
$node=App\Models\Server::findOrFail(1);
check(data_get($node->custom_route_rules,'0.action.target')==='renamed','OUTBOUND_RENAME_UPDATES_ROUTE');
check(data_get($node->custom_balancers,'0.selector.0')==='renamed','OUTBOUND_RENAME_UPDATES_MEMBERS');
check(data_get($node->custom_balancers,'0.fallback_tag')==='renamed','OUTBOUND_RENAME_UPDATES_FALLBACK');
echo "TOTAL_PASS=$count\n";
