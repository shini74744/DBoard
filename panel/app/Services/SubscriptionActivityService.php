<?php
namespace App\Services;
use App\Models\{User,Order,SubscriptionActivity};
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Eloquent\Builder;

class SubscriptionActivityService {
 public const LABELS=['open'=>'开通套餐','extend'=>'叠加套餐时长','renew'=>'续费套餐','cancel'=>'取消套餐订阅','adjust'=>'调整套餐','reset'=>'重置流量','order_cancel'=>'取消订单'];
 private const FIELDS=['u'=>'已用上行','d'=>'已用下行','transfer_enable'=>'总流量','speed_limit'=>'限速','device_limit'=>'设备限制','connection_limit'=>'连接数限制','expired_at'=>'到期时间','billing_period'=>'付费周期','billing_prices'=>'周期价格','plan_id'=>'套餐'];
 public static function snapshot(User $user):array {
  return ['plan_id'=>$user->plan_id,'plan_name'=>$user->plan?->name ?? '套餐','values'=>$user->only(array_keys(self::FIELDS))];
 }
 private static function format(string $field,$value):string {
  $periods=['monthly'=>'月付','quarterly'=>'季付','half_yearly'=>'半年付','yearly'=>'年付','two_yearly'=>'两年付','three_yearly'=>'三年付','onetime'=>'一次性'];
  if ($field==='billing_prices') {
   if (!$value) return '标准价';
   $parts=[];foreach($value as $period=>$cents)$parts[]=($periods[$period]??$period).' ¥'.number_format($cents/100,2,'.','');
   return implode('，',$parts);
  }
  if ($field==='billing_period') return $periods[$value]??'未设置';
  if ($field==='expired_at') return $value===null?'长期有效':($value?date('Y-m-d H:i:s',(int)$value):'无');
  if (in_array($field,['u','d','transfer_enable'],true))return rtrim(rtrim(number_format(((int)$value)/1073741824,4,'.',''),'0'),'.').' GB';
  if (in_array($field,['speed_limit','device_limit','connection_limit'],true))return $value?($value.($field==='speed_limit'?' Mbps':($field==='device_limit'?' 台':' 个'))):'不限';
  return $value===null?'无':(string)$value;
 }
 public static function record(User $account,User $target,string $action,array $before,?int $actor=null,?Order $order=null):?SubscriptionActivity {
  $after=self::snapshot($target);$changes=[];
  foreach(self::FIELDS as $field=>$label){
   $old=$before['values'][$field]??null;$new=$after['values'][$field]??null;
   if ($old===$new)continue;
   $changes[]=['field'=>$field,'label'=>$label,'before'=>$field==='plan_id'?($old?$before['plan_name']:'无'):self::format($field,$old),'after'=>$field==='plan_id'?($new?$after['plan_name']:'无'):self::format($field,$new)];
  }
  if ($action==='adjust'&&!$changes)return null;
  $name=$action==='cancel'?$before['plan_name']:$after['plan_name'];
  $description=implode('；',array_map(fn($c)=>$c['label'].'：'.$c['before'].' → '.$c['after'],$changes));
  $summary=$name.'：'.(self::LABELS[$action]??$action).($description?'；'.$description:'');
  return SubscriptionActivity::create([
   'user_id'=>$account->id,'subscription_user_id'=>$target->id,
   'plan_id'=>$action==='cancel'?$before['plan_id']:$target->plan_id,
   'plan_name'=>$name,'admin_actor_id'=>$actor,'order_id'=>$order?->id,
   'action'=>$action,'summary'=>$summary,'changes'=>$changes,'created_at'=>time(),
  ]);
 }
 // A read-only union. Activity entries never enter the financial orders table.
 public static function feed():Builder {
  $columns=Schema::getColumnListing('v2_order');
  $real=[];$events=[];
  $mapping=['id'=>'-a.id','user_id'=>'a.user_id','plan_id'=>'a.plan_id','subscription_user_id'=>'a.subscription_user_id','trade_no'=>"CAST(a.id AS CHAR)",'type'=>'CAST(6 AS DECIMAL)','status'=>'3','total_amount'=>'0','commission_balance'=>'0','created_at'=>'a.created_at','updated_at'=>'a.created_at','is_admin_created'=>'1','admin_actor_id'=>'a.admin_actor_id'];
  foreach($columns as $name){
   $real[]=$name==='type'?"CAST(CASE WHEN o.is_admin_created = 1 THEN 6 ELSE o.type END AS DECIMAL) AS type":'o.'.$name;
   $events[]=($mapping[$name]??'NULL').' AS '.$name;
  }
  $extraReal=["'order' AS record_kind",'o.type AS business_type','a.action AS activity_action','a.summary AS activity_summary','a.changes AS activity_changes','a.plan_name AS activity_plan_name'];
  $extraEvents=["'activity' AS record_kind",'NULL AS business_type','a.action AS activity_action','a.summary AS activity_summary','a.changes AS activity_changes','a.plan_name AS activity_plan_name'];
  $orders=DB::table('v2_order as o')->leftJoin('dboard_subscription_activity as a','a.order_id','=','o.id')->selectRaw(implode(',',array_merge($real,$extraReal)));
  $activity=DB::table('dboard_subscription_activity as a')->where(function($q){$q->whereNull('a.order_id')->orWhereNotExists(function($sub){$sub->selectRaw('1')->from('v2_order as existing')->whereColumn('existing.id','a.order_id');});})->selectRaw(implode(',',array_merge($events,$extraEvents)));
  return Order::query()->fromSub($orders->unionAll($activity),'v2_order');
 }
 public static function forCustomer(Order $order):?Order {
  $allowed=['u','d','transfer_enable','expired_at','billing_period','billing_prices'];
  $changes=array_values(array_filter($order->activity_changes ?? [],fn($entry)=>in_array($entry['field']??'', $allowed,true)));
  $order->activity_changes=$changes;
  if ($order->activity_action) {
   $description=implode('；',array_map(fn($c)=>$c['label'].'：'.$c['before'].' → '.$c['after'],$changes));
   $order->activity_summary=($order->activity_plan_name ?: $order->plan?->name ?: '套餐').'：'.(self::LABELS[$order->activity_action]??'套餐变更').($description?'；'.$description:'');
  }
  if ($order->record_kind==='activity' && $order->activity_action==='adjust' && !$changes) return null;
  return $order;
 }
 public static function decorate(Order $order):Order {
  if ($order->record_kind==='activity')$order->trade_no='ACT-'.abs((int)$order->getKey());
  if ($order->activity_plan_name) { $plan=$order->plan ? clone $order->plan : new \App\Models\Plan(); $plan->id=$order->plan_id; $plan->name=$order->activity_plan_name; $order->setRelation('plan',$plan); }
  if ($order->is_admin_created&&!$order->activity_summary) {
   $verb=match((int)$order->status){0=>'创建订单（待开通）',1=>'开通处理中',2=>'订单已取消',default=>match($order->subscription_action){'extend'=>'叠加套餐时长','renew'=>'续费套餐',default=>'开通套餐'}};
   $order->activity_summary=($order->plan?->name??'套餐').'：管理员手动'.$verb;
  }
  return $order;
 }
}
