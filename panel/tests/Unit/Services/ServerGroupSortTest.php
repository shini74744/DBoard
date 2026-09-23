<?php
namespace Tests\Unit\Services;
use App\Models\{ServerGroup,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class ServerGroupSortTest extends TestCase {
 use RefreshDatabase;
 public function test_sort_is_persisted_and_stale_or_duplicate_lists_are_rejected(): void {
  $admin=User::create(['email'=>'sort@example.invalid','password'=>'unused','is_admin'=>true,'uuid'=>'sort-fixture','token'=>'sort-fixture']);
  Sanctum::actingAs($admin);
  $a=new ServerGroup();$a->name='A';$a->save();
  $b=new ServerGroup();$b->name='B';$b->save();
  $c=new ServerGroup();$c->name='C';$c->save();
  $url='/api/v2/00000000/server/group/';
  $ids=[$b->id,$c->id,$a->id];
  $this->postJson($url.'sort',['ids'=>$ids])->assertOk();
  $this->assertSame($ids,array_column($this->getJson($url.'fetch')->assertOk()->json('data'),'id'));
  $this->postJson($url.'sort',['ids'=>[$a->id,$a->id,$c->id]])->assertStatus(422);
  $this->assertNotEquals(200,$this->postJson($url.'sort',['ids'=>[$a->id,$b->id]])->status());
  $this->assertSame($ids,ServerGroup::orderBy('sort')->pluck('id')->all());
  $this->postJson($url.'save',['name'=>'D'])->assertOk();
  $this->assertSame('D',ServerGroup::orderByDesc('sort')->first()->name);
  $this->assertSame('A',ServerGroup::find($a->id)->name);
 }
 public function test_outbound_tags_accept_unicode_spaces_and_symbols_and_keep_chain_references(): void {
  $admin=User::create(['email'=>'tag@example.invalid','password'=>'unused','is_admin'=>true,'uuid'=>'tag-fixture','token'=>'tag-fixture']);
  Sanctum::actingAs($admin);
  $url='/api/v2/00000000/server/route/';
  $tag='香港 A / 出口（专用）#1 🚀';
  $payload=['name'=>'测试出口','tag'=>$tag,'protocol'=>'socks','settings'=>['server'=>'127.0.0.1','server_port'=>1080],'enabled'=>true];
  $data=$this->postJson($url.'save',$payload)->assertOk()->json('data');
  $this->assertSame($tag,$data['tag']);
  $chain=$this->postJson($url.'save',array_replace($payload,['name'=>'链式出口','tag'=>'日本 B + 备用','proxy_tag'=>$tag]))->assertOk()->json('data');
  $renamed='香港 B & 出口 @新';
  $this->postJson($url.'save',array_replace($payload,['id'=>$data['id'],'tag'=>$renamed]))->assertOk();
  $model=\App\Models\ServerOutbound::find($chain['id']);
  $this->assertSame($renamed,$model->proxy_tag);
  $this->assertSame($renamed,$model->toNodeConfig()['proxy_tag']);
  $this->assertNotEquals(200,$this->postJson($url.'save',array_replace($payload,['tag'=>$renamed]))->status());
 }

}
