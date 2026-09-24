<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\UserNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserNoticeTest extends TestCase
{
    use RefreshDatabase;
    public function createApplication() {
        $app=parent::createApplication(); $app->detectEnvironment(fn()=>'testing');
        $app['config']->set('app.env','testing');
        // Settings use an explicit redis store; isolate it from other runs too.
        $app['config']->set('cache.stores.redis',['driver'=>'array','serialize'=>false]);
        Cache::purge('redis');
        $app['config']->set('app.key','base64:'.base64_encode(str_repeat('T',32)));
        $app['config']->set('database.default','sqlite');
        $app['config']->set('database.connections.sqlite',['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true]);
        DB::purge('sqlite'); Cache::setDefaultDriver('array'); return $app;
    }
    protected function setUp():void {
        parent::setUp(); Queue::fake();
        $this->app->forgetInstance(\App\Support\Setting::class);
        Sanctum::actingAs(User::create(['email'=>'notice@example.invalid','password'=>'unused','uuid'=>'test','token'=>'test','is_admin'=>true]));
    }
    public function test_admin_changes_are_persisted_and_public_config_preserves_disabled_and_zero() {
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.user_notice',null);
        $data=array_replace(UserNotice::defaults(),['enabled'=>true,'title'=>'测试须知','content'=>'<p><strong>欢迎</strong>使用</p>','close_wait_seconds'=>0]);
        $this->postJson('/api/v2/00000000/user-notice/save',$data)->assertOk()->assertJsonPath('data.enabled',true);
        Cache::flush();
        $this->getJson('/api/v2/00000000/user-notice/fetch')->assertOk()->assertJsonPath('data.title','测试须知');
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.user_notice.cooldown_hours',0)->assertJsonPath('data.user_notice.close_wait_seconds',0);
        $data['enabled']=false;
        $this->postJson('/api/v2/00000000/user-notice/save',$data)->assertOk();
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.user_notice.enabled',false);
    }
    public function test_validation_and_markup_safety() {
        $data=UserNotice::defaults(); $data['cooldown_hours']=-1;
        $this->postJson('/api/v2/00000000/user-notice/save',$data)->assertStatus(422);
        $data['cooldown_hours']=0; $data['content']='<script>alert(1)</script>';
        $this->postJson('/api/v2/00000000/user-notice/save',$data)->assertStatus(422);
        $data['content']='<p onclick="evil()">欢迎<img src=x onerror="evil()"><a href="javascript:evil()">链接</a><a href="https://example.org">帮助</a></p><script>evil()</script>';
        $safe=$this->postJson('/api/v2/00000000/user-notice/save',$data)->assertOk()->json('data.content');
        $this->assertStringNotContainsString('evil',$safe);
        $this->assertStringNotContainsString('<img',$safe);
        $this->assertStringContainsString('href="https://example.org"',$safe);
        $this->assertStringContainsString('欢迎',$safe);
    }
    public function test_regular_user_cannot_edit_notice() {
        Sanctum::actingAs(User::create(['email'=>'member@example.invalid','password'=>'unused','uuid'=>'member','token'=>'member','is_admin'=>false]));
        $this->postJson('/api/v2/00000000/user-notice/save',UserNotice::defaults())->assertStatus(403);
    }
}
