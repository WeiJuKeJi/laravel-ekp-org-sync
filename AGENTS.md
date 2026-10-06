# EKP组织同步扩展包约定

- 通用Laravel扩展，只处理EKP协议、同步运行和IAM目录适配，不包含客户域名、凭据、资金业务或自动授权。
- 默认关闭，IAM目录为可选依赖能力。保留IAM旧接口、User和Department语义。
- 密码字段和任意customProps必须在进入持久化／日志前丢弃；连接密码仅加密保存。
- 禁止fresh、wipe、RefreshDatabase及删表重建；验证使用唯一隔离库和普通migrate。
- 不自动委派，不提交／推送／发布，除非用户明确要求。
- 正式中文文档分类放docs/，实质工作记录docs/工作日志/。
