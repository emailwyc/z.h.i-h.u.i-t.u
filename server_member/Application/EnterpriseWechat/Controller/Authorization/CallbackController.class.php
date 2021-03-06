<?php
/**
 *
 * http://qydev.weixin.qq.com/wiki/index.php?title=第三方回调协议
 */
namespace EnterpriseWechat\Controller\Authorization;

use EnterpriseWechat\Controller\EnterprisewConfigController;

class CallbackController extends EnterprisewConfigController
{
    public function _initialize()
    {
        parent::__initialize(); // TODO: Change the autogenerated stub
    }

    /**
     * test:
     * token=UxI37O8A6bbndY6OBjA6C1FszCLbO
     * EncodingAESKey:iAME3ifxBu9ygnAGLz5eOjdwzelXDQ8Rq9k64wwnhsG
     * https://mem.rtmap.com/EnterpriseWechat/Authorization/Callback/Receive/suite/testsuite
     */
    public function Receive()
    {
        $suite = I('get.suite');
        if ( false == $suite ) {
            return;
        }else{
            $db = M('enterprise_suite', 'total_');
            $find = $db->where(array('suite_name'=>$suite))->find();
            if (false == $find){
                echo 'success';exit;
            }else{
                $token = $find['suite_token'];
                $encodingAesKey = $find['suite_encodingaeskey'];
                $corpId = $find['corpid'];
            }
        }
        $get = I('get.');
        $postData = file_get_contents('php://input');
        //引入微信提供的解密类
        require_once 'Class/Enterprise/WXBizMsgCrypt.php';
        $wxcpt = new \WXBizMsgCrypt($token, $encodingAesKey, $corpId);
        /*
            ------------使用一：验证回调URL---------------
            *企业开启回调模式时，企业号会向验证url发送一个get请求
            假设点击验证时，企业收到类似请求：
            * GET /cgi-bin/wxpush?msg_signature=5c45ff5e21c57e6ad56bac8758b79b1d9ac89fd3&timestamp=1409659589&nonce=263014780&echostr=P9nAzCzyDtyTWESHep1vC5X9xho%2FqYX3Zpb4yKa9SKld1DsH3Iyt3tP3zNdtp%2B4RPcs8TgAE7OaBO%2BFZXvnaqQ%3D%3D
            * HTTP/1.1 Host: qy.weixin.qq.com

            接收到该请求时，企业应
            1.解析出Get请求的参数，包括消息体签名(msg_signature)，时间戳(timestamp)，随机数字串(nonce)以及公众平台推送过来的随机加密字符串(echostr),
            这一步注意作URL解码。
            2.验证消息体签名的正确性
            3. 解密出echostr原文，将原文当作Get请求的response，返回给公众平台
            第2，3步可以用公众平台提供的库函数VerifyURL来实现。

            */
        if (isset($get['echostr']) && '' == $postData){
            $sEchoStr = '';
            $echostr= urldecode($get['echostr']);
            $errCode = $wxcpt->VerifyURL($get['msg_signature'], $get['timestamp'], $get['nonce'], $get['echostr'], $sEchoStr);
            if ($errCode == 0) {
                $echo = $sEchoStr;
            } else {
                $echo = 'success';
            }
            $log['type'] = 'CheckURL';
            $log['get'] = $get;
            $log['postData'] = $postData;
            $log['sEchoStr'] = $sEchoStr;
            $log['echo'] = $echo;
            $log['errCode'] = $errCode;
        }

        /*
            ------------使用示例二：对用户回复的消息解密---------------
            用户回复消息或者点击事件响应时，企业会收到回调消息，此消息是经过公众平台加密之后的密文以post形式发送给企业，密文格式请参考官方文档
            假设企业收到公众平台的回调消息如下：
            POST /cgi-bin/wxpush? msg_signature=477715d11cdb4164915debcba66cb864d751f3e6&timestamp=1409659813&nonce=1372623149 HTTP/1.1
            Host: qy.weixin.qq.com
            Content-Length: 613
            <xml>
            <ToUserName><![CDATA[wx5823bf96d3bd56c7]]></ToUserName><Encrypt><![CDATA[RypEvHKD8QQKFhvQ6QleEB4J58tiPdvo+rtK1I9qca6aM/wvqnLSV5zEPeusUiX5L5X/0lWfrf0QADHHhGd3QczcdCUpj911L3vg3W/sYYvuJTs3TUUkSUXxaccAS0qhxchrRYt66wiSpGLYL42aM6A8dTT+6k4aSknmPj48kzJs8qLjvd4Xgpue06DOdnLxAUHzM6+kDZ+HMZfJYuR+LtwGc2hgf5gsijff0ekUNXZiqATP7PF5mZxZ3Izoun1s4zG4LUMnvw2r+KqCKIw+3IQH03v+BCA9nMELNqbSf6tiWSrXJB3LAVGUcallcrw8V2t9EL4EhzJWrQUax5wLVMNS0+rUPA3k22Ncx4XXZS9o0MBH27Bo6BpNelZpS+/uh9KsNlY6bHCmJU9p8g7m3fVKn28H3KDYA5Pl/T8Z1ptDAVe0lXdQ2YoyyH2uyPIGHBZZIs2pDBS8R07+qN+E7Q==]]></Encrypt>
            <AgentID><![CDATA[218]]></AgentID>
            </xml>

            企业收到post请求之后应该
            1.解析出url上的参数，包括消息体签名(msg_signature)，时间戳(timestamp)以及随机数字串(nonce)
            2.验证消息体签名的正确性。
            3.将post请求的数据进行xml解析，并将<Encrypt>标签的内容进行解密，解密出来的明文即是用户回复消息的明文，明文格式请参考官方文档
            第2，3步可以用公众平台提供的库函数DecryptMsg来实现。
            */
        elseif ('' != $postData && !isset($get['echostr']) ){
            $wxcpt = new \WXBizMsgCrypt($token, $encodingAesKey, $find['suiteid']);//f u c k ,最后一个参数不是文档里面的corpid，是套件的套件id，草草草草草草草草草
            $sMsg = "";  // 解析之后的明文
            $errCode = $wxcpt->DecryptMsg($get['msg_signature'], $get['timestamp'], $get['nonce'], $postData, $sMsg);
            if ($errCode == 0) {
                // 解密成功，sMsg即为xml格式的明文
                // TODO: 对明文的处理
                // For example:
                $xml = new \DOMDocument();
                $xml->loadXML($sMsg);
                $infotype = $xml->getElementsByTagName('InfoType')->item(0)->nodeValue;//InfoType有4哥值，需根据不同的值做不同的处理
                echo $infotype;
                if ($infotype == 'suite_ticket') {//推送suite_ticket协议
                    $suite_ticket = $xml->getElementsByTagName('SuiteTicket')->item(0)->nodeValue;
                    $suiteid = $xml->getElementsByTagName('SuiteId')->item(0)->nodeValue;
                    $this->redis->set('enterprise:suiteticket:' . $suiteid, $suite_ticket);
                }elseif ($infotype == 'change_auth') {//变更授权
/**
 * <xml>
<SuiteId><![CDATA[wxfc918a2d200c9a4c]]></SuiteId>
<InfoType><![CDATA[change_auth]]></InfoType>
<TimeStamp>1403610513</TimeStamp>
<AuthCorpId><![CDATA[wxf8b4f85f3a794e77]]></AuthCorpId>
</xml>
 */
                }elseif ($infotype == 'cancel_auth') {
/**
 * <xml>
<SuiteId><![CDATA[wxfc918a2d200c9a4c]]></ SuiteId>
<InfoType><![CDATA[cancel_auth]]></InfoType>
<TimeStamp>1403610513</TimeStamp>
<AuthCorpId><![CDATA[wxf8b4f85f3a794e77]]></AuthCorpId>
</xml>
 */
                }elseif ($infotype == 'create_auth') {
/**
 * <xml>
<SuiteId><![CDATA[wxfc918a2d200c9a4c]]></ SuiteId>
<AuthCode><![CDATA[AUTHCODE]]></AuthCode>
<InfoType><![CDATA[create_auth]]></InfoType>
<TimeStamp>1403610513</TimeStamp>
</xml>
 *
 */
                    $authcode = $xml->getElementsByTagName('AuthCode')->item(0)->nodeValue;
                    $suiteid = $xml->getElementsByTagName('SuiteId')->item(0)->nodeValue;
                    $getPermanentCode = $this->getPermanentCode($authcode, $suiteid);

                    $data['suiteid'] = $suiteid;
                    $data['permanent_code']=$getPermanentCode['permanent_code'];
                    $data['corpid']=$getPermanentCode['auth_corp_info']['corpid'];
                    $data['corp_name']=$getPermanentCode['auth_corp_info']['corp_name'];
                    $data['corp_type']=$getPermanentCode['auth_corp_info']['corp_type'];
                    $data['corp_round_logo_url']=$getPermanentCode['auth_corp_info']['corp_round_logo_url'];
                    $data['corp_square_logo_url']=$getPermanentCode['auth_corp_info']['corp_square_logo_url'];
                    $data['corp_user_max']=$getPermanentCode['auth_corp_info']['corp_user_max'];
                    $data['corp_agent_max']=$getPermanentCode['auth_corp_info']['corp_agent_max'];
                    $data['corp_full_name']=$getPermanentCode['auth_corp_info']['corp_full_name'];
                    $data['verified_end_time']=$getPermanentCode['auth_corp_info']['verified_end_time'];
                    $data['subject_type']=$getPermanentCode['auth_corp_info']['subject_type'];
                    $data['corp_wxqrcode']=$getPermanentCode['auth_corp_info']['corp_wxqrcode'];
                    $data['is_new_auth']=$getPermanentCode['auth_info']['is_new_auth'];
                    $data['agent']=json_encode($getPermanentCode['auth_info']['agent']);//太多，没分开，转json
                    $data['email']=$getPermanentCode['auth_user_info']['email'];
                    $data['mobile']=$getPermanentCode['auth_user_info']['mobile'];
                    $data['userid']=$getPermanentCode['auth_user_info']['userid'];

                    $dbcorp = M('enterprise_corp_info', 'total_');
                    $add = $dbcorp->add($data);

                    $dbagent = M('enterprise_agent', 'total_');
                    foreach ($getPermanentCode['auth_info']['agent'] as $key => $val){
                        $agent[$key]['corp_info_id'] = $add;
                        $agent[$key]['agentid'] = $val['agentid'];
                        $agent[$key]['name'] = $val['name'];
                        $agent[$key]['round_logo_url'] = $val['round_logo_url'];
                        $agent[$key]['square_logo_url'] = $val['square_logo_url'];
                        $agent[$key]['appid'] = $val['appid'];
                        $agent[$key]['privilege_level'] = $val['privilege']['level'] ? $val['privilege']['level'] : '';
                        $agent[$key]['privilege_allow_party'] = $val['privilege']['allow_party'] ? json_encode($val['privilege']['allow_party']) : '';
                        $agent[$key]['privilege_allow_user'] = $val['privilege']['allow_user'] ? json_encode($val['privilege']['allow_user']) : '';
                        $agent[$key]['privilege_allow_tag'] = $val['privilege']['allow_tag'] ? json_encode($val['privilege']['allow_tag']) : '';
                        $agent[$key]['privilege_extra_party'] = $val['privilege']['extra_party'] ? json_encode($val['privilege']['extra_party']) : '';
                        $agent[$key]['privilege_extra_user'] = $val['privilege']['extra_user'] ? json_encode($val['privilege']['extra_user']) : '';
                        $agent[$key]['privilege_extra_tag'] = $val['privilege']['extra_tag'] ? json_encode($val['privilege']['extra_tag']) : '';
                        $agent[$key]['privilege'] = json_encode($val['privilege']);
                    }
                    $dbagent->addAll($agent);

                    $content = $getPermanentCode;
                }



                $echo = 'success';
            } else {
                $echo = 'success';
            }
            $log['type'] = 'Encrypt';
            $log['get'] = $get;
            $log['postData'] = $postData;
            $log['Msg'] = $sMsg;
            $log['content'] = $content;
            $log['echo'] = $echo;
            $log['errCode'] = $errCode;
        }else{
            $log['echo']='success';
        }
        ob_clean();
        print ($echo);
        $logpath=MODULE_NAME.'-'.CONTROLLER_NAME.'-'.ACTION_NAME;
        $logpath=str_replace('/','-', $logpath);
        $logpath=strtolower($logpath);
        $logpath='enterpriseWechat/'.date('Y-m-d').'/'.$logpath;
        writeOperationLog($log, $logpath);
//        $this->getAccessToken('adfasdfad');
    }
}


?>

