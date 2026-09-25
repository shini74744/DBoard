<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProbeSetting extends Model {
 protected $table='dboard_probe_settings';
 protected $guarded=['id'];
 protected $casts=['enabled'=>'boolean','backups'=>'array','control_key'=>'encrypted','connector_key'=>'encrypted'];
 protected $hidden=['control_key','connector_key'];
}
