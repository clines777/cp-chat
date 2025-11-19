# Introduction

This is a skeleton application using the Hyperf framework. This application is meant to be used as a starting place for those looking to get their feet wet with Hyperf Framework.

# Requirements

Hyperf has some requirements for the system environment, it can only run under Linux and Mac environment, but due to the development of Docker virtualization technology, Docker for Windows can also be used as the running environment under Windows.

The various versions of Dockerfile have been prepared for you in the [hyperf/hyperf-docker](https://github.com/hyperf/hyperf-docker) project, or directly based on the already built [hyperf/hyperf](https://hub.docker.com/r/hyperf/hyperf) Image to run.

When you don't want to use Docker as the basis for your running environment, you need to make sure that your operating environment meets the following requirements:  

 - PHP >= 8.1
 - Any of the following network engines
   - Swoole PHP extension >= 5.0，with `swoole.use_shortname` set to `Off` in your `php.ini`
   - Swow PHP extension >= 1.3
 - JSON PHP extension
 - Pcntl PHP extension
 - OpenSSL PHP extension （If you need to use the HTTPS）
 - PDO PHP extension （If you need to use the MySQL Client）
 - Redis PHP extension （If you need to use the Redis Client）
 - Protobuf PHP extension （If you need to use the gRPC Server or Client）

# Installation using Composer

The easiest way to create a new Hyperf project is to use [Composer](https://getcomposer.org/). If you don't have it already installed, then please install as per [the documentation](https://getcomposer.org/download/).

To create your new Hyperf project:

```bash
composer create-project hyperf/hyperf-skeleton path/to/install
```

If your development environment is based on Docker you can use the official Composer image to create a new Hyperf project:

```bash
docker run --rm -it -v $(pwd):/app composer create-project --ignore-platform-reqs hyperf/hyperf-skeleton path/to/install
```

# Getting started

Once installed, you can run the server immediately using the command below.

```bash
cd path/to/install
php bin/hyperf.php start
```

Or if in a Docker based environment you can use the `docker-compose.yml` provided by the template:

```bash
cd path/to/install
docker-compose up
```

This will start the cli-server on port `9501`, and bind it to all network interfaces. You can then visit the site at `http://localhost:9501/` which will bring up Hyperf default home page.

## Hints

- A nice tip is to rename `hyperf-skeleton` of files like `composer.json` and `docker-compose.yml` to your actual project name.
- Take a look at `config/routes.php` and `app/Controller/IndexController.php` to see an example of a HTTP entrypoint.

**Remember:** you can always replace the contents of this README.md file to something that fits your project description.

===================================================================================================================================================================

PHP版本: 8.3
Swoole版本: >= 5.1
Mysql: >= 8.0
Redis: >= 5.0 (需支援Redis Stream)

开发注意事项:

 - 服务启动 简易 php bin/hyperf.php start (本地开发用, 关闭直接 Ctrl+C 即可) 
 - 服务启动 测试环境以上 nohup php bin/hyperf.php start > runtime/logs/hyperf.log 2>&1 &

 - 服务关闭 测试环境以上 kill -TERM $(cat runtime/hyperf.pid) (不可用kill -9强杀, 会收不到讯号导致关闭流程未执行)

 - 本地简易测试用view(叫AI生的view, 需要其他东西自己再加): cp-chat/dev_view/chat.html, 直接开或本地架个web server访问, 范例路径:
   http://localhost:8083/chat.html?l=001B666B2D39056F993A83F8A44e325CE0C5D981758161413172
   参数l为登入token, 从彩票端 talk/cp-chat/get-token 拿

 - Model生成指令: php bin/hyperf.php gen:model {table_name} -F -R --with-comments

 - 程式码相关:
   1. 物件初始化方法
   Hyperf启动的服务Server, 不管是WebAPI或是WebSocket都是记忆体常驻程式, 所以凡是自定义的Service, Repository等商业逻辑密集的class请都交由容器管理(后续如果要套用AOP, 前提条件也是该物件必须由Container管理), 避免记忆体泄漏. 其他像Helper这种纯工具类class则不用. 
   物件初始化范例语法: (1) $fooService = Container::get(FooService::class) (2) $barService = make(BarService::class, ['initParam' => $init]) 
      (3) $fooService = Hyperf\Context\ApplicationContext::getContainer()->get(FooService::class);
   2. DB操作的code都在Repository中(类似DAO, 只是定义上不太一样)
   3. 如果要使用ORM请避免捞出大量Model型别的物件, Hyperf的相关元件在这方面没有特别优化
   4. 目前web API跟Websocket资料交换格式都是采json, Websocket使用MsgPayload class处理, web API则是使用CommonResult class.
   5. API的Response请尽量使用CommonResult物件处理, 该物件也经常被用作其他function执行结果的返回值.
   6. WebSocket资料交换的格式分为type(消息类型, string), data(附带的数据, array), 额外的meta栏位是给client使用的自由栏位, 后端不处理, 有收到就直接附带打回给client, 相关物件为MsgType(用于消息类型定义)
      跟MsgPayload(消息载体)
   7. 框架预设启用Swoole Hook(见bin/hyperf.php), hook行为是拦截底层function呼叫, 并以其他function替代, 大部分资源操作function(档案操作=f开头系列, DB=PDO或Redis等)
      被hook过后发生错误时行为是直接抛例外
