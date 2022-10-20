<?php


namespace app\teacherclass\model;

use think\Model;

/**
 * 后台用户模型
 * @package app\admin\model
 */
class AttachModel extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $table = 'tc_attach';

    // 设置当前模型对应的完整数据表名称

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

}
