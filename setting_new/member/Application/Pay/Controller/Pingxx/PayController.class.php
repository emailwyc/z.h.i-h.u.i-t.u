<?php
/**
 * Created by PhpStorm.
 * User: zhangkaifeng
 * Date: 2017/5/25
 * Time: 15:20
 */

namespace Pay\Controller\Pingxx;


use Common\Controller\CommonController;
use Coupon\Controller\IndexController;
use Pingpp\Charge;
use Pingpp\Error\Base;
use Pingpp\Order;
use Pingpp\Pingpp;

class PayController extends CommonController
{
    public function _initialize()
    {
        parent::__initialize(); // TODO: Change the autogenerated stub
        include_once './Class/pingpp-php/init.php';
    }


    /**
     * 带有券的支付订单创建
     */
    public function createChargeCoupon()
    {
        $params['openid'] = I('openid');//openid
        $params['amount'] = (float)I('amount');//订单总金额
        $params['buildid'] = I('buildid');
        $params['channel'] = I('channel');//订单支付类型：支付宝，微信，小程序等等
        $params['key_admin'] = I('key_admin');
        $params['poiid'] = I('poiid');
        if (in_array('', $params)) {
            returnjson(array('code'=>1030),$this->returnstyle,$this->callback);
        }
        $params['couponqr'] = I('couponqr');//券qr值
        $params['shopname'] = I('shopname');//商户名称
        /**
         * 获取商户和此商户的pingxx配置信息
         */
        $admininfo = $this->getMerchant($this->ukey);//商户信息
        $pingxxConfig = $this->getPingxxConfig($admininfo, $params['buildid']);
//        dump($pingxxConfig);die;



        //支付总金额小于0，错误
        if ($params['amount'] <= 0 ) {
            returnjson(array('code'=>5001),$this->returnstyle,$this->callback);
        }



        $status = 0;
        //判断有没有传qr（要不要用券核销）,计算总金额减去券的面值后的实际支付金额
        if ($params['couponqr']){
            if ($params['amount'] > 0) {//订单总额大于0时，计算总额减去券的差

                //现获取券价值，以免核销后获取价值失败
                $url = 'http://101.201.175.219/promo/mini/apps/prize/bag/detail';
                $curl = http($url, array('qrCode'=>$params['couponqr']));
                if (!is_json($curl)){//请求接口错误
                    returnjson(array('code'=>101, 'data'=>$curl),$this->returnstyle,$this->callback);
                }
                $arr = json_decode($curl, true);
                if ($arr['status'] == 200){
                    $arr = $arr['data'];
                    $arr['main'] = $arr['main_info'];
                }else{
                    returnjson(array('code'=>101, 'data'=>$curl),$this->returnstyle,$this->callback);
                }

                //判断使用门槛
                if ($params['amount'] < $arr['condition_price']){
                    returnjson(array('code'=>1502, 'data'=>$curl),$this->returnstyle,$this->callback);
                }

                if (!$arr['price']){//如果营销平台没有返回抵扣券金额，则默认抵扣0，否则抵扣金额为实际券的抵扣金额，在此做一个判断
                    $arr['price'] =0;
                }


                //获取完券信息后，核销券
//                $verifycoupon = $this->verifyCoupon($params['couponqr']);
//                if ($verifycoupon === true){
                    $amount = round($params['amount']-(float)$arr['price'],2);
                    if ($amount <= 0){
                        $amount = 0;
                        $verifycoupon = $this->verifyCoupon($params['couponqr']);
                        $status = 1;
                    }
                    $pingxx['amount'] = $amount * 100;//元换算为分
//                }else{//如果返回的不是bool类型true
//                    returnjson(array('code'=>$verifycoupon['code']),$this->returnstyle,$this->callback);
//                }
            }

            if ($params['amount'] == 0 ) {//且直接支付
                $pingxx['amount'] = $params['amount'];

            }
        }else{//如果没有传递qr值，直接拿总金额计算实际金额
            $pingxx['amount'] = $params['amount']*100;//将人民币元换为人民币分
            $arr['main'] = '购买商品';//给一个默认值，下面的pingxx的charge用
        }


        switch ($params['channel']) {
            case 'wx_lite':
                $extra = array(
                    'open_id' => $params['openid']// 请求参数中的open_id
                );
                $pingxx['orderNO'] = date('YmdHis').substr(md5(time()), 0, 12);
                break;
            default:
                returnjson(array('code'=>1501),$this->returnstyle,$this->callback);
                break;
        }
        //获取商场信息
        $buildid_db=M('total_buildid');
        $buildid_arr=$buildid_db->where(array('buildid'=>array('eq',$params['buildid'])))->find();
        
        try {
            $metadata = array(
                'shopid'=>$params['poiid'],
                'key_admin'=>$params['key_admin'],
                'buildid'=>$params['buildid'],
                'openid'=>$params['openid'],
                'orderno'=>$pingxx['orderNO'],
                'qrcode'=>$params['couponqr'] ? $params['couponqr'] : '',
            );
            /**
             * 数据信息存入数据库
             */
            $db = M('pingxx_pay', $admininfo['pre_table']);
            $data['main'] = $arr['main'] ;
            $data['mount'] = $params['amount'] * 100;//总金额
            $data['amount'] = $pingxx['amount'];//实付金额
            $data['couponprice'] = $arr['price'];//券面额
            $data['orderno'] = $pingxx['orderNO'];
            $data['openid'] = $extra['open_id'];
            $data['channel'] = $params['channel'];
            $data['currency'] = 'cny';
            $data['status'] = $status;
            $data['shopid']=$params['poiid'];
            $data['key_admin']=$params['key_admin'];
            $data['buildid']=$params['buildid'];
            $data['couponqr']=$params['couponqr'];//优惠券号
            $data['marketname']=$buildid_arr['name'];//商场名称
            $data['shopname']=$params['shopname'];//商户名称
            $data['datetime']=date('Y-m-d H-i-s',time());//支付时间
            $add = $db->add($data);
            if ($pingxx['amount'] == 0) {
                returnjson(array( 'code'=>200, 'data'=>(int)0 ),$this->returnstyle,$this->callback);
            }
            /**
             * pingxx支付开始
             */
            Pingpp::setApiKey($pingxxConfig['live_secret_key']);// 设置 API Key，测试正式注意切换
            Pingpp::setPrivateKeyPath($pingxxConfig['private_key_path']);// 设置私钥
            $ch = Charge::create(
                array(
                    //请求参数字段规则，请参考 API 文档：https://www.pingxx.com/api#api-c-new
                    'subject'   => $arr['main'] ? $arr['main'] : '',
                    'body'      => $arr['main'],
                    'amount'    => $pingxx['amount'],//订单总金额, 人民币单位：分（如订单总金额为 1 元，此处请填 100）
                    'order_no'  => $pingxx['orderNO'],// 推荐使用 8-20 位，要求数字或字母，不允许其他字符
                    'currency'  => 'cny',
                    'extra'     => $extra,//https://www.pingxx.com/api#支付渠道-extra-参数说明
                    'channel'   => $params['channel'],// 支付使用的第三方支付渠道取值，请参考：https://www.pingxx.com/api#api-c-new
                    'client_ip' => $_SERVER['REMOTE_ADDR'],// 发起支付请求客户端的 IP 地址，格式为 IPV4，如: 127.0.0.1
                    'app'       => array('id' => $pingxxConfig['appid']),
                    'metadata'  => $metadata
                )
            );
            $ch = json_decode($ch, true);
            
            returnjson(array('code'=>200, 'data'=>$ch, 'other'=>'where'),$this->returnstyle,$this->callback);
        } catch (Base $e) {
            // 捕获报错信息
            if ($e->getHttpStatus() != null) {
                header('Status: ' . $e->getHttpStatus());
//                echo $e->getHttpBody();
                returnjson(array('code'=>104, 'data'=>$e->getHttpBody()),$this->returnstyle,$this->callback);
            } else {
                returnjson(array('code'=>104, 'data'=>$e->getMessage()),$this->returnstyle,$this->callback);
//                echo $e->getMessage();
            }
        }
    }




    /**
     * 核销券接口
     */
    private function verifyCoupon($qrcode)
    {
        $url = 'http://101.200.216.60:8080/proxy/verify/pos';
        $data = array('code'=>$qrcode);
        $res = http($url, json_encode($data), 'POST', array('Content-Type:application/json'), true);
        if (is_json($res)){
            $array = json_decode($res, true);
            if ($array['code'] == 0) {
                return true;
            }else{
                return array('code'=>1500);
            }
        }else{
            return array('code'=>101, 'data'=>$res);
        }
    }

//    public function pingxx()
//    {
//        include_once './Class/pingpp-php/init.php';
//        Pingpp::setApiKey('sk_test_CKiTm9u90uLOa500KCibL884');                                         // 设置 API Key
//        Pingpp::setAppId('app_8KurvDLqvPqLnzT0');                                           // 设置 APP ID
//        Pingpp::setPrivateKeyPath('./rsa_key/pingxx_huiyuecheng_rsa_private_key.pem');   // 设置私钥
//
//
//// 创建商品订单
//        $order_no = substr(md5(time()), 0, 10);
//        try {
//            $or = Order::create(
//                array(
//                    "amount" => 100,
//                    "app" => 'app_8KurvDLqvPqLnzT0',
//                    "merchant_order_no" => "201609{$order_no}",
//                    "subject" => "subj{$order_no}",
//                    "currency" => "cny",
//                    "body" => "body{$order_no}",
//                    "uid" => "test_user_0001",
//                    "client_ip" => "192.168.0.101",
//                    'receipt_app' => 'app_8KurvDLqvPqLnzT0',    // 收款方应用
//                    'service_app' => 'app_8KurvDLqvPqLnzT0',    // 服务方应用
////                    'time_expire' =>
//                )
//            );
//            echo $or;
//        } catch (\Pingpp\Error\Base $e) {
//            // 捕获报错信息
//            if ($e->getHttpStatus() != NULL) {
//                echo $e->getHttpStatus() . PHP_EOL;
//                echo $e->getHttpBody() . PHP_EOL;
//            } else {
//                echo $e->getMessage() . PHP_EOL;
//            }
//        }
//        exit;
//
//    }
//
//
//
//
//    public function pingxxpay()
//    {
//        include_once './Class/pingpp-php/init.php';
//        Pingpp::setApiKey('sk_test_CKiTm9u90uLOa500KCibL884');                                         // 设置 API Key
//        Pingpp::setAppId('app_8KurvDLqvPqLnzT0');                                           // 设置 APP ID
//        Pingpp::setPrivateKeyPath('./rsa_key/pingxx_huiyuecheng_rsa_private_key.pem');   // 设置私钥
//        $order_id=I('orderid');
//        // 商品订单支付
////        $order_id = '2011611170000003651';
//        $params = array(
//            'channel'=>'wx_lite',
//            'balance_amount'    => 0,
//            'charge_amount'     => 100,
//        );
//        try {
//            $pay = Order::pay($order_id, $params);
//            echo $pay;
//        } catch (Base $e) {
//            if ($e->getHttpStatus() != null) {
//                header('Status: ' . $e->getHttpStatus());
//                echo $e->getHttpBody();
//            } else {
//                echo $e->getMessage();
//            }
//        }
//    }


}