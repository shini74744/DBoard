<?php
namespace App\Http\Routes\V2;
use Illuminate\Contracts\Routing\Registrar;
use App\Http\Controllers\V2\Server\ProbeController;
class ProbeRoute {
 public function map(Registrar $r){$r->post('probe/endpoint',[ProbeController::class,'endpoint']);$r->post('probe/dispatch',[ProbeController::class,'forward']);$r->post('probe/credential',[ProbeController::class,'credential']);}
}
