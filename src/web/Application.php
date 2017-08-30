<?php

namespace swoft\web;

use swoft\App;
use swoft\base\ApplicationContext;
use swoft\base\RequestContext;
use swoft\event\Event;
use swoft\filter\FilterChain;
use swoft\helpers\ResponseHelper;

/**
 * 应用主体
 *
 * @uses      Application
 * @version   2017年04月25日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2016 swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class Application extends \swoft\base\Application
{
    /**
     * request请求处理
     *
     * @param \Swoole\Http\Request  $request
     * @param \Swoole\Http\Response $response
     *
     * @return bool
     */
    public function doRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        // chrome两次请求bug修复
        if (isset($request->server['request_uri']) && $request->server['request_uri'] === '/favicon.ico') {
            $response->end('favicon.ico');
            return false;
        }

        // 初始化request和response
        RequestContext::setRequest($request);
        RequestContext::setResponse($response);


        // 请求数测试
        $this->count = $this->count + 1;

        App::trigger(Event::BEFORE_REQUEST);

        $swfRequest = RequestContext::getRequest();
        try {

            // 解析URI和method
            $uri = $swfRequest->getRequestUri();
            $method = $swfRequest->getMethod();

            // 运行controller
            $this->runController($uri, $method);

        } catch (\Exception $e) {
            App::getErrorHandler()->handlerException($e);
        }

        App::trigger(Event::AFTER_REQUEST);
    }

    /**
     * rpc内部服务
     *
     * @param \Swoole\Server $server
     * @param int            $fd
     * @param int            $from_id
     * @param string         $data
     */
    public function doReceive(\Swoole\Server $server, int $fd, int $from_id, string $data)
    {
        try {
            // 解包
            $packer = App::getPacker();
            $data = $packer->unpack($data);

            // 初始化
            $this->beforeReceiver($data);

            // 执行函数调用
            $response = $this->runService($data);
            $data = $packer->pack($response);

        } catch (\Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            $data = ResponseHelper::formatData("", $code, $message);
        }

        App::trigger(Event::AFTER_REQUEST);
        $server->send($fd, $data);
    }

    /**
     * 运行控制器
     *
     * @param string $uri
     * @param string $method
     *
     * @throws \Exception
     */
    public function runController(string $uri, string $method = "get")
    {
        /* @var Router $router */
        $router = App::getBean('router');

        // 路由解析
        App::profileStart("router.match");
        list($path, $info) = $router->match($uri, $method);
        App::profileEnd("router.match");

        // 路由未定义处理
        if ($info == null) {
            throw new \RuntimeException("路由不存在，uri=".$uri." method=".$method);
        }

        /* @var Controller $controller */
        list($controller, $actionId, $params) = $this->createController($path, $info);

        /* run controller with filters */
        $this->runControllerWithFilters($controller, $actionId, $params);
    }

    /**
     * onReceiver初始化
     *
     * @param array $data RPC包数据
     */
    private function beforeReceiver($data)
    {
        $logid = $data['logid'] ?? uniqid();
        $spanid = $data['spanid'] ?? 0;
        $uri = $data['func'] ?? "null";

        $contextData = [
            'logid'       => $logid,
            'spanid'      => $spanid,
            'uri'         => $uri,
            'requestTime' => microtime(true),
        ];
        RequestContext::setContextData($contextData);
    }

    /**
     * run controller with filters
     *
     * @param Controller $controller 控制器
     * @param string     $actionId   actionID
     * @param array      $params     action参数
     */
    private function runControllerWithFilters(Controller $controller, string $actionId, array $params)
    {
        $request = App::getRequest();
        $response = App::getResponse();


        /* @var FilterChain $filter */
        $filter = App::getBean('filter');

        App::profileStart("filter");
        $result = $filter->doFilter($request, $response, $filter);
        App::profileEnd("filter");

        if ($result) {
            $response = $controller->run($actionId, $params);
            $response->send();
        }
    }
}
