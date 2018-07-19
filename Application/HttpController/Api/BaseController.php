<?php
/**
 * Created by PhpStorm.
 * User: cheng
 * Date: 2018/3/4
 * Time: 0:10
 */

namespace App\HttpController\Api;


use App\Utility\HoldUp;
use App\Utility\Security;
use App\Utility\Status;
use App\Utility\Utils;
use EasySwoole\Config;
use EasySwoole\Core\Component\Di;
use EasySwoole\Core\Http\AbstractInterface\Controller;
use Jenssegers\Blade\Blade;

class BaseController extends Controller
{

    /**
     * 浏览器存储token的cookie key;
     * @var string
     */
    protected $cookiePrefix = "lan_token";

    /**
     * @var \swoole_table  TODO 暂时没有用，需要的话可以在onRequest里加入
     */
    protected $memory;

    /**
     * @var string redis缓存的键
     */
    protected $cacheUri;


    /**
     * @var int 缓存默认60秒 redis
     */
    protected $cacheExpire = 60;

    /**
     * 登录之后token在redis里的前缀
     * @var string
     */
    protected $tokenPrefix = "lan_token:";

    /**
     * 缓存数据在redis里的前缀
     * @var string
     */
    protected $cachePrefix = "lan_cache:";

    /**
     * token默认过期时间
     * @var int
     */
    protected $tokenExpire = 604800; //生成token的过期时间为86400s 一天, 设置的一周

    /**
     * 未认证过的token默认过期时间
     * @var int
     */
    protected $notVerifyTokenExpire = 3600; //生成token的过期时间为3600s 一小时
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * 异步redis
     * @var \App\Vendor\Db\AsyncRedis
     */
    protected $asyncRedis;

    /**
     * @var \Memcached
     */
    protected $memcached;

    protected $TemplateViews = EASYSWOOLE_ROOT . '/Templates/';
    protected $TemplateCache = EASYSWOOLE_ROOT . '/Temp/TplCache';

    /**
     * 返回false 则终止后续调用控制器方法, 直接返回
     * @param $action
     * @return bool|null
     */
    function onRequest($action): ?bool
    {
        //redis 初始化
        $this->redis = Di::getInstance()->get("REDIS")->getConnect();

        //初始化memcached
//        $this->memcached = Di::getInstance()->get("MEMCACHED")->getConnect();
        //异步redis初始化
//        $this->asyncRedis = Di::getInstance()->get("ASYNC_REDIS");
//        $this->shareMemory = ShareMemory::getInstance();
//        $this->memory = Di::getInstance()->get("memory")->getTable();
        /*初始化session超级全局变量*/
        $cookie = $this->request()->getCookieParams();
        /*初始化session超级全局变量, GLOBALS['userInfo'], 此全局变量在单个进程内是全局变量, 所以当每个请求到来时, 需要进行初始化， 否则会是上一个请求的userInfo信息*/
        if(isset($cookie[$this->cookiePrefix]) && !empty($cookie[$this->cookiePrefix])){
            $token = $cookie[$this->cookiePrefix];
            $userInfo = $this->redis->get($this->tokenPrefix.$cookie[$this->cookiePrefix]);
            $GLOBALS["userInfo"] = @json_decode($userInfo, true); //无法解析, 则结果为null
        }
        else{
            //如果用户没有认证过, 初始化基础会话token
            $memberInfo = array();
            $token = $this->tokenStart($memberInfo, $this->notVerifyTokenExpire);
            $this->response()->setcookie($this->cookiePrefix, $token, time() + $this->notVerifyTokenExpire, "/", "", false, true);
            $GLOBALS["userInfo"] = array();
        }
        $GLOBALS['token'] = $token;
        /*校验请求数据是否安全，不安全则退出*/
        if($this->checkSafe() === false){
            return false; //如果请求不安全则直接返回
        }
        /*校验请求的Uri, uri请求拦截器, 这里可以对需要登录或者不需要登录的接口做处理*/
        if($this->checkUri() === false){
            $this->view("Fail/forbidden");
            $this->response()->end();
            return false;  //这里返回false, 如果子类没有重写onRequest此方法, 则直接返回客户端数据, 如果重写了, 根据具体重写规则而定.
        }
        /*初始化缓存uri*/
//        $this->initCacheUri();
        /*检验是否有redis缓存, 有的话则直接返回缓存, 后续方法调用停止。 将缓存的方法都带上Cache, 以作为区分*/
//        $res =  $this->checkCache();
//        if($res === true){
//            return false;
//        }
        return parent::onRequest($action); // TODO: Change the autogenerated stub
    }

    function index()
    {
        // TODO: Implement index() method.
    }

    /**
     * 任何请求检验是否有缓存， 优先返回缓存数据
     * @return bool
     */
    protected function checkCache(){
        $res = $this->redis->get($this->cacheUri);
        if(!empty($res)){
            $this->response()->write($res);
            $this->response()->withHeader('Content-type','application/json;charset=utf-8');
            $this->response()->end(); //特别注意end(), 这里只是限制了不能再写入输出缓存区，但是程序还是会在任何一个钩子函数或者控制器某个方法里继续逐行执行完毕，要想程序不继续执行，只有在onRequest返回false, 终止程序运行！！！
            return true;
        }
        return false;
    }

    /**
     * 设置redis缓存
     * @param $jsonStr string json字符串
     * @param $expire int 缓存到期时间,默认60秒
     */
    protected function setCache($jsonStr, $expire=null){
        if($expire !== null){
            $this->redis->set($this->cacheUri, $jsonStr, $expire);
        }
        else{
            $this->redis->set($this->cacheUri, $jsonStr, $this->cacheExpire);
        }
    }

    /**
     * 初始化redis缓存的请求唯一键
     */
    protected function initCacheUri(){
        $this->cacheUri = "";
        $requests = $this->request()->getRequestParam();
        $uri = $this->request()->getMethod().$this->request()->getUri()->getPath();
        //TODO 目前这种缓存生成方案针对全局缓存, 后期还需针对单个用户的缓存可以带上cookie则为各个用户缓存
        if(!empty($requests)){
            $this->cacheUri = "?";
            foreach ($requests as $k => &$v){
                $this->cacheUri .= "{$k}={$v}&";
            }
//            $this->cacheUri = str_replace(":", "@", $this->cacheUri);
        }
        $this->cacheUri = $this->cachePrefix . $uri . $this->cacheUri;
    }

    /**
     * json输出
     * @param null $data
     * @param null $msg
     * @param int $code 业务自行定义的返回码
     * @param int $status http code
     * @return bool|string
     */
    protected function writeJson($data = null, $msg = null, $code = 200,  $status = 200){
        if(!$this->response()->isEndResponse()){
            $data = Array(
                "code"=> $code,
                "data"=> $data,
                "msg"=>$msg
            );
            $output = json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $this->response()->write($output);
            $this->response()->withHeader('Content-type','application/json;charset=utf-8');
            $this->response()->withStatus($status);
            return $output;
        }else{
            trigger_error("response has end");
            return false;
        }
    }

    /**
     * jsonp输出
     * @param string $callback
     * @param null $data
     * @param int $code
     * @param null $msg
     * @param int $status
     * @return bool|string
     */
    protected function writeJsonp($callback, $data = null, $msg = null, $code = 200,  $status = 200){
        if(!$this->response()->isEndResponse()){
            $data = Array(
                "code"=> $code,
                "data"=> $data,
                "msg"=> $msg
            );
            $output = json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $output = $callback . "({$output})";
            $this->response()->write($output);
            $this->response()->withHeader('Content-type','text/javascript; charset=utf-8');
            $this->response()->withStatus($status);
            return $output;
        }else{
            trigger_error("response has end");
            return false;
        }
    }

    /**
     * 校验请求uri是否需要登录
     * @return bool
     */
    protected function checkUri(){
        $path = $this->request()->getUri()->getPath();
        $path = strtolower($path); //将所有字母转为小写, 确保拦截器设置统一小写
        /*判断此接口是否需要登录, 如果需要，则验证是否登录; 不需要则放行*/
        if(isset(HoldUp::$uri[$path]) && HoldUp::$uri[$path] === 1){
            /*如果含有token数组, 并且非空, 表示登陆过*/
            if(isset($GLOBALS['userInfo']['user_mobile']) && !empty($GLOBALS['userInfo']['user_mobile']) && is_array($GLOBALS['userInfo'])){
                return true;
            }
            return false;
        }
        return true;
    }

    /**
     * 检查请求数据是否安全
     * @return bool
     */
    protected function checkSafe(){
        if(!(Security::check($this->request()->getRequestParam()) && Security::check($this->request()->getCookieParams()))){
            $this->writeJson(null, Status::getReasonPhrase(Status::CHECK_SAFE_FAIL), Status::CHECK_SAFE_FAIL);
            $this->response()->end();
            return false;
        }
        return true;
    }

    /**
     * 生成token 或者 唯一session, 并且将当前回话信息写入此token, TODO 之后可以继续完善
     * @param  array $userInfoArr 用户账户唯一标志(可以是手机号，用户名, 微信id等等)
     * @param  int $expire token redis里的过期时间(单位:秒)
     * @return string|bool 返回token
     */
    protected function tokenStart(&$userInfoArr, int &$expire = 0) {
        $token = "";
        while (1){
            $token = Utils::randomStr(Config::getInstance()->getConf("TOKEN")["length"]);
            /*如果token存在, 则重新生成一个新的token, 不存在则跳出循环*/
            if(!$this->redis->exists($this->tokenPrefix.$token)){
                break;
            }
        }
        /*如果用户指定的过期时间, 则根据具体时间定义设置，否则用默认时间一天*/
        if($expire !== 0){
            $res = $this->redis->set($this->tokenPrefix.$token, json_encode($userInfoArr), $expire);
        }
        else{
            $res = $this->redis->set($this->tokenPrefix.$token, json_encode($userInfoArr), $this->tokenExpire);
        }
        if($res === true){
            return $token;
        }
        return false;
    }

    /**
     * 根据token获取用户信息
     * @param $token
     * @return mixed
     */
    protected function getTokenInfo(&$token){
        $res = $this->redis->get($this->tokenPrefix.$token);
        return json_decode($res, true);
    }

    /**
     * 设置token登录有效时间
     * @param $token
     * @param $userInfo
     * @param int $expire
     * @return bool
     */
    protected function setTokenInfo(&$token, &$userInfo, $expire=0){
        if($expire !== 0){
            return $this->redis->set($this->tokenPrefix.$token, json_encode($userInfo), $expire);
        }
        return $this->redis->set($this->tokenPrefix.$token, json_encode($userInfo), $this->tokenExpire);
    }

    /**
     * 删除token
     * @param $token
     * @return int
     */
    protected function delToken(&$token){
        return $this->redis->del($this->tokenPrefix.$token);
    }

    protected function view($tplName, $tplData = [])
    {
        $blade = new Blade([$this->TemplateViews], $this->TemplateCache);
        $viewTemplate = $blade->render($tplName, $tplData);
        $this->response()->write($viewTemplate);
    }

}