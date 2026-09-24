<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionActivity extends Model {
 protected $table='dboard_subscription_activity';
 protected $guarded=['id'];
 public $timestamps=false;
 protected $casts=['changes'=>'array','created_at'=>'integer'];
}
