<?php
/**
 * Created by PhpStorm.
 * User: zhangkaifeng
 * Date: 2017/6/1
 * Time: 16:43
 */

namespace Pay\Controller\Pingxx;

use Common\Controller\CommonController;
use common\ServiceLocator;

class PingxxWebHooksController extends CommonController
{
    public function _initialize()
    {
        parent::__initialize(); // TODO: Change the autogenerated stub
    }


    /**
     * ping++ 的webhooks回调地址
     * DOMAIN./Pay/Pingxx/PingxxWebHooks/webHooks
     */
    public function webHooks()
    {
        $json = file_get_contents('php://input');
        $arr = json_decode($json, true);
        if (is_array($arr)){
            if (isset($arr['type']) && $arr['type'] == 'charge.succeeded') {
                if (isset($arr['data']['object']['order_no'])){

                    //券核销逻辑
                    if(!empty($arr['data']['object']['metadata']['payType']) && $arr['data']['object']['metadata']['payType'] == 'appletCoupon')
                    {
                        //营销平台4.0小程序
                        $appletCouponService = ServiceLocator::getAppletCouponService();
                        $verifycoupon = $appletCouponService->verifyCoupon('http://211.157.182.226:8080', $arr['data']['object']['metadata']['qrcode'], $arr['data']['object']['metadata']['shopid'], $arr['data']['object']['metadata']['openid'], $arr['data']['object']['metadata']['adminid']);
                    }
                    else
                    {
                        //营销平台3.0
                        $verifycoupon = $this->verifyCoupon($arr['data']['object']['metadata']['qrcode']);
                    }
                    
                    $key_admin = $arr['data']['object']['metadata']['key_admin'];
                    $admininfo =$this->getMerchant($key_admin);
                    $db = M('pingxx_pay', $admininfo['pre_table']);

                    $data['orderno']= $arr['data']['object']['order_no'];
                    $data['shopid'] = $arr['data']['object']['metadata']['shopid'];
                    $amount = $db->where($data)->find();
                    $db->where($data)->save(array('status'=>1));
                    //给商户发个短信
                    $dbbuild = M( $admininfo['pre_table'].'map_poi_'.$arr['data']['object']['metadata']['buildid'] , '', 'DB_CONFIG2');
                    $sel = $dbbuild->field('phones')->where(array('id'=>$arr['data']['object']['metadata']['shopid']))->find();
                    if ($sel){
                        $url = 'http://m.5c.com.cn/api/send/index.php';
                        $data['username'] = 'zhihuitu';
                        $data['password_md5'] = md5('rtmap_911');
                        $data['apikey'] = 'd40d62eec4fbd6a6ce6dfdec1d9315cf';
                        $data['mobile'] = $sel['phones'];
                        $data['encode'] = 'UTF-8';
                        $amount['amount'] = $amount['amount']/100;

                        $static = M('total_static');
                        $result = $static->where(array('tid' => 12, 'admin_id' => $admininfo['id']))->find();
                        $tag = $result['content'];

                        $data['content'] = urlencode('您好,收到支付金额：' . $amount['amount'] . '元【'. $tag .'】');
                        $curl_re = http($url, $data, 'post');
                    }
                    echo 'success';
                }else{
                    echo 'hello c';
                }
            }
        }else{
            echo 'hello hello';
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
}

/*
    {
        "id": "evt_ugB6x3K43D16wXCcqbplWAJo",
    "created": 1427555101,
    "livemode": true,
    "type": "charge.succeeded",
    "data": {
        "object": {
            "id": "ch_Xsr7u35O3m1Gw4ed2ODmi4Lw",
            "object": "charge",
            "created": 1427555076,
            "livemode": true,
            "paid": true,
            "refunded": false,
            "app": "app_1Gqj58ynP0mHeX1q",
            "channel": "upacp",
            "order_no": "123456789",
            "client_ip": "127.0.0.1",
            "amount": 100,
            "amount_settle": 100,
            "currency": "cny",
            "subject": "Your Subject",
            "body": "Your Body",
            "extra": {},
            "time_paid": 1427555101,
            "time_expire": 1427641476,
            "time_settle": null,
            "transaction_no": "1224524301201505066067849274",
            "refunds": {
                "object": "list",
                "url": "/v1/charges/ch_L8qn10mLmr1GS8e5OODmHaL4/refunds",
                "has_more": false,
                "data": []
            },
            "amount_refunded": 0,
            "failure_code": null,
            "failure_msg": null,
            "metadata": {},
            "credential": {},
            "description": null
        }
    },
    "object": "event",
    "pending_webhooks": 0,
    "request": "iar_qH4y1KbTy5eLGm1uHSTS00s"
}
*/