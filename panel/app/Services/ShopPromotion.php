<?php

namespace App\Services;

class ShopPromotion
{
    public static function defaults(): array
    {
        return [
            'hero_title' => '商店',
            'hero_description' => '查看并购买可用的套餐',
            'global_nodes_title' => '全球节点',
            'global_nodes_description' => '遍布全球的高速节点',
            'speed_title' => '极速体验',
            'speed_description' => '高速稳定的网络体验',
            'streaming_title' => '流媒体解锁',
            'streaming_description' => '解锁各类流媒体服务',
            'devices_title' => '多设备支持',
            'devices_description' => '同时支持多台设备使用',
            'popup_enabled' => true,
            'popup_title' => '用户须知',
            'popup_content' => '常规套餐默认每月订单日重置流量，您当月未用使用完的流量，不会累积到下个月',
            'popup_cooldown_hours' => 0,
            'popup_close_wait_seconds' => 0,
        ];
    }

    public static function overrides(): array
    {
        $stored = admin_setting('shop_promotion', '{}');
        $values = is_array($stored) ? $stored : json_decode((string) $stored, true);
        return is_array($values) ? array_intersect_key($values, self::defaults()) : [];
    }

    public static function current(): array
    {
        return array_replace(self::defaults(), self::overrides());
    }
}
