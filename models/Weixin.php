<?php
/**
 * Created by PhpStorm.
 * User: caoxiang
 * Date: 2017/3/7
 * Time: 下午12:51
 */

namespace caoxiang\weixin;

use Yii;
use yii\base\Object;
use yii\httpclient\Client;

/**
 * Class Weixin
 * @package caoxiang
 *
 * @property string $appTokenName
 * @property string $webTokenName
 * @property boolean $isWeixin
 */
class Weixin extends Object
{
    public $appId;
    public $appSecret;

    public $mchId;
    public $mchKey;
    public $mchSecret;

    public $certFile;
    public $certKey;

    public $openId;
    public $unionId;

    protected $timestamp;
    protected $nonceStr;
    protected $url;

    private $_isSub = null;
    private $_isWeixin = null;

    public function getSign($url = '')
    {
        $this->timestamp = time();
        $this->nonceStr = $this->getNonceStr();
        if (!$url && isset(Yii::$app->request->absoluteUrl)) {
            $this->url = Yii::$app->request->absoluteUrl;
        } else {
            $this->url = $url;
        }

        return sha1("jsapi_ticket=" . $this->getTicket() . "&noncestr=$this->nonceStr&timestamp=$this->timestamp&url=$this->url");
    }

    private function getNonceStr($length = 16)
    {
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * @param string $action
     * @param array $data
     * @param string $method
     * @return \yii\httpclient\Response
     */
    protected function api($action, $data, $method = 'get')
    {
        $http = (new Client)->createRequest()
            ->setMethod($method)
            ->setUrl('https://api.weixin.qq.com/cgi-bin/' . $action)
            ->setData($data);
        if ($method == 'post') {
            $http->setFormat(Client::FORMAT_JSON);
        }

//        $response = null;
//        for ($i = 0; $i < 3; $i++) {
//            $response = $http->send();
//            if ($response->isOk) {
//                break;
//            }
//        }

        $response = $http->send();
        return $response;
    }

    /**
     * @param string $action
     * @param array $data
     * @param string $method
     * @return \yii\httpclient\Response
     */
    protected function apiSns($action, $data, $method = 'get')
    {
        return (new Client)->createRequest()
            ->setMethod($method)
            ->setUrl('https://api.weixin.qq.com/sns/' . $action)
            ->setData($data)
            ->send();
    }

    /**
     * @param string $action
     * @param array|string $data
     * @param string $method
     * @return \yii\httpclient\Response
     */
    protected function apiPay($action, $data, $certFile = false)
    {
        return $this->httpRequest('https://api.mch.weixin.qq.com/' . $action, $data, [], true, $certFile);
    }

    private function getTicket()
    {
        $key = 'weixin:ticket';

        if (!$ticket = Yii::$app->redis->get($key)) {
            $response = $this->api('ticket/getticket', [
                'type' => 'jsapi',
                'access_token' => $this->getServerToken(),
            ]);
            if ($response->isOk && isset($response->data['ticket'])) {
                $ticket = $response->data['ticket'];
                Yii::$app->redis->setex($key, 7000, $ticket);
            }
        }

        return $ticket;
    }

    public function makeSign($values)
    {
        ksort($values);
        $buff = "";
        foreach ($values as $k => $v) {
            if ($k != "sign" && $v != "" && !is_array($v)) {
                $buff .= $k . "=" . $v . "&";
            }
        }
        $string = trim($buff, "&");
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=" . $this->mchSecret;
        Yii::warning('wx_pay_string:' . $string);
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * 获取微信保存的媒体文件
     */
    public function getFile($media_id)
    {
        $token = $this->getServerToken();
        $url = "http://file.api.weixin.qq.com/cgi-bin/media/get?access_token=$token&media_id=$media_id";
        if ($data = Logic::request($url)) {
            $new_name = md5($media_id . rand(100, 999)) . '.amr';
            $file_name = Yii::getAlias('@answer' . '/' . $new_name);
            file_put_contents($file_name, $data);
            return $new_name;
        }
        return false;
    }

    /**
     * 退款
     *
     * @param $orderId string
     * @param $money int
     * @param $refundId string
     * @return bool
     */
    public function refund($orderId, $money, $refundId)
    {
        $param = [
            'appid' => $this->appId,
            'mch_id' => $this->mchId,
            'nonce_str' => $this->getNonceStr(),
            'out_trade_no' => $orderId,
            'out_refund_no' => $refundId,
            'total_fee' => $money * 100,
            'refund_fee' => $money * 100,
        ];
        $param['sign'] = $this->makeSign($param);
        $xml = $this->makeXML($param);
        $response = $this->apiPay('secapi/pay/refund', $xml, true);
        if (!$response) {
            return false;
        }
        $result = json_decode(json_encode(simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if ($result['return_code'] != 'SUCCESS') {
            return false;
        }
        return true;
    }

    /*
     * 企业向用户付款
     */
    public function payToUser($orderId, $openId, $money, $desc, $ip = '')
    {
        if (!$ip && isset($_SERVER['SERVER_ADDR'])) {
            $ip = $_SERVER['SERVER_ADDR'];
        }
        $param = [
            'mch_appid' => $this->appId,
            'mchid' => $this->mchId,
            'nonce_str' => $this->getNonceStr(),
            'partner_trade_no' => $orderId,
            'openid' => $openId,
            'check_name' => 'NO_CHECK',
            'amount' => $money,
            'desc' => $desc,
            'spbill_create_ip' => $ip,
        ];
        $param['sign'] = $this->makeSign($param);
        $xml = $this->makeXML($param);
        $response = $this->apiPay('mmpaymkttransfers/promotion/transfers', $xml, true);
        if (!$response) {
            return false;
        }
        $result = json_decode(json_encode(simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if ($result['result_code'] != 'SUCCESS') {
            return false;
        }
        return true;
    }

    /**
     * 获取用户信息
     *
     * @param $open_id
     * @return bool|mixed
     */
    public function getUserInfo($open_id)
    {
        $response = $this->api('user/info', [
            'access_token' => $this->getServerToken(),
            'openid' => $open_id,
            'lang' => 'zh_CN',
        ]);
        if ($response->isOk) {
            return $response->data;
        }
        return false;
    }

    /**
     * 网页方式获取用户信息
     *
     * @param $open_id
     * @return bool|mixed
     */
    public function getWebUserInfo($open_id)
    {
        if (!$token = $this->getWebToken()) {
            return false;
        }
        $response = $this->apiSns('userinfo', [
            'access_token' => $token,
            'openid' => $open_id,
            'lang' => 'zh_CN'
        ]);
        if ($response->isOk) {
            return $response->data;
        }
        return false;
    }

    /**
     * 检查用户是否关注公众号
     *
     * @param $open_id
     * @return bool|null
     */
    public function checkSub($open_id)
    {
        if ($this->_isSub === null) {
            $info = $this->getUserInfo($open_id);
            if (isset($info['subscribe']) && $info['subscribe'] == 1) {
                $this->_isSub = true;
            } else {
                $this->_isSub = false;
            }
        }
        return $this->_isSub;
    }

    /**
     * 获得Token，用于服务端调用API
     *
     * return structure: {"access_token":"ACCESS_TOKEN","expires_in":7200}
     *
     * @return mixed
     */
    private function getServerToken()
    {
        $key = 'weixin:' . $this->appId . ':server_token';

        if (!$access_token = Yii::$app->redis->get($key)) {
            $response = $this->api('token', [
                'appid' => $this->appId,
                'secret' => $this->appSecret,
                'grant_type' => 'client_credential',
            ]);

            if ($response->isOk && isset($response->data['access_token'])) {
                $access_token = $response->data['access_token'];
                Yii::$app->redis->setex($key, 3600, $access_token);
                Yii::warning('Weixin getServerToken OK:' . json_encode($response->data));
            } else {
                Yii::warning('Weixin getServerToken:' . json_encode($response->data));
            }
        }

        return $access_token;
    }

    public function getAppTokenName()
    {
        return 'weixin:' . $this->appId . ':app_token';
    }

    /**
     * 获得Token，用于小程序校验
     *
     * @return mixed
     */
    public function getAppToken()
    {
        return Yii::$app->redis->get($this->appTokenName);
    }

    /**
     * 通过code获取用户open_id和token，用于小程序
     *
     * response data structure: ['openid', 'session_key']
     *
     * @param $code
     * @return bool|mixed
     */
    public function getAppAuth($code)
    {
        $response = $this->apiSns('jscode2session', [
            'appid' => $this->appId,
            'secret' => $this->appSecret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]);
        if ($response->isOk && isset($response->data['openid'])) {
            Yii::$app->redis->setex($this->appTokenName, 7000, $response->data['session_key']);
            $this->openId = $response->data['openid'];
            if (isset($response->data['unionid'])) {
                $this->unionId = $response->data['unionid'];
            }
            return $response->data;
        }
        return false;
    }

    public function getWebTokenName()
    {
        return 'weixin:' . $this->appId . ':web_token';
    }

    /**
     * 获得Token，用于Web接口请求
     *
     * @return mixed
     */
    public function getWebToken()
    {
        return Yii::$app->redis->get($this->webTokenName);
    }

    /**
     * 通过页面跳转获取code，再获取用户open_id和token，用于公众号
     * 使用此函数时判断返回值是否是Response，如果是，则跳转，如果不是，则为返回值
     *
     * @param string $type
     * @return mixed|\yii\web\Response
     */
    public function getWebAuth($type = 'base')
    {
        if ($code = Yii::$app->request->get('code', '')) {
            return $this->getWebAuthFromCode($code);
        }

        if ($type == 'base') {
            $scope = 'snsapi_base';
        } else {
            $scope = 'snsapi_userinfo';
        }
        $redirect_url = "https://open.weixin.qq.com/connect/oauth2/authorize?appid=" . $this->appId .
            "&redirect_uri=" . urlencode(Yii::$app->request->absoluteUrl) .
            "&response_type=code&scope=$scope&state=STATE#wechat_redirect";

        return Yii::$app->controller->redirect($redirect_url);
    }

    /**
     * 获取用户open_id和token，用于公众号
     *
     * return structure: {
     * "access_token":"ACCESS_TOKEN",
     * "expires_in":7200,
     * "refresh_token":"REFRESH_TOKEN",
     * "openid":"OPENID",
     * "scope":"SCOPE",
     * "unionid": "o6_bmasdasdsad6_2sgVt7hMZOPfL"
     * }
     *
     * @param $code
     * @return bool|mixed
     */
    public function getWebAuthFromCode($code)
    {
        $response = $this->apiSns('oauth2/access_token', [
            'appid' => $this->appId,
            'secret' => $this->appSecret,
            'code' => $code,
            'grant_type' => 'authorization_code'
        ]);
        if ($response->isOk && isset($response->data['openid'])) {
            $this->openId = $response->data['openid'];
            Yii::$app->redis->setex($this->webTokenName, 7000, $response->data['access_token']);
            return $response->data;
        }
        return false;
    }

    /*
     * 发起支付
     */
    public function payRequest($name, $open_id, $order_id, $price, $back_url)
    {
        if (!$open_id) {
            return false;
        }

        $param = [
            'body' => $name,
            'attach' => 'theattach',
            'out_trade_no' => $order_id,
            'total_fee' => $price,
            'time_start' => date("YmdHis"),
            'time_expire' => date("YmdHis", time() + 600),
            'goods_tag' => 'a_goods_tag',
            'notify_url' => $back_url,
            'trade_type' => 'JSAPI',
            'openid' => $open_id,
            'appid' => $this->appId,
            'mch_id' => $this->mchId,
            'spbill_create_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : gethostbyname(trim(`hostname`)),
            'nonce_str' => $this->getNonceStr(),
        ];
        $param['sign'] = $this->makeSign($param);
        $xml = $this->makeXML($param);
        $response = $this->apiPay('pay/unifiedorder', $xml);
        if (!$response) {
            return false;
        }

        $result = json_decode(json_encode(simplexml_load_string($response, 'SimpleXMLElement',
            LIBXML_NOCDATA)), true);
        if (!isset($result['result_code']) || $result['result_code'] != 'SUCCESS') {
            return false;
        }

        $js_param = [
            'appId' => $result['appid'],
            'timeStamp' => strval(time()),
            'nonceStr' => $this->getNonceStr(),
            'package' => 'prepay_id=' . $result['prepay_id'],
            'signType' => 'MD5',
        ];
        $js_param['paySign'] = $this->makeSign($js_param);
        return $js_param;
    }

    /*
     * 确认支付状态
     */
    public function payConfirm()
    {
        $check_result = false;
        $out_trade_no = '';
        $trade_no = '';

        $postData = file_get_contents("php://input");
        Yii::warning('wx_result:' . $postData);
        $xml = simplexml_load_string($postData);
        if ((string)$xml->return_code[0] == 'SUCCESS' && (string)$xml->result_code[0] == 'SUCCESS') {
            $out_trade_no = (string)$xml->out_trade_no[0];
            $trade_no = (string)$xml->transaction_id[0];

            $rstr = $this->getNonceStr();
            $sign = strtoupper(md5("appid=" . $this->appId . "&mch_id=" . $this->mchId .
                "&nonce_str=$rstr&transaction_id=$trade_no&key=" . $this->mchKey));
            $data = "<xml>
                           <appid>" . $this->appId . "</appid>
                           <mch_id>" . $this->mchId . "</mch_id>
                           <nonce_str>$rstr</nonce_str>
                           <transaction_id>$trade_no</transaction_id>
                           <sign>$sign</sign>
                        </xml>";
            $result = $this->apiPay('pay/orderquery', $data);

            Yii::warning('wx_check_result:' . $result);
            $check_xml = simplexml_load_string($result);
            if ((string)$check_xml->return_code[0] == 'SUCCESS' && (string)$check_xml->result_code[0] == 'SUCCESS') {
                $check_result = true;
            }
        }

        $payResult = new PayResult();
        $payResult->output = "<xml>
                    <return_code><![CDATA[SUCCESS]]></return_code>
                    <return_msg><![CDATA[OK]]></return_msg>
                  </xml>";
        $payResult->result = $check_result;
        $payResult->vendorString = $postData;
        $payResult->orderId = $out_trade_no;
        $payResult->vendorOrderId = $trade_no;

        return $payResult;
    }

    /*
     * 公众号推送
     */
    public function push($template, $open_id, $param, $target_url)
    {
        if (!$open_id || !$template) {
            return false;
        }

        $params = [];
        foreach ($param as $key => $value) {
            $params[$key] = ['value' => $value];
        }

        $response = $this->api('message/template/send?access_token=' . $this->getServerToken(),
            [
                'touser' => $open_id,
                'template_id' => $template,
                'url' => $target_url,
                'data' => $params,
            ],
            'post'
        );
        if ($response->isOk) {
            return $response->data;
        }
        return false;
    }

    /**
     * 小程序推送
     *
     * @param string $template
     * @param string $open_id
     * @param array $param
     * @param string $formId
     * @param string $page
     * @return bool|mixed
     */
    public function pushWxapp($template, $open_id, $param, $formId, $page = '')
    {
        if (!$open_id || !$template || !$formId) {
            return false;
        }

        $params = [];
        foreach ($param as $key => $value) {
            $params[$key] = ['value' => $value];
        }

        $data = [
            'touser' => $open_id,
            'template_id' => $template,
            'form_id' => $formId,
            'data' => $params,
        ];
        if ($page) {
            $data['page'] = $page;
        }
        $url = 'message/wxopen/template/send?access_token=' . $this->getServerToken();
        $response = $this->api($url, $data, 'post');

        if (!$response->isOk || !isset($response->data['errcode']) || $response->data['errcode'] != 0) {
            Yii::warning('Weixin pushWxapp:' . $url . ':' . json_encode($response->data) . ':' . json_encode($data));
            return false;
        }
        return true;
    }

    /**
     * 判断请求是否发自微信
     */
    public function getIsWeixin()
    {
        if (isset(Yii::$app->params['notWeixin']) || !isset(Yii::$app->request->userAgent)) {
            return false;
        }
        if ($this->_isWeixin === null) {
            $wweixinSign = strstr(strtolower(Yii::$app->request->userAgent), 'micromessenger');
            $this->_isWeixin = !empty($wweixinSign);
        }
        return $this->_isWeixin;
    }

    /**
     * @param $view \yii\web\View
     */
    public function setupView($view)
    {
        $view->registerJsFile('http://res.wx.qq.com/open/js/jweixin-1.0.0.js', ['depends' => 'yii\web\YiiAsset']);

        $sign = $this->getSign();
        $script = "
            wx.config({
                debug: false,
                appId: '{$this->appId}',
                timestamp: '{$this->timestamp}',
                nonceStr: '{$this->nonceStr}',
                signature: '{$sign}',
                jsApiList: [ 'onMenuShareTimeline', 'onMenuShareAppMessage', 'onMenuShareQQ', 'onMenuShareWeibo',
                'onMenuShareQZone','chooseImage','previewImage','uploadImage','downloadImage', 'translateVoice',
                'startRecord','stopRecord','onVoiceRecordEnd','playVoice','onVoicePlayEnd','pauseVoice','stopVoice',
                'uploadVoice','downloadVoice','chooseWXPay']
                });
            ";
        $view->registerJs($script);
    }

    /**
     * @param $view \yii\web\View
     * @param $config \caoxiang\weixin\ShareData
     */
    public function share($view, $config)
    {
        $this->setupView($view);
        $script = "
            wx.ready(function () {
                wx.onMenuShareAppMessage({
                    title: '{$config->messageTitle}',
                    desc: '{$config->messageDesc}',
                    link: '{$config->messageLink}',
                    imgUrl: '{$config->messageImage}'
                });
                wx.onMenuShareTimeline({
                    title: '{$config->timeLineTitle}',
                    link: '{$config->timeLineLink}',
                    imgUrl: '{$config->timeLineImage}'
                });
            });
            ";
        $view->registerJs($script);
    }

    public function makeXML($param)
    {
        if (!is_array($param)
            || count($param) <= 0
        ) {
            return false;
        }

        $xml = "<xml>";
        foreach ($param as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";
        return $xml;
    }


    public function httpRequest($url, $param = array(), $header = array(), $ssl = false, $files = false)
    {
        $ch = curl_init();
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 10,
        );
        if ($param) {
            $options[CURLOPT_POST] = 1;
            if (is_array($param)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($param);
            } else {
                $options[CURLOPT_POSTFIELDS] = $param;
            }
        }
        if ($header) {
            $options[CURLOPT_HTTPHEADER] = $header;
        }
        if ($ssl) {
            if ($files && $this->certFile && $this->certKey) {
                curl_setopt($ch, CURLOPT_SSLCERT, $this->certFile);
                curl_setopt($ch, CURLOPT_SSLKEY, $this->certKey);
            } else {
                $options[CURLOPT_SSL_VERIFYPEER] = false;
                $options[CURLOPT_SSL_VERIFYHOST] = false;
                $options[CURLOPT_SSLVERSION] = 1;
            }
        }
        curl_setopt_array($ch, $options);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }
}