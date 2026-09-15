<?php

namespace App\Services;

/**
 * 课后分析 → 训练方向 规则引擎。
 *
 * 数据来源：全职老师提交的《体验课课后分析＋训练计划模板》。
 * 对应关系：
 *  - TYPES         ←「分类型训练方向预设」+「训练方向一览表」（5 类 × 三阶段 / 频率 / 课程 / 边界）
 *  - OBSERVATIONS  ←「课后分析＋训练计划」主表 6 组 31 项 ＋「观察项→训练方向参考」16 条映射
 *  - RED_FLAGS     ←「使用说明」红线清单
 *
 * 为什么是规则引擎而不是 AI：
 *  1. 响应快、结果稳定（同一输入同一输出）、零调用成本；
 *  2. 业务方（店长/教研）可直接维护文案，不需要发版；
 *  3. 关键在于「观察项参与组装」——同一个「塑形线条」类型下，
 *     臀肌参与少的学员和核心不稳的学员拿到的三阶段不同，
 *     从数据流层面解决照抄预设导致的千人一面。
 */
final class PostClassPlanEngine
{
    /** 学员类型 */
    public const TYPES = [
        '产后恢复' => [
            'label' => '产后恢复',
            'alias' => '产后妈妈',
            'phases' => [
                '呼吸重建与腹压管理 → 深层核心与盆底协同 → 骨盆稳定。体位以仰卧、坐姿、四点支撑为主，幅度小、速度慢、不憋气。',
                '核心支撑进阶，加入臀腿与下肢力线；同时调整抱娃体态带来的肩颈胸背紧张。',
                '进入塑形线条阶段：腰腹、臀腿、肩背分区塑造，可开始用器械阻力。',
            ],
            'frequency' => ['min' => 1, 'max' => 2, 'stableMin' => 2, 'stableMax' => 3, 'text' => '每周 1–2 次起，稳定后 2–3 次'],
            'courses' => ['产后修复', '垫上普拉提', '核心床'],
            'boundary' => '产后早期、剖腹产恢复期、盆底症状明显（下坠感、漏尿明显增加）或有医生特殊建议 → 先专业评估，不直接上高负荷核心动作。',
            'keywords' => ['产后', '妈妈', '抱娃', '漏尿', '盆底', '剖腹', '顺产', '哺乳'],
        ],
        '塑形线条' => [
            'label' => '塑形线条',
            'alias' => '想要线条和身形',
            'phases' => [
                '建立发力路径：呼吸＋核心＋骨盆中立，先找到臀腿与肩背的发力感。轻重量、多次数、慢节奏，不求强度。',
                '分区强化：臀腿塑形 / 肩背塑形 / 腰腹核心。先单侧后双侧，每 2 周只调一个变量（重量或组数）。',
                '全身整合＋循环训练，提升训练密度与消耗，把线条稳定下来。',
            ],
            'frequency' => ['min' => 1, 'max' => 2, 'stableMin' => 2, 'stableMax' => 2, 'text' => '每周 2 次最佳，最低 1 次'],
            'courses' => ['核心床', '稳踏椅', '弹力带塑形', '维密燃脂'],
            'boundary' => '塑形练的是线条和支撑，不是减脂手段，不承诺围度变化。变化先出现在体态和穿衣效果：通常 3–4 周有感，8 周左右见轮廓。',
            'keywords' => ['线条', '塑形', '瘦一点', '手臂', '肚子松', '臀', '直角肩', '身形'],
        ],
        '体态调整' => [
            'label' => '体态调整',
            'alias' => '含胸圆肩、骨盆问题',
            'phases' => [
                '呼吸与胸椎活动度 → 肩胛稳定与上背激活 → 建立支撑。先松解再训练，不要只拉不练。',
                '分区调整：上背与肩颈 / 骨盆与髋 / 下肢力线，加入单侧控制与左右差异调整。',
                '把正确排列放进站姿、行走和日常动作里，形成习惯，配合器械强化维持。',
            ],
            'frequency' => ['min' => 1, 'max' => 2, 'stableMin' => 1, 'stableMax' => 2, 'text' => '每周 1–2 次，配合每天 5–10 分钟自我练习'],
            'courses' => ['精准正位', '核心床', '墙绳瑜伽', '肩颈类'],
            'boundary' => '说「支撑、稳定、发力更顺、更挺拔」，不说「矫正、修复」。有疼痛、麻木或放射痛 → 先专业评估。',
            'keywords' => ['含胸', '圆肩', '脖子', '肩颈', '骨盆前倾', '挺拔', '体态', '久坐'],
        ],
        '康复' => [
            'label' => '康复',
            'alias' => '有旧伤或慢性不适',
            'phases' => [
                '低负荷、可控幅度、多支撑。先建立呼吸与核心支撑，确认训练后无异常反应，再考虑加量。',
                '在无痛范围内逐步增加阻力和幅度，加强薄弱环节：臀中肌、上背、足踝与足弓。',
                '功能整合：站立、走路、上下楼、搬东西等日常动作，把训练成果放回生活里。',
            ],
            'frequency' => ['min' => 1, 'max' => 2, 'stableMin' => 1, 'stableMax' => 2, 'text' => '每周 1–2 次'],
            'courses' => ['康复理疗', '精准正位', '垫上普拉提'],
            'boundary' => '不诊断、不解释影像。出现麻木、放射痛、进行性肌力下降、夜间痛、红肿热痛 → 建议进一步专业评估，不硬接课，只做低强度低风险版本。',
            'keywords' => ['腰不好', '膝盖', '肩抬不起来', '受过伤', '医生说', '旧伤', '疼'],
        ],
        '减脂体能' => [
            'label' => '减脂体能',
            'alias' => '想瘦、体力差',
            'phases' => [
                '先把动作做对、节奏建立起来，不追求出汗和强度；同步给饮食建议，先控饮食再谈训练量。',
                '加入循环训练和低冲击有氧，提升训练密度；用能否说出短句判断强度是否合适。',
                '全身整合＋规律频率，把训练变成能长期坚持的习惯。',
            ],
            'frequency' => ['min' => 2, 'max' => 3, 'stableMin' => 2, 'stableMax' => 3, 'text' => '每周 2–3 次'],
            'courses' => ['维密燃脂', '波速球', '核心床', '室内蹦极'],
            'boundary' => '不承诺减重数字，不用体重作为唯一标准。减脂核心在饮食与频率，训练负责保肌和提升消耗。',
            'keywords' => ['减脂', '瘦', '出汗', '体力', '减重', '消耗', '燃脂'],
        ],
    ];

    /** 阶段骨架：时长按月与周定义，次课数由周数 × 每周频率换算 */
    public const PHASES = [
        ['key' => 'phase1', 'name' => '第一阶段（打基础）', 'duration' => '约 1–2 个月', 'weeks' => [4, 8]],
        ['key' => 'phase2', 'name' => '第二阶段（针对性改善）', 'duration' => '约 2–3 个月', 'weeks' => [9, 13]],
        ['key' => 'phase3', 'name' => '第三阶段（巩固塑形）', 'duration' => '3 个月后', 'weeks' => [14, 26]],
    ];

    /**
     * 观察项目录。
     * group 分组；fast=进入当场快筛（其余为详细档）；level 支持轻/中/重；
     * direction 为「观察项→训练方向参考」的对应方向；courses 为该方向的建议课程；
     * phase 表示该观察主要影响哪个阶段（1/2/3）；guest 为对客表述（讲观察不讲诊断）。
     */
    public const OBSERVATIONS = [
        // A 体态与站姿
        'head_forward' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '头前引（下巴往前伸）', 'fast' => true, 'phase' => 1,
            'direction' => '先开胸椎、加强上背支撑，改善含胸和肩颈代偿；顺序是上背激活 → 开胸 → 肩胛稳定',
            'courses' => ['核心床', '墙绳瑜伽', '肩颈类'],
            'guest' => '头有往前伸的倾向，颈后侧一直在代偿',
            'progress' => '你今天最明显的进步，是头的位置比刚开始回来了——颈后侧不再一直绷着',
        ],
        'round_shoulder' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '圆肩 / 含胸', 'fast' => true, 'phase' => 1,
            'direction' => '先开胸椎、加强上背支撑，改善含胸和肩颈代偿；顺序是上背激活 → 开胸 → 肩胛稳定',
            'courses' => ['核心床', '墙绳瑜伽', '肩颈类'],
            'guest' => '肩有点往前包，上背的支撑还没有建立起来',
            'progress' => '你今天最明显的进步，是肩打开了一些，不再像刚开始那样一直往前包着',
        ],
        'thoracic_kyphosis' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '胸椎后凸（上背圆拱）', 'fast' => false, 'phase' => 1,
            'direction' => '先开胸椎、加强上背支撑，改善含胸和肩颈代偿；顺序是上背激活 → 开胸 → 肩胛稳定',
            'courses' => ['墙绳瑜伽', '核心床', '肩颈类'],
            'guest' => '上背比较圆拱，胸椎的活动度用得不充分',
            'progress' => '你今天最明显的进步，是上背比刚开始更好展开了',
        ],
        'pelvis_ant' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '骨盆前倾', 'fast' => true, 'phase' => 1,
            'direction' => '骨盆稳定与髋部灵活一起练，配合核心和臀腿支撑，改善站姿发力',
            'courses' => ['精准正位', '核心床', '臀腿类'],
            'guest' => '骨盆有前倾的倾向，腰部的压力会偏大',
            'progress' => '你今天最明显的进步，是骨盆的位置比刚开始更好找了，腰的压力小了',
        ],
        'pelvis_post' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '骨盆后倾', 'fast' => false, 'phase' => 1,
            'direction' => '骨盆稳定与髋部灵活一起练，配合核心和臀腿支撑，改善站姿发力',
            'courses' => ['精准正位', '核心床', '臀腿类'],
            'guest' => '骨盆偏后倾，臀部发力的位置不太好找',
            'progress' => '你今天最明显的进步，是臀部发力的感觉比刚开始好找了',
        ],
        'pelvis_lateral' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '骨盆侧倾或旋转', 'fast' => false, 'phase' => 2,
            'direction' => '骨盆稳定与髋部灵活一起练，配合核心和臀腿支撑，改善站姿发力',
            'courses' => ['精准正位', '核心床', '臀腿类'],
            'guest' => '骨盆两侧不在一个水平上，走路时受力会偏一边',
            'progress' => '你今天最明显的进步，是两侧受力比刚开始更平均了',
        ],
        'knee_hyper' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '膝超伸', 'fast' => false, 'phase' => 1,
            'direction' => '调整下肢力线：足弓唤醒、膝踝对齐、臀部参与，避免深蹲到底和跳跃',
            'courses' => ['精准正位', '臀腿塑形'],
            'guest' => '站立时膝盖有往后顶的倾向，关节在承担本该由肌肉承担的力',
            'progress' => '你今天最明显的进步，是站姿里膝盖不再像刚开始那样往后顶了',
        ],
        'knee_valgus' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '膝内扣', 'fast' => true, 'phase' => 1,
            'direction' => '调整下肢力线：足弓唤醒、膝踝对齐、臀部参与，避免深蹲到底和跳跃',
            'courses' => ['精准正位', '臀腿塑形'],
            'guest' => '下蹲时膝盖容易往里走，臀部没有充分参与',
            'progress' => '你今天最明显的进步，是下蹲时膝盖的方向比刚开始好控制了',
        ],
        'foot_arch' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '足弓塌陷 / 踝外翻', 'fast' => false, 'phase' => 1,
            'direction' => '调整下肢力线：足弓唤醒、膝踝对齐、臀部参与，避免深蹲到底和跳跃',
            'courses' => ['精准正位', '臀腿塑形'],
            'guest' => '足弓比较平，站久了脚踝和小腿容易累',
            'progress' => '你今天最明显的进步，是脚底踩地的感觉比刚开始清楚了',
        ],
        'asymmetry' => [
            'group' => 'A', 'groupLabel' => '体态与站姿', 'label' => '左右明显不对称', 'fast' => false, 'phase' => 2,
            'direction' => '以弱侧为准安排次数和幅度，先做单侧控制，再进入双侧整合',
            'courses' => ['核心床', '稳踏椅', '私教'],
            'guest' => '左右两侧的差别比较明显，需要先按弱侧来安排',
            'progress' => '你今天最明显的进步，是弱侧也能跟上来了，两侧差别在缩小',
        ],

        // B 呼吸与腹压
        'chest_breath' => [
            'group' => 'B', 'groupLabel' => '呼吸与腹压', 'label' => '以胸式呼吸为主', 'fast' => false, 'phase' => 1,
            'direction' => '先调呼吸模式和腹压方向，用呼吸带动核心参与，再进动作',
            'courses' => ['垫上普拉提', '芳香瑜伽', '精准正位'],
            'guest' => '呼吸主要在上面，腹部和肋骨的配合还没建立',
            'progress' => '你今天最明显的进步，是呼吸比刚开始更顺了，腹部也不再一直往外顶',
        ],
        'breath_shallow' => [
            'group' => 'B', 'groupLabel' => '呼吸与腹压', 'label' => '呼吸偏浅', 'fast' => true, 'phase' => 1,
            'direction' => '先调呼吸模式和腹压方向，用呼吸带动核心参与，再进动作',
            'courses' => ['垫上普拉提', '芳香瑜伽', '精准正位'],
            'guest' => '呼吸偏浅，核心不太容易被带动起来',
            'progress' => '你今天最明显的进步，是呼吸比刚开始深了，核心更容易被带起来',
        ],
        'breath_hold' => [
            'group' => 'B', 'groupLabel' => '呼吸与腹压', 'label' => '容易憋气', 'fast' => false, 'phase' => 1,
            'direction' => '先调呼吸模式和腹压方向，用呼吸带动核心参与，再进动作',
            'courses' => ['垫上普拉提', '芳香瑜伽', '精准正位'],
            'guest' => '用力的时候容易不自觉憋气，腹压方向会乱',
            'progress' => '你今天最明显的进步，是用力时能保持呼吸了，不再一直憋着',
        ],
        'rib_flare' => [
            'group' => 'B', 'groupLabel' => '呼吸与腹压', 'label' => '肋骨外翻', 'fast' => true, 'phase' => 1,
            'direction' => '呼吸配合肋骨下沉，练腹压管理和核心支撑，减少腹部鼓起代偿',
            'courses' => ['垫上普拉提', '核心床'],
            'guest' => '肋骨有点往外翻，腹部容易往外顶',
            'progress' => '你今天最明显的进步，是呼气时肋骨能往下沉了，腹部不再往外鼓',
        ],
        'low_pressure' => [
            'group' => 'B', 'groupLabel' => '呼吸与腹压', 'label' => '腹压方向不明显', 'fast' => false, 'phase' => 1,
            'direction' => '呼吸配合肋骨下沉，练腹压管理和核心支撑，减少腹部鼓起代偿',
            'courses' => ['垫上普拉提', '核心床'],
            'guest' => '腹压的方向还不明确，一发力腹部就先往外顶',
            'progress' => '你今天最明显的进步，是腹压方向比刚开始清楚了',
        ],

        // C 核心与骨盆控制
        'core_weak' => [
            'group' => 'C', 'groupLabel' => '核心与骨盆控制', 'label' => '核心参与度低', 'fast' => true, 'phase' => 1,
            'direction' => '先建立核心支撑和骨盆中立，避免用腰发力；动作幅度先小、速度先慢',
            'courses' => ['垫上普拉提', '核心床'],
            'guest' => '核心参与得比较少，动作更多靠腰和四肢在带',
            'progress' => '你今天最明显的进步，是核心开始参与进来了，动作不再只靠腰带',
        ],
        'pelvis_control' => [
            'group' => 'C', 'groupLabel' => '核心与骨盆控制', 'label' => '骨盆控制弱', 'fast' => false, 'phase' => 1,
            'direction' => '先建立核心支撑和骨盆中立，避免用腰发力；动作幅度先小、速度先慢',
            'courses' => ['垫上普拉提', '核心床'],
            'guest' => '骨盆的控制还在建立中，动起来容易晃',
            'progress' => '你今天最明显的进步，是骨盆比刚开始稳了，动起来不怎么晃了',
        ],
        'abdomen_bulge' => [
            'group' => 'C', 'groupLabel' => '核心与骨盆控制', 'label' => '用小腹鼓起代偿', 'fast' => false, 'phase' => 1,
            'direction' => '呼吸配合肋骨下沉，练腹压管理和核心支撑，减少腹部鼓起代偿',
            'courses' => ['垫上普拉提', '核心床'],
            'guest' => '发力时小腹会先鼓出去，是典型的代偿',
            'progress' => '你今天最明显的进步，是小腹不再一发力就往外鼓了',
        ],
        'lumbar_comp' => [
            'group' => 'C', 'groupLabel' => '核心与骨盆控制', 'label' => '腰部代偿明显', 'fast' => false, 'phase' => 1,
            'direction' => '先建立核心支撑和骨盆中立，避免用腰发力；动作幅度先小、速度先慢',
            'courses' => ['垫上普拉提', '核心床'],
            'guest' => '腰在替核心发力，练完容易腰酸',
            'progress' => '你今天最明显的进步，是腰的负担比刚开始小了，说明核心在接手',
        ],

        // D 下肢与发力
        'glute_weak' => [
            'group' => 'D', 'groupLabel' => '下肢与发力', 'label' => '臀肌参与少', 'fast' => true, 'phase' => 1,
            'direction' => '先找臀肌发力，改善髋主导模式，减少大腿前侧抢力',
            'courses' => ['臀腿塑形', '稳踏椅', '垫上普拉提'],
            'guest' => '臀部参与得少，发力主要在大腿前侧',
            'progress' => '你今天最明显的进步，是臀部的发力感比刚开始好找了，大腿前侧不再一直抢力',
        ],
        'quad_dom' => [
            'group' => 'D', 'groupLabel' => '下肢与发力', 'label' => '大腿前侧代偿', 'fast' => false, 'phase' => 1,
            'direction' => '先找臀肌发力，改善髋主导模式，减少大腿前侧抢力',
            'courses' => ['臀腿塑形', '稳踏椅', '垫上普拉提'],
            'guest' => '大腿前侧一直在抢力，所以腿容易显粗',
            'progress' => '你今天最明显的进步，是大腿前侧不再像刚开始那样一直抢力了',
        ],
        'single_leg' => [
            'group' => 'D', 'groupLabel' => '下肢与发力', 'label' => '单腿稳定性差', 'fast' => true, 'phase' => 1,
            'direction' => '增加支撑面、减幅度，先做单腿控制和足踝稳定，再进入整合',
            'courses' => ['稳踏椅', '臀腿类', '精准正位'],
            'guest' => '单腿站立时稳定性不够，身体会晃',
            'progress' => '你今天最明显的进步，是单腿站立比刚开始稳了，晃的幅度小了很多',
        ],
        'ankle_knee_unstable' => [
            'group' => 'D', 'groupLabel' => '下肢与发力', 'label' => '膝踝受力不稳', 'fast' => false, 'phase' => 2,
            'direction' => '增加支撑面、减幅度，先做单腿控制和足踝稳定，再进入整合',
            'courses' => ['稳踏椅', '臀腿类', '精准正位'],
            'guest' => '膝和踝的受力不太稳，落地时缓冲不够',
            'progress' => '你今天最明显的进步，是膝踝的受力比刚开始稳了，落地更从容',
        ],

        // E 肩颈与上背
        'upper_trap_tight' => [
            'group' => 'E', 'groupLabel' => '肩颈与上背', 'label' => '斜方肌上束偏紧', 'fast' => false, 'phase' => 1,
            'direction' => '课前先松解肩颈，训练中避免耸肩用力；重量以不耸肩为准',
            'courses' => ['肩颈舒缓', '芳香瑜伽'],
            'guest' => '肩颈的上束比较紧，一用力就容易耸肩',
            'progress' => '你今天最明显的进步，是肩颈比刚开始松了，动作里不再一直耸肩',
        ],
        'scapula_weak' => [
            'group' => 'E', 'groupLabel' => '肩颈与上背', 'label' => '肩胛控制弱', 'fast' => false, 'phase' => 1,
            'direction' => '建立肩胛下沉后缩的控制，先把上背支撑做起来，再往上加负荷',
            'courses' => ['核心床', '垫上普拉提', '肩颈类'],
            'guest' => '肩胛的控制比较弱，手臂发力时肩会跟着动',
            'progress' => '你今天最明显的进步，是肩胛比刚开始能控制住了，手臂发力时肩不再跟着跑',
        ],
        'shoulder_rom' => [
            'group' => 'E', 'groupLabel' => '肩颈与上背', 'label' => '抬手范围受限（无痛范围内）', 'fast' => false, 'phase' => 2,
            'direction' => '建立肩胛下沉后缩的控制，先把上背支撑做起来，再往上加负荷',
            'courses' => ['核心床', '墙绳瑜伽', '肩颈类'],
            'guest' => '抬手时上背的配合还不够，活动范围还有提升空间',
            'progress' => '你今天最明显的进步，是抬手比刚开始顺畅了，上背的配合也跟上了',
        ],
        'upper_back_weak' => [
            'group' => 'E', 'groupLabel' => '肩颈与上背', 'label' => '上背支撑不足', 'fast' => false, 'phase' => 1,
            'direction' => '建立肩胛下沉后缩的控制，先把上背支撑做起来，再往上加负荷',
            'courses' => ['核心床', '垫上普拉提', '肩颈类'],
            'guest' => '上背的支撑不够，所以肩颈一直在帮忙',
            'progress' => '你今天最明显的进步，是上背的支撑比刚开始能建立起来了，肩颈没那么吃力',
        ],

        // F 动作质量与表现
        'control_unstable' => [
            'group' => 'F', 'groupLabel' => '动作质量与表现', 'label' => '动作控制不稳', 'fast' => true, 'phase' => 1,
            'direction' => '降低速度、缩短力臂、扩大支撑面，先把动作做稳再加量',
            'courses' => ['核心床', '垫上普拉提'],
            'guest' => '动作的控制还在建立，现在做慢一点反而更有效',
            'progress' => '你今天最明显的进步，是动作比刚开始稳了，控制得住了',
        ],
        'pace_fast' => [
            'group' => 'F', 'groupLabel' => '动作质量与表现', 'label' => '节奏偏快 / 抢动作', 'fast' => false, 'phase' => 1,
            'direction' => '降低速度、缩短力臂、扩大支撑面，先把动作做稳再加量',
            'courses' => ['核心床', '垫上普拉提'],
            'guest' => '节奏习惯偏快，容易用惯性代替控制',
            'progress' => '你今天最明显的进步，是节奏比刚开始沉得下来了，不再用惯性带动作',
        ],
        'lr_diff' => [
            'group' => 'F', 'groupLabel' => '动作质量与表现', 'label' => '左右差异明显', 'fast' => false, 'phase' => 2,
            'direction' => '以弱侧为准安排次数和幅度，先做单侧控制，再进入双侧整合',
            'courses' => ['核心床', '稳踏椅', '私教'],
            'guest' => '左右两边的差异比较明显，需要按弱侧来安排',
            'progress' => '你今天最明显的进步，是左右两边比刚开始更接近了',
        ],
        'fatigue_form' => [
            'group' => 'F', 'groupLabel' => '动作质量与表现', 'label' => '后半段疲劳后动作变形', 'fast' => false, 'phase' => 2,
            'direction' => '拆分训练量、增加组间休息，把动作质量放在训练量前面',
            'courses' => ['私教', '小班'],
            'guest' => '后半段有点跟不上，动作会走形，所以质量要排在量前面',
            'progress' => '你今天最明显的进步，是后半段也能维持住动作质量了',
        ],
    ];

    /** 详细档的分组展示顺序 */
    public const OBSERVATION_GROUPS = ['A', 'B', 'C', 'D', 'E', 'F'];

    /** 红线：命中则不产出对客训练方案，改为建议进一步专业评估 */
    public const RED_FLAGS = [
        'numb_radiate' => ['label' => '麻木、放射痛、进行性肌力下降', 'hint' => '涉及神经症状，任何负荷都可能加重'],
        'cardio' => ['label' => '头晕、胸闷、异常气短', 'hint' => '需先排除心肺问题再排训练'],
        'joint_acute' => ['label' => '关节红肿热痛 / 夜间痛', 'hint' => '急性期表现，不宜进入常规训练'],
        'postpartum_pelvic' => ['label' => '产后盆底下坠感或漏尿明显增加', 'hint' => '需先做盆底专项评估'],
        'post_surgery' => ['label' => '近期外伤、骨折、手术后未获医生运动许可', 'hint' => '未获运动许可前不排训练'],
    ];

    /** 课程方向（顾问衔接用，只填方向不写价格） */
    public const CARD_DIRECTIONS = ['定制私教', '私教小班·全能小班', '精品团课', '新人套餐'];

    /** 对客免责声明（保留方案原文口径） */
    public const DISCLAIMER = '这份方向基于今天的观察和你的身体反馈，属于运动训练建议，不替代医学诊断。过程中如果出现麻木、刺痛、头晕、胸闷或明显不适，我们会立刻调整或停止。';

    /** 三条表达底线，同时作为生成文案的硬约束 */
    public const SPEECH_RULES = [
        '讲观察，不讲诊断 —— 说「我观察到」，不说「你这是……病」',
        '讲方向，不承诺疗效 —— 说「通常会改善 / 更稳 / 更轻松」，不说「能治好」',
        '讲频率，不给压力 —— 给每周 1–2 次这种能坚持的节奏，不推高强度',
    ];

    /** 违禁词：生成的对客文案里一律不允许出现 */
    public const FORBIDDEN_WORDS = ['治疗', '矫正', '修复', '治愈', '治好', '改善疾病', '包好'];

    /** 提供给前端的目录（用于渲染表单，避免前端再抄一份文案） */
    public static function catalog(): array
    {
        $groups = [];
        foreach (self::OBSERVATION_GROUPS as $g) {
            $items = [];
            $label = '';
            foreach (self::OBSERVATIONS as $key => $o) {
                if ($o['group'] !== $g) {
                    continue;
                }
                $label = $o['groupLabel'];
                $items[] = [
                    'key' => $key,
                    'label' => $o['label'],
                    'fast' => $o['fast'],
                    'phase' => $o['phase'],
                ];
            }
            $groups[] = ['group' => $g, 'label' => $label, 'items' => $items];
        }

        $types = [];
        foreach (self::TYPES as $key => $t) {
            $types[] = [
                'key' => $key,
                'label' => $t['label'],
                'alias' => $t['alias'],
                'frequencyText' => $t['frequency']['text'] ?? '',
                'courses' => $t['courses'],
                'boundary' => $t['boundary'],
            ];
        }

        $redFlags = [];
        foreach (self::RED_FLAGS as $key => $f) {
            $redFlags[] = ['key' => $key, 'label' => $f['label'], 'hint' => $f['hint']];
        }

        return [
            'groups' => $groups,
            'types' => $types,
            'redFlags' => $redFlags,
            'cardDirections' => self::CARD_DIRECTIONS,
            'students' => self::STUDENT_TYPES,
            'levels' => self::LEVELS,
            'scenes' => self::SCENES,
            'speechRules' => self::SPEECH_RULES,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    public const LEVELS = ['轻' => '轻', '中' => '中', '重' => '重'];

    public const SCENES = [
        'trial' => '体验课',
        'private_first' => '私教首课',
        'private' => '私教常规课',
    ];

    /** 学员主观反馈的参考项，用于前端提示 */
    public const STUDENT_TYPES = ['产后恢复', '塑形线条', '体态调整', '康复', '减脂体能'];

    /**
     * 按学员自己说的目标 + 观察项，猜一个主类型（老师可覆盖）。
     * 猜不中就返回空，交给老师选。
     */
    public static function inferType(string $goalText, array $observationKeys): string
    {
        $score = [];
        foreach (self::TYPES as $key => $t) {
            $score[$key] = 0;
            foreach ($t['keywords'] as $kw) {
                if ($kw !== '' && mb_strpos($goalText, $kw) !== false) {
                    $score[$key] += 3;
                }
            }
        }
        // 观察项倾向：体态类多 → 体态调整；呼吸/核心类多 → 产后或康复
        $postureItems = ['head_forward', 'round_shoulder', 'thoracic_kyphosis', 'pelvis_ant', 'pelvis_post', 'pelvis_lateral'];
        $breathCoreItems = ['chest_breath', 'breath_shallow', 'breath_hold', 'rib_flare', 'low_pressure', 'core_weak', 'abdomen_bulge', 'lumbar_comp'];
        foreach ($observationKeys as $k) {
            if (in_array($k, $postureItems, true)) {
                $score['体态调整'] += 1;
            }
            if (in_array($k, $breathCoreItems, true)) {
                $score['产后恢复'] += 1;
                $score['康复'] += 1;
            }
        }
        arsort($score);
        $top = array_key_first($score);

        return ($score[$top] ?? 0) >= 3 ? (string) $top : '';
    }

    /**
     * 生成训练方向。
     *
     * @param  array  $input  [
     *   student_type, observations:[{key,level}], red_flags:[key], goal_text
     * ]
     * @return array [
     *   red_flag, student_type, plan{phases,frequency,homeWork,cautions,basis},
     *   objective{observed,plan,frequency,homework,nextStep,disclaimer}, script{...}
     * ]
     */
    public static function generate(array $input): array
    {
        $redFlags = array_values(array_filter((array) ($input['red_flags'] ?? []), fn ($k) => isset(self::RED_FLAGS[$k])));
        $observations = self::normalizeObservations($input['observations'] ?? []);
        $goalText = trim((string) ($input['goal_text'] ?? ''));
        $type = (string) ($input['student_type'] ?? '');
        if (! isset(self::TYPES[$type])) {
            $type = self::inferType($goalText, array_keys($observations));
        }

        if ($redFlags !== []) {
            return self::redFlagResult($type, $redFlags, $observations);
        }

        return self::normalPlan($type, $observations, $input);
    }

    /** 归一化观察项：去重、只保留合法 key、按严重度降序、补默认等级 */
    private static function normalizeObservations(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $item) {
            $key = is_array($item) ? (string) ($item['key'] ?? '') : (string) $item;
            if (! isset(self::OBSERVATIONS[$key])) {
                continue;
            }
            $level = is_array($item) ? (string) ($item['level'] ?? '') : '';
            $out[$key] = isset(self::LEVELS[$level]) ? $level : '中';
        }
        $weight = ['重' => 0, '中' => 1, '轻' => 2];

        return collect($out)
            ->sortBy(fn ($lv, $k) => ($weight[$lv] ?? 1) * 100 + (self::OBSERVATIONS[$k]['fast'] ? 0 : 1))
            ->all();
    }

    /** 红线分支：不产出训练方案，只出沟通卡 */
    private static function redFlagResult(string $type, array $redFlags, array $observations): array
    {
        $flagLabels = array_map(fn ($k) => self::RED_FLAGS[$k]['label'], $redFlags);
        $received = array_map(fn ($k) => self::OBSERVATIONS[$k]['label'], array_keys($observations));

        return [
            'red_flag' => true,
            'student_type' => $type,
            'plan' => null,
            'objective' => null,
            'script' => [
                'whatPracticed' => '今天以安全范围内的呼吸、支撑和动作模式梳理为主，没有做超出范围的动作。',
                'progress' => '后面几个动作比刚开始更稳了，说明身体是可以被带动起来的。',
                'nextClass' => '建议先做一次进一步的专业评估，确认安全边界后我们再安排训练。',
                'reminder' => '回去按我说的，不要做超出范围的动作，有不舒服立刻停。',
            ],
            'cautions' => [
                '本次记录命中红线：'.implode('；', $flagLabels).'。',
                '老师只记录主诉与课堂表现，不做诊断、不解释影像、不承诺疗效。',
                '已收到的观察项（仅记录）：'.($received === [] ? '无' : implode('、', $received)).'。',
                '该记录不进入成交跟进池，避免继续推销。',
            ],
            'handoff' => [
                'cardDirection' => '',
                'weeklyTimes' => '',
                'focus' => '建议进一步专业评估后再谈卡项，当前不做卡项推荐。',
                'needConsultant' => false,
                'blocked' => true,
            ],
            'notes' => [
                '建议话术：这几项属于需要先做专业评估的情况，我们先不排训练，等评估结果出来再定方向。',
            ],
        ];
    }

    /** 正常分支：按「类型 × 观察项」组装三阶段 */
    private static function normalPlan(string $type, array $observations, array $input): array
    {
        $typeConf = self::TYPES[$type] ?? null;
        $goalText = trim((string) ($input['goal_text'] ?? ''));

        // 观察项按主要影响阶段归位
        $byPhase = [1 => [], 2 => [], 3 => []];
        foreach ($observations as $key => $level) {
            $p = (int) (self::OBSERVATIONS[$key]['phase'] ?? 1);
            $byPhase[$p][] = ['key' => $key, 'level' => $level];
        }

        $freqMin = $typeConf['frequency']['min'] ?? 1;
        $freqMax = $typeConf['frequency']['max'] ?? 2;

        $phases = [];
        foreach (self::PHASES as $i => $skeleton) {
            $idx = $i + 1;
            $base = $typeConf['phases'][$i] ?? '';
            $items = $byPhase[$idx] ?? [];

            // 个体化：把该阶段命中的观察项对应的训练方向拼进去 —— 这是「不千人一面」的关键
            $directions = [];
            $courses = [];
            foreach ($items as $it) {
                $o = self::OBSERVATIONS[$it['key']];
                $text = $o['direction'];
                if ($it['level'] === '重') {
                    $text .= '（本次观察偏重，进阶节奏放慢）';
                }
                $directions[$text] = true;
                foreach ($o['courses'] as $c) {
                    $courses[$c] = true;
                }
            }
            $directions = array_slice(array_keys($directions), 0, 3);

            // 次课换算：按推荐频率换算，让区间只来自「时长"而不是「频率 × 时长」双重不确定，
            // 顾问据此对应次卡（10 次 / 20 次）
            [$weeksFrom, $weeksTo] = $skeleton['weeks'];
            $timesText = '每周 '.$freqMax.' 次，约 '.($weeksFrom * $freqMax).'–'.($weeksTo * $freqMax).' 次课';

            $phases[] = [
                'key' => $skeleton['key'],
                'name' => $skeleton['name'],
                'duration' => $skeleton['duration'],
                'durationTimes' => $timesText,
                'goal' => $base,
                'focus' => $directions,
                'courses' => array_slice(array_keys($courses), 0, 4),
                'observationKeys' => array_map(fn ($it) => $it['key'], $items),
            ];
        }

        // 全局课程方向 = 类型课程 ∪ 全部观察项课程，取前 5
        $allCourses = [];
        foreach (($typeConf['courses'] ?? []) as $c) {
            $allCourses[$c] = true;
        }
        foreach (array_keys($observations) as $k) {
            foreach (self::OBSERVATIONS[$k]['courses'] as $c) {
                $allCourses[$c] = true;
            }
        }
        $allCourses = array_slice(array_keys($allCourses), 0, 5);

        // 对客：我在你身上观察到的（只讲观察，不讲诊断）
        $observed = [];
        foreach (array_keys($observations) as $k) {
            $observed[] = self::OBSERVATIONS[$k]['guest'];
        }
        $observed = array_slice($observed, 0, 3);

        // 回家作业：从第一阶段的观察项里挑最容易执行的
        $homeWork = [];
        foreach (array_column($byPhase[1], 'key') as $k) {
            $homeWork[] = match ($k) {
                'breath_shallow', 'chest_breath', 'breath_hold', 'rib_flare', 'low_pressure' => '每天 5–10 分钟躺着做呼吸练习，重点是呼气时肋骨下沉、不憋气',
                'head_forward', 'round_shoulder', 'thoracic_kyphosis', 'upper_back_weak', 'scapula_weak' => '久坐每小时起来活动一次肩颈，不要一直让肩膀耸着',
                'pelvis_ant', 'pelvis_post', 'pelvis_lateral', 'pelvis_control' => '站姿时有意识地让骨盆回到中立位，避免一直塌腰顶着',
                'glute_weak', 'quad_dom' => '走路和上楼梯时注意用臀部发力，减少大腿前侧抢力',
                'single_leg', 'ankle_knee_unstable', 'knee_valgus', 'knee_hyper', 'foot_arch' => '每天做 2 分钟单腿站立练习，扶墙保持，注意膝盖不要内扣',
                'core_weak', 'lumbar_comp', 'abdomen_bulge' => '日常搬东西、起身时先收一下下腹，不要直接用腰发力',
                default => '保持每周的活动量，不要长时间保持一个姿势不动',
            };
        }
        $homeWork = array_values(array_slice(array_unique($homeWork), 0, 2));
        if ($homeWork === []) {
            $homeWork = ['保持每周的活动量，不要长时间保持一个姿势不动'];
        }

        $cautions = [];
        if ($typeConf !== null) {
            $cautions[] = $typeConf['boundary'];
        }
        $cautions[] = '表达口径：说「支撑、稳定、发力更顺、更轻松」，不说「治疗、矫正、修复、治愈」。';
        $cautions[] = '变化先出现在体态和穿衣效果上，不承诺围度或体重数字。';

        // 内部依据：观察项 → 方向，方便老师复核和店长教研
        $basis = [];
        foreach (array_keys($observations) as $k) {
            $o = self::OBSERVATIONS[$k];
            $basis[] = [
                'key' => $k,
                'label' => $o['label'],
                'level' => $observations[$k],
                'direction' => $o['direction'],
            ];
        }

        $nextStep = $phases[0]['goal'];
        $nextFocus = $phases[0]['focus'][0] ?? '';

        // 提词卡第②句：整段话术里最有杀伤力的一句，必须具体到人而不是套模板。
        // 用第一条（最重）观察项对应的「进步表述」，老师可再改。
        $firstKey = array_key_first($observations);
        $progressLine = $firstKey !== null
            ? self::OBSERVATIONS[$firstKey]['progress']
            : '你今天进入状态比刚开始快，动作完成得比预想好。';

        return [
            'red_flag' => false,
            'student_type' => $type,
            'inferred' => $input['student_type'] === null || $input['student_type'] === '',
            'plan' => [
                'phases' => $phases,
                'frequency' => [
                    'text' => $typeConf['frequency']['text'] ?? '每周 1–2 次',
                    'min' => $freqMin,
                    'max' => $freqMax,
                    'stableMin' => $typeConf['frequency']['stableMin'] ?? $freqMin,
                    'stableMax' => $typeConf['frequency']['stableMax'] ?? $freqMax,
                ],
                'courses' => $allCourses,
                'homeWork' => $homeWork,
                'cautions' => $cautions,
                'basis' => $basis,
            ],
            'objective' => [
                'observed' => $observed,
                'plan' => array_map(fn ($p) => [
                    'name' => $p['name'],
                    'duration' => $p['duration'].'（'.$p['durationTimes'].'）',
                    'goal' => $p['goal'],
                    'focus' => $p['focus'],
                ], $phases),
                'frequency' => $typeConf['frequency']['text'] ?? '每周 1–2 次',
                'homework' => $homeWork,
                'nextStep' => $nextFocus !== '' ? $nextFocus : $nextStep,
                'disclaimer' => self::DISCLAIMER,
            ],
            'script' => [
                'whatPracticed' => self::scriptPracticed($observations),
                'progress' => $progressLine,
                'nextClass' => $nextFocus !== ''
                    ? '下节课我们继续把「'.$nextFocus.'」往前推进一点。'
                    : '下节课我们继续按这个方向往下走。',
                'reminder' => $homeWork[0] ?? '回去保持每周的活动量就好。',
            ],
            'cautions' => $cautions,
            'handoff' => [
                'cardDirection' => self::suggestCardDirection($type, $allCourses),
                'weeklyTimes' => $typeConf['frequency']['text'] ?? '每周 1–2 次',
                'focus' => $observed === []
                    ? '按'.$type.'方向承接。'
                    : '学员对「'.mb_substr($observed[0], 0, 18).'」有共鸣，可从这一点切入谈训练价值。',
                'needConsultant' => true,
                'blocked' => false,
            ],
            'notes' => [],
        ];
    }

    /** 建议卡项方向：只给方向，不写价格（方案原文口径） */
    private static function suggestCardDirection(string $type, array $courses): string
    {
        // 方案原文：定制私教 / 私教小班·全能小班 / 精品团课 / 新人套餐
        return match ($type) {
            '康复' => '定制私教',
            '产后恢复' => in_array('核心床', $courses, true) ? '定制私教' : '私教小班·全能小班',
            '塑形线条' => '私教小班·全能小班',
            '体态调整' => '定制私教',
            '减脂体能' => '精品团课',
            default => '私教小班·全能小班',
        };
    }

    /** 提词卡第①句：今天练了什么，按命中的观察项归到训练主题 */
    private static function scriptPracticed(array $observations): string
    {
        $themes = [];
        foreach (array_keys($observations) as $k) {
            $themes[self::OBSERVATIONS[$k]['groupLabel']] = true;
        }
        $themes = array_slice(array_keys($themes), 0, 2);

        if ($themes === []) {
            return '今天这节课我们先做的是呼吸、支撑和动作模式的梳理，把基础找准。';
        }

        return '今天这节课我们先从'.implode('、', $themes).'入手，把发力顺序和动作模式理清楚。';
    }
}
