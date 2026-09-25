<?php


namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    private const PRICE_MULTIPLIER = 100;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'group_id' => $this->resource['group_id'],
            'name' => $this->resource['name'],
            'tags' => $this->resource['tags'],
            'content' => $this->formatContent(),
            ...$this->getPeriodPrices(),
            'capacity_limit' => $this->getFormattedCapacityLimit(),
            'capacity_display_mode' => $this->resource['capacity_display_mode'] ?? 'status',
            'capacity_remaining' => PlanService::remainingCapacity($this->resource),
            'transfer_enable' => $this->resource['transfer_enable'],
            'speed_limit' => $this->resource['speed_limit'],
            'device_limit' => $this->resource['device_limit'],
            'connection_limit' => $this->resource['connection_limit'] ?? null,
            'billing_period' => !empty($this->resource['billing_period']) ? PlanService::getLegacyPeriod($this->resource['billing_period']) : null,
            'show' => (bool) $this->resource['show'],
            'sell' => (bool) $this->resource['sell'],
            'renew' => (bool) $this->resource['renew'],
            'reset_traffic_method' => $this->resource['reset_traffic_method'],
            'sort' => $this->resource['sort'],
            'created_at' => $this->resource['created_at'],
            'updated_at' => $this->resource['updated_at']
        ];
    }

    /**
     * Get transformed period prices using Plan mapping
     *
     * @return array<string, float|null>
     */
    protected function getPeriodPrices(): array
    {
        return collect(Plan::LEGACY_PERIOD_MAPPING)
            ->mapWithKeys(function (string $newPeriod, string $legacyPeriod): array {
                $price = $this->resource['prices'][$newPeriod] ?? null;
                return [
                    $legacyPeriod => $price !== null
                        ? (float) $price * self::PRICE_MULTIPLIER
                        : null
                ];
            })
            ->all();
    }

    /**
     * Get formatted capacity limit value
     *
     * @return int|string|null
     */
    protected function getFormattedCapacityLimit(): int|string|null
    {
        $limit = $this->resource['capacity_limit'];

        return match (true) {
            $limit === null => null,
            $limit <= 0 => __('Sold out'),
            default => (int) $limit,
        };
    }

    /**
     * Format content with template variables
     *
     * @return string
     */
    public function formatContent(): string
    {
        $content = $this->filterOptionalPriceContent($this->resource['content'] ?? '');
        // Normalize units in the built-in template before substituting unlimited values.
        if (($this->resource['speed_limit'] ?? 0) <= 0) {
            $content = preg_replace('/\{\{speed\}\}[ \t]*Mbps/i', '{{speed}}', $content);
        }
        if (($this->resource['device_limit'] ?? 0) <= 0) {
            $content = preg_replace('/\{\{devices\}\}[ \t]*台/u', '{{devices}}', $content);
        }
        $content = str_replace('流量{{reset_method}}重置', '流量重置：{{reset_method}}', $content);

        
        $replacements = [
            '{{onetime_price}}' => $this->formatOptionalPrice('onetime'),
            '{{reset_price}}' => $this->formatOptionalPrice('reset_traffic'),
            '{{speed_text}}' => ($this->resource['speed_limit'] ?? 0) > 0 ? $this->resource['speed_limit'] . ' Mbps' : __('No Limit'),
            '{{devices_text}}' => ($this->resource['device_limit'] ?? 0) > 0 ? $this->resource['device_limit'] . ' 台' : __('No Limit'),
            '{{transfer}}' => $this->resource['transfer_enable'],
            '{{speed}}' => ($this->resource['speed_limit'] ?? 0) <= 0 ? __('No Limit') : $this->resource['speed_limit'],
            '{{connections}}' => ($this->resource['connection_limit'] ?? 0) > 0 ? $this->resource['connection_limit'] : __('No Limit'),
            '{{devices}}' => ($this->resource['device_limit'] ?? 0) <= 0 ? __('No Limit') : $this->resource['device_limit'],
            '{{reset_method}}' => $this->getResetMethodText(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    private function hasOptionalPrice(string $key): bool
    {
        $value = $this->resource['prices'][$key] ?? null;
        return $value !== null && trim((string) $value) !== '' && is_numeric($value) && (float) $value >= 0;
    }

    private function formatOptionalPrice(string $key): string
    {
        return $this->hasOptionalPrice($key) ? number_format((float) $this->resource['prices'][$key], 2, '.', '') : '';
    }

    /** Conditional blocks remain in the saved template and are filtered only for display. */
    private function filterOptionalPriceContent(string $content): string
    {
        if (!str_contains($content, 'data-plan-price')) return $content;
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><div id="plan-description-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new \DOMXPath($dom);
            $remove = [];
            foreach ($xpath->query('//*[@data-plan-price]') as $node) {
                $key = $node->getAttribute('data-plan-price');
                if (in_array($key, ['onetime', 'reset_traffic'], true) && !$this->hasOptionalPrice($key)) $remove[] = $node;
            }
            foreach ($remove as $node) $node->parentNode?->removeChild($node);
            $root = $dom->getElementById('plan-description-root');
            if (!$root) return $content;
            $result = '';
            foreach ($root->childNodes as $node) $result .= $dom->saveHTML($node);
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Get reset method text
     *
     * @return string
     */
    protected function getResetMethodText(): string
    {
        $method = $this->resource['reset_traffic_method'];
        
        if ($method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $method = admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }
        return match ($method) {
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => __('First Day of Month'),
            Plan::RESET_TRAFFIC_MONTHLY => __('Monthly'),
            Plan::RESET_TRAFFIC_NEVER => __('Never'),
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => __('First Day of Year'),
            Plan::RESET_TRAFFIC_YEARLY => __('Yearly'),
            default => __('Monthly')
        };
    }
}