<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 随心瑜 KeepYoga（只读接入，凭据仅存服务端）
    'ky' => [
        'phone' => env('KY_PHONE'),
        'password' => env('KY_PASSWORD'),
        // 本地门店名 → 随心瑜 venue_id。
        //
        // 这份映射原先硬编码在 KyController 的三个方法里，加第三家店要改三处、
        // 漏一处就会出现"某店在合同页有数据、在经营概览里没有"这类诡异现象。
        // 放到配置里只改一处；env 覆盖用 KY_STORES='{"绿地店":"1","东部店":"4250"}'。
        'stores' => json_decode((string) env('KY_STORES', ''), true) ?: [
            '绿地店' => '1',
            '东部店' => '4250',
        ],
    ],

    // Gitee API（版本更新对比远端提交）
    'gitee' => [
        'token' => env('GITEE_TOKEN'),
    ],

    // AI 大模型代理（共用一把 key，按账号日配额限制成本滥用）
    'ai' => [
        'daily_quota' => env('AI_DAILY_QUOTA', 200),
        // 单张图片 base64 解码后的字节上限（默认 1.5MB，与前端压缩口径配套）。
        // 调大前先确认 PHP post_max_size 与前置代理的 body 限制够用。
        'max_image_bytes' => env('AI_MAX_IMAGE_BYTES', 1572864),
        // 多图合计（解码后）字节上限（默认 4MB）。单张 ×3 的 base64 膨胀后约 6MB，
        // 已在默认 post_max_size=8M 的 75%，这条是应用层唯一能提前拦下的地方。
        'max_total_image_bytes' => env('AI_MAX_TOTAL_IMAGE_BYTES', 4194304),
    ],

    // 微信小程序（授权登录是 P2 合规硬门：运营规范 2.12.6 要求「存在账号体系时
    // 必须提供微信授权登录」）。secret 只用于服务端 code2session，
    // 绝不下发前端、不进日志、不进响应体。
    'wechat' => [
        'appid' => env('WECHAT_MINI_APPID', ''),
        'secret' => env('WECHAT_MINI_SECRET', ''),

        /*
        |--------------------------------------------------------------------------
        | 订阅消息模板目录（模板通知）
        |--------------------------------------------------------------------------
        | 键是**我们自己的稳定标识**（小程序与库里的 wx_subscriptions.template_key 都用它），
        | 与微信侧的 template_id 解耦 —— 重新申请模板只换 env，不丢历史授权账本。
        |
        | fields 的每一项是 `关键词 => ['label' => 中文名, 'key' => 语义值键]`：
        |  - 关键词（thing1/name2/time3…）由公众号后台**所选模板**决定，不是我们起的名字。
        |    申请到的模板长什么样，就把关键词改成那个模板的参数名；
        |  - label 只用于接口下发与文档展示；
        |  - key 是服务端传值用的语义键（customerName / staffName / venue / role / time），
        |    换模板时只要关键词对得上，代码不用改。
        |
        | 留空 template_id = 该模板「未配置」：接口 configured=false、页面显示「待管理员配置模板」，
        | 发送走 skipped（不报错、不影响主流程）。
        |
        | 申请步骤与字段映射表见 docs/miniapp-migration/12-模板通知落地手册.md。
        */
        'templates' => [
            // 新客资分配到某位老师名下（LeadController::store/update 触发）
            'leadAssigned' => [
                // 线上实际申请到的是「客户分配提醒」（模板编号 730，类目：信息查询，场景：客资分配）。
                // 该模板同时覆盖「新客资分配」与「会员归属变更」两种事件 —— 一次性订阅本来就是
                // 一次授权一条消息，拆成两个 key 抢同一份授权只会一边发不出去，所以会员事件
                // 也复用本 key（见 WeChatSubscribeMessage::notifyMemberAssigned）。
                'title' => '客户分配提醒',
                'desc' => '有新客资或会员归属分配到您名下时通知',
                'template_id' => env('WECHAT_TPL_LEAD_ASSIGNED', ''),
                // 临时停用某条通知就改 false（保留 template_id，恢复时改回 true 即可；
                // 清空 id 反而要重新抄一遍，更容易配错）
                'enabled' => true,
                // 留空 = 用户点通知进小程序首页；要深链就填小程序页面路径（填错会 41030 发不出去）
                'page' => '',
                // 开发/体验版调试时改 developer / trial，生产保持 formal
                'miniprogram_state' => 'formal',
                // 关键词必须与「我的模板 → 详情」里的实号参数名逐字一致，否则下发回 47003。
                // 本模板实号：客户姓名 {{name3.DATA}} / 客户手机 {{phone_number4.DATA}} /
                // 被分配人姓名 {{name2.DATA}} / 分配时间 {{time6.DATA}} / 温馨提示 {{thing5.DATA}}
                'fields' => [
                    'name3' => ['label' => '客户姓名', 'key' => 'customerName'],
                    'phone_number4' => ['label' => '客户手机', 'key' => 'phone'],
                    'name2' => ['label' => '被分配人姓名', 'key' => 'staffName'],
                    'time6' => ['label' => '分配时间', 'key' => 'time'],
                    'thing5' => ['label' => '温馨提示', 'key' => 'remark'],
                ],
            ],

            // 会员归属（会籍顾问 / 负责人）变更到某人名下（CustomerController::update 触发）
            // 模板选择由服务层按配置自动决定（WeChatSubscribeMessage::notifyMemberAssigned）：
            //  - 本键配了 template_id 且 enabled=true → 用独立的 memberAssigned 模板；
            //  - 否则回退 leadAssigned（线上目前唯一在用的「客户分配提醒」730）。
            // 填 WECHAT_TPL_MEMBER_ASSIGNED 即自动切换，代码不用改；切换后小程序
            // 「模板通知」页会多出一项，用户需要为该模板单独授权（一次性授权，按 key 记账）。
            'memberAssigned' => [
                'title' => '会员归属变更提醒',
                'desc' => '有会员的归属变更到您名下时通知',
                'template_id' => env('WECHAT_TPL_MEMBER_ASSIGNED', ''),
                'enabled' => true,
                'page' => '',
                'miniprogram_state' => 'formal',
                // 申请到模板后按「我的模板 → 详情」的实号参数名逐字校正，否则下发回 47003
                // 线上实号「会员关联通知」（模板编号 5394）：昵称 {{name1.DATA}} /
                // 关联时间 {{date2.DATA}} / 温馨提示 {{thing3.DATA}}
                'fields' => [
                    'name1' => ['label' => '会员称呼', 'key' => 'customerName'],
                    'date2' => ['label' => '关联时间', 'key' => 'time'],
                    'thing3' => ['label' => '温馨提示', 'key' => 'remark'],
                ],
            ],

            // 待办提醒（TaskController::store 新建任务时触发）
            //
            // ⚠️ 截止时间按 **thing 类型**下发（`tasks.deadline` 是自由文本列：
            // 可能为空、「2026-11-05」、「本周内」）。服务层会先把可解析的日期
            // 格式化成「2026年11月5日 18:00」，其余原样下发、空值给「待定」。
            // 如果你申请到的模板把截止时间做成了 time 类型字段，把下面 thing2 改成
            // time2（关键词必须与模板实号一致），并在服务层把 deadline 固定成时间串。
            'todoReminder' => [
                'title' => '待办提醒',
                'desc' => '有新待办指派给您时通知',
                'template_id' => env('WECHAT_TPL_TODO', ''),
                'enabled' => true,
                'page' => '',
                'miniprogram_state' => 'formal',
                // 线上实号「任务接收通知」（模板编号 802）：任务标题 {{thing3.DATA}} /
                // 执行人 {{name5.DATA}} / 截止时间 {{date6.DATA}} / 温馨提示 {{thing24.DATA}}
                'fields' => [
                    'thing3' => ['label' => '待办事项', 'key' => 'title'],
                    'name5' => ['label' => '执行人', 'key' => 'staffName'],
                    'date6' => ['label' => '截止时间', 'key' => 'deadline'],
                    'thing24' => ['label' => '温馨提示', 'key' => 'remark'],
                ],
            ],
        ],
    ],

    // 自助注册（默认关闭；开启后请同时设置 REGISTRATION_CODE 邀请码兜底）
    'registration' => [
        'enabled' => env('REGISTRATION_ENABLED', false),
        'code' => env('REGISTRATION_CODE', ''),
    ],

    // 官网 WordPress（小程序「到店指引」博客栏目数据源；生产与 API 同机）
    // base_url 可用 WP_BLOG_BASE_URL 覆盖（本地开发指向测试 WP 时用）
    'wp_blog' => [
        'base_url' => env('WP_BLOG_BASE_URL', 'https://www.nbyimai.com'),
    ],

];
