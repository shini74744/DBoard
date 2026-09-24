<?php

namespace App\Services;

class UserNotice
{
    public static function defaults(): array
    {
        return ['enabled' => false, 'title' => '用户须知',
            'content' => '<p><strong>欢迎使用我们的服务！</strong></p><p>请注意以下事项：</p><ul><li>请妥善保管您的账号信息</li><li>如有问题请联系客服</li></ul>',
            'cooldown_hours' => 0, 'close_wait_seconds' => 3];
    }

    public static function configured(): ?array
    {
        $stored = admin_setting('user_notice');
        $values = is_array($stored) ? $stored : json_decode((string) $stored, true);
        return is_array($values) ? array_replace(self::defaults(), array_intersect_key($values, self::defaults())) : null;
    }

    public static function current(): array
    {
        return self::configured() ?? self::defaults();
    }

    public static function sanitize(string $html): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a'];
        $clean = function ($parent) use (&$clean, $allowed) {
            foreach (iterator_to_array($parent->childNodes) as $node) {
                if ($node instanceof \DOMComment) { $parent->removeChild($node); continue; }
                if (!($node instanceof \DOMElement)) continue;
                $tag = strtolower($node->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'svg', 'math', 'template', 'form'], true)) {
                    $parent->removeChild($node); continue;
                }
                $clean($node);
                if (!in_array($tag, $allowed, true)) {
                    while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                    $parent->removeChild($node); continue;
                }
                $href = trim($node->getAttribute('href'));
                foreach (iterator_to_array($node->attributes) as $attribute) $node->removeAttribute($attribute->name);
                if ($tag === 'a' && preg_match('~^(https?://|mailto:)~i', $href)) {
                    $node->setAttribute('href', $href);
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        };
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return '';
        $clean($body);
        $result = '';
        foreach ($body->childNodes as $node) $result .= $doc->saveHTML($node);
        return trim($result);
    }
}
