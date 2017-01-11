<?php

namespace App\Http\Controllers\Admin;

use App;
use App\Models\Article;
use App\Models\Cate;
use App\Http\Controllers\Controller;
use App\Http\Requests;
use App\Http\Requests\ArtRequest;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class ArtController extends Controller
{
    public function __construct()
    {
    	$this->cate = new Cate;
    	$this->art = new Article;
    }
    /**
     * 文章列表
     * @return [type] [description]
     */
    public function getIndex(Request $res)
    {
    	$title = '文章列表';
    	$catid = $res->input('catid');
        // 搜索关键字
        $key = trim($res->input('q',''));
        $starttime = $res->input('starttime');
        $endtime = $res->input('endtime');
        $status = $res->input('status');
        // 超级管理员可以看所有的
        $cats = $this->cate->get();
    	$tree = App::make('com')->toTree($cats,'0');
    	$cate = App::make('com')->toTreeSelect($tree);
		$list = $this->art->with('cate')->where(function($q) use($catid){
                if ($catid != '') {
                    $q->where('catid',$catid);
                }
            })->where(function($q) use($key){
                if ($key != '') {
                    $q->where('title','like','%'.$key.'%');
                }
            })->where(function($q) use($starttime){
                if ($starttime != '') {
                    $q->where('created_at','>',$starttime);
                }
            })->where(function($q) use($endtime){
                if ($endtime != '') {
                    $q->where('created_at','<',$endtime);
                }
            })->where(function($q) use($status){
                if ($status != '') {
                    $q->where('status',$status);
                }
            })->orderBy('id','desc')->paginate(15);
        // 保存一次性数据，url参数，供编辑完成后跳转用
        $res->flash();
    	return view('admin.art.index',compact('title','list','cate','catid','key','starttime','endtime','status'));
    }

    /**
     * 添加文章
     * @param  string $catid [栏目ID]
     * @return [type]        [description]
     */
    public function getAdd($catid = '0')
    {
    	$title = '添加文章';
    	// 如果catid=0，查出所有栏目，并转成select
    	$cate = '';
    	if($catid == '0')
    	{
    		$cats = $this->cate->get();
	    	$tree = App::make('com')->toTree($cats,'0');
	    	$cate = App::make('com')->toTreeSelect($tree);
    	}
    	return view('admin.art.add',compact('title','catid','cate'));
    }
    public function postAdd(ArtRequest $res)
    {
        $data = $res->input('data');
        if ($data['status'] == '') {
            return back()->with('message','你没有权限操作！');
        }
        // 处理附件数据
        // $attrsT = $res->input('attrs.title');
        // $attrsU = $res->input('attrs.url');
        // $data['attrs'] = [];
        // foreach ($attrsT as $k => $v) {
        //     $data['attrs'][] = ['title'=>$v,'url'=>$attrsU[$k]];
        // }
        // $data['attrs'] = json_encode($data['attrs']);
        $data['inputtime'] = strtotime($data['inputtime']).'.'.explode('.',microtime(time()))[1];
        // 开启事务
        DB::beginTransaction();
        try {
            $art = $this->art->create($data);
            // 组合权限数据
            if ($res->input('ids') != null) {
                $groupdata = [];
                foreach ($res->input('ids') as $v) {
                    $groupdata[] = ['group_id'=>$v,'art_id'=>$art->id];
                }
                GroupArt::insert($groupdata);
            }
            // 处理tags数据，添加tags数据库，及对应关系表，先找出有的，再把没有的添加进去
            $tags = collect(explode('|', $res->input('data.tags')));
            $getTag = Tag::whereIn('name',$tags)->get();
            $tmpTag = [];  // 取出对应内容
            foreach ($getTag as $v) {
                $tmpTag[] = $v['name'];
            }
            $tagDiff = $tags->diff($tmpTag);  // 需要新添加的标签
            // 判断不是空，把新插入进去
            if (!$tagDiff->isEmpty()) {
                // 组成数组
                $tmpTag2 = [];
                foreach ($tagDiff as $v) {
                    if ($v != '') {
                        $tmpTag2[] = ['name'=>$v,'date'=>date("Y-m-d H:i:s",time())];
                    }
                }
                if (count($tmpTag2) > 0) {
                    Tag::insert($tmpTag2);
                }
                $allTag = Tag::whereIn('name',$tags)->get(); // 再查出所有的ID，建立对应关系
            }
            else
            {
                $allTag = $getTag;
            }
            $allTag = Tag::whereIn('name',$tags)->get(); // 再查出所有的ID，建立对应关系
            $tagArt = [];
            foreach ($allTag as $v) {
                $tagArt[] = ['tag_id'=>$v['id'],'art_id'=>$art->id];
            }
            TagArt::insert($tagArt);  // 插入对应关系
            // 没出错，提交事务
            DB::commit();
            // 跳转回添加的栏目列表
            return redirect('/admin/art/index?catid='.$res->input('data.catid'))->with('message', '添加文章成功！');
        } catch (Exception $e) {
            // 出错回滚
            DB::rollBack();
            return back()->with('message','添加失败，请稍后再试！');
        }
    }
    /**
     * 修改文章
     * @param  string $id [文章ID]
     * @return [type]     [description]
     */
    public function getEdit(Request $res,$id = '')
    {
        $title = '修改文章';
        // 拼接返回用的url参数
        $ref = '?catid='.old('catid').'&page='.old('page');
        $info = $this->art->findOrFail($id);
        $cats = $this->cate->get();
        $tree = App::make('com')->toTree($cats,'0');
        $cate = App::make('com')->toTreeSelect($tree);
        return view('admin.art.edit',compact('title','cate','info','ref'));
    }
    public function postEdit(ArtRequest $res,$id = '')
    {
        $data = $res->input('data');
        // 开启事务
        DB::beginTransaction();
        try {
            $art = $this->art->where('id',$id)->update($data);
            // 没出错，提交事务
            DB::commit();
            // 取得编辑前url参数，并跳转回去
            return redirect('/admin/art/index'.$res->input('ref'))->with('message', '修改文章成功！');
        } catch (Exception $e) {
            // 出错回滚
            DB::rollBack();
            return back()->with('message','添加失败，请稍后再试！');
        }
    }
    /**
     * 删除文章
     * @param  string $id [文章ID]
     * @return [type]     [description]
     */
    public function getDel($id = '')
    {
        // 开启事务
        DB::beginTransaction();
        try {
            $this->art->destroy($id);
            // 没出错，提交事务
            DB::commit();
            return back()->with('message', '删除文章成功！');
        } catch (Exception $e) {
            // 出错回滚
            DB::rollBack();
            return back()->with('message','删除失败，请稍后再试！');
        }
    }
    /**
     * 查看文章
     * @param  string $id [description]
     * @return [type]     [description]
     */
    public function getShow($id = '')
    {
        $title = '查看文章详情';
        // 拼接返回用的url参数
        $ref = '?catid='.old('catid').'&page='.old('page');
        $info = $this->art->with('comment')->findOrFail($id);
        return view('admin.art.show',compact('title','info','ref'));
    }
    // 批量删除
    public function postAlldel(Request $res)
    {
        $ids = $res->input('sids');
        // 是数组更新数据，不是返回
        if(is_array($ids))
        {
            // 开启事务
            DB::beginTransaction();
            try {
                Article::whereIn('id',$ids)->delete();
                // 没出错，提交事务
                DB::commit();
                return back()->with('message', '批量删除完成！');
            } catch (Exception $e) {
                // 出错回滚
                DB::rollBack();
                return back()->with('message','删除失败，请稍后再试！');
            }
        }
        else
        {
            return back()->with('message','请选择文章！');
        }
    }
    // 批量排序
    public function postListorder(Request $res)
    {
        $ids = $res->input('sids');
        $listorder = $res->input('listorder');
        if (is_array($ids))
        {
            foreach ($ids as $v) {
                Article::where('id',$v)->update(['listorder'=>$listorder[$v]]);
            }
            return back()->with('message', '排序成功！');
        }
        else
        {
            return back()->with('message', '请先选择文章！');
        }
    }
}