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

        $r = $weixin->payToUser('1', 'oYFC0wAIqd_vmscqWQuVKPQLZGyA', 1, '测试');
        var_dump($r);
    }
}
