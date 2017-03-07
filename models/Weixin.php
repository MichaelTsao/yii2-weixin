<?php
/**
 * Created by PhpStorm.
 * User: caoxiang
 * Date: 2017/3/7
 * Time: 下午12:51
 */

namespace caoxiang;

use yii\base\Object;
use yii\httpclient\Client;
use Yii;

class Weixin extends Object
{
    public $appId;
    public $appSecret;

    public $mchId;
    public $mchKey;

    public $certFile;
    public $certKey;

    private $_isSub = null;

    public function getSign($url = '')
    {
        $jsapiTicket = $this->getTicket();
        $timestamp = time();
        $nonceStr = $this->getNonceStr();
        if (!$url && isset(Yii::$app->request->absoluteUrl)) {
            $url = Yii::$app->request->absoluteUrl;
        }

        return [
            "appId" => $this->appId,
            "nonceStr" => $nonceStr,
            "timestamp" => $timestamp,
            "url" => $url,

            // 这里参数的顺序要按照 key 值 ASCII 码升序排序
            "signature" => sha1("jsapi_ticket=$jsapiTicket&noncestr=$nonceStr&timestamp=$timestamp&url=$url"),
        ];
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
        return (new Client)->createRequest()
            ->setMethod($method)
            ->setUrl('https://api.weixin.qq.com/cgi-bin/' . $action)
            ->setData($data)
            ->send();
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

    private function getTicket()
    {
        $key = 'weixin:ticket';

        if (!$ticket = Yii::$app->redis->get($key)) {
            $response = $this->api('ticket/getticket', [
                'type' => 'jsapi',
                'access_token' => $this->getAccessToken(),
            ]);
            if ($response->isOk && isset($response->data['ticket'])) {
                $ticket = $response->data['ticket'];
                Yii::$app->redis->setex($key, 7000, $ticket);
            }
        }

        return $ticket;
    }

    private function getAccessToken()
    {
        $key = 'weixin:token';

        if (!$access_token = Yii::$app->redis->get($key)) {
            $response = $this->api('token', [
                'appid' => $this->appId,
                'secret' => $this->appSecret,
                'grant_type' => 'client_credential',
            ]);

            if ($response->isOk && isset($response->data['access_token'])) {
                $access_token = $response->data['access_token'];
                Yii::$app->redis->setex($key, 7000, $access_token);
            }
        }

        return $access_token;
    }

    public static function makeSign($values)
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
        $string = $string . "&key=" . Yii::$app->params['wx_pay_key'];
        Yii::warning('wx_pay_string:' . $string);
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    public function makeMySign($values)
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
        $string = $string . "&key=" . $this->appPayKey;
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
        $token = $this->getAccessToken();
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
     */
    public function refund($orderId, $money, $refundId)
    {
        $refund_id = Logic::getOrderId();
        $url = 'https://api.mch.weixin.qq.com/secapi/pay/refund';
        $param = [
            'appid' => $this->appId,
            'mch_id' => $this->appMchId,
            'nonce_str' => $this->getNonceStr(),
            'out_trade_no' => $orderId,
            'out_refund_no' => $refundId,
            'total_fee' => $money * 100,
            'refund_fee' => $money * 100,
            'op_user_id' => $this->appMchId,
        ];
        $param['sign'] = $this->makeMySign($param);
//        var_dump($param);
        $xml = Logic::makeXML($param);
        $data = Logic::request($url, $xml, [], true, ['cert' => $this->certFile, 'key' => $this->certKey]);
//        var_dump($data);
        $result = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if ($result['return_code'] != 'SUCCESS') {
            return false;
        }
        return $refund_id;
    }

    /**
     * 获取用户信息
     *
     * @param $open_id
     * @return bool|mixed
     */
    public function getInfo($open_id)
    {
        $response = $this->api('user/info', [
            'access_token' => $this->getAccessToken(),
            'openid' => $open_id,
            'lang' => 'zh_CN',
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
            $info = $this->getInfo($open_id);
            if (isset($info['subscribe']) && $info['subscribe'] == 1) {
                $this->_isSub = true;
            } else {
                $this->_isSub = false;
            }
        }
        return $this->_isSub;
    }

    /**
     * 通过code获取用户open_id，用于公众号网页
     *
     * @param $code
     * @return bool|mixed
     */
    public function codeToSession($code)
    {
        $response = $this->apiSns('jscode2session', [
            'appid' => $this->appId,
            'secret' => $this->appSecret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]);
        if ($response->isOk && isset($response->data['openid'])) {
            return $response->data;
        }
        return false;
    }
}