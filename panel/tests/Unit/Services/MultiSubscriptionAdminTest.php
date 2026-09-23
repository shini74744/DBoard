<?php

namespace Tests\Unit\Services;

use App\Models\{Order,Plan,Server,SubscriptionCombination,User};
use App\Services\{MultiSubscriptionService,OrderService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiSubscriptionAdminTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $plans=[];
        foreach (['Alpha','Beta'] as $i=>$name) {
            $plans[]=Plan::create([
                'name'=>$name,'group_id'=>$i+1,'transfer_enable'=>100,
                'show'=>1,'sell'=>1,'renew'=>1,'sort'=>0,
                'reset_traffic_method'=>Plan::RESET_TRAFFIC_MONTHLY,
                'prices'=>[Plan::PERIOD_MONTHLY=>10],
            ]);
        }
        $account=User::create([
            'email'=>'package-admin@example.invalid','password'=>'unused',
            'uuid'=>'00000000-0000-0000-0000-000000000111','token'=>str_repeat('1',32),
            'plan_id'=>$plans[0]->id,'group_id'=>1,'transfer_enable'=>100*1073741824,
            'u'=>1073741824,'d'=>2*1073741824,'expired_at'=>time()+30*86400,
            'is_admin'=>true,'balance'=>0,'commission_balance'=>0,'speed_limit'=>50,'device_limit'=>3,
        ]);
        $child=MultiSubscriptionService::createPackage($account);
        $child->update([
            'plan_id'=>$plans[1]->id,'group_id'=>2,'transfer_enable'=>151*1073741824,
            'u'=>3*1073741824,'d'=>4*1073741824,'expired_at'=>time()+40*86400,
            'speed_limit'=>17,'device_limit'=>2,
        ]);
        return [$account,$child,$plans];
    }

    public function test_extension_only_preserves_usage_quota_limits_and_other_package(): void
    {
        [$account,$child,$plans]=$this->fixture();
        Sanctum::actingAs($account);
        $before=$child->only(['u','d','transfer_enable','speed_limit','device_limit','expired_at']);
        $rootBefore=$account->fresh()->getAttributes();
        $tradeNo=$this->postJson('/api/v2/00000000/order/assign',[
            'email'=>$account->email,'plan_id'=>$plans[1]->id,'period'=>'monthly',
            'total_amount'=>0,'subscription_action'=>'extend',
            'subscription_user_id'=>$child->id,'custom_duration_days'=>7,
        ])->assertOk()->json('data');
        $order=Order::where('trade_no',$tradeNo)->sole();
        $order->update(['status'=>Order::STATUS_PROCESSING]);
        (new OrderService($order))->open();
        $after=$child->fresh();
        $this->assertSame($before['expired_at']+7*86400,$after->expired_at);
        foreach (['u','d','transfer_enable','speed_limit','device_limit'] as $key) $this->assertSame($before[$key],$after->$key);
        $this->assertSame($rootBefore,$account->fresh()->getAttributes());
        $this->assertSame(1,User::where('parent_id',$account->id)->count());
        (new OrderService($order))->open();
        $this->assertSame($after->expired_at,$child->fresh()->expired_at);
    }

    public function test_extension_cannot_shorten_expiry_or_target_another_account(): void
    {
        [$account,$child,$plans]=$this->fixture();
        Sanctum::actingAs($account);
        $payload=['email'=>$account->email,'plan_id'=>$plans[1]->id,'period'=>'monthly','total_amount'=>0,
            'subscription_action'=>'extend','subscription_user_id'=>$child->id,'custom_expired_at'=>time()+86400];
        $this->postJson('/api/v2/00000000/order/assign',$payload)->assertStatus(422);
        $this->assertSame(0,Order::count());
        $this->expectException(\App\Exceptions\ApiException::class);
        OrderService::createFromRequest($account,$plans[0],'monthly',null,'extend',$account->id);
    }

    public function test_package_update_changes_only_requested_fields_and_owner(): void
    {
        [$account,$child]=$this->fixture();
        Sanctum::actingAs($account);
        $before=$account->fresh()->getAttributes();
        $this->postJson('/api/v2/00000000/user/subscription/update',[
            'user_id'=>$account->id,'subscription_user_id'=>$child->id,'speed_limit'=>28,'device_limit'=>5,
        ])->assertOk();
        $this->assertSame(28,$child->fresh()->speed_limit);
        $this->assertSame(5,$child->fresh()->device_limit);
        $this->assertSame(3*1073741824,$child->fresh()->u);
        $this->assertSame($before,$account->fresh()->getAttributes());
        $other=User::create(['email'=>'other@example.invalid','password'=>'unused','uuid'=>'other','token'=>'other','plan_id'=>$account->plan_id]);
        $this->postJson('/api/v2/00000000/user/subscription/update',[
            'user_id'=>$account->id,'subscription_user_id'=>$other->id,'speed_limit'=>1,
        ])->assertStatus(422);
        $this->postJson('/api/v2/00000000/user/subscription/update',[
            'user_id'=>$account->id,'subscription_user_id'=>$child->id,'transfer_enable'=>-1,
        ])->assertUnprocessable();
        $this->postJson('/api/v2/00000000/user/update',[
            'id'=>$account->id,'transfer_enable'=>999,
        ])->assertStatus(422);
    }

    public function test_admin_list_exposes_each_package_without_overwriting_primary_quota(): void
    {
        [$account,$child]=$this->fixture();
        Sanctum::actingAs($account);
        $item=$this->getJson('/api/v2/00000000/user/fetch')->assertOk()->json('data.0');
        $this->assertSame($account->id,$item['id']);
        $this->assertSame(100*1073741824,$item['transfer_enable']);
        $this->assertSame(1073741824,$item['u']);
        $this->assertCount(2,$item['subscription_summaries']);
        $this->assertSame(['Alpha','Beta'],array_column($item['subscription_summaries'],'plan_name'));
        $first=$this->getJson('/api/v2/00000000/user/traffic-breakdown?id='.$account->id.'&subscription_user_id='.$account->id)->assertOk()->json('data');
        $second=$this->getJson('/api/v2/00000000/user/traffic-breakdown?id='.$account->id.'&subscription_user_id='.$child->id)->assertOk()->json('data');
        $this->assertSame(100*1073741824,$first['total_bytes']);
        $this->assertSame(151*1073741824,$second['total_bytes']);

    }

    public function test_subscription_information_is_inert_and_separate_for_selected_packages(): void
    {
        [$account,$child,$plans]=$this->fixture();
        admin_setting(['show_info_to_server_enable'=>1,'show_protocol_to_server_enable'=>1]);
        foreach ($plans as $plan) Server::create([
            'name'=>'Real node '.$plan->name,'type'=>'vless','host'=>'real-node.example','port'=>'443',
            'server_port'=>443,'group_ids'=>[(string)$plan->group_id],'show'=>true,'rate'=>1,
            'protocol_settings'=>['network'=>'tcp','tls'=>0,'tls_settings'=>['server_name'=>'real-sni.example']],
        ]);
        $combined=SubscriptionCombination::create([
            'user_id'=>$account->id,'name'=>'Both','token'=>bin2hex(random_bytes(24)),
            'package_ids'=>[$account->id,$child->id],'duplicate_node_mode'=>'all',
        ]);
        $response=$this->get('/api/v1/client/subscribe?flag=general&token='.$combined->token)->assertOk();
        $lines=array_values(array_filter(explode("\n",trim(base64_decode($response->getContent())))));
        $this->assertCount(8,$lines);
        $hints=array_values(array_filter($lines,fn($line)=>str_contains($line,'subscription-info.invalid')));
        $this->assertCount(6,$hints);
        foreach ($hints as $hint) {
            $this->assertStringContainsString('00000000-0000-0000-0000-000000000000@subscription-info.invalid:1',$hint);
            $this->assertStringNotContainsString('real-node.example',$hint);
            $this->assertStringNotContainsString('real-sni.example',$hint);
        }
        $decoded=rawurldecode(implode("\n",$hints));
        $this->assertStringContainsString('Alpha · 剩余流量：97',$decoded);
        $this->assertStringContainsString('Beta · 剩余流量：144',$decoded);
        foreach (['meta','sing-box'] as $flag) {
            $formatted=$this->get('/api/v1/client/subscribe?flag='.$flag.'&token='.$combined->token)->assertOk();
            $this->assertSame(6,substr_count($formatted->getContent(),'subscription-info.invalid'));
        }
        $child->update(['u'=>$child->transfer_enable+1,'d'=>0]);
        $depleted=$this->get('/api/v1/client/subscribe?flag=general&token='.$combined->token)->assertOk();
        $depletedText=rawurldecode(base64_decode($depleted->getContent()));
        $this->assertStringContainsString('Beta · 剩余流量：0',$depletedText);
        $single=$this->get('/api/v1/client/subscribe?flag=general&token='.MultiSubscriptionService::primaryPackageToken($account))->assertOk();
        $singleText=rawurldecode(base64_decode($single->getContent()));
        $this->assertSame(3,substr_count($singleText,'subscription-info.invalid'));
        $this->assertStringNotContainsString('Beta ·',$singleText);
        $this->assertDoesNotMatchRegularExpression('/ #\d+/', $decoded);
        $child->update(['plan_id'=>$plans[0]->id,'group_id'=>1,'u'=>0,'d'=>0]);
        $labels=MultiSubscriptionService::displayNames($account);
        $this->assertSame('Alpha（第1份）',$labels[$account->id]);
        $this->assertSame('Alpha（第2份）',$labels[$child->id]);
        $servers=MultiSubscriptionService::mergedServers($account);
        $this->assertCount(2,array_unique(array_column($servers,'name')));

    }
}
