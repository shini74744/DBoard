<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\UserNotice;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserNoticeController extends Controller
{
    public function fetch()
    {
        return $this->success(UserNotice::current());
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean', 'title' => 'required|string|max:100',
            'content' => 'required|string|max:20000',
            'cooldown_hours' => 'required|integer|min:0|max:720',
            'close_wait_seconds' => 'required|integer|min:0|max:60',
        ]);
        $data['enabled'] = (bool) $data['enabled'];
        $data['title'] = trim(strip_tags($data['title']));
        $data['content'] = UserNotice::sanitize($data['content']);
        foreach (['title', 'content'] as $key) {
            if (trim(html_entity_decode(strip_tags($data[$key]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
                throw ValidationException::withMessages([$key => '标题和正文不能为空']);
            }
        }
        $data['cooldown_hours'] = (int) $data['cooldown_hours'];
        $data['close_wait_seconds'] = (int) $data['close_wait_seconds'];
        admin_setting(['user_notice' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return $this->success(UserNotice::current());
    }
}
