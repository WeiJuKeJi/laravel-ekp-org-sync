# Laravel EKP 组织同步扩展

通用 Composer 包 `weijukeji/laravel-ekp-org-sync`。读取蓝凌 EKP 组织接口，把公司、部门、人员、岗位及群组写入 IAM 的可选组织目录，提供全量、增量、定时同步和运行记录。默认关闭，不包含客户地址、凭据或业务公司映射。

源码仓库：[WeiJuKeJi/laravel-ekp-org-sync](https://github.com/WeiJuKeJi/laravel-ekp-org-sync)。源码版本为 Git 标签 `v1.0.0`，位于 `master` 分支；尚未发布至 Packagist。配套 IAM 1.5.0 候选源码仍需单独准备。PHP 8.2+，依赖声明 Laravel 11/12/13。实际验证版本与数据库见[协议及验证边界](docs/设计方案/组织同步协议与故障恢复.md)。

## 安装与启用

开发期间在宿主的 composer.json 中添加这两个包的 path 仓库，分别设置本地版本别名 IAM 1.5.0、同步包 1.0.0；仓库相对路径由宿主决定。Composer 不继承依赖包内的 repositories 配置，必须在宿主声明两个仓库。本扩展源码可锁定到 `v1.0.0`；配套 IAM 仍使用本地候选源码。生产部署前须准备可安装的 IAM 版本和宿主锁文件，不把本地别名当作 IAM 的发布记录。

```json
{"type":"path","url":"/path/to/laravel-iam","options":{"symlink":true,"versions":{"weijukeji/laravel-iam":"1.5.0"}}}
```

同步包仓库同样配置 `weijukeji/laravel-ekp-org-sync: 1.0.0`。安装后合并配置，保持宿主既有 IAM 配置：

```php
// config/iam.php 新增
'directory' => ['enabled' => true, 'route_middleware' => ['api', 'auth:sanctum']],
// config/ekp-org-sync.php
return array_replace(require base_path('vendor/weijukeji/laravel-ekp-org-sync/config/ekp-org-sync.php'), ['enabled' => true]);
```

执行普通 `php artisan migrate`，只增加可选目录与同步表，不修改 users、旧部门表或已有权限。配置缓存开启时先重新生成缓存。迁移 down 拒绝删表；停用功能保留数据，需要整体恢复时使用宿主经确认的备份。

## 来源与手工同步

```bash
php artisan ekp-org:source "示例组织来源" --url=https://ekp.example.test --username=api-reader
# 交互式隐藏密码输入；自动加密保存，禁止把密码写到命令参数或日志
php artisan ekp-org:sync 1 --full --dry-run
php artisan ekp-org:sync 1 --full
php artisan ekp-org:sync 1
```

APP_KEY 用于来源密码加密，必须随宿主安全备份；换密钥需按 Laravel 的密钥轮换方案处理已有密文。来源创建命令显示有效数据库目标，并拒绝同 provider/name 重复来源。来源的稳定实例 UUID 与外部 GUID 绑定，域名变化不能另建身份来合并旧记录。

`--dry-run` 执行相同的读取、完整性、树关系和缺失阈值检查，输出 planned 的新增/更新/缺失数量，不写目录或推进源游标；会保存一条预览运行记录。失败返回固定错误码，不保留源响应正文或凭据。

## 定时执行

启用后向 Laravel Scheduler 注册每分钟 `ekp-org:sync --due`，每个来源默认 5 分钟增量、24 小时全量校准。生产由既有 cron 或进程管理器运行 Laravel Scheduler，避免重复配置两套调度。

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /path/to/scheduler.log 2>&1
```

本地或只需运行组织同步时，可以单独启动 `php artisan ekp-org:work --sleep=30`。它只执行本包到期来源，不执行宿主其他计划任务；Ctrl+C 或 SIGTERM 正常停止。该进程不是开机自启服务。`--once` 执行一次；抓取与应用互斥租约 900 秒，崩溃后到期可恢复。

增量回看已提交时间前 60 分钟，按 GUID、源修改时间和内容摘要处理重复；同毫秒记录超过 count 时全部保留。空页返回的服务器当前时间不会推进游标。周期全量用于删除/缺失及较迟的变化校准，不能承诺任意回写旧时间的修改会在下一次增量立即出现。

## 管理 API 与授权

默认前缀 `/api/ekp-org-sync`，宿主可改为 `/api/v1/ekp-org-sync`：

| 请求 | 权限 | 行为 |
| --- | --- | --- |
| GET /sources | ekp-org-sync.sources.view | 来源策略与状态，隐藏用户名、密码、租约所有者 |
| GET /runs | ekp-org-sync.sources.view | 分页运行历史，可按 source_id 过滤 |
| POST /sources/{id}/sync | ekp-org-sync.sources.manage | body mode=full/incremental，返回 202，写入调度请求，由后台执行 |

IAM 目录 GET entries/tree/sources 使用 `iam.directory.view`。仅同步组织不会自动授权。宿主使用严格权限标签时须为以上资源登记中文 labels，并按自身策略为指定管理员授权；本包不自动创建管理员或业务范围。

目录人员与登录用户分开，不要求每个人具有唯一邮箱。默认仅登记外部身份，不创建账号、复制 OA 密码、修改登录名或授予角色。已有账号可通过 IAM `IdentityLinker::bind()` 显式关联，默认不托管访问。只有明确开启 `managesAccess=true` 的关联在源停用或全量缺失时禁用本地账号并撤销 token，保留密码、角色及业务历史；源复活不自动重新启用。受保护管理员拒绝托管。

详细流程、错误恢复与测试见[组织同步协议与故障恢复](docs/设计方案/组织同步协议与故障恢复.md)。

## 可选同步人员账号

IAM的directory.accounts仍默认关闭。宿主明确启用来源白名单后，目录实际写入事务会由IAM同时维护User及外部身份；同步包不授角色、权限或业务范围，不按姓名/邮箱/手机号合并账号。EKP明确返回canLogin时，将布尔值清洗为目录links.can_login，供IAM登录资格策略使用；该字段不允许任意元数据进入持久化。来源无效仍优先禁止登录，不因为canLogin=true恢复失效人员。

普通升级需要执行IAM新增的provisioned标记迁移。旧目录模式不变，dry-run不创建账号。账号冲突聚合结果位于运行summary.applied.accounts；详细策略、技术邮箱与SSO验证边界见IAM的组织目录接入指南。未包含CAS票据验证、登录回调或自动授权。
