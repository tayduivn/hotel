<?php

namespace App\Http\Controllers\Admin;

use App\Events\Admin\Chat;
use App\Events\Admin\UpdateStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    public function index(Request $request, $email)
    {
        if ($email == 'room') {
            $redis_keys = Redis::keys('chat_log:*');
            $i = 0;
            $contacts = [];
            foreach ($redis_keys as $redis_key) {
                $unread = 0;
                $new_key = cutRedisKey($redis_key);
                $email = getEmailRedis($new_key);
                $data = json_decode(Redis::get($new_key), true);
                foreach ($data as $item) {
                    if (isset($item['status']) && $item['status'] == 0) {
                        $unread++;
                    }
                }
                end($data);
                $latest = key($data);
                $new_message = $data[$latest]['body'];
                $arr = [
                    'email' => $email,
                    'new_message' => $new_message,
                    'unread' => $unread,
                ];
                $contacts[$i] = $arr;
                $i++;
            }
            $data = compact(
                'redis_keys',
                'contacts'
            );
        } else {
            $user_email = $email;
            if (!Redis::exists('chat_log:' . $email)) {
                abort(404);
            } else {
                $this->updateStatus($email);
                $logs = json_decode(Redis::get('chat_log:' . $email), true);
                $redis_keys = Redis::keys('chat_log:*');
                $i = 0;
                $contacts = [];
                foreach ($redis_keys as $redis_key) {
                    $unread = 0;
                    $new_key = cutRedisKey($redis_key);
                    $email = getEmailRedis($new_key);
                    $data = json_decode(Redis::get($new_key), true);
                    foreach ($data as $item) {
                        if (isset($item['status']) && $item['status'] == 0) {
                            $unread++;
                        }
                    }
                    end($data);
                    $latest = key($data);
                    $new_message = $data[$latest]['body'];
                    $arr = [
                        'email' => $email,
                        'new_message' => $new_message,
                        'unread' => $unread,
                    ];
                    $contacts[$i] = $arr;
                    $i++;
                }
                $data = compact(
                    'contacts',
                    'redis_keys',
                    'logs',
                    'user_email'
                );
            }
        }

        return view('admin.chat.index', $data);
    }

    public function send(Request $request)
    {
        $now = date('H:i d-m-Y');
        $request->time = $now;
        $data = $request->all();
        $data['time'] = $now;
        $rules = [
            'message' => 'required',
        ];
        $messages = [
            'message.required' => 'Vui lòng nhập nội dung',
        ];
        $validator = Validator::make($data, $rules, $messages);
        if ($validator->fails()) {
            $data_response = [
                'messages' => 'Validation_fails',
                'data' => $validator->messages(),
            ];
        } else {
            $new_log = [
                'id' => uniqid(),
                'body' => $data['message'],
                'time' => $now,
                'type' => 'admin',
            ];
            $log = json_decode(Redis::get('chat_log:' . $data['channel']), true);
            array_push($log, $new_log);
            Redis::getSet('chat_log:' . $data['channel'], json_encode($log));
            event(new Chat($request));
            $data_response = [
                'messages' => 'success',
                'data' => $data
            ];
        }

        return response()->json($data_response, 200);
    }

    public function updateStatus($email)
    {
        event(new UpdateStatus($email));

        return response()->json(['messages' => 'success'], 200);
    }
}
