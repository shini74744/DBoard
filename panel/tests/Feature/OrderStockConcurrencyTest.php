<?php
namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Jobs\OrderHandleJob;
use App\Models\{Order, Plan, User};
use App\Services\{OrderService, PlanService};
use Illuminate\Support\Facades\{Bus, Cache, DB, Queue};
use Tests\TestCase;

/** Real independent database connections; never uses the configured application database. */
class OrderStockConcurrencyTest extends TestCase
{
    private string $stockDatabase;

    public function createApplication()
    {
        $this->stockDatabase=tempnam(sys_get_temp_dir(),'dboard-stock-test-');
        $app=parent::createApplication();
        $app->detectEnvironment(fn ()=>'testing');
        $app['config']->set('app.env','testing');
        $app['config']->set('database.default','sqlite');
        $app['config']->set('database.connections.sqlite',[
            'driver'=>'sqlite','database'=>$this->stockDatabase,'prefix'=>'',
            'foreign_key_constraints'=>true,'busy_timeout'=>10000,
        ]);
        DB::purge('sqlite');
        Cache::setDefaultDriver('array');
        return $app;
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
        foreach (['','-wal','-shm'] as $suffix) {
            if (isset($this->stockDatabase) && is_file($this->stockDatabase.$suffix)) unlink($this->stockDatabase.$suffix);
        }
    }

    public function test_two_processes_cannot_reserve_the_last_slot(): void
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('Requires pcntl.');
        $this->artisan('migrate',['--force'=>true])->assertExitCode(0);
        Queue::fake();Bus::fake([OrderHandleJob::class]);
        for($round=0;$round<5;$round++) {
            $plan=Plan::create(['name'=>'Last slot '.$round,'group_id'=>7,'transfer_enable'=>100,
                'prices'=>['monthly'=>40],'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>1]);
            $buyers=[];
            for($i=0;$i<2;$i++) {
                $key=$round.'-'.$i;
                $buyers[]=User::create(['email'=>$key.'@example.invalid','password'=>'unused',
                    'uuid'=>$key,'token'=>$key,'expired_at'=>0,'transfer_enable'=>0,'balance'=>0])->id;
            }
            DB::disconnect('sqlite');
            $workers=[];
            foreach($buyers as $buyerId) {
                [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
                $pid=pcntl_fork();
                if($pid===-1) $this->fail('Unable to fork test worker.');
                if($pid===0) {
                    fclose($parent);fread($child,1);
                    DB::purge('sqlite');
                    try {
                        OrderService::createFromRequest(User::findOrFail($buyerId),Plan::findOrFail($plan->id),
                            'monthly',null,'add',null,'shop');
                        $result='reserved';
                    } catch(ApiException $e) {
                        $result='sold-out';
                    } catch(\Throwable $e) {
                        $result=get_class($e).': '.$e->getMessage();
                    }
                    fwrite($child,json_encode($result));fclose($child);exit(0);
                }
                fclose($child);$workers[]=[$pid,$parent];
            }
            foreach($workers as [$pid,$pipe]) fwrite($pipe,'1');
            $results=[];
            foreach($workers as [$pid,$pipe]) {
                pcntl_waitpid($pid,$status);
                $this->assertSame(0,pcntl_wexitstatus($status));
                $results[]=json_decode(stream_get_contents($pipe),true);fclose($pipe);
            }
            sort($results);
            $this->assertSame(['reserved','sold-out'],$results);
            DB::purge('sqlite');
            $this->assertSame(1,Order::where('plan_id',$plan->id)->count());
            $this->assertSame(0,PlanService::remainingCapacity($plan));
        }
    }
}
