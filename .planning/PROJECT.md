# 苍井寿司 AI 积分管理系统

## What This Is

一个本地运行的 Web 积分管理系统，供 FDE 工程师一人操作，将《苍井寿司 AI 积分管理制度》的全部规则数字化落地。涵盖员工管理、四大积分类型（调查/培训/考核/成就）发放、积分兑换、成就积分审核流、排行榜与报表，所有数据存储在 SQLite 中。支持一键同步积分数据到飞书多维表「人员积分表」。

## Core Value

FDE 工程师能高效管理全员积分台账——积分发放、兑换、排名、报表一键完成，确保制度落地执行零阻力。

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] 员工管理（增删改查、按部门分组）
- [ ] 调查积分发放（FDE 直接发放，0.5分/次）
- [ ] 培训积分发放（签到记录 + 作业完成 → 核发 1-2分）
- [ ] 考核积分发放（L1-L4 等级考试通过 → 4/8/16/32分）
- [ ] 成就积分审核流（员工提交申请 → FDE 三维度评估 → 核定发放 2-10分）
- [ ] 积分兑换管理（假期/实物兑换 → 扣减积分 → 更新台账）
- [ ] 积分排行榜（总积分排名、月度新增排名、部门人均排名）
- [ ] 积分查询与筛选（按员工、类型、时间范围筛选明细）
- [ ] 积分变动日志（所有积分增减操作留痕）
- [ ] 月度荣誉评选（AI之星，当月新增≥10分+案例≥2个）
- [ ] 飞书多维表同步（按姓名匹配，一键同步积分到指定多维表）

### Out of Scope

- 部门/公司级福利阈值自动追踪 — V2 迭代
- 员工自助登录与查询 — 当前为纯 FDE 后台
- 飞书审批对接 — 后续按需接入
- 飞书多维表新增人员 — 仅同步已有记录，新增人员在本地手动管理
- 实物奖励目录管理 — V2 迭代
- 季度/年度荣誉评选（AI达人、AI专家、AI部门） — V2 迭代

## Context

- **业务背景**：苍井寿司约300家门店，全员适用 AI 积分制度
- **制度依据**：《苍井寿司 AI 积分管理制度》V2.0（2026年6月10日发布）
- **核心换算**：1 积分 = 1 小时
- **管理者**：FDE 工程师（总经办），单人操作
- **技术栈**：PHP 8.4 + SQLite，本地 PHP 内置服务器运行，浏览器访问
- **界面风格**：简洁实用型，表格+表单为主，功能优先
- **制度配套**：与《AI 培训管理制度》配套使用，L1-L4 等级体系对齐

## Constraints

- **运行环境**：本地运行，不依赖外部服务
- **用户范围**：单用户（FDE），无需登录鉴权
- **技术栈**：PHP 8.4 + SQLite，纯 PHP 内置服务器（php -S）
- **界面**：Bootstrap + jQuery 传统方案，轻量实用
- **数据隔离**：SQLite 单文件数据库，便于备份迁移
- **飞书同步**：通过 lark-cli 调用飞书 OpenAPI，按姓名匹配记录写入多维表

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| PHP + SQLite 本地服务 | 用户已有 PHP 8.4 环境，SQLite 零配置，单文件数据库易迁移 | — Pending |
| 纯 FDE 后台 | 制度执行由 FDE 一人负责，无需员工登录 | — Pending |
| 表格+表单界面 | 积分台账天然适合表格操作，简洁高效 | — Pending |
| 不含飞书集成 | V1 聚焦核心积分流程，后续按需接入 | — Pending |
| 不含部门公司级阈值 | 阈值追踪复杂度较高，先跑通核心流程 | — Pending |
| 飞书多维表同步 | 手动触发、按姓名匹配、仅同步已有记录，通过 lark-cli 调用飞书 API | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-06-26 after initialization*
