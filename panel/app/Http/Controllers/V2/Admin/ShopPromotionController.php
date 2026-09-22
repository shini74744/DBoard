<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ShopPromotion;
use Illuminate\Http\Request;

class ShopPromotionController extends Controller
{
    public function fetch()
    {
        return $this->success(ShopPromotion::current());
    }

    public function save(Request $request)
    {
        $text = ['hero_title', 'hero_description', 'global_nodes_title', 'global_nodes_description',
            'speed_title', 'speed_description', 'streaming_title', 'streaming_description',
            'devices_title', 'devices_description', 'popup_title', 'popup_content'];
        $rules = array_fill_keys($text, 'required|string|max:500');
        $rules['popup_content'] = 'required|string|max:3000';
        $rules['popup_enabled'] = 'required|boolean';
        $rules['popup_cooldown_hours'] = 'required|integer|min:0|max:720';
        $rules['popup_close_wait_seconds'] = 'required|integer|min:0|max:60';
        $data = $request->validate($rules);
        foreach ($text as $key) {
            $data[$key] = trim(strip_tags($data[$key]));
        }
        admin_setting(['shop_promotion' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return $this->success(ShopPromotion::current());
    }
}
