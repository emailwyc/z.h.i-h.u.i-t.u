<?php
/**
 * Created by PhpStorm.
 * User: jaleel
 * Date: 7/14/16
 * Time: 3:44 PM
 */

namespace Common\Controller;

class JaleelController extends CommonController
{

	public $admin_arr;
    public $domain;
    /**
     * 初始化操作
     */
    public function _initialize()
    {
        parent::__initialize(); // TODO: Change the autogenerated stub

		/*$admin_arr=$this->getMerchant($this->ukey);
		$this->admin_arr=$admin_arr;
		$check_auth_arr=$this->getAuthId($admin_arr['id']);
		
		//是否具有权限
		if(!in_array(CONTROLLER_NAME,$check_auth_arr)){
			exit();
		}*/




		
        // 验证基础参数
        /*if (!$this->ukey) {
            $data = array('code' => '1030', 'msg' => 'miss ukey params');
            returnjson($data, $this->returnstyle, $this->callback);
        }*/
    }
}