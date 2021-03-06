<?php

namespace App\Repositories\Post;

use App\Jobs\SendMailApprovePost;
use App\Jobs\SendMailDeletePost;
use App\Models\Post;
use App\Repositories\EloquentRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Session;

class PostRepository extends EloquentRepository
{
    public function getModel()
    {
        return Post::class;
    }

    public function countStatusPosts()
    {
        $language = Session::get('locale');

        $user = Auth::user();

        $whereConditional = [
            ['lang_id', $language],
            $user->role_id <= config('common.roles.admin') ? ['id', '>', 0] : ['posted_by', $user->id]
        ];

        $reject = $this->_model->where('approve', -1)->where($whereConditional)->where('edited_from', null)->count();
        $pending = $this->_model->where('approve', 0)->where('edited_from', null)->where($whereConditional)->count();
        $approve = $this->_model->where('approve', 1)->where($whereConditional)->where('edited_from', null)->count();
        $requestEdit = $this->_model->where('edited_from', '<>', null)->where($whereConditional)->count();

        $count = [
            'reject' => $reject,
            'pending' => $pending,
            'approve' => $approve,
            'requestEdit' => $requestEdit,
        ];

        return $count;
    }

    public function searchPost($input)
    {
        $paginate = config('common.pagination.default');
        $language = Session::get('locale');
        $title = $input['title'] ?? null;
        $approve = $input['approve'] ?? 'null';
        $isRequestEdited = $input['request_edited'] ?? null;
        $user = Auth::user();

        $whereConditional = [
            ['title', 'like', '%' . $title . '%'],
            ['lang_id', $language],
            !is_string($approve) ? ['approve', $approve] : ['id', '>', 0],
            $isRequestEdited != null ? ['edited_from', '<>', null] : ['edited_from', null],
            $user->role_id <= config('common.roles.admin') ? ['id', '>', 0] : ['posted_by', $user->id]
        ];

        $result = $this->_model->where($whereConditional)
            ->with('category', 'postedBy', 'approveBy', 'parentTranslate', 'parentEdited')
            ->get();

        return $result;
    }

    public function insertPost($input)
    {
        $input['image'] = uploadImage('posts', $input['image']);

        $result = $this->_model->create($input);

        return $result;
    }

    public function editPost($data, $input)
    {
        if (isset($input['image']) && !is_string($input['image'])) $input['image'] = uploadImage('posts', $input['image']);

        $data->update($input);

        if (isset($input['image']) || isset($input['category_id'])) {
            $dataUpdate = [
                'category_id' => $data->category_id,
                'image' => $data->image
            ];
            $data->childrenTranslate()->update($dataUpdate);
        }

        return $data;
    }

    public function editFromApprovedPost($id, $input)
    {
        $result = $this->find($id);

        $dataEditPost = $result->toArray();
        $input['edited_from'] = $id;
        $input['id'] = null;
        $input['posted_by'] = Auth::user()->id;
        $input['lang_id'] = config('common.languages.default');
        $input['lang_parent_id'] = $dataEditPost['lang_parent_id'];
        !isset($input['image']) ? $input['image'] = $dataEditPost['image'] : $input['image'] = uploadImage('posts', $input['image']);

        return $this->_model->create($input);
    }

    public function getPostById($id)
    {
        $user = Auth::user();

        $whereConditional = [
            ['id', $id],
            $user->role_id <= config('common.roles.admin') ? ['id', '>', 0] : ['posted_by', $user->id]
        ];

        return $this->_model->where($whereConditional)
            ->with('language', 'childrenTranslate.language', 'parentTranslate.language', 'postedBy')->first();
    }

    public function getAllTranslatePosts($id)
    {
        $user = Auth::user();

        $whereConditional = [
            ['id', $id],
            $user->role_id <= config('common.roles.admin') ? ['id', '>', 0] : ['posted_by', $user->id]
        ];

        $orWhereConditional = [
            ['lang_parent_id', $id],
            $user->role_id <= config('common.roles.admin') ? ['id', '>', 0] : ['posted_by', $user->id]
        ];

        return $this->_model->where($whereConditional)->orWhere($orWhereConditional)
            ->with('language')->get();
    }

    public function findEditedPost($id)
    {
        $user = Auth::user();

        $whereConditional = [
            ['id', $id],
            $user->role_id <= config('common.roles.admin') ? ['id', '>', 0] : ['posted_by', $user->id]
        ];

        return $this->_model->where($whereConditional)->with('editedFrom', 'parentEdited', 'category.childrenTranslate', 'childrenTranslate', 'parentTranslate', 'postedBy')->first();
    }

    public function deletePost($result)
    {
        $result->childrenTranslate()->delete();

        $result->editedFrom()->delete();

        $result->delete();

        return !!$result;
    }

    public function translate($id, $input)
    {
        $post = $this->find($id);

        $input['lang_parent_id'] = $id;

        $input['image'] = $post->image;

        $result = $this->_model->create($input);

        return $result;
    }

    public function checkUniqueTitle($input)
    {
        $title = $input['title'];
        $langId = $input['lang_id'];

        $whereConditional = [
            ['title', $title],
            ['lang_id', $langId]
        ];

        $result = $this->_model->where($whereConditional)->get();

        return $result;
    }

    public function approvePost($result, $input)
    {
        $input['approve_by'] = Auth::user()->id;

        $input['approve'] == config('common.posts.approve_key.reject') && $input['message_reject'] != null
            ? $input['message_reject'] = $input['message_reject'] ?? 'Rejected'
            : null;

        if ($input['approve'] == -1) {
            $result->childrenTranslate()->update([
                'approve' => $input['approve'],
                'message_reject' => $input['message_reject'],
                'approve_by' => $input['approve_by'],
            ]);
        }

        $result->update($input);

        return $result;
    }

    public function approveFromPostApproved($post)
    {
        $dataPost = $post->toArray();

        $id = $dataPost['parent_edited']['id'];
        $dataPost['approve_by'] = Auth::user()->id;
        $dataPost['posted_by'] = $dataPost['posted_by']['id'];

        if ($dataPost['approve'] == config('common.posts.approve_key.approved')) {
            $dataPost['edited_from'] = null;
            $result = $this->update($id, $dataPost);

            $this->delete($dataPost['id']);
        } else {
            $dataPost['edited_from'] = $id;
            $result = $this->update($dataPost['id'], $dataPost);
        }

        return $result;
    }

    public function getClientPost()
    {
        $paginate = config('common.pagination.default');
        $language = Session::get('locale');

        $whereConditional = [
            ['lang_id', $language],
            ['edited_from', null],
            ['approve', config('common.posts.approve_key.approved')],
        ];

        return $this->_model->where($whereConditional)->with('postedBy')->orderBy('id', 'desc')->paginate($paginate);
    }

    public function clientDetail($id)
    {
        $language = Session::get('locale');

        $whereConditional = [
            ['id', $id],
            ['lang_id', $language]
        ];

        $orWhereConditional = [
            ['lang_parent_id', $id],
            ['lang_id', $language]
        ];

        return $this->_model->where($whereConditional)->orWhere($orWhereConditional)->with('postedBy')->first();
    }

    public function postsSameCategory($data)
    {
        $language = Session::get('locale');
        $categoryId = $data->category_id;
        $currentPostId = $data->id;

        $whereConditional = [
            ['lang_id', $language],
            ['category_id', $categoryId],
            ['edited_from', null],
            ['approve', config('common.posts.approve_key.approved')],
            ['id', '<>', $currentPostId],
        ];

        return $this->_model->where($whereConditional)->with('postedBy')->get();
    }

    public function getClientPostViaCategoryName($name)
    {
        $language = Session::get('locale');
        $paginate = config('common.pagination.default');

        $whereConditional = [
            ['lang_id', $language],
            ['edited_from', null],
            ['approve', config('common.posts.approve_key.approved')],
        ];

        $result = $this->_model->where($whereConditional)->with('category')
            ->whereHas('category', function ($query) use ($name, $language) {
                $categoryWhereConditional = [
                    ['name', $name],
                    ['type', 0],
                ];

                $query->where($categoryWhereConditional);
            })->orderBy('id', 'desc')->paginate($paginate);

        return $result;
    }

    public function getRandomPost()
    {
        $language = Session::get('locale');
        $approve = config('common.posts.approve_key.approved');

        return $this->_model->where('approve', $approve)->where('lang_id', $language)->orderBy(DB::raw('RAND()'))->limit(5)->get();
    }

    public function sendMailApprovePost($data)
    {
        SendMailApprovePost::dispatch($data);
    }

    public function sendMailDeletePost($data, $messageDelete)
    {
        SendMailDeletePost::dispatch($data, $messageDelete);
    }

    public function approveSelectedPosts($input)
    {
        $arrayId = $input['arrayId'];
        $approve = $input['approve'];

        $result = $this->_model->whereIn('id', $arrayId)->with('childrenTranslate');

        $arrayUpdate = [
            'approve' => $approve,
            'message_reject' => $input['message_reject'] ?? null,
        ];

        $result->update($arrayUpdate);

        $postsApproved = $result->get();

        if ($postsApproved != null) {
            foreach ($postsApproved as $post) {
                $arrayMail[$post->postedBy->email]['dataPost'] = $post;
                $arrayMail[$post->postedBy->email]['title'][] = $post->title;

                if ($approve == -1 && $post->childrenTranslate != null) {
                    $post->childrenTranslate()->update($arrayUpdate);
                }

                if ($post->parentEdited) {
                    $post->approve = $approve;
                    $this->approveFromPostApproved($post);
                }

            }

            foreach ($arrayMail as $key => $value) {
                $dataToSend = new \stdClass();

                $dataToSend->title = implode($value['title'], ', ');
                $dataToSend->approve = $value['dataPost']->approve;
                $dataToSend->postedBy = $value['dataPost']->postedBy;

                $this->sendMailApprovePost($dataToSend);
            }
        }

        return $result;
    }
}
