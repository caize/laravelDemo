<?php

namespace App\Http\Controllers\admin;

use App;
use App\Models\Article;
use App\Models\Cate;
use App\Http\Controllers\Controller;
use App\Http\Requests;
use App\Http\Requests\CateRequest;
use App\Models\Role;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class CateController extends Controller
{
    public function __construct()
    {
    	$this->cate = new Cate;
    }
    /**
     * 栏目列表
     * @return [type] [description]
     */
    public function getIndex()
    {
    	$title = '栏目管理';
        // 超级管理员可查看所有部门下栏目
        $all = $this->cate->orderBy('listorder','asc')->get();
        $tree = App::make('com')->toTree($all,'0');
    	$treeHtml = $this->toTreeHtml($tree);
    	return view('admin.cate.index',compact('title','treeHtml'));
    }
    // 树形菜单 html
    private function toTreeHtml($tree)
    {
        $html = '';
        if (is_array($tree)) {
            foreach ($tree as $v) {
                // 用level判断层级，最好不要超过四层，样式中只写了四级
                $cj = count(explode(',', $v['arrparentid']));
                $level = $cj > 4 ? 4 : $cj;
                $typename = $v['type'] ? "<span class='color-green'>单页</span>" : "<span class='color-blue'>栏目</span>";
                $html .= "<tr>
                    <td>".$v['listorder']."</td>
                    <td>".$v['id']."</td>
                    <td><span class='level-".$level."'></span>".$v['name']."<a href='/admin/cate/add/".$v['id']."' class='glyphicon glyphicon-plus add_submenu'></a></td>
                    <td>".$typename."</td>
                    <td><a href='/admin/cate/edit/".$v['id']."' class='btn btn-sm btn-info'>修改</a> <a href='/admin/cate/del/".$v['id']."' class='confirm btn btn-sm btn-danger'>删除</a></td>
                    </tr>";
                if ($v['parentid'] != '')
                {
                    $html .= $this->toTreeHtml($v['parentid']);
                }
            }
        }
        return $html;
    }
    // 更新缓存
    public function getCache()
    {
        App::make('com')->updateCache($this->cate,'cateCache');
        return redirect('/admin/cate/index')->with('message', '更新栏目缓存成功！');
    }
    /**
     * 添加栏目
     * @param  integer $pid [父栏目ID]
     * @return [type]       [description]
     */
    public function getAdd($pid = '0')
    {
    	$title = '添加栏目';
        $role = Role::where('status',1)->get();
    	return view('admin.cate.add',compact('title','pid','role'));
    }
    public function postAdd(CateRequest $res,$pid = '0')
    {
        // 开启事务
        DB::beginTransaction();
        try {
            $data = $res->input('data');
            $data['url'] = pinyin_permalink(trim($data['name']),'-');
            $resId = $this->cate->create($data);
            // 后台用户组权限
            App::make('com')->updateCache($this->cate,'cateCache');
            // 没出错，提交事务
            DB::commit();
            return redirect('/admin/cate/index')->with('message', '添加成功！');
        } catch (Exception $e) {
            // 出错回滚
            DB::rollBack();
            return back()->with('message','添加失败，请稍后再试！');
        }
    }
    /**
     * 修改栏目
     * @param  string $id [要修改的栏目ID]
     * @return [type]     [description]
     */
    public function getEdit($id = '')
    {
        $title = '修改栏目';
        $info = $this->cate->findOrFail($id);
        // 超级管理员可查看所有部门下栏目
        $all = $this->cate->orderBy('listorder','asc')->get();
        $tree = App::make('com')->toTree($all,'0');
        $treeHtml = App::make('com')->toTreeSelect($tree,$info->parentid);
        $role = Role::where('status',1)->get();
        return view('admin.cate.edit',compact('title','info','treeHtml','role'));
    }
    public function postEdit(CateRequest $res,$id = '')
    {
        // 开启事务
        DB::beginTransaction();
        try {
            $data = $res->input('data');
            $data['url'] = pinyin_permalink(trim($data['name']),'-');
            $this->cate->where('id',$id)->update($data);
            // 更新缓存
            App::make('com')->updateCache($this->cate,'cateCache');
            // 没出错，提交事务
            DB::commit();
            return redirect('/admin/cate/index')->with('message', '修改成功！');
        } catch (Exception $e) {
            // 出错回滚
            DB::rollBack();
            return back()->with('message','修改失败，请稍后再试！');
        }
    }
    public function getDel($id)
    {
        // 先找出所有子栏目，再判断子栏目中是否有文章，如果有文章，返回错误
        $allChild = $this->cate->where('id',$id)->value('arrchildid');
        // 所有子栏目ID转换为集合，查看是否含有文章或者专题
        $childs = collect(explode(',',$allChild));
        $child = Article::whereIn('catid',$childs)->get()->count();
        if($child != 0)
        {
            $message = '请检查栏目及子栏目下是否有文章或文章！';
        }
        else
        {
            // 开启事务
            DB::beginTransaction();
            try {
                $this->cate->destroy($childs);
                $message = '删除成功！';
                // 没出错，提交事务
                DB::commit();
            } catch (Exception $e) {
                // 出错回滚
                DB::rollBack();
                return back()->with('message','删除失败，请稍后再试！');
            }
        }
        return back()->with('message', $message);
    }
}
