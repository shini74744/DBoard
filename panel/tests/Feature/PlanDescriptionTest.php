<?php
namespace Tests\Feature;

use App\Models\Plan;
use App\Http\Resources\PlanResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

class PlanDescriptionTest extends TestCase
{
    use RefreshDatabase;
    public function createApplication()
    {
        $app=parent::createApplication();
        $app->detectEnvironment(fn ()=>'testing');
        $app['config']->set('app.env','testing');
        $app['config']->set('database.default','sqlite');
        $app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('sqlite');Cache::setDefaultDriver('array');return $app;
    }
    public function test_markdown_placeholders_keep_structure_and_unlimited_values_have_no_units(): void
    {
        $content="## 套餐详情\n\n- 流量：{{transfer}} GB\n- 速度限制：{{speed}} Mbps\n- 同时在线设备：{{devices}} 台\n\n## 服务说明\n\n1. 流量{{reset_method}}重置\n2. 支持多平台使用";
        $plan=Plan::create(['name'=>'私家车','group_id'=>1,'transfer_enable'=>200,'device_limit'=>2,'speed_limit'=>null,'reset_traffic_method'=>Plan::RESET_TRAFFIC_NEVER,'prices'=>['monthly'=>50],'content'=>$content]);
        $format=fn ()=>(new PlanResource($plan->fresh()))->toArray(request())['content'];
        $text=$format();
        $this->assertStringContainsString("## 套餐详情\n\n- 流量：200 GB",$text);
        $this->assertStringContainsString('- 速度限制：'.__('No Limit')."\n",$text);
        $this->assertStringContainsString('- 同时在线设备：2 台',$text);
        $this->assertStringContainsString('1. 流量重置：'.__('Never'),$text);
        $this->assertStringNotContainsString('重置重置',$text);
        $plan->update(['speed_limit'=>100,'device_limit'=>0,'reset_traffic_method'=>Plan::RESET_TRAFFIC_MONTHLY]);
        $text=$format();
        $this->assertStringContainsString('- 速度限制：100 Mbps',$text);
        $this->assertStringContainsString('- 同时在线设备：'.__('No Limit')."\n",$text);
        $this->assertStringContainsString('1. 流量重置：'.__('Monthly'),$text);
        $this->assertSame($content,$plan->fresh()->content);
    }
    public function test_optional_pack_template_hides_blank_prices_but_keeps_free_prices_and_styles(): void
    {
        $content='<h2 style="color:#2563eb">套餐详情</h2><ul><li>流量：{{transfer}} GB</li><li data-plan-price="onetime"><span style="color:#ef4444">流量包：{{onetime_price}} 元</span></li><li data-plan-price="reset_traffic">重置包：{{reset_price}} 元</li></ul>';
        $plan=new Plan(['content'=>$content,'transfer_enable'=>200,'reset_traffic_method'=>2,'prices'=>['onetime'=>'','reset_traffic'=>null]]);
        $render=fn ()=>(new PlanResource($plan))->formatContent();
        $this->assertStringNotContainsString('流量包',$render());
        $this->assertStringNotContainsString('重置包',$render());
        $plan->prices=['onetime'=>'0','reset_traffic'=>'50.50'];
        $this->assertStringContainsString('流量包：0.00 元',$render());
        $this->assertStringContainsString('重置包：50.50 元',$render());
        $this->assertStringContainsString('color:#ef4444',$render());
        $plan->prices=['onetime'=>50,'reset_traffic'=>' '];
        $this->assertStringContainsString('流量包：50.00 元',$render());
        $this->assertStringNotContainsString('重置包',$render());
        $this->assertSame($content,$plan->content);
    }

    public function test_admin_preview_matches_customer_rendering_without_saving(): void
    {
        $admin=\App\Models\User::create(['email'=>'plan-editor@example.invalid','password'=>'unused','uuid'=>'preview','token'=>'preview','is_admin'=>true]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $data=['content'=>'<ul><li data-plan-price="onetime">流量包：{{onetime_price}} 元</li><li data-plan-price="reset_traffic">重置包：{{reset_price}} 元</li></ul>','prices'=>['onetime'=>'','reset_traffic'=>0],'reset_traffic_method'=>2];
        $expected=(new PlanResource(new Plan($data)))->formatContent();
        $this->postJson('/api/v2/00000000/plan/preview',$data)->assertOk()->assertJsonPath('data.content',$expected);
        $this->assertSame(0,Plan::count());
        $admin->is_admin=false;
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->assertNotEquals(200,$this->postJson('/api/v2/00000000/plan/preview',$data)->status());
    }

}
