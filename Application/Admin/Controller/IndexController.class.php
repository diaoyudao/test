<?php

namespace Admin\Controller;

use Think\Controller;

/**
 * 后台管理主页
 * @package Admin\Controller
 */
class IndexController extends Controller
{
	public function index()
	{
		// 获取提醒
		$this->assign('employee', M('employee')->find(session('employee_id')));
		$this->wait();
		
		$this->display();
	}
	
	private function wait()
	{
		//获取配置
		$config = $this->getConfig();
		$common_where = $this->getDepartmentWhere();
		$wait = [];
		//新分配客户
		$new_cust_where['employee_id'] = session('employee_id');
//		$next_day = date("Y-m-d", strtotime("+1 day"));
//		$new_cust_where['_string'] = "((aa.update_time is null) or (aa.update_time < '" . $next_day . "')) and aa.delete_time is null";
//		$new_cust_where['_string'] = "aa.update_time is null and aa.delete_time is null";
		$new_cust_where['_string'] = "aa.update_time is not null and aa.delete_time is null";
//		$new_cust_where['aa.create_time'] = ['egt', date('Y-m-d')];
		$field = 'aa.*,(select name from customer where customer_id=aa.customer_id and delete_time is null) customer_name';
		$new_cust = M('customer_employee')->alias('aa')->field($field)->where($new_cust_where)->select();
		foreach ($new_cust as &$item) {
			$item['type'] = 'cust';
		}
		
		if (session('employee')['role_type_code'] == 'boss') {
			if (session('employee_id') != 1000) {
				$project_where['approve'] = ['in', [1, 2, 3]];
				$project_where['approve_status'] = 2;
				$project_where['_string'] = 'delete_time is null';
				$project = M('project')->where($project_where)->select();
				$wait = $project;
			}
		} else {
			$project = [];
			if (session('employee')['role_type_code'] == 'director') {
				$project_where['approve'] = ['neq', 0];
				$project_where['approve_status'] = ['in', [1, 3]];
				$project_where['next_department_id'] = session('employee')['department_id'];
				$project_where['_string'] = 'delete_time is null';
				$project = M('project')->where($project_where)->select();
//				dump($project);
				$task_where['department_id'] = session('employee')['department_id'];
			}
			//项目提醒
			$liaison_where = $common_where;
			$liaison_where['next_time'] = [['elt', date('Y-m-d', strtotime('+' . $config['liaison_limit'] . ' day'))], ['egt', date('Y-m-d')]];
			
			$liaison = M('customer_liaison')
//				->field('customer_id')
				->where($liaison_where)->select();
			foreach ($liaison as &$v) {
				$v['customer_name'] = M('customer')->where(['customer_id' => $v['customer_id'], '_string' => 'delete_time is null'])->getField('name');
				$v['project_name'] = M('project')->where(['project_id' => $v['project_id'], '_string' => 'delete_time is null'])->getField('name');
				$v['type'] = 'liaison';
			}
			
			//客户生日提醒
			$contact_where = $common_where;
			$contact_where['is_remind'] = 1;
			$contact_where['birthday'] = [['elt', date('m-d', strtotime('+' . $config['birthday_limit'] . ' day'))], ['egt', date('m-d')]];
			
			$contact = M('customer_contact')->where($contact_where)->select();
			foreach ($contact as &$vv) {
				$vv['customer_name'] = M('customer')->where(['customer_id' => $vv['customer_id'], '_string' => 'delete_time is null'])->getField('name');
				$vv['type'] = 'contact';
			}
			//任务提醒
//			$task_where = $common_where;
			$task_where['end_date'] = [['elt', date('Y-m-d', strtotime('+' . $config['task_limit'] . ' day'))], ['egt', date('Y-m-d')]];
			$task_where['_string'] .= 'delete_time is null and over_time is null';
			if (!isset($task_where['department_id'])) {
				$task_where['handler'] = ['like', "%" . session('employee_id') . "%"];
			}
			$task = M('task')->where($task_where)->select();
			foreach ($task as &$val) {
				$val['type'] = 'task';
			}
			// 审核、审批未通过
			$approve_where['pa.create_time'] = ['egt', date('Y-m-d', strtotime("-30 day"))];
			$approve_where['pa.status'] = ['in', [2, 3]];
			$approve_where['pa.is_pass'] = 0;
			$approve_where['pa.start_employee'] = session('employee_id');
			$approve_where['_string'] = 'pa.update_time is  null and pa.delete_time is null';
			$approve_field = 'pa.*,(select customer_id from project where project_id=pa.project_id and delete_time is null) customer_id,(select name from project where project_id=pa.project_id and delete_time is null) name';
			$approve = M('project_approve')->alias('pa')->field($approve_field)->where($approve_where)->select();
			foreach ($approve as &$value) {
				$value['type'] = 'approve';
			}
			$wait = array_merge($project, $liaison, $contact, $task, $new_cust, $approve);
		}
//		dump(M()->_sql());
		$count = count($wait);
		if (IS_POST) {
			$this->ajaxReturn(['status' => 'success', 'wait' => $wait, 'count' => $count]);
		}
		$this->assign('wait', $wait);
		$this->assign('task', $task);
		$this->assign('count', $count);
//		dump($contact_where);
//		dump($contact);
//		dump($task);
	}
	
	
	
	private function notice()
	{
		// 主任和业务员才会有提醒
		if ('director' != session('employee.role_type_code')
			&& 'salesman' != session('employee.role_type_code')) {
			return [];
		}
		
		$config = M('config')->field('name,value')->select();
		// 生日
		$where['_string'] = 'delete_time is null and birthday is not null';
		$where['status'] = 1; // 在职
		$where['is_remind'] = 1;
		
		$contact = M('customer_contact')->where($where)
			->field('birthday,customer_contact_id,name,function,position')
			->select();
		
		
		// 任务
		
		// 联络
	}
	
	
	private function getConfig()
	{
		$config = M('config')->where(['status' => 1])->getField('name,value', true);
		return $config;
	}
	
	private function getDepartmentWhere()
	{
		$department_type_id = session('employee')['role_type_code'];
//		dump(session('employee'));
//		exit();
		$res = [];
		$res['_string'] = 'delete_time is null';
		switch ($department_type_id) {
			case 'director':
				$res['department_id'] = session('employee')['department_id'];
				break;
			case 'salesman':
				$res['employee_id'] = session('employee_id');
				break;
			case 'boss':
				break;
			case 'other':
				$res['employee_id'] = session('employee_id');
				break;
		}
		return $res;
	}
	
	private function whereRole()
	{
	
	}
}