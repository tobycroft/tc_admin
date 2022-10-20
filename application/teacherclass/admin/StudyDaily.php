<?php


namespace app\teacherclass\admin;

use app\admin\controller\Admin;
use app\admin\model\Attachment;
use app\common\builder\ZBuilder;
use app\teacherclass\model\StudyDailyModel;
use app\teacherclass\model\StudyModel;
use app\teacherclass\model\StudyTagModel;
use app\teacherclass\model\TagModel;
use app\user\model\Role as RoleModel;
use app\user\model\User;
use think\Db;
use think\facade\Hook;
use Tobycroft\AossSdk\Aoss;
use util\Tree;

/**
 * 用户默认控制器
 * @package app\user\admin
 */
class StudyDaily extends Admin
{
    /**
     * 用户首页
     * @return mixed
     * @throws \think\Exception
     * @throws \think\exception\DbException
     */
    public function index()
    {
        // 获取排序
        $order = $this->getOrder("id desc");
        $map = $this->getMap();
        // 读取用户数据
        $data_list = StudyDailyModel::where($map)
            ->order($order)
            ->paginate()
            ->each(function ($item, $key) {
                $item["common_tag"] = StudyTagModel::alias("a")
                    ->leftJoin(["ps_tag" => "b"], "a.tag_id=b.id")
                    ->where("study_id", $item["id"])
                    ->where("a.study_type", "daily")
                    ->where("b.tag_type", "common")
                    ->column("name");
                $item["special_tag"] = StudyTagModel::alias("a")
                    ->leftJoin(["ps_tag" => "b"], "a.tag_id=b.id")
                    ->where("study_id", $item["id"])
                    ->where("a.study_type", "daily")
                    ->where("b.tag_type", "special")
                    ->column("name");
                $item["common_tag"] = join(",", $item["common_tag"]);
                $item["special_tag"] = join(",", $item["special_tag"]);
                return $item;
            });

        $page = $data_list->render();
        $todaytime = date('Y-m-d H:i:s', strtotime(date("Y-m-d"), time()));

        $num1 = StudyDailyModel::where("date", ">", $todaytime)
            ->count();
        $num2 = StudyDailyModel::count();

        return ZBuilder::make('table')
            ->setPageTips("总数量：" . $num2 . "    今日数量：" . $num1, 'danger')
//            ->setPageTips("总数量：" . $num2, 'danger')
            ->addTopButton("add")
            ->setPageTitle('列表')
            ->setSearch(['id' => 'ID', "title" => "标题", 'slogan' => 'slogan']) // 设置搜索参数
            ->addOrder('id')
            ->addColumns([['id', 'ID'], //                ['grade', '年级', 'number'],
//                ['area_id', '对应区域', 'number'],
//                ['school_id', '学校id', 'number'],
                ['title', '标题'],
                ['slogan', '推荐金句'],
                ['special_tag', '特殊标签'],
                ['common_tag', '特殊标签'], //                ['img', '小图头图', "picture"],
//                ['img_intro', '简介图', "picture"],
                ['from1', '内容来源1'],
                ['from2', '内容来源2'], //                ['can_push', '是否可以推送', 'switch'],
//                ['push_date', '推送日期', 'text.edit'],
//                ['show_date', '展示日期', 'text.edit'],
//                ['attach_type', '附件类型', 'text'],
//                ['show_to', '展示给谁'],
                ['attach_duration', '附件时长', 'number'],
                ['change_date', '修改时间'],
                ['date', '创建时间'],])
            ->addColumn('right_button', '操作', 'btn')
            ->addRightButton('edit') // 添加编辑按钮
            ->addRightButton('delete') //添加删除按钮
            ->setRowList($data_list) // 设置表格数据
            ->setPages($page)
            ->fetch();
    }

    /**
     * 新增
     * @return mixed
     * @throws \think\Exception
     */
    public function add()
    {
        // 保存数据
        if ($this->request->isPost()) {
            $data = $this->request->post();
            // 非超级管理需要验证可选择角色
            if (session('user_auth.role') != 1) {
                if ($data['role'] == session('user_auth.role')) {
                    $this->error('禁止创建与当前角色同级的用户');
                }
                $role_list = RoleModel::getChildsId(session('user_auth.role'));
                if (!in_array($data['role'], $role_list)) {
                    $this->error('权限不足，禁止创建非法角色的用户');
                }

                if (isset($data['roles'])) {
                    $deny_role = array_diff($data['roles'], $role_list);
                    if ($deny_role) {
                        $this->error('权限不足，附加角色设置错误');
                    }
                }
            }


            $atta = new Attachment();
            $md5 = $atta->getFileMd5($data["attach_url"]);
            if ($md5) {
                $Aoss = new Aoss(config("upload_prefix"), "complete");
                $md5_data = $Aoss->md5($md5);
                if ($md5_data->isSuccess()) {
                    $data["attach_duration"] = $md5_data->duration;
                }
            }
            $special_tag = $data["special_tag"];
            $common_tag = $data["common_tag"];
            unset($data["special_tag"]);
            unset($data["common_tag"]);
            $daily_input = ["title" => $data["title"],
                "slogan" => $data["slogan"],
                "content" => $data["content"],
                "img" => $data["img"],
                "img_intro" => $data["img_intro"],
                "from1" => $data["from1"],
                "from2" => $data["from2"],
                "attach_type" => $data["attach_type"],
                "attach_url" => $data["attach_url"],
                "attach_duration" => $data["attach_duration"],
                "show_to" => $data["show_to"],];
            $study_input = ["area_id" => $data["area_id"],
                "school_id" => $data["school_id"],
                "grade" => $data["grade"],
                "push_date" => $data["push_date"],
                "show_date" => $data["show_date"],
                "end_date" => $data["end_date"],
                "can_push" => $data["can_push"] == "on",
                "can_show" => $data["can_show"] == "on",
                "study_type" => $data["study_type"],];
            Db::startTrans();
            if ($user = StudyDailyModel::create($daily_input)) {
                $lastid = $user->id;
                if ($special_tag) {
                    foreach ($special_tag as $id) {
                        StudyTagModel::create(["study_id" => $lastid, "study_type" => "daily", "tag_id" => $id,]);
                    }
                }
                if ($common_tag) {
                    foreach ($common_tag as $id) {
                        StudyTagModel::create(["study_id" => $lastid, "study_type" => "daily", "tag_id" => $id,]);
                    }
                }
                $study_input["study_id"] = $lastid;
                StudyModel::create($study_input);
                Db::commit();
                Hook::listen('user_add', $user);
                // 记录行为
                action_log('user_add', 'admin_user', $user['id'], UID);
                $this->success('新增成功', url('index'));
            } else {
                Db::rollback();
                $this->error('新增失败');
            }
        }

        // 角色列表
        if (session('user_auth.role') != 1) {
            $role_list = RoleModel::getTree(null, false, session('user_auth.role'));
        } else {
            $role_list = RoleModel::getTree(null, false);
        }

        $tag_common = TagModel::where("tag_type", "common")
            ->column("id,name");
//        foreach ($tag_common as $key => $value) {
//            $tag_common[strval($key)] = $value;
//        }
        $tag_special = TagModel::where("tag_type", "special")
            ->column("id,name");
//        foreach ($tag_special as $key => $value) {
//            $tag_special[strval($key)] = $value;
//        }

        // 使用ZBuilder快速创建表单
        return ZBuilder::make('form')
            ->setPageTitle('新增') // 设置页面标题
            ->addFormItems([ // 批量添加表单项
                ['text', 'grade', '年级', 'number'],
                ['number', 'area_id', '对应区域'],
                ['number', 'school_id', '学校id'],
                ['select', 'study_type', '课程类型', '', \Study\Type::get_type()],
                ['text', 'title', '标题'],
                ['text', 'slogan', '推荐金句'],
                ['checkbox', 'special_tag', '特殊标签', "", $tag_special],
                ['checkbox', 'common_tag', '普通/推荐标签', "", $tag_common],
                ['ueditor', 'content', '内容'],
                ['image', 'img', '小图头图', "picture"],
                ['image', 'img_intro', '简介图', "picture"],
                ['text', 'from1', '内容来源1'],
                ['text', 'from2', '内容来源2'],
                ['switch', 'can_push', '是否可以推送'],
                ['switch', 'can_show', '是否可以展示'],
                ['datetime', 'push_date', '推送日期'],
                ['datetime', 'show_date', '展示日期'],
                ['datetime', 'end_date', '结束展示日期'],
                ['select', 'attach_type', '附件类型', '', \Study\Type::get_attach_type()],
                ['file', 'attach_url', '附件类型'],
                ['number', 'attach_duration', '附件时长(秒)'],
                ['text', 'show_to', '展示给谁', "填写爸爸妈妈爷爷奶奶"],])
            ->fetch();
    }

    /**
     * 编辑
     * @param null $id 用户id
     * @return mixed
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function edit($id = null)
    {
        if ($id === null)
            $this->error('缺少参数');

        // 非超级管理员检查可编辑用户
        if (session('user_auth.role') != 1) {
            $role_list = RoleModel::getChildsId(session('user_auth.role'));
            $user_list = User::where('role', 'in', $role_list)
                ->column('id');
            if (!in_array($id, $user_list)) {
                $this->error('权限不足，没有可操作的用户');
            }
        }

        // 保存数据
        if ($this->request->isPost()) {
            $data = $this->request->post();

            // 非超级管理需要验证可选择角色
            $atta = new Attachment();
            $md5 = $atta->getFileMd5($data["attach_url"]);
            if ($md5) {
                $Aoss = new Aoss(config("upload_prefix"), "complete");
                $md5_data = $Aoss->md5($md5);
                if ($md5_data->isSuccess()) {
                    $data["attach_duration"] = $md5_data->duration;
                }
            }
            Db::startTrans();
            StudyTagModel::where("study_id", $data["id"])
                ->where("study_type", "daily")
                ->delete();
            if (isset($data["special_tag"])) {
                $special_tag = $data["special_tag"];
                foreach ($special_tag as $id) {
                    StudyTagModel::create(["study_id" => $data["id"], "study_type" => "daily", "tag_id" => $id,]);
                }
            }
            if (isset($data["common_tag"])) {
                $common_tag = $data["common_tag"];
                foreach ($common_tag as $id) {
                    StudyTagModel::create(["study_id" => $data["id"], "study_type" => "daily", "tag_id" => $id,]);
                }
            }
            $daily_input = ["id" => $data["id"],
                "title" => $data["title"],
                "slogan" => $data["slogan"],
                "content" => $data["content"],
                "img" => $data["img"],
                "img_intro" => $data["img_intro"],
                "from1" => $data["from1"],
                "from2" => $data["from2"],
                "attach_type" => $data["attach_type"],
                "attach_url" => $data["attach_url"],
                "attach_duration" => $data["attach_duration"],
                "show_to" => $data["show_to"],];
            $study_input = ["area_id" => $data["area_id"],
                "school_id" => $data["school_id"],
                "grade" => $data["grade"],
                "push_date" => $data["push_date"],
                "show_date" => $data["show_date"],
                "end_date" => $data["end_date"],
                "can_push" => $data["can_push"] == "on",
                "can_show" => $data["can_show"] == "on",
                "study_type" => $data["study_type"],
                "study_id" => $data["id"],];
            $study = StudyModel::where("study_type", $data["study_type"])
                ->where("study_id", $data["id"])
                ->find();
            if ($study) {
                StudyModel::where("study_type", $data["study_type"])
                    ->where("study_id", $data["id"])
                    ->update($study_input);
            } else {
                StudyModel::where("study_type", $data["study_type"])
                    ->insert($study_input);
            }
            if (StudyDailyModel::where("id", $data["id"])
                ->update($daily_input)) {
                Db::commit();
                // 记录行为
                action_log('user_edit', 'user', $id, UID);
                $this->success('编辑成功');
            } else {
                Db::rollback();
                $this->error('编辑失败');
            }
        }

        // 获取数据

        $info2 = StudyModel::where("study_type", "daily")
            ->where("study_id", $id)
            ->find();
        if (!$info2) {
            $study_input = ["study_type" => "daily", "study_id" => $id,];
            StudyModel::create($study_input);
        }
        $info = StudyDailyModel::field("b.*,a.*")
            ->alias("a")
            ->leftJoin(["ps_study" => "b"], "b.study_id=a.id")
            ->where("b.study_type", "daily")
            ->where('a.id', $id)
            ->find();
        // 使用ZBuilder快速创建表单

        $tag_common = TagModel::where("tag_type", "common")
            ->column("id,name");
        $tag_special = TagModel::where("tag_type", "special")
            ->column("id,name");
        $tag_choose = StudyTagModel::where("study_id", $id)
            ->where("study_type", "daily")
            ->column("tag_id");
        $info["special_tag"] = null;
        $info["common_tag"] = null;

        $data = ZBuilder::make('form')
            ->setPageTitle('编辑') // 设置页面标题
            ->addFormItems([ // 批量添加表单项
                ['hidden', 'id'],
                ['text', 'grade', '年级', 'number'],
                ['number', 'area_id', '对应区域'],
                ['number', 'school_id', '学校id'],
                ['select', 'study_type', '课程类型', '', \Study\Type::get_type()],
                ['text', 'title', '标题'],
                ['text', 'slogan', '推荐金句'],
                ['checkbox', 'special_tag', '特殊标签', "", $tag_special, $tag_choose],
                ['checkbox', 'common_tag', '普通/推荐标签', "", $tag_common, $tag_choose],
                ['ueditor', 'content', '内容'],
                ['image', 'img', '小图头图', "picture"],
                ['image', 'img_intro', '简介图', "picture"],
                ['text', 'from1', '内容来源1'],
                ['text', 'from2', '内容来源2'],
                ['switch', 'can_push', '是否可以推送'],
                ['switch', 'can_show', '是否可以展示'],
                ['datetime', 'push_date', '推送日期'],
                ['datetime', 'show_date', '展示日期'],
                ['datetime', 'end_date', '结束展示日期'],
                ['select', 'attach_type', '附件类型', '', \Study\Type::get_attach_type()],
                ['file', 'attach_url', '附件类型'],
                ['number', 'attach_duration', '附件时长(秒)'],
                ['text', 'show_to', '展示给谁', "填写爸爸妈妈爷爷奶奶"],]);

        return $data->setFormData($info) // 设置表单数据
        ->fetch();;
    }

    /**
     * 删除用户
     * @param array $ids 用户id
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function delete($ids = [])
    {
        Hook::listen('user_delete', $ids);
        action_log('user_delete', 'user', $ids, UID);
        return $this->setStatus('delete');
    }

    /**
     * 设置用户状态：删除、禁用、启用
     * @param string $type 类型：delete/enable/disable
     * @param array $record
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function setStatus($type = '', $record = [])
    {
        $ids = $this->request->isPost() ? input('post.ids/a') : input('param.ids');
        $ids = (array)$ids;

        switch ($type) {
            case 'enable':
                if (false === StudyDailyModel::where('id', 'in', $ids)
                        ->setField('status', 1)) {
                    $this->error('启用失败');
                }
                break;
            case 'disable':
                if (false === StudyDailyModel::where('id', 'in', $ids)
                        ->setField('status', 0)) {
                    $this->error('禁用失败');
                }
                break;
            case 'delete':
                if (false === StudyDailyModel::where('id', 'in', $ids)
                        ->delete()) {
                    $this->error('删除失败');
                }
                break;
            default:
                $this->error('非法操作');
        }

        action_log('user_' . $type, 'admin_user', '', UID);

        $this->success('操作成功');
    }

    /**
     * 授权
     * @param string $module 模块名
     * @param int $uid 用户id
     * @param string $tab 分组tab
     * @return mixed
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function access($module = '', $uid = 0, $tab = '')
    {
        if ($uid === 0)
            $this->error('缺少参数');

        // 非超级管理员检查可编辑用户
        if (session('user_auth.role') != 1) {
            $role_list = RoleModel::getChildsId(session('user_auth.role'));
            $user_list = User::where('role', 'in', $role_list)
                ->column('id');
            if (!in_array($uid, $user_list)) {
                $this->error('权限不足，没有可操作的用户');
            }
        }

        // 获取所有授权配置信息
        $list_module = ModuleModel::where('access', 'neq', '')
            ->where('access', 'neq', '')
            ->where('status', 1)
            ->column('name,title,access');

        if ($list_module) {
            // tab分组信息
            $tab_list = [];
            foreach ($list_module as $key => $value) {
                $list_module[$key]['access'] = json_decode($value['access'], true);
                // 配置分组信息
                $tab_list[$value['name']] = ['title' => $value['title'], 'url' => url('access', ['module' => $value['name'], 'uid' => $uid])];
            }
            $module = $module == '' ? current(array_keys($list_module)) : $module;
            $this->assign('tab_nav', ['tab_list' => $tab_list, 'curr_tab' => $module]);

            // 读取授权内容
            $access = $list_module[$module]['access'];
            foreach ($access as $key => $value) {
                $access[$key]['url'] = url('access', ['module' => $module, 'uid' => $uid, 'tab' => $key]);
            }

            // 当前分组
            $tab = $tab == '' ? current(array_keys($access)) : $tab;
            // 当前授权
            $curr_access = $access[$tab];
            if (!isset($curr_access['nodes'])) {
                $this->error('模块：' . $module . ' 数据授权配置缺少nodes信息');
            }
            $curr_access_nodes = $curr_access['nodes'];

            $this->assign('tab', $tab);
            $this->assign('access', $access);

            if ($this->request->isPost()) {
                $post = $this->request->param();
                if (isset($post['nodes'])) {
                    $data_node = [];
                    foreach ($post['nodes'] as $node) {
                        list($group, $nid) = explode('|', $node);
                        $data_node[] = ['module' => $module, 'group' => $group, 'uid' => $uid, 'nid' => $nid, 'tag' => $post['tag']];
                    }

                    // 先删除原有授权
                    $map['module'] = $post['module'];
                    $map['tag'] = $post['tag'];
                    $map['uid'] = $post['uid'];
                    if (false === AccessModel::where($map)
                            ->delete()) {
                        $this->error('清除旧授权失败');
                    }

                    // 添加新的授权
                    $AccessModel = new AccessModel;
                    if (!$AccessModel->saveAll($data_node)) {
                        $this->error('操作失败');
                    }

                    // 调用后置方法
                    if (isset($curr_access_nodes['model_name']) && $curr_access_nodes['model_name'] != '') {
                        if (strpos($curr_access_nodes['model_name'], '/')) {
                            list($module, $model_name) = explode('/', $curr_access_nodes['model_name']);
                        } else {
                            $model_name = $curr_access_nodes['model_name'];
                        }
                        $class = "app\\{$module}\\model\\" . $model_name;
                        $model = new $class;
                        try {
                            $model->afterAccessUpdate($post);
                        } catch (\Exception $e) {
                        }
                    }

                    // 记录行为
                    $nids = implode(',', $post['nodes']);
                    $details = "模块($module)，分组(" . $post['tag'] . ")，授权节点ID($nids)";
                    action_log('user_access', 'admin_user', $uid, UID, $details);
                    $this->success('操作成功', url('access', ['uid' => $post['uid'], 'module' => $module, 'tab' => $tab]));
                } else {
                    // 清除所有数据授权
                    $map['module'] = $post['module'];
                    $map['tag'] = $post['tag'];
                    $map['uid'] = $post['uid'];
                    if (false === AccessModel::where($map)
                            ->delete()) {
                        $this->error('清除旧授权失败');
                    } else {
                        $this->success('操作成功');
                    }
                }
            } else {
                $nodes = [];
                if (isset($curr_access_nodes['model_name']) && $curr_access_nodes['model_name'] != '') {
                    if (strpos($curr_access_nodes['model_name'], '/')) {
                        list($module, $model_name) = explode('/', $curr_access_nodes['model_name']);
                    } else {
                        $model_name = $curr_access_nodes['model_name'];
                    }
                    $class = "app\\{$module}\\model\\" . $model_name;
                    $model = new $class;

                    try {
                        $nodes = $model->access();
                    } catch (\Exception $e) {
                        $this->error('模型：' . $class . "缺少“access”方法");
                    }
                } else {
                    // 没有设置模型名，则按表名获取数据
                    $fields = [$curr_access_nodes['primary_key'], $curr_access_nodes['parent_id'], $curr_access_nodes['node_name']];

                    $nodes = Db::name($curr_access_nodes['table_name'])
                        ->order($curr_access_nodes['primary_key'])
                        ->field($fields)
                        ->select();
                    $tree_config = ['title' => $curr_access_nodes['node_name'], 'id' => $curr_access_nodes['primary_key'], 'pid' => $curr_access_nodes['parent_id']];
                    $nodes = Tree::config($tree_config)
                        ->toLayer($nodes);
                }

                // 查询当前用户的权限
                $map = ['module' => $module, 'tag' => $tab, 'uid' => $uid];
                $node_access = AccessModel::where($map)
                    ->select();
                $user_access = [];
                foreach ($node_access as $item) {
                    $user_access[$item['group'] . '|' . $item['nid']] = 1;
                }

                $nodes = $this->buildJsTree($nodes, $curr_access_nodes, $user_access);
                $this->assign('nodes', $nodes);
            }

            $page_tips = isset($curr_access['page_tips']) ? $curr_access['page_tips'] : '';
            $tips_type = isset($curr_access['tips_type']) ? $curr_access['tips_type'] : 'info';
            $this->assign('page_tips', $page_tips);
            $this->assign('tips_type', $tips_type);
        }

        $this->assign('module', $module);
        $this->assign('uid', $uid);
        $this->assign('tab', $tab);
        $this->assign('page_title', '数据授权');
        return $this->fetch();
    }

    /**
     * 构建jstree代码
     * @param array $nodes 节点
     * @param array $curr_access 当前授权信息
     * @param array $user_access 用户授权信息
     * @return string
     */
    private function buildJsTree($nodes = [], $curr_access = [], $user_access = [])
    {
        $result = '';
        if (!empty($nodes)) {
            $option = ['opened' => true, 'selected' => false];
            foreach ($nodes as $node) {
                $key = $curr_access['group'] . '|' . $node[$curr_access['primary_key']];
                $option['selected'] = isset($user_access[$key]) ? true : false;
                if (isset($node['child'])) {
                    $curr_access_child = isset($curr_access['child']) ? $curr_access['child'] : $curr_access;
                    $result .= '<li id="' . $key . '" data-jstree=\'' . json_encode($option) . '\'>' . $node[$curr_access['node_name']] . $this->buildJsTree($node['child'], $curr_access_child, $user_access) . '</li>';
                } else {
                    $result .= '<li id="' . $key . '" data-jstree=\'' . json_encode($option) . '\'>' . $node[$curr_access['node_name']] . '</li>';
                }
            }
        }

        return '<ul>' . $result . '</ul>';
    }

    /**
     * 启用用户
     * @param array $ids 用户id
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function enable($ids = [])
    {
        Hook::listen('user_enable', $ids);
        return $this->setStatus('enable');
    }

    /**
     * 禁用用户
     * @param array $ids 用户id
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function disable($ids = [])
    {
        Hook::listen('user_disable', $ids);
        return $this->setStatus('disable');
    }

    public function quickEdit($record = [])
    {
        $field = input('post.name', '');
        $value = input('post.value', '');
        $type = input('post.type', '');
        $id = input('post.pk', '');

        switch ($type) {
            // 日期时间需要转为时间戳
            case 'combodate':
                $value = strtotime($value);
                break;
            // 开关
            case 'switch':
                $value = $value == 'true' ? 1 : 0;
                break;
            // 开关
            case 'password':
                $value = Hash::make((string)$value);
                break;
        }
        // 非超级管理员检查可操作的用户
        if (session('user_auth.role') != 1) {
            $role_list = Role::getChildsId(session('user_auth.role'));
            $user_list = \app\user\model\User::where('role', 'in', $role_list)
                ->column('id');
            if (!in_array($id, $user_list)) {
                $this->error('权限不足，没有可操作的用户');
            }
        }
        $result = StudyDailyModel::where("id", $id)
            ->setField($field, $value);
        if (false !== $result) {
            action_log('user_edit', 'user', $id, UID);
            $this->success('操作成功');
        } else {
            $this->error('操作失败');
        }
    }
}
