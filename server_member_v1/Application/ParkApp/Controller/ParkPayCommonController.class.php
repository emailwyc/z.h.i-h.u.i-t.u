<?php
/**
 * 停车缴费支付应用类
 * User: jaleel
 * Date: 7/14/16
 * Time: 3:07 PM
 */

namespace ParkApp\Controller;

use Common\Controller\CommonController;
use PublicApi\Controller\QiniuController;
//use CrmService\Controller\OutputApi\IndexController;

class ParkPayCommonController extends CommonController
{
    protected $url_hzt;
    protected $url_djy;
    protected $member_url = 'http://mem.rtmap.com/';

    public function _initialize()
    {
        parent::__initialize();
        $this->url_hzt = 'http://211.157.182.226:8090/promo/parking/'; // 测试接口
//        $this->url_hzt = 'http://101.201.175.219/promo/parking/'; // 生产接口
        $this->url_djy = 'http://101.200.216.74:8080/parking-web/'; // 测试接口
//        $this->url_djy = 'http://groupon.rtmap.com/parking-web/'; // 生产接口
    }

    /**
     * 获取空闲车位数
     * @throws \Exception
     */
    public function getfreeparking()
    {
        $mer_chant = $this->getMerchant($this->ukey);
        $data['key_admin'] = $this->ukey;
        $data['sign_key'] = $mer_chant['signkey'];
        $data['sign'] = sign($data);
        unset($data['sign_key']);
        $url = C('DOMAIN') . '/Parkservice/Parkoutput/get_left_park';
        $curl_re = http($url, $data, 'post');
        writeOperationLog(array('get free parking' => $curl_re), 'jaleel_logs');
        $data = json_decode($curl_re, true);

        // 查询商场配置的logo
        $re = $this->GetOneAmindefault($mer_chant['pre_table'], $this->ukey, 'logo');

        if (is_array($re)) {
            $data['logo'] = $re['function_name'];
        } else {
            $data['logo'] = '';
        }

        $data['localstorage'] = $mer_chant['is_localstorage'];

        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 按车牌号搜索车辆
     * @throws \Exception
     */
    public function searchcar()
    {
        $carno = I('carno');
        if (!$carno) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $mer_chant = $this->getMerchant($this->ukey);
        $data['key_admin'] = $this->ukey;
        $data['sign_key'] = $mer_chant['signkey'];
        $data['carno'] = $carno;
        $data['sign'] = sign($data);
        unset($data['sign_key']);
        $url = C('DOMAIN') . '/Parkservice/Parkoutput/searchcar';
        $curl_re = http($url, $data, 'post');

        writeOperationLog(array('search car result' => $curl_re), 'jaleel_logs');
        $data = json_decode($curl_re, true);

        // 对车的图片做处理 将图片拉到七牛上 然后返回相应的url
        $qi = new QiniuController();

        foreach ($data['data'] as $k=>$v) {
            if (!empty($v['carimg'])) {
                $re = $qi->qiniu_fetch($v['carimg'], 'img/carPic/' . strtolower($v['CarSerialNo']));

                if (is_array($re) && isset($re[0]['key'])) {
                    $data['data'][$k]['carimg'] = 'https://oe5n68bv6.qnssl.com/' . $re[0]['key'];
                }
            }
        }
        
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 选择我的车
     * @throws \Exception
     */
    public function choosecar()
    {
        $carno = I('carno');
        if (!$carno) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $mer_chant = $this->getMerchant($this->ukey);

        writeOperationLog(array('choose car get merchant' => json_encode($mer_chant)), 'jaleel_logs');

        //获取车的信息
        $data = $this->getCarInfo($carno, $mer_chant['signkey'], $mer_chant['pre_table']);
        $data['data']['is_scorepay'] = $mer_chant['is_scorepay'];
        $data['data']['is_reft'] = $mer_chant['is_reft'];
        $data['data']['park_time'] = strtotime($data['data']['EndTime']) - strtotime($data['data']['BeginTime']);
        $data['data']['payFee'] = $data['data']['MoneyValue'];

        // 对车的图片做处理 将图片拉到七牛上 然后返回相应的url
//        $qi = new QiniuController();
//        $re = $qi->qiniu_fetch($data['data']['carimg'], 'img/carPic/' . strtolower($carno));

//        if (is_array($re) && isset($re[0]['key'])) {

        if (!empty($data['data']['carimg'])) {
            $data['data']['carimg'] = 'https://oe5n68bv6.qnssl.com/img/carPic/' . strtolower($carno);
        } else {
            $data['data']['carimg'] = '';
        }
//        }

        // 如果是西单则进行特殊处理 调用获取停车券接口 来获得用户可以使用的停车券
        /*if ($this->ukey == 'e4273d13a384168962ee93a953b58ffd') {
            $total_fee = $data['data']['MoneyValue'] / 100;
//            $total_fee = 100;
            $discount_fee = 0; // 折扣金额
            $coupons_used = array(); // 存储将要使用的券信息数组

            if ($total_fee > 0) { // 总支付金额为零时不需要进行优惠价券抵扣
                $discount_arr = $this->parkPayByCoupon($total_fee, $this->user_openid); // array('payFee' => $pay_fee, 'discountFee' => $discount_fee, 'couponsUsed' => $coupons_used);

                $discount_fee = $discount_arr['discountFee'];
                $coupons_used = $discount_arr['couponsUsed'];
            }

            $data['data']['MoneyValue'] = $total_fee; // 付款总金额
            $data['data']['payFee'] = $total_fee - $discount_fee; // 实际付款金额
            $data['data']['discountFee'] = $discount_fee; // 抵扣金额
            $data['data']['coupons'] = $coupons_used; // 使用的券的信息
            $data['data']['is_scorepay'] = '1';

            // 查询会员积分
            $user_card = $this->getUserCard($this->user_openid);
            $crm_info = $this->getUserInfo($user_card, $this->ukey, $mer_chant['signkey']);
            writeOperationLog(array('choose car get user info' => json_encode($crm_info)), 'jaleel_logs');
            $user_bonus = (int)$crm_info['data']['score'];

            $data['data']['bonus'] = $user_bonus; // 用户积分余额

            writeOperationLog(array('choose car return to client' => json_encode($data)), 'jaleel_logs');

            returnjson($data, $this->returnstyle, $this->callback);
            exit;
        }*/

        // 查询会员信息
        $uinfo = $this->getUserCardByUserid($mer_chant['pre_table'], $this->user_userid);

        // 非会员没有相关优惠 此处的目的是避免客户自己在后台开启相关优惠功能
        if (!$uinfo) {
            $mer_chant['is_discount'] = 0;
            $mer_chant['is_freetime'] = 0;
            $mer_chant['is_scorepay'] = 0;
            $mer_chant['is_reft'] = 0;
            $data['data']['bonus'] = 0;
            $data['data']['level'] = '';
        } else {
            $obj = M('total_static');
            $re = $obj->where(array('admin_id' => $mer_chant['id'],'tid' => 5))->find();
            $level_arr = json_decode($re['content'], true);
            foreach ($level_arr as $k=>$v) {
                if ($k == $uinfo['level']) {
                    $data['data']['level'] = $v;
                }
            }
        }

        // 判断是否有优惠信息
        // 判断商户是否开通了折扣优惠
        /*if ($mer_chant['is_discount'] != 0 && $now >= $mer_chant['is_discount']) {
            $discount_arr = $this->getDiscountFee($mer_chant['pre_table'], $this->ukey, $data['data']['MoneyValue'], $uinfo['level'], $this->user_openid);
        }*/

        if ($uinfo['parkft'] > 0)
        {
            $last_ft = date('Ymd',$uinfo['parkft']);
        } else {
            $last_ft = 0;
        }

        $today = date('Ymd');

        writeOperationLog(array('choose car date today' => $today), 'jaleel_logs');
        writeOperationLog(array('choose car date last' => $last_ft), 'jaleel_logs');

        // 判断商户是否开通了免费时长优惠 一天只能使用一次
        if ($mer_chant['is_freetime'] == 1 &&  $last_ft != $today) {
            $park_price = $data['data']['MoneyValue'] / $data['data']['park_time']; // 分/秒
            $free_arr = $this->getFreeTimeFee($mer_chant['pre_table'], $this->ukey, $data['data']['MoneyValue'], $uinfo['level'], $park_price);
            $free_arr['ft_money'] = floor($free_arr['ft_money']);
            $data['data']['payFee'] = $data['data']['MoneyValue'] - $free_arr['ft_money'];
            if ($data['data']['payFee'] < 0) {
                $data['data']['payFee'] = '0';
            }
            $data['data']['member_free']['free_time'] = round($free_arr['free_time']/3600, 2);
            $data['data']['member_free']['free_money'] = $free_arr['ft_money']/100;
        } else {
            $data['data']['member_free']['free_time'] = '0';
            $data['data']['member_free']['free_money'] = '0';
        }

        // 判断商户是否开通了消费送停车时长
        if ($mer_chant['is_reft'] != 0) {

            // 请求接口返回免费时长
            $data['data']['refund_free']['free_time'] = '0';
            $data['data']['refund_free']['free_money'] = '0';
        } else {
            $data['data']['refund_free']['free_time'] = '0';
            $data['data']['refund_free']['free_money'] = '0';
        }

        $data['data']['is_freetime'] = $mer_chant['is_freetime'];
        $data['data']['is_reft'] = $mer_chant['is_reft'];

        $data['data']['MoneyValue'] = $data['data']['MoneyValue'] / 100; // 转化成元为单位
        $data['data']['payFee'] = $data['data']['payFee'] / 100; // 转化成元为单位

        // 判断会员积分是否够支付
        if ($uinfo) {
            $crm_info = $this->getUserInfo($uinfo['cardno'], $this->ukey, $mer_chant['signkey']);
            $user_bonus = (int)$crm_info['data']['score'];
            $data['data']['bonus'] = $user_bonus; // 用户积分余额
        }

        // 获取优惠信息
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 按车牌号查询停车的详细信息
     * @param $carno
     * @param $signkey
     * @param string $pre_table
     * @return mixed
     * @throws \Exception
     */
    protected function getCarInfo($carno, $signkey, $pre_table = '')
    {
        $data['key_admin'] = $this->ukey;
        $data['sign_key'] = $signkey;
        $data['carno'] = $carno;
        $data['sign'] = sign($data);

        // 先只是世纪金源查询订单号 若还有其他的车场也是这样则不做判断
        if ($data['key_admin'] == '15e623784693e70dc4d1e6009da6790d') {

            // 查询此车牌号最近一次没有支付的订单 因为世纪金源是先下单的 并且查询车俩缴费信息时需要传递他们的订单号
            $order = M('carpay_order', $pre_table);
            $result = $order->where(array('carno' => $carno, 'status' => 0))->order('createtime desc')->limit(1)->select();

            if (is_array($result)) {
                $data['orderNo'] = $result[0]['client_orderno'];
            }
        }

        unset($data['sign_key']);
        $url = C('DOMAIN') . '/Parkservice/Parkoutput/choosemycar';
        $curl_re = http($url, $data, 'post');
        writeOperationLog(array('choose car result' => $curl_re), 'jaleel_logs');
        $data = json_decode($curl_re, true);

        if ($data['code'] == 1103) {
            $data['msg'] = '该车已缴过费';
        }

        return $data;
    }

    /**
     * 获取会员停车信息接口(包含会员积分余额)
     * @throws \Exception
     */
    public function getpayinfo()
    {
        $carno = urldecode(I('carno'));
        $free_coupon_num = I('use_num'); // 所使用的免费时长券数

        if (!$carno) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        $car = $this->getCarInfo($carno, $mer_chant['signkey'], $mer_chant['pre_table']);
        $score_fee = $car['data']['IntValue']; // 单位积分
        $rmb_fee = $car['data']['MoneyValue']; // 单位元
        $begin = $car['data']['BeginTime'];
        $end = $car['data']['EndTime'];

        // 查询会员信息
        $uinfo = $this->getUserCardByusercid($mer_chant['pre_table']);

        // 非会员没有相关优惠 此处的目的是避免客户自己在后台开启相关优惠功能
        if (!$uinfo) {
            $mer_chant['is_discount'] = 0;
            $mer_chant['is_freetime'] = 0;
            $mer_chant['is_scorepay'] = 0;
        }

        // 根据会员卡号查询会员积分
        $uinfo_arr = $this->getUserInfo($uinfo['cardno'], $this->ukey, $mer_chant['signkey']);

        if ($uinfo_arr['errcode'] != 200) {
            $data = array('code' => '2000', 'msg' => '会员卡号不存在!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $now = time();

        // 判断商户是否开通了折扣优惠
        if ($mer_chant['is_discount'] != 0 && $now >= $mer_chant['is_discount']) {

            // 判断会员今日使用折扣优惠的次数
        }

        // 判断商户是否开通了免费时长优惠
        if ($mer_chant['is_freetime'] != 0 && $now >= $mer_chant['is_freetime']) {

            // 判断会员今日使用免费时长优惠的次数
        }

        // 判断商户是否开通了积分支付
        if ($mer_chant['is_scorepay'] != 0 && $now >= $mer_chant['is_scorepay']) {

        }

        // 计算实际应支付金额 优惠金额

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => array('score_pay' => $score_fee, 'rmb_pay' => $rmb_fee, 'bonus' => $uinfo_arr['data']['score'], 'begin' => $begin, 'end' => $end));
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 积分支付下单接口
     */
    public function cscoreorder()
    {
        $carno = urldecode(I('carno'));

        // 验证参数
        if (!$carno or !$this->userucid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        $car = $this->getCarInfo($carno, $mer_chant['signkey'], $mer_chant['pre_table']);
        $total_fee = $car['data']['IntValue']; // 单位积分
        $begintime = strtotime($car['data']['BeginTime']); // 停车起始时间
        $endtime = strtotime($car['data']['EndTime']); // 停车结束时间
        $client_orderno = $car['data']['orderNo'];
//        $total_fee = 1; // 单位积分

        /**
         * 支付积分为零则直接提示下单失败
         */
        if ($total_fee == 0) {
            $data = array('code' => '5000', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询会员信息
//        $uinfo = $this->getUserCardByusercid($mer_chant['pre_table'], $this->user_openid);
//        if (!$uinfo) {
//            $data = array('code' => '2000', 'msg' => 'sorry,u are not our member,please go to register!');
//            returnjson($data, $this->returnstyle, $this->callback);
//        }

        // 插入定单
        $order = M('carpay_order', $mer_chant['pre_table']);
        $order_no = uniqid('JF') . date('YmdHis') . rand(1000, 9999);
        $in_order = $this->createOrder($order, $carno, $total_fee, 1, $order_no, $begintime, $endtime, $client_orderno,$total_fee);

        if (!$in_order) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => array('orderNo' => $order_no, 'payscore' => $car['data']['IntValue']));
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 积分下单接口(对外)
     * 需要签名验证
     * 此处需要传递支付的积分数 因为此外是对接的轻停 他们是优惠一定数量的积分 具体的积分数是他们传递过来的
     */
    public function cscoreorderv2()
    {
        $carno = urldecode(I('carno'));
        $score = I('score');
        $timestamp = I('timestamp');
        $sign_par = I('sign');

        // 验证参数
        if (!$carno or !$this->user_openid or !$timestamp or !$this->ukey or !$sign_par or !$score) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        $sign_arr = array(
            'key_admin' => $this->ukey,
            'openid' => $this->user_openid,
            'carno' => $carno,
            'timestamp' => $timestamp,
            'score' => $score,
            'sign_key' => $mer_chant['signkey'],
        );

        $sign = sign($sign_arr);
        writeOperationLog(array('调用方传递的参数:' . json_encode($sign_arr) . "\n\n"), 'jaleel_logs');
        writeOperationLog(array('调用方传递的sign:' . $sign_par . "\n\n"), 'jaleel_logs');
        writeOperationLog(array('我方加密码的sign:' . $sign . "\n\n"), 'jaleel_logs');

        // 签名错误
        if ($sign != $sign_par) {
            $data = array('code' => '1002', 'msg' => 'invalid sign!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $total_fee = $score; // 单位积分

        /**
         * 支付积分为零则直接提示下单失败
         */
        if ($total_fee == 0) {
            $data = array('code' => '5000', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询会员信息
        $uinfo = $this->getUserCardByusercid($mer_chant['pre_table']);
        if (!$uinfo) {
            $data = array('code' => '2000', 'msg' => 'sorry,u are not our member,please go to register!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 插入定单
        $order = M('carpay_order', $mer_chant['pre_table']);
        $order_no = uniqid('jfo') . date('YmdHis') . rand(1000, 9999);
        $in_order = $this->createOrder($order, $carno, $total_fee, 1, $order_no, '', '', '');

        if (!$in_order) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => array('orderNo' => $order_no));
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 积分支付接口
     * @throws \Exception
     */
    public function paybyscore()
    {
        $carno = urldecode(I('carno'));
        $orderNo = I('orderno');
        $use_freetime = I('use_freetime');
        $use_refreetime = I('use_refreetime');

        if (!$carno or !$orderNo or !$this->userucid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        // 查询会员信息

        $uinfo = $this->getUserCardByUserid($mer_chant['pre_table'], $this->userucid);

        if (!$uinfo) {
            $data = array('code' => '15', 'msg' => '您还不是会员!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $user_card = $uinfo['cardno'];

        // 根据会员卡号查询会员积分
        $uinfo_arr = $this->getUserInfo($user_card, $this->ukey, $mer_chant['signkey']);

        // 查询应该缴纳的会员积分数
        $car = $this->getCarInfo($carno, $mer_chant['signkey'], $mer_chant['pre_table']);

        $total_fee = $car['data']['IntValue'];

        /*if ($uinfo_arr['data']['cardtype'] == '02') {
            $total_fee = $car['data']['VIPIntValue'];
        } else {
            $total_fee = $car['data']['IntValue'];
        }*/
//        $total_fee = 1; // 单位积分

        /**
         * 支付积分为零则直接提示下单失败
         */
        if ($total_fee == 0) {
            $data = array('code' => '5000', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询定单信息
        $order = M('carpay_order', $mer_chant['pre_table']);
        $order_info = $order->where(array('orderno' => $orderNo))->find();

        if (!$order_info) {
            $data = array('code' => '2012', 'msg' => '无效定单!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 判断下单时间是否超过十分钟
        if (is_array($order_info) && time() - $order_info['createtime'] > 600) {
            $data = array('code' => '2013', 'msg' => '定单过期!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 定单已经完成
        if ($order_info['status'] == 2) {
            $data = array('code' => '2016', 'msg' => '定单已完成!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询积分抵现比例
        $score_re = $this->GetOneAmindefault($mer_chant['pre_table'], $this->ukey, 'score');

        // 停车单价 分/秒
        $park_time = strtotime($data['data']['EndTime']) - strtotime($data['data']['BeginTime']);
        $price = $car['data']['MoneyValue'] / $park_time;

        $pay_fee = $total_fee;
        // 使用免费时长
        if ($use_freetime == 1) {
            $free_re = $this->getFreeTimeFee($mer_chant['pre_table'], $this->ukey, $car['data']['MoneyValue'], $uinfo['level'], $price);
            $free_re['ft_money'] = floor($free_re['ft_money']);
            $pay_fee = $total_fee - $free_re['ft_money']/100 * $score_re['function_name'];
        }

        if ($pay_fee > 0) {

            // 使用消费返时长
            if ($use_refreetime = 1) {

            }
        }


       /* if ($uinfo_arr['code'] != 200) {
            $data = array('code' => '2000', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }*/

        // 判断用户的积分是否够支付
        if ($uinfo_arr['data']['score'] < $pay_fee) {
            $data = array('code' => '319', 'msg' => '积分不足,您的当前积分为' . (int)$uinfo_arr['data']['score'] . '!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        if ($pay_fee > 0) {

            // 请求扣除积分接口
            $post_arr['scoreno'] = abs($pay_fee); // 单位积分
            $post_arr['cardno'] = $uinfo_arr['data']['cardno'];
            $post_arr['why'] = '停车支付';
            $post_arr['key_admin'] = $this->ukey;
            $post_arr['sign_key'] = $mer_chant['signkey'];
            $post_arr['sign'] = sign($post_arr);
            unset($post_arr['sign_key']);
            $url = $this->member_url . 'CrmService/OutputApi/Index/cutScore';
            $curl_re = http($url, $post_arr, 'post');
            $curl_arr = json_decode($curl_re, true);

            if ($curl_arr['code'] != 200) {
                $data = array('code' => '2014', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }
        }

        if ($pay_fee <= 0) {
            $pay_fee = 0;
        }

        // 更新定单状态为支付成功状态
        $up_re = $order->where(array('orderno' => $orderNo))->save(array('status' => 1, 'pay_time' => time(), 'payfee' => $pay_fee));

        if ($up_re === false) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 通知车场
        $this->noticePark($mer_chant['signkey'], $carno, 1, $orderNo, $total_fee, $mer_chant['pre_table'], $pay_fee);

        if($use_freetime == 1) {

            // 更新会员免费时长使用时间
            $user = M('mem',$mer_chant['pre_table']);
            $user->where(array('userid'=>$this->user_userid))->save(array('parkft' => time()));
        }

        // 支付成功返回相应参数
        $return['timeStamp'] = $order_info['createtime'];
        $return['outTradeNo'] = $orderNo;
        $return['bonus'] = $total_fee;

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => $return);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 积分支付接口(对外)
     * 需要签名
     * 目前对接的轻停没有涉及到通知车场接口 因为那边不是积分 只是做的积分抵扣部分现金
     * @throws \Exception
     */
    public function paybyscorev2()
    {
        $carno = urldecode(I('carno'));
        $orderNo = I('orderno');
        $timestamp = I('timestamp');
        $sign_par = I('sign');

        if (!$carno or !$orderNo or !$this->user_openid or !$timestamp or !$sign_par) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        // 签名验证
        $sign_arr = array(
            'key_admin' => $this->ukey,
            'openid' => $this->user_openid,
            'timestamp' => $timestamp,
            'orderno' => $orderNo,
            'carno' => $carno,
            'sign_key' => $mer_chant['signkey'],
        );

        $sign = sign($sign_arr);

        // 签名错误
        if ($sign != $sign_par) {
            $data = array('code' => '1002', 'msg' => 'invalid sign!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

//        writeOperationLog(array('merchant infor' => json_encode($mer_chant)), 'jaleel_logs');
//        writeOperationLog(array('key_admin' => $this->ukey), 'jaleel_logs');

        // 查询会员信息
        $uinfo = $this->getUserCardByusercid($mer_chant['pre_table']);
        if (!$uinfo) {
            $data = array('code' => '2000', 'msg' => 'sorry,u are not our member,please go to register!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询定单信息
        $order = M('carpay_order', $mer_chant['pre_table']);
        $order_info = $order->where(array('orderno' => $orderNo))->find();

        if (!$order_info) {
            $data = array('code' => '2012', 'msg' => '无效定单!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $total_fee = $order_info['total_fee']; // 单位积分

        // 判断下单时间是否超过十分钟
        if (is_array($order_info) && time() - $order_info['createtime'] > 600) {
            $data = array('code' => '2013', 'msg' => '定单过期!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 根据会员卡号查询会员积分
        $post_data['card'] = $uinfo['cardno'];
        $post_data['key_admin'] = $this->ukey;
        $post_data['sign_key'] = $mer_chant['signkey'];
        $post_data['sign'] = sign($post_data);
        unset($post_data['sign_key']);
        $url = $this->member_url . '/CrmService/OutputApi/Index/getuserinfobycard';
        $curl_uinfo = http($url, $post_data, 'post');

        writeOperationLog(array('park car get member by card' => $curl_uinfo), 'jaleel_logs');

        $uinfo_arr = json_decode($curl_uinfo, true);

        if ($uinfo_arr['code'] != 200) {
            $data = array('code' => '2000', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

//判断用户的等级是否支持积分支付
        if($uinfo_arr['data']['cardtype'] == 72){
            $msg['code']=104;
            $msg['data']='您的会员等级暂不能使用该功能；详询：66069500';
            $msg['msg']='您的会员等级暂不能使用该功能；详询：66069500';
            returnjson($msg, $this->returnstyle, $this->callback);die;
        }

        // 判断用户的积分是否够支付
        if ($uinfo_arr['data']['score'] < $total_fee) {
            $data = array('code' => '319', 'msg' => '积分不足!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 请求扣除积分接口
        $post_arr['scoreno'] = abs($total_fee); // 单位积分
        $post_arr['cardno'] = $uinfo['cardno'];
        $post_arr['why'] = '停车支付';
        $post_arr['key_admin'] = $this->ukey;
        $post_arr['sign_key'] = $mer_chant['signkey'];
        $post_arr['sign'] = sign($post_arr);
        unset($post_arr['sign_key']);
        $url = $this->member_url . '/CrmService/OutputApi/Index/cutScore';
        $curl_re = http($url, $post_arr, 'post');
        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['code'] != 200) {
            $data = array('code' => '2014', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 更新定单状态为支付成功状态
        $up_re = $order->where(array('orderno' => $orderNo))->save(array('status' => 1));
        if ($up_re === false) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 更新定单状态为通知车场成功状态
        $up_re = $order->where(array('orderno' => $orderNo))->save(array('status' => 2));
        if ($up_re === false) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!');
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 按用户openid或支付宝userid查询用户信息
     * @param $prefix
     * @param $openid
     * @return mixed
     */
    protected function getUserCardByusercid($prefix)
    {
        $user = M('mem', $prefix);
        $where = 'wechat' == $this->from ? 'openid' : 'userid';
        $re = $user->where(array($where => $this->userucid))->find();
        return $re;
    }

    /**
     * 按用户userid查询用户信息
     * @param $prefix
     * @param $openid
     * @return mixed
     */
    protected function getUserCardByUserid($prefix, $userid)
    {
        $user = M('mem', $prefix);
        $re = $user->where(array('userid' => $userid))->find();
        return $re;
    }

    /**
     * 微信支付下单接口
     * @throws \Exception
     */
    public function paybyweixin()
    {
        $carno = urldecode(I('carno'));
        $use_freetime = I('use_freetime');
        $use_refreetime = I('use_refreetime');
        $pay_class = I('pay_class');

        if (!$carno or !$this->user_openid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 验证key_admin
        $mer_chant = $this->getMerchant($this->ukey);

        $car = $this->getCarInfo($carno, $mer_chant['signkey'], $mer_chant['pre_table']);
        $begintime = strtotime($car['data']['BeginTime']); // 停车起始时间
        $endtime = strtotime($car['data']['EndTime']); // 停车结束时间
        $client_orderno = $car['data']['orderNo']; // 停车客户端定单编号
        $total_fee = $car['data']['MoneyValue']; // 单位为分
        $pay_fee = $car['data']['MoneyValue']; // 单位为分
//        $total_fee = 1;

        // 初始化相关变量
        $discountFee = 0; // 折扣金额 单位为分
        $lowPriceFee = 0; // 低价抵扣金额
        $freeTimeFee = 0; // 免费时长抵扣金额
        $freeTime = 0; // 免费时间

        $order = M('carpay_order', $mer_chant['pre_table']);

        // 查询会员信息
        $uinfo = $this->getUserCardByusercid($mer_chant['pre_table']);

        // 停车单价 分/秒
        $park_time = $endtime - $begintime;
        $price = $car['data']['MoneyValue'] / $park_time;

        // 使用免费时长
        if ($use_freetime == 1) {
            $free_re = $this->getFreeTimeFee($mer_chant['pre_table'], $this->ukey, $car['data']['MoneyValue'], $uinfo['level'], $price);
            $free_re['ft_money'] = floor($free_re['ft_money']);
            $pay_fee = $total_fee - $free_re['ft_money'];
            $freeTime = $free_re['free_time'];
        }

        if ($pay_fee > 0) {

            // 使用消费返时长
            if ($use_refreetime = 1) {

            }
        }

        if (isset($client_orderno)) {

            // 判断订单是否已经存在 若存在则进行更新 不存在则插入 (因为目前世纪金源有车俩入场接口 入场时会插入定单)
            $order_info = $order->where(array('client_orderno' => $client_orderno))->find();
        }

        // 若全部抵扣了 则生成一个类似微信支付的定单号
        if ($pay_fee <= 0 && !$order_info) {
            $order_no = uniqid('WX') . date('YmdHis') . rand(1000, 9999);

            // 插入定单
//            $this->createOrder($order, $carno, $total_fee, 0, $order_no, $begintime, $endtime, $client_orderno, $pay_fee, $discountFee, $freeTimeFee, $lowPriceFee, $freeTime);
        }

        writeOperationLog(array('order_no：' => $order_no), 'jaleel_logs');

        if ($pay_fee > 0) {

            // 请求微信支付接口进行支付
            $post_arr['total_fee'] = $pay_fee; // 单位分
//            $post_arr['total_fee'] = 1; // 单位分
            $post_arr['attach'] = json_encode(array('carNo' => $carno, 'key_admin' => $this->ukey, 'payType' => 0, 'amount' => $total_fee, 'discountfee' => $discountFee, 'freetimefee' => $freeTimeFee, 'payfee' => $pay_fee, 'client_orderno' => $client_orderno, 'order_no' => $order_no, 'user_freetime' => $use_freetime, 'openid' => $this->user_openid));
            $post_arr['attach_transmit_tag'] = 'N';
            $post_arr['notify_url'] = C('DOMAIN') . "/ParkApp/ParkPay/confirmPay";
            $post_arr['body'] = '停车支付';
            $post_arr['appid'] = $pay_class=="applet"?$mer_chant['applet_appid']:$mer_chant['wechat_appid'];
            $post_arr['wxa_tag'] = $pay_class=="applet"?"Y":"N";

            // 查询当前商户的支付是否是子商户支付111
//            if ($mer_chant['wechat_pay_type'] == 2) {

                // 查询子商户账号
                $def_re = $this->GetOneAmindefault($mer_chant['pre_table'], $this->ukey, 'subpayacc');
                $sub_mich = $def_re['function_name'];
                $post_arr['openid'] = $this->user_openid;

                $post_arr['sign'] = $this->paySign($post_arr, $mer_chant['signkey']);
//                $post_arr['sign'] = $this->paySign($post_arr, 'bcafe711753c411898c7806b775f1d63');//西单
//                $post_arr['sign'] = $this->paySign($post_arr, '274f498cb3244c8087a81ca1393da5bb');//智慧图

//                $url = "http://123.56.103.28/pays/api/pay/wx/{$sub_mich}/JSAPI/prePay";
                $url = "http://pay.rtmap.com/pay-api/v3/wx/{$sub_mich}/jsapi/prepay";
            /*} else {
                $post_arr['openId'] = $this->user_openid;
//                $url = "http://pay.rtmap.com/pay/api/wxpay/{$mer_chant['wechat_appid']}/jsapi/unifiedorder";
                $url = "http://weix.rtmap.com/pay/api/wxpay/{$mer_chant['wechat_appid']}/jsapi/unifiedorder";
            }*/
//            $curl_re = http($url, $post_arr, 'POST');
            $curl_re = $this->curl_json($url, json_encode($post_arr));

            writeOperationLog(array('请求微信支付接口参数' => json_encode($post_arr)), 'jaleel_logs');
            writeOperationLog(array('请求微信支付请求url' => $url), 'jaleel_logs');
            writeOperationLog(array('请求微信支付回调url' => $post_arr['notify_url']), 'jaleel_logs');
            writeOperationLog(array('请求微信支付接口' => $curl_re), 'jaleel_logs');
            //writeOperationLog(array('请求微信支付接口sign字符串' => $str), 'jaleel_logs');

//        $curl_re = http($url, $post_arr, 'POST');

            $curl_arr = json_decode($curl_re, true);

            if ($curl_arr['status'] != 200) {
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }

            $return = $curl_arr['data'];
            $return['total_fee'] = $pay_fee;

            if (isset($curl_arr['data']['timeStamp'])) {
                $return['timeStamp'] = (string)$curl_arr['data']['timeStamp'];
            } else {
                $return['timeStamp'] = (string)time();
                $return['outTradeNo'] = $curl_arr['data']['ordId'];
            }
        }

        if ($pay_fee <= 0) {
            $orderNo = $order_no;
        } else {
            $orderNo = $curl_arr['data']['outTradeNo'];
        }

        if (!$order_info) {

            if ($pay_fee <= 0) {
                $pay_fee = 0;
            }

            // 插入定单
            $in_order = $this->createOrder($order, $carno, $total_fee, 0, $orderNo, $begintime, $endtime, $client_orderno, $pay_fee, $discountFee, $freeTimeFee, $lowPriceFee, $freeTime);

            /*if (!$in_order) {
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }*/
        } else {

            // 更新订单
            $save_data['orderno'] = $orderNo;
            $save_data['openid'] = $this->user_openid;
            $save_data['total_fee'] = $total_fee;
            $save_data['paytype'] = 0;
            $save_data['endtime'] = time();
            $save_data['payfee'] = $pay_fee;
            $save_data['freetime'] = $freeTime;
            $save_data['discountfee'] = $discountFee;
            $save_data['lowpricefee'] = $lowPriceFee;
            $save_data['freetimefee'] = $freeTimeFee;
            $re = $order->where(array('client_orderno' => $client_orderno))->save($save_data);

            if ($re === false) {
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }
        }

        if ($pay_fee <= 0) {

            // 通知车场
            $this->noticePark($mer_chant['signkey'], $carno, 0, $orderNo, $total_fee/100, $mer_chant['pre_table'], $pay_fee/100);

            if($use_freetime == 1) {

                // 更新会员免费时长使用时间
                $user = M('mem',$mer_chant['pre_table']);
                $user->where(array('openid'=>$this->user_openid))->save(array('parkft' => time()));
            }

            $return['outTradeNo'] = $orderNo;
            $return['total_fee'] = 0;
            $return['timeStamp'] = time();
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => $return);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 通知车场
     * @param $signkey
     * @param $carno
     * @param $paytype
     * @param $orderNo
     * @param $total_fee
     * @param $pre_table
     * @param $pay_fee
     * @return bool
     */
    protected function noticePark($signkey, $carno, $paytype, $orderNo, $total_fee, $pre_table, $pay_fee) {

        // 通知车场支付成功
        $info['key_admin'] = $this->ukey;
        $info['sign_key'] = $signkey;
        $info['carno'] = $carno;
        $info['paytype'] = $paytype;
        $info['sign'] = sign($info);
        $info['orderNo'] = $orderNo;
        $info['amount'] = $pay_fee;
        $info['discount'] = $total_fee-$pay_fee;
        unset($info['sign_key']);
        $url = C('DOMAIN') . '/Parkservice/Parkoutput/pay';
        $curl_re = http($url, $info, 'post');
        writeOperationLog(array('微信支付通知车场结果' => $curl_re), 'jaleel_logs');
        writeOperationLog(array('微信支付通知车场参数' => json_encode($info)), 'jaleel_logs');
        $confirm_re = json_decode($curl_re, true);
        if ($confirm_re['code'] != 200) {
            $data = array('code' => '2015', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 更新定单状态为通知车场成功状态
        $order = M('carpay_order', $pre_table);
        $up_re = $order->where(array('orderno' => $orderNo))->save(array('status' => 2, 'paytime' => time()));
        return $up_re;
    }

    protected function xiDanWeChatPay($discountFee, $coupons_used, $total_fee, $carno, $freeTimeFee, $lowPriceFee, $client_orderno, $orderNo = '') {

        // 按商户key_admin查询buildid
        $build_info = $this->getBuildIdByKey($this->ukey);

        //writeOperationLog(array('查询西单商户buildid' => json_encode($build_info)), 'jaleel_logs');

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['buildId'] = $build_info['buildid'];

        $pay_fee = $total_fee - $discountFee; // 计算实际应付金额 单位分
//        $pay_fee = 1; // 计算实际应付金额 单位分

        $mer_chant = $this->getMerchant($this->ukey);

        // 查询微信支付子商户账号
        $def_re = $this->GetOneAmindefault($mer_chant['pre_table'],$this->ukey,'subpayacc');
        $sub_mich = $def_re['function_name'];

        // 查询营销平台支付子商户账号
        $acc_re = $this->GetOneAmindefault($mer_chant['pre_table'],$this->ukey,'payacc');
        $mich = $acc_re['function_name'];

        // 请求微信支付接口进行支付
        $post_arr['totalFee'] = $pay_fee; // 单位分
        $post_arr['attach'] = json_encode(array('carNo' => $carno, 'key_admin' => $this->ukey, 'payType' => 0, 'amount' => $total_fee, 'discountfee' => $discountFee, 'freetimefee' => $freeTimeFee, 'payfee' => $pay_fee, 'lowpricefee' => $lowPriceFee, 'client_orderno' => $client_orderno, 'order_no' => $orderNo));
        $post_arr['notifyUrl'] = C('DOMAIN') . "/ParkApp/ParkPay/confirmPay";
        $post_arr['customerName'] = '西单大悦城';
        $post_arr['customerType'] = 2;
        $post_arr['customerId'] = $this->user_openid;
        $post_arr['merchantId'] = $mich;
        $post_arr['mainTitle'] = '停车缴费';
        $post_arr['subTitle'] = '停车缴费';
        $post_arr['itemNums'] = 1; // 购买的商户种类数
        $post_arr['type'] = 2;

        if ($pay_fee > 0) {
            $sign_arr = array(
                'totalFee' => $pay_fee,
                'type' => 1,
                'appid' => $mer_chant['wechat_appid'],
                'mchid' => $sub_mich,
                'openid' => $this->user_openid,
                'tradeType' => 'JSAPI',
            );

            $sign_arr['sign'] = $this->paySign($sign_arr, $mer_chant['signkey']);

            $post_arr['trades'] = array($sign_arr);
        }

        if (count($coupons_used) > 0) {
            foreach ($coupons_used as $v) {
                $post_arr['trades'][] = array('totalFee' => $v['price'] * $v['num'] * 100, 'quantity' => $v['num'], 'type' => 4, 'prizeId' => $v['prize_id']);
            }
        }

        //$post_arr['sign'] = $this->paySign($post_arr, $mer_chant['signkey']);

        $url = $this->url_djy . "order/submit";

        $data_string = json_encode($post_arr);
        $curl_re = $this->curl_json($url, $data_string);

        writeOperationLog(array('请求微信支付接口参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('请求微信支付请求url' => $url), 'jaleel_logs');
        writeOperationLog(array('请求微信支付回调url' => $post_arr['notifyUrl']), 'jaleel_logs');
        writeOperationLog(array('请求微信支付接口' => $curl_re), 'jaleel_logs');
        /*$curl_arr = json_decode($curl_re, true);

        if (!is_array($curl_arr) or $curl_arr['status'] != 200) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
            exit;
        }

        $return_data = $curl_arr['data'];
        $return_data['total_fee'] = $pay_fee;
        $return_data['timeStamp'] = (string)$return_data['timeStamp'];

        $data = array('code' => '200', 'msg' => 'success!', 'data' => $return_data);
        returnjson($data, $this->returnstyle, $this->callback);
        exit;*/
        return $curl_re;
    }

    /**
     * 生成订单
     * @param $order
     * @param $carno
     * @param $total_fee
     * @param $paytype
     * @param $orderNo
     * @param $begintime
     * @param $endtime
     * @param $client_orderno
     * @param $payfee
     * @param string $discountfee
     * @param string $freetimefee
     * @param string $lowpricefee
     * @param string $freetime
     * @return mixed
     */
    protected function createOrder($order, $carno, $total_fee, $paytype, $orderNo, $begintime, $endtime, $client_orderno, $payfee, $discountfee = '', $freetimefee = '', $lowpricefee = '', $freetime = '')
    {

        $order_arr['orderno'] = $orderNo;
        if($this->user_userid) {
            $order_arr['userid'] = $this->user_userid;
        }else{
            $order_arr['openid'] = $this->user_openid;
        }
        $order_arr['carno'] = strtoupper($carno);
        $order_arr['total_fee'] = $total_fee;
        $order_arr['paytype'] = $paytype;
        $order_arr['client_orderno'] = $client_orderno;
        $order_arr['begintime'] = $begintime;
        $order_arr['endtime'] = $endtime;
        $order_arr['createtime'] = time();
        $order_arr['payfee'] = $payfee;
        $order_arr['discountfee'] = $discountfee;
        $order_arr['freetimefee'] = $freetimefee;
        $order_arr['lowpricefee'] = $lowpricefee;
        $order_arr['freetime'] = $freetime;
        $in_order = $order->add($order_arr);
        return $in_order;
    }

    /**
     * 获取折扣低价免额
     * @param $pre_table
     * @param $key_admin
     * @param $total_fee
     * @param $grade
     * @param $openid
     * @return array|bool
     */
    protected function getDiscountFee($pre_table, $key_admin, $total_fee, $grade, $openid)
    {

        // 查询折扣配置
        $dis_data = $this->GetOneAmindefault($pre_table, $key_admin, 'discountConf');

        // 如json
        // {"discount":{"1":"0.95","2":"0.9","3":"0.85","4":0}}
        // {"lowPrice":{"1":{"price":300,"time":1},"2":{"price":200,"time":1},"3":{"price":100,"time":1},"4":{"price":1,"time":1}}}
        $dis_arr = json_decode($dis_data, true);

        if (is_array($dis_arr)) {
            $discount_fee = 0;
            if (!empty($dis_arr['discount'])) {
                $discount_fee = $total_fee * (1 - $dis_arr['discount'][$grade]);
            }

            $lowPrice = 0;
            if (!empty($dis_arr['lowPrice'])) {

                // 判断今天低价使用次数是否用完
                $order = M('carpay_order', $pre_table);
                $start = strtotime(date('Y-m-d') . ' 00:00:00');
                $end = strtotime(date('Y-m-d') . ' 23:59:59');
                $order_num = $order->where(array('openid' => $openid, 'paytime' => array('between', "{$start},{$end}"), 'lowpricefee' => array('gt', 0)))->count('id')->select();

                if ($dis_arr['lowPrice'][$grade]['time'] < $order_num) {
                    $lowPrice = $total_fee - $dis_arr['lowPrice'][$grade]['price'];
                }
            }

            return array(
                'discount' => array(
                    'discount' => $dis_arr['discount'][$grade] * 10, // 折扣
                    'discountFee' => $discount_fee // 折扣金额
                ),
                'lowPrice' => array(
                    'lowPrice' => $dis_arr['lowPrice'][$grade]['price'], // 低价额度
                    'lowPriceFee' => $lowPrice // 低价优惠额度
                )
            );
        }
        return false;
    }

    /**
     * 获取免费时长额度
     * @param $pre_table
     * @param $key_admin
     * @param $total_fee
     * @param $grade
     * @param $price
     * @return array|bool
     */
    protected function getFreeTimeFee($pre_table, $key_admin, $total_fee, $grade, $price)
    {

        // 查询免费时长配置
        $free_data = $this->GetOneAmindefault($pre_table, $key_admin, 'memberfreetime');

        writeOperationLog(array('get member free time' => json_encode($free_data)), 'jaleel_logs');

        // 如json:
        // [{"id":"2","level":"\u94f6\u5361","val":"2"},{"id":"3","level":"\u91d1\u5361","val":"3"}]

        $free_arr = json_decode($free_data['function_name'], true);

        if (is_array($free_arr)) {

            foreach ($free_arr as $v) {
                if ($v['id'] == $grade) {
                    $free_time = $v['val'] * 60 * 60; //单位为秒
                }
            }

            $ft_money = $free_time * $price;

            return array('ft_money' => $ft_money, 'free_time' => $free_time);
        }
        return false;
    }

    /**
     * 停车缴费支付回调通知接口(用于微信支付成功后的回调)
     * @return array
     * @throws \Exception
     */
    public function confirmPay()
    {

        /**
         * 此处接收的是json字符串
         * 注意不能使用TP中的I函数
         * 因为会被转义
         * 转义后无法使用json_decode函数转换成数组
         */
        $content = file_get_contents("php://input");
        writeOperationLog(array('停车缴费回调参数' => $content), 'ParkPayCommon');
        $par_arr = json_decode($content, true);
        $attach = json_decode(urldecode($par_arr['attach']), true);
        writeOperationLog(array('停车缴费回调参数attach' => json_encode($attach)), 'ParkPayCommon');

        if ($attach['payfee'] == 0) {
            $orderNo = $attach['order_no'];
        } else {
            $orderNo = $par_arr['out_trade_no'];
        }

        writeOperationLog(array('停车缴费回调参数orderNo' => $orderNo), 'ParkPayCommon');

        $carNo = $attach['carNo'];
        $key_admin = $attach['key_admin'];
        $payType = $attach['payType'];
        $amount = $attach['amount'];
        $discount = $amount - $attach['payfee'];
        $client_orderno = $attach['client_orderno'];

        $mer_chant = $this->getMerchant($key_admin);

        writeOperationLog(array('停车缴费回调参数merchan' => json_encode($mer_chant)), 'jaleel_logs');
        
        $order = M('carpay_order', $mer_chant['pre_table']);
        /*$order_info = $order->where(array('orderno' => $orderNo))->find();

        if (!$order_info) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }*/

        // 更新定单状态为支付成功状态
        $up_re = $order->where(array('orderno' => $orderNo))->save(array('status' => 1, 'pay_time' => time()));
        /*if ($up_re === false) {
            $data = array('code' => '1011', 'msg' => 'system error!');
            returnjson($data, $this->returnstyle, $this->callback);
        }*/

        $data['key_admin'] = $key_admin;
        $data['sign_key'] = $mer_chant['signkey'];
        $data['carno'] = $carNo;
        $data['paytype'] = $payType;
        $data['sign'] = sign($data);
        $data['orderNo'] = $client_orderno;
        $data['amount'] = $attach['payfee'] / 100;
        $data['discount'] = $discount / 100;
        unset($data['sign_key']);
        $url = C('DOMAIN') . '/Parkservice/Parkoutput/pay';
        $curl_re = http($url, $data, 'post');
        writeOperationLog(array('通知车场支付成功结果' => $curl_re), 'jaleel_logs');
        $data = json_decode($curl_re, true);

        // 更新定单状态为通知车场成功状态 西单暂时没有订单相关信息
//        if ($data['code'] == 200 && $key_admin != 'e4273d13a384168962ee93a953b58ffd') {
        if ($data['code'] == 200) {
            $order = M('carpay_order', $mer_chant['pre_table']);
            $up_re = $order->where(array('orderno' => $orderNo))->save(array('status' => 2, 'pay_time' => time()));
            //writeOperationLog(array('通知车场后更改支付状态sql' => $order->_sql()), 'jaleel_logs');
            writeOperationLog(array('通知车场后更改支付状态结果' => $up_re), 'jaleel_logs');
            /*if ($up_re === false) {
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }*/
        }

        if($attach['use_freetime'] == 1) {
            // 更新会员免费时长使用时间
            $user = M('mem',$mer_chant['pre_table']);
            //$user->where(array('openid'=>$attach['openid']))->save(array('parkft' => time()));
        }

        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 车辆入场记录接口
     */
    public function carenter()
    {
        $carno = I('carno');

        if (!$carno) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $mer_chant = $this->getMerchant($this->ukey);
        $action = M('car_action', $mer_chant['pre_table']);
        $data['carno'] = $carno;
        $data['action'] = 1;
        $data['createtime'] = time();
        $re = $action->add($data);

        if (!$re) {
            $data = array('code' => '1011', 'msg' => 'system error');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!');
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 车辆出场记录接口
     */
    public function carexit()
    {
        $carno = I('carno');

        if (!$carno) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $mer_chant = $this->getMerchant($this->ukey);
        $action = M('car_action', $mer_chant['pre_table']);
        $data['carno'] = $carno;
        $data['action'] = 0;
        $data['createtime'] = time();
        $re = $action->add($data);

        if (!$re) {
            $data = array('code' => '1011', 'msg' => 'system error');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!');
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 获取会员免费停车时长(对外)
     * 需要签名验证
     * @param key_dmin 商户key
     * @param user_openid 会员openid
     */
    public function getFreeParkTime()
    {
        if (!$this->ukey or !$this->user_openid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        $static = M('total_static');

        // 查询会员卡样 根据卡样的的等级和用户是哪个等级来确定会员免费停车时长
        $static->where(array('admin_id' => $mer_chant['id'], 'tid' => '5'))->find();
    }

    /**
     * 免费停车时长核销接口(对外)
     * 需要签名验证
     */
    public function checkFreePark()
    {
    }

    /**
     * 调用CRM按卡号查询会员接口来查询会员信息
     * @param $cardno
     * @param $key_admin
     * @param $signkey
     * @return mixed
     * @throws \Exception
     */
    protected function getUserInfo($cardno, $key_admin, $signkey)
    {
        $post_data['card'] = $cardno;
        $post_data['key_admin'] = $key_admin;
        $post_data['sign_key'] = $signkey;
        $post_data['sign'] = sign($post_data);
        unset($post_data['sign_key']);
//        $url = C('DOMAIN') . '/CrmService/OutputApi/Index/getuserinfobycard';
        $url = 'http://mem.rtmap.com/CrmService/OutputApi/Index/getuserinfobycard';
        $curl_uinfo = http($url, $post_data, 'post');

        writeOperationLog(array('park car get member by card' => $curl_uinfo), 'jaleel_logs');

        $uinfo_arr = json_decode($curl_uinfo, true);
        return $uinfo_arr;
    }

    /**
     * 查询会员停车缴费订单
     * 此处只查询缴费成功的订单
     * @param key_dmin 商户key
     * @param user_openid 会员openid
     * @param page 分页页码
     */
    public function getParkOrderLists()
    {
        $page = I('page'); // 接收分页页码

        // 验证为空性
        if (!$this->ukey or !$this->user_userid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        // 分页查询 每页显示十条数据
        $page = isset($page) ? $page : 1;

        $order = M('carpay_order', $mer_chant['pre_table']);

        // status为2代表支付成功并通知车场成功
        $orders = $order->where(array('userid' => $this->user_userid, 'status' => 2))->order('createtime')->page($page, 10)->select();

        // 将实际支付金额转换成元
        if (is_array($orders) && count($orders) > 0) {
            foreach ($orders as $k => $v) {
                foreach ($v as $key => $val) {
                    if ($key == 'payfee') {
                        $orders[$k][$key] = $val / 100;
                    }
                }
            }
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => $orders);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 查询缴费订单详情
     * @param key_admin 商户key
     * @param user_openid 会员openid
     * @param orderNo 订单编号
     */
    public function getParkOderDetails()
    {
        $orderNo = I('orderNo');

        // 验证为空性
        if (!$this->ukey or !$orderNo) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);
        $detail_obj = M('carpay_order', $mer_chant['pre_table']);
        $details = $detail_obj->where(array('orderno' => $orderNo))->find();

        if (!$details) {
            $data = array('code' => '1011', 'msg' => '没有相关数据!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success', 'data' => $details);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 停车缴费标准数据提供接口
     * @param key_admin 商户key
     */
    public function getParkIntro()
    {

        // 验证为空性
        if (!$this->ukey) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 查询商户信息
        $mer_chant = $this->getMerchant($this->ukey);

        $intro_obj = M('default', $mer_chant['pre_table']);
        $intro = $intro_obj->where(array('customer_name' => 'carpayintro'))->find();

        if (!$intro) {
            $data = array('code' => '1011', 'msg' => '没有相关数据!');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $intro['function_name'] = htmlspecialchars_decode($intro['function_name']);

        $data = array('code' => '200', 'msg' => 'success', 'data' => $intro);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 上传图片到七牛接口
     */
    public function uploadToQiNiu()
    {
        $file = I('file');
        $name = I('name');

        // 对商场Logo上传到七牛上 然后返回相应的url
        $qi = new QiniuController();
        $re = $qi->qiniu_fetch($file, 'img/merLogo/' . $name);

        if (is_array($re) && isset($re[0]['key'])) {
            echo 'https://oe5n68bv6.qnssl.com/' . $re[0]['key'];
            exit;
        }

        echo 'upload file failed!';
        exit;
    }

    /**
     * ////////////////////////////////////////////////////
     * 以下接口是针对西单大悦城而开发 后续可能扩展到其他的商场
     * 针对停车缴费所设定的相关优惠券购买使用激活等相关操作
     * ///////////////////////////////////////////////////
     */

    /**
     * 获取商户所有停车券
     * 此接口获取的是商场针对停车所设定的可购买的优惠券券种列表
     * @throws \Exception
     */
    public function getParkCouponType()
    {

        // 验证为空性
        if (!$this->ukey) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['build_id'] = $build_info['buildid'];
        $post_data['coupon_type'] = 6; // 0：折扣券(APP专用)；1：礼品券；2：代金券；3：广告券；4：优惠券；5：红包券 ; 6：停车券 7：团购券
        $url = $this->url_hzt . 'coupon/list';
        $curl_re = http($url, $post_data);

        writeOperationLog(array('park car get coupon type list' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        if ($curl_arr['data'] == '') {
            $curl_arr['data'] = array();
        }

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 可购买的停车券详情接口
     * @param string $prize_id
     * @param string $from
     * @return mixed
     */
    public function getParkCouponDetails($prize_id = '', $from = '') {

        if ($prize_id == '') {
            $prize_id = I('prize_id'); // 停车券ID
        }

        // 验证为空性
        if (!$prize_id) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['prize_id'] = $prize_id;

        $url = $this->url_hzt . 'coupon/detail';
        $curl_re = $this->curl_json($url, json_encode($post_data));

        writeOperationLog(array('park car get coupon details' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        // 内部调用直接返回数组
        if ($from == 'inside') {
            return $curl_arr;
        }

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 激活停车券接口
     * @throws \Exception
     */
    public function activateParkCoupon()
    {
        $active_code = I('active_code');

        // 验证为空性
        if (!$this->user_openid or !$active_code) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['open_id'] = $this->user_openid;
        $post_data['active_code'] = $active_code;

        $url = $this->url_djy . 'coupon/active';
        $curl_re = $this->curl_json($url, json_encode($post_data));

        writeOperationLog(array('park car activate coupon param' => json_encode($post_data)), 'jaleel_logs');
        writeOperationLog(array('park car activate coupon' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message'], 'data' => array());
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 获取我的停车券接口
     * @throws \Exception
     */
    public function getMyParkCoupon()
    {
        $status = (int)I('status'); // 0：未使用 1：已使用

        $status = isset($status) ? $status : 0;

        // 验证为空性
        if (!$this->user_openid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['build_id'] = $build_info['buildid'];
        $post_data['open_id'] = $this->user_openid;
        $post_data['status'] = $status;
        $post_data['coupon_type'] = 6; // 券类型：0、折扣券(APP专用)；1、礼品券；2、代金券；3、广告券；4、优惠券；5、红包券 ; 6、停车券 7：团购券

        $url = $this->url_hzt . 'coupon/card';
        $curl_re = http($url, $post_data, 'post');

        writeOperationLog(array('park car get my coupon param' => json_encode($post_data)), 'jaleel_logs');
        writeOperationLog(array('park car get my coupon' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['data'] == '') {
            $curl_arr['data'] = array();
        }

        /*foreach ($curl_arr['data'] as $k=>$v) {
            if ($v['status'] == 2) {
                $curl_arr['data'][$k]['status'] = 0;
            } else if ($v['status'] == 3) {
                $curl_arr['data'][$k]['status'] = 1;
            }
        }*/

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 生成将要分享的停车券码接口
     */
    public function createActivateCode() {
        $post_data = array();
        $url = $this->url_hzt . 'coupon/code';
        $curl_re = http($url, $post_data, 'post');

        writeOperationLog(array('park car create park activate code' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 转赠停车券接口
     * @throws \Exception
     */
    public function shareParkCoupon()
    {
        $donate_num = I('donate_num'); // 转赠的停车券数量
        $active_code = I('active_code'); // 激活码
        $prize_id = I('prize_id'); // 停车券id
        $type = I('type'); // 分享方式：0：分享到朋友圈 1：分享给好友

        // 验证为空性
        if (!$this->user_openid or !$donate_num or !$active_code or !$prize_id) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['open_id'] = $this->user_openid;
        $post_data['donate_num'] = $donate_num;
        $post_data['active_code'] = $active_code;
        $post_data['prize_id'] = $prize_id;
        $post_data['type'] = $type;

        $url = $this->url_djy . 'coupon/donate';
        $curl_re = $this->curl_json($url, json_encode($post_data));

        writeOperationLog(array('park car share my coupon' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 获得我的停车券详情接口
     * @throws \Exception
     */
    public function myParkCouponDetails()
    {
        $prize_id = I('prize_id'); // 券ID
        $status = I('status'); // 券状态

        $status = isset($status) ? $status : 0;

        // 验证为空性
        if (!$this->user_openid or !$prize_id or !$this->ukey) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['build_id'] = $build_info['buildid'];
        $post_data['open_id'] = $this->user_openid;
        $post_data['status'] = $status;
        $post_data['prize_id'] = $prize_id;
        $post_data['coupon_type'] = 6;

        $url = $this->url_hzt . 'coupon/card/detail';
        $curl_re = http($url, $post_data, 'post');

        writeOperationLog(array('park car get my coupon details param' => json_encode($post_data)), 'jaleel_logs');
        writeOperationLog(array('park car get my coupon details' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        /*if ($curl_arr['data']['status'] == 2) {
            $curl_arr['data']['status'] = 0;
        } else if ($curl_arr['data']['status'] == 3) {
            $curl_arr['data']['status'] = 1;
        }*/

        $data = array('code' => '200', 'msg' => 'success', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 使用用户所拥有的优惠券进行停车支付
     * @param $fee // 总支付金额
     * @param $openid
     * @return array
     */
    protected function parkPayByCoupon($fee, $openid) {
        $total_fee = $fee; // 付款总额
        $pay_fee = $total_fee; // 实际付款金额
        $discount_fee = 0; // 折扣金额
        $coupons_used = array(); // 存储将要使用的券信息数组

        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_data['build_id'] = $build_info['buildid'];
        $post_data['open_id'] = $openid;
        $post_data['status'] = 2; // 2：未使用 3：已使用
        $post_data['coupon_type'] = 6; // 券类型：0、折扣券(APP专用)；1、礼品券；2、代金券；3、广告券；4、优惠券；5、红包券 ; 6、停车券 7：团购券

        $url = $this->url_hzt . 'coupon/card';

        $coupons_json = http($url, $post_data, 'post');

        writeOperationLog(array('search my park coupon param' => json_encode($post_data)), 'jaleel_logs');
        writeOperationLog(array('search my park coupon result' => $coupons_json), 'jaleel_logs');

        $coupons_arr = json_decode($coupons_json, true);

        foreach ($coupons_arr['data'] as $k => $v) {
            $need_num = ceil($pay_fee / $v['price']); // 所需要的停车券的数量
            $coupons_used[$k] = $v; // 所使用的停车券的信息 包含使用的数量

            if ($need_num <= $v['num']) {
                $discount_fee = $total_fee;
                $pay_fee = 0;
                $coupons_used[$k]['num'] = $need_num;
                break;
            } else {
                $pay_fee = $pay_fee - $v['price'] * $v['num'];
                $discount_fee += $v['price'] * $v['num'];
            }
        }

        return array('payFee' => $pay_fee, 'discountFee' => $discount_fee, 'couponsUsed' => $coupons_used);
    }

    /**
     * 购买停车券下单接口
     */
    public function createCouponOrder() {
        $prize_id = I('prize_id');
        $num = I('num');
        $uname = I('uname');

        // 验证为空性
        if (!$this->user_openid or !$prize_id or !$this->ukey or !$num or !$uname) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 验证key_admin
        $mer_chant = $this->getMerchant($this->ukey);

        // 按商户key_admin查询buildid
        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $buildId = $build_info['buildid'];

        // 查询营销平台子商户账号
        $acc_re = $this->GetOneAmindefault($mer_chant['pre_table'],$this->ukey,'payacc');
        $mich = $acc_re['function_name'];

        // 查询停车券信息
        $coupon_info = $this->getParkCouponDetails($prize_id, 'inside');
        $total_fee = $coupon_info['data']['money'] * $num * 100; // 计算实际应付金额 单位分

        $post_arr['customerId'] = $this->user_openid;
        $post_arr['buildId'] = $buildId;
        $post_arr['customerName'] = $uname;
        $post_arr['customerType'] = 2; // 消费者类型：1.商户、2.微信用户
        $post_arr['type'] = 13; // 订单类型：2.付款订单、3.转赠订单、11.商户购买订单、12.商城购买订单、13.个人购买订单、31.商户转赠、32、个人转赠
        $post_arr['totalFee'] = $total_fee;
        $post_arr['hasReceipt'] = 0;
        $post_arr['itemNums'] = 1;
        $post_arr['items'] = array(
            array(
                'productId'     => $prize_id,
                'merchantId'    => $mich,
                'quantity'      => $num,
                'fee'           => $coupon_info['data']['money'] * 100,
                'totalFee'      => $total_fee,
                'activity_id'   => 46,
            )
        );

        $url = $this->url_djy . 'order/submit';
        $curl_re = $this->curl_json($url, json_encode($post_arr));

        writeOperationLog(array('购买停车券下单接口参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('购买停车券下单url' => $url), 'jaleel_logs');
        writeOperationLog(array('请求购买停车券下单接口' => $curl_re), 'jaleel_logs');

        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    /**
     * 购买停车券接口
     * @throws \Exception
     */
    public function buyParkCoupon() {
        $orderId = I('order_id');
        $num = I('num');
        $prize_id = I('prize_id');

        // 验证为空性
        if (!$this->user_openid or !$orderId or !$this->ukey or !$num or !$prize_id) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        // 验证key_admin
        $mer_chant = $this->getMerchant($this->ukey);

        // 按商户key_admin查询buildid
        $build_info = $this->getBuildIdByKey($this->ukey);

        if (!isset($build_info['buildid'])) {
            $data = array('code' => '1001', 'msg' => 'invalid key_admin');
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $post_arr['buildId'] = $build_info['buildid'];

        // 查询子商户账号
        $def_re = $this->GetOneAmindefault($mer_chant['pre_table'],$this->ukey,'subpayacc');
        $sub_mich = $def_re['function_name'];

        // 查询停车券信息
        $coupon_info = $this->getParkCouponDetails($prize_id, 'inside');
        $pay_fee = $coupon_info['data']['money'] * $num * 100; // 计算实际应付金额 单位分

        // 请求微信支付接口进行支付
        $post_arr['orderId'] = $orderId;
        $post_arr['totalFee'] = $pay_fee; // 单位分
        $post_arr['customerType'] = 2;
        $post_arr['customerId'] = $this->user_openid;
        $post_arr['customerName'] = '西单大悦城';
        $post_arr['mainTitle'] = '悦米停车';
        $post_arr['subTitle'] = '停车券购买';
        $post_arr['notifyUrl'] = '';
        $post_arr['attach'] = '';
        $post_arr['type'] = 2;
        $post_arr['merchantId'] = $sub_mich;
        $post_arr['trades'] = array(
            array(
                'category' => 1, // 交易分类：1.微信、2.支付宝、3.银联、4.优惠券、5.礼品卡、6.积分
                'appid' => $mer_chant['wechat_appid'],
                'total_fee' => $pay_fee,
                'type' => 1, // 交易类型：1.支付、2.退款
                'mchid' => $sub_mich,
                'openid' => $this->user_openid,
                'body' => '购买悦米停车券',
                'tradeType' => 'JSAPI',
            ),
        );

        $post_arr['trades'][0]['sign'] = $this->paySign($post_arr['trades'], $mer_chant['signkey']);

//        echo json_encode($post_arr);die;
        $url = $this->url_djy . "pay/submit";
        $curl_re = $this->curl_json($url, json_encode($post_arr));
        writeOperationLog(array('购买停车券请求微信支付接口参数' => json_encode($post_arr)), 'jaleel_logs');
        writeOperationLog(array('购买停车券请求微信支付请求url' => $url), 'jaleel_logs');
        writeOperationLog(array('购买停车券请求微信支付接口' => $curl_re), 'jaleel_logs');
        //writeOperationLog(array('请求微信支付接口sign字符串' => $str), 'jaleel_logs');
        $curl_arr = json_decode($curl_re, true);

        if ($curl_arr['status'] != 200) {
            $data = array('code' => $curl_arr['status'], 'msg' => $curl_arr['message']);
            returnjson($data, $this->returnstyle, $this->callback);
        }

        $curl_arr['data']['total_fee'] = $pay_fee;

        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data' => $curl_arr['data']);
        returnjson($data, $this->returnstyle, $this->callback);
    }

    public function getInvoice() {

    }

    /**
     * 微信支付签名
     * @param $data
     * @param $key
     * @return mixed
     */
    protected function paySign($data, $key) {
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

    /**
     * 查询会员卡号
     * @param $openid
     * @return mixed
     */
    public function getUserCard($openid) {
        $post_data['openid'] = $openid;
        $url = 'http://fw.joycity.mobi/kaapi/Api/Index/getcard';
        $json_re = http($url, $post_data, 'POST');
        $arr_re = json_decode($json_re, true);

        return $arr_re['data']['user_card'];
    }

    /**
     * 支付宝支付下单接口
     * @throws \Exception
     */
    public function paybyalipay()
    {
        $carno = urldecode(I('carno'));
        $use_freetime = I('use_freetime');
        $return_url = I('return_url');
        $use_refreetime = I('use_refreetime');
        $return_url = I('return_url');
        if (!$carno or !$this->user_userid) {
            $data = array('code' => '1030', 'msg' => 'miss params');
            returnjson($data, $this->returnstyle, $this->callback);
        }
        // 验证key_admin
        $mer_chant = $this->getMerchant($this->ukey);

        $car = $this->getCarInfo($carno, $mer_chant['signkey'], $mer_chant['pre_table']);
        $begintime = strtotime($car['data']['BeginTime']); // 停车起始时间
        $endtime = strtotime($car['data']['EndTime']); // 停车结束时间
        $client_orderno = (string)$car['data']['orderNo']; // 停车客户端定单编号
        $total_fee = (int)$car['data']['MoneyValue']; // 单位为分
        $pay_fee = (int)$car['data']['MoneyValue']; // 单位为分
        // 初始化相关变量
        $discountFee = 0; // 折扣金额 单位为分
        $lowPriceFee = 0; // 低价抵扣金额
        $freeTimeFee = 0; // 免费时长抵扣金额
        $freeTime = 0; // 免费时间

        $order = M('carpay_order', $mer_chant['pre_table']);

        // 查询会员信息
        $uinfo = $this->getUserCardByUserid($mer_chant['pre_table'], $this->user_userid);

        // 停车单价 分/秒
        $park_time = $endtime - $begintime;
        $price = $car['data']['MoneyValue'] / $park_time;

        // 使用免费时长
        if ($use_freetime == 1) {
            $free_re = $this->getFreeTimeFee($mer_chant['pre_table'], $this->ukey, $car['data']['MoneyValue'], $uinfo['level'], $price);
            $free_re['ft_money'] = floor($free_re['ft_money']);
            $pay_fee = (int)($total_fee - $free_re['ft_money']);
            $freeTime = $free_re['free_time'];
        }

        if ($pay_fee > 0) {
            // 使用消费返时长
            if ($use_refreetime = 1) {

            }
        }
        if (!empty($client_orderno)) {
            // 判断订单是否已经存在 若存在则进行更新 不存在则插入 (因为目前世纪金源有车俩入场接口 入场时会插入定单)
            $order_info = $order->where(array('client_orderno' => $client_orderno))->find();
        }
        // 若全部抵扣了 则生成一个类似微信支付的定单号
        $pay_fee = (int)$pay_fee;
        $order_no = uniqid('ALI') . date('YmdHis') . rand(1000, 9999);
        writeOperationLog(array('order_no：' => $order_no), 'ParkPayCommon');
        if ($pay_fee > 0) {

            // 请求微信支付接口进行支付
            $post_arr['total_amount'] = (int)$pay_fee; // 单位分
            $post_arr['attach'] = urlencode(json_encode(array('carNo' => $carno, 'key_admin' => $this->ukey, 'payType' => 0, 'amount' => $total_fee, 'discountfee' => $discountFee, 'freetimefee' => $freeTimeFee, 'payfee' => $pay_fee, 'client_orderno' => $client_orderno, 'order_no' => $order_no, 'user_freetime' => $use_freetime, 'userid' => $this->user_userid)));
            $post_arr['subject'] = '支付宝停车支付';
            $post_arr['notify_url'] = C('DOMAIN') . "/ParkApp/ParkPayCommon/confirmPay";
            $post_arr['body'] = '支付宝停车支付';
            $post_arr['return_url'] = $return_url;
            $post_arr['out_trade_no'] = $order_no;
            // 查询子商户账号
            $def_re = $this->GetOneAmindefault($mer_chant['pre_table'], $this->ukey, 'alipay_payconf');
            $sub_mich = json_decode($def_re['function_name'],true);
            $post_arr['sign'] = $this->paySign($post_arr, $sub_mich['signkey']);
            $url = "http://pay.rtmap.com/pay-api/v3/alipay/{$sub_mich['merchant']}/wappay/prepay";
            $curl_re = $this->curl_json($url, json_encode($post_arr));

            writeOperationLog(array('请求alipay支付接口参数' => json_encode($post_arr)), 'ParkPayCommon');
            writeOperationLog(array('请求alipay支付请求url' => $url), 'ParkPayCommon');
            writeOperationLog(array('请求alipay支付接口' => $curl_re), 'ParkPayCommon');
            if (is_json($curl_re)){
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }

        }

        $orderNo = $order_no;
        if (!$order_info) {
            if ($pay_fee <= 0) {
                $pay_fee = 0;
            }
            // 插入定单
            $in_order = $this->createOrder($order, $carno, $total_fee, 0, $orderNo, $begintime, $endtime, $client_orderno, $pay_fee, $discountFee, $freeTimeFee, $lowPriceFee, $freeTime);
        } else {
            if (!empty($client_orderno)) {
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }
            // 更新订单
            $save_data['orderno'] = $orderNo;
            $save_data['userid'] = $this->user_userid;
            $save_data['total_fee'] = $total_fee;
            $save_data['paytype'] = 0;
            $save_data['endtime'] = time();
            $save_data['payfee'] = $pay_fee;
            $save_data['freetime'] = $freeTime;
            $save_data['discountfee'] = $discountFee;
            $save_data['lowpricefee'] = $lowPriceFee;
            $save_data['freetimefee'] = $freeTimeFee;
            $re = $order->where(array('client_orderno' => $client_orderno))->save($save_data);

            if ($re === false) {
                $data = array('code' => '1011', 'msg' => 'system error!');
                returnjson($data, $this->returnstyle, $this->callback);
            }
        }
        if ($pay_fee <= 0) {

            // 通知车场
            $this->noticePark($mer_chant['signkey'], $carno, 0, $orderNo, $total_fee/100, $mer_chant['pre_table'], $pay_fee/100);
            if($use_freetime == 1) {
                // 更新会员免费时长使用时间
                $user = M('mem',$mer_chant['pre_table']);
                //$user->where(array('openid'=>$this->user_openid))->save(array('parkft' => time()));
            }
        }
        $return = array();
        $return['total_fee'] = $pay_fee;
        $return['timeStamp'] = (string)time();
        $return['outTradeNo'] = $order_no;
        $return['form'] = $curl_re;
        $data = array('code' => '200', 'msg' => 'SUCCESS!', 'data'=>$return);
        returnjson($data, $this->returnstyle, $this->callback);
    }

}
