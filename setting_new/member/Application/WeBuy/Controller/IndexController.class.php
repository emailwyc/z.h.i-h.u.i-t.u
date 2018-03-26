<?php
/**
 * 团购控制器类
 * User: jaleel
 * Date: 11/16/16
 * Time: 14:51 PM
 */
namespace WeBuy\Controller;
use Common\Controller\JaleelController;

class IndexController extends JaleelController {
    protected $url = 'http://211.157.182.226:8080/'; // 请求接口地址
    protected $build_id; // 建筑物id

    public function _initialize()
    {
        parent::_initialize(); // TODO: Change the autogenerated stub

        // 按商户key_admin查询buildid
        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $this->build_id = $build_info['buildid'];
    }

    /**
     * 团购券列表接口
     */
    public function index(){
        if (!$this->ukey) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['build_id'] = $this->build_id;
        $post_arr['coupon_type'] = 7; // 券类型：0、折扣券(APP专用)；1、礼品券；2、代金券；3、广告券；4、优惠券；5、红包券 ; 6、停车券 7：团购券

        $url= $this->url . 'promo/prize/grouppurchase/coupon/list';
        $curl_re = http($url, $post_arr, 'POST');
        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 团购券详情接口
     */
    public function couponDetails() {
        $prize_id = I('prize_id');

        if (!$prize_id) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['prize_id'] = $prize_id;

        $url= $this->url . 'promo/prize/grouppurchase/coupon/detail';
        $curl_re = http($url, $post_arr, 'POST');
        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 微信下单接口
     */
    public function createOrder() {
        $total_fee = I('total_fee') * 100; // 支付总金额
        $num = I('num'); // 购买数量
        $prize_id = I('prize_id'); // 团购券ID
        $fee = I('fee') * 100; // 团购券单价
        $uname = I('nickname'); // 微信昵称

        if (!$this->ukey or !$total_fee or !$this->user_openid or !$num) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 按商户key_admin查询buildid
        $build_info = $this->getBuildIdByKey($this->ukey);

        //writeOperationLog(array('查询西单商户buildid' => json_encode($build_info)), 'jaleel_logs');

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['buildId'] = $build_info['buildid'];

        $pay_fee = $total_fee; // 计算实际应付金额 单位分
//        $pay_fee = 1; // 计算实际应付金额 单位分

        // 请求微信支付接口进行支付
        $post_arr['totalFee'] = $pay_fee; // 单位分
        $post_arr['type'] = 1;
        $post_arr['attach'] = '';
        $post_arr['notifyUrl'] = '';
        $post_arr['customerType'] = 2; // 消费者类型：1.商户、2.微信用户
        $post_arr['customerId'] = $this->user_openid;
        $post_arr['mainTitle'] = '西单团购';
        $post_arr['subTitle'] = '微信下单';
        $post_arr['hasReceipt'] = 0; // 是否开发票：0.不开发票，1.开发票
        $post_arr['itemNums'] = 1; // 购买的商户种类数
        $post_arr['customerName'] = $uname; // 微信昵称
        $post_arr['items'] = array(
            array(
                'productId' => $prize_id,
                'merchantId' => 0,
                'quantity' => $num,
                'fee' => $fee, // 单位为分
                'totalFee' => $total_fee, // 单位为分
                'category' => 3, // 订单分类：1.余额充值、2.停车缴费、3.团购券
            ),
        );

        $url = "http://order.rtmap.com/settlement-web/order/submit";
        $data_string = json_encode($post_arr);
        $curl_re = $this->curl_json($url, $data_string);
        $curl_arr = json_decode($curl_re, true);

        writeOperationLog(array('团购请求微信支付下单接口参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('团购请求微信支付下单请求url' => $url), 'jaleel_logs');
        writeOperationLog(array('团购请求微信支付下单接口返回' => $curl_re), 'jaleel_logs');

        if (!is_array($curl_arr) or $curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
            exit;
        }

        //$return_data['total_fee'] = $pay_fee / 100;
        $return_data = $curl_arr['data'];
        $return_data['order_id'] = $curl_arr['data']['ordId'];

        writeOperationLog(array('西单团购返回值' => json_encode($return_data)), 'jaleel_logs');

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $return_data);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 微信支付接口
     */
    public function buyCoupon() {
        $order_id = I('order_id');
        $total_fee = I('total_fee') * 100;

        if (!$this->ukey or !$order_id or !$this->user_openid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        // 查询商户支付子账号
        $sub_acc = $this->GetOneAmindefault($mer_chant['pre_table'], $this->ukey, 'subpayacc');
        $sub_merch = $sub_acc['function_name'];

        $pay_fee = $total_fee; // 计算实际应付金额 单位分
//        $pay_fee = 1; // 计算实际应付金额 单位分

        // 请求微信支付接口进行支付
        $post_arr['orderId'] = $order_id;
        $post_arr['trades'] = array(
            array(
                'total_fee' => $pay_fee, // 单位为分
                'category' => 1, // 交易分类：1.微信、2.支付宝、3.银联、4.优惠券、5.礼品卡、6.积分
                'type' => 1, // 交易类型：1.支付、2.退款
                'appid' => $mer_chant['wechat_appid'],
                'mchid' => $sub_merch,
                'openid' => $this->user_openid,
                'body' => '团购支付',
                'tradeType' => 'JSAPI',
            ),
        );

        // 生成签名
        $post_arr['trades'][0]['sign'] = $this->mkSign($post_arr['trades'][0], $mer_chant['signkey']);

        $url = "http://order.rtmap.com/settlement-web/api/pay/submit";
        $data_string = json_encode($post_arr);
        $curl_re = $this->curl_json($url, $data_string);
        $curl_arr = json_decode($curl_re, true);

        writeOperationLog(array('团购请求微信支付接口参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('团购请求微信支付请求url' => $url), 'jaleel_logs');
        writeOperationLog(array('团购请求微信支付接口' => $curl_re), 'jaleel_logs');

        if (!is_array($curl_arr) or $curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
            exit;
        }

        writeOperationLog(array('西单团购支付返回值' => json_encode($curl_arr)), 'jaleel_logs');

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 我的团购券接口
     */
    public function myCoupon() {
        $status = I('status');

        if (!$this->ukey or !$this->user_openid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['openid'] = $this->user_openid;
        $post_arr['buildId'] = $this->build_id;
        $post_arr['status'] = $status; // 状态：0：全部 1:待使用（完成）2：退款
        $post_arr['platform'] = 'client';
        $post_arr['page'] = 1; // 默认为第一页
        $post_arr['pageSize'] = 10; // 每页显示十条

        $data_string = json_encode($post_arr);

        $url= 'http://order.rtmap.com/settlement-web/order/list';
        $curl_re = $this->curl_json($url, $data_string);
        $curl_arr = json_decode($curl_re, true);

        writeOperationLog(array('我的订单列表参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('我的订单列表' => $curl_re), 'jaleel_logs');

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $curl_arr['data']['list']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 订单详情接口
     */
    public function myOrderDetails() {
        $status = I('status');
        $order_id = I('order_id');

        if (!$this->ukey or !$this->user_openid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['open_id'] = $this->user_openid;
        $post_arr['buildId'] = $this->build_id;
        $post_arr['status'] = $status; // 状态：0：全部 1:待使用 2：退款 3:已使用
        $post_arr['orderId'] = $order_id;
        $post_arr['platform'] = 'client';

        $data_string = json_encode($post_arr);

        $url= 'http://order.rtmap.com/settlement-web/order/detail';
        $curl_re = $this->curl_json($url, $data_string);
        $curl_arr = json_decode($curl_re, true);

        writeOperationLog(array('我的订单详情参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('我的订单详情' => $curl_re), 'jaleel_logs');
        
        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 退款接口
     */
    public function refund() {
        $order_id = I('order_id');
        $oritem_id = I('oritem_id');
        $product_id = I('prize_id');
        $num = I('num');
        $mainTitle = I('mainTitle');
        $subTitle = I('subTitle');

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        // 查询商户支付子账号
        $sub_acc = $this->GetOneAmindefault($mer_chant['pre_table'], $this->ukey, 'subpayacc');
        $sub_merch = $sub_acc['function_name'];

        $post_arr['ordId'] = $order_id;
        $post_arr['ordItemId'] = $oritem_id;
        $post_arr['productId'] = $product_id;
        $post_arr['quantity'] = $num;
        $post_arr['customerId'] = $this->user_openid;
        $post_arr['mainTitle'] = $mainTitle;
        $post_arr['subTitle'] = $subTitle;
        $post_arr['mchid'] = $sub_merch;

        $data_string = json_encode($post_arr);

        $url = 'http://order.rtmap.com/settlement-web/api/refund';
        $curl_re = $this->curl_json($url, $data_string);
        $curl_arr = json_decode($curl_re, true);

        writeOperationLog(array('退款参数' => $data_string), 'jaleel_logs');
        writeOperationLog(array('退款结果' => $curl_re), 'jaleel_logs');

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success!');
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * curl请求
     * POST数据为JSON数据
     * @param $url
     * @param $data_string
     * @return mixed
     */
    protected function curl_json($url, $data_string) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string))
        );
        $curl_re = curl_exec($ch);
        curl_close($ch);

        return $curl_re;
    }

    /**
     * 请示接口签名
     * @param $data
     * @param $key
     * @return mixed
     */
    protected function mkSign($data, $key) {
        ksort($data);
        $str = '';
        foreach ($data as $k => $v) {
            if ($v == '') { // 值为空不参与签名
                continue;
            }

            if ('' == $str) {
                $str .= $k . '=' . trim($v);
            } else {
                $str .= '&' . $k . '=' . trim($v);
            }
        }

        $str .= '&key=' . $key;
        $sign = strtoupper(md5($str));
        return $sign;
    }
}
