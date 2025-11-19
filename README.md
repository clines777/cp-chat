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
   7. 避免直接new PDO()或new Redis()的行为, 这些原生方式没有经过Swoole Hook, 在进行资源操作时是会阻塞的
   8. 框架预设启用Swoole Hook(见bin/hyperf.php), hook行为是拦截底层function呼叫, 并以其他function替代, 大部分资源操作function(档案操作=f开头系列, DB=PDO或Redis等)
      被hook过后发生错误时行为是直接抛例外.
   9. 本地红包开发问题, cp-chat正常都在自己local开发, 所以会有本地DB跟测试DB(不直接连测试DB是因为VPN的关系导致本地开发连过去会常态性延迟), 但因为游戏专属红包需要同步给彩票储存, 
      所以本地创的红包跟测试创的红包在对应彩票群聊红包table时会遇到id重复问题, 
      此时可将本地lucky_money table id的auto_increment设很大, 这样就可避免ID碰撞问题.

   目前未解决需求:
 - 红包领取状态即时更新: 目前发红包时, 会把红包做成一笔新的系统群或一般群的讯息, 其中extra栏位包含红包ID, 结束时间等相关静态资料, 但因为讯息仅有一笔, 而领取状态是根据用户判断, 所以必须在将讯息送给client时判断该用户的领取状态, 
   但目前考虑到效能问题先不做这块. 目前预案是另外再开一张table, 其中检查的key是红包ID跟user_id, 另一个栏位纪录已领取状态(没领过就不会有纪录), 捞讯息给用户时把红包ID提出来跟用户ID去对这张纪录表捞出对应纪录, 没有对应纪录就是没领过
   另外红包结算时(已抽完, 收回跟超时三种)去更新这笔红包讯息的红包状态, 因为这是每个红包的统一状态, 所以不用管用户是谁.
 - 置顶讯息跳转, 一般作法是client会储存所有用户的历史讯息, 当用户点击置顶讯息时, client会自动跳转到该笔, 但如果依照此作法, 目前此专案client只有web版, 虽然也可以让client把历史讯息都存在浏览器(by域名), 
   可一旦中间用户换了浏览器或是前端域名更换
   (eg. 被检举), 造成client找不到历史讯息, 就必须经常性要求server进行历史讯息同步导致流量浪费, 因此目前群内取讯息的机制是进群时先给一批最新讯息, 当用户往上拉时, 会透过socket送历史讯息给client, 当有用户发新讯息时后端会每笔进行广播.

   未完整实作机制:
 - 防止一般群内用户发送网址, 这个功能是防刷子预作用的, 由于当前已经进入测试末期, 后台就先不添加栏位设定, 具体机制在SendChatHandler中有实现, 该栏位预设是使用group.allow_url栏位, 预设开启(值为1), 
   临时需要挡的话就把group.allow_url改成0, 需注意此功能不会套用在群管理跟群主身上.
