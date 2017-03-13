<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use caoxiang\weixin\Weixin;
use yii\console\Controller;

/**
 * This command echoes the first argument that you have entered.
 *
 * This command is provided as an example for you to learn how to create console commands.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class TestController extends Controller
{
    /**
     * This command echoes what you have entered as the message.
     * @param string $message the message to be echoed.
     */
    public function actionIndex()
    {
        $weixin = new Weixin();
        $weixin->appId = 'wx37c81fe0f40f5093';
        $weixin->appSecret = '5fd8c4141656928dfa004f85348b4e4a';

        $r = $weixin->push('ZvWaaAZ6JpFpsaLE4jU5Bdn9mx0AfU1g0Fngc9EB01w', 'opBoAt-Dzhrxf3Lsd1m-pAnZ6gNk', [
            'first' => '您的加入班级申请已通过！',
            'keyword1' => '加入',
            'keyword2'=>'通过',
            'keyword3'=>'2016-10-26 17:45',
            'remark'=>'您可以'
        ], 'http://www.baidu.com');
        var_dump($r);
    }
}
