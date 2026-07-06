<?php
/**
 * 苍井寿司 AI 积分管理系统 — 共享布局 v3.0
 * 
 * renderLayout($title, $activeNav, $content) 输出完整 HTML5 页面：
 *   - 左侧固定导航栏（图标+分组，深色背景+金色点缀）
 *   - 右侧内容区注入 $content
 *   - CDN 加载 Bootstrap 4.6 + jQuery 3.x
 */

function renderLayout(string $title, string $activeNav, string $content): void
{
    // ---- Navigation definition with icons ----
    $navGroups = [
        [
            'label' => '概览',
            'items' => [
                ['key' => 'dashboard',  'label' => '首页仪表盘', 'href' => '/dashboard.php',        'icon' => '📊', 'enabled' => true],
            ]
        ],
        [
            'label' => '基础管理',
            'items' => [
                ['key' => 'departments', 'label' => '部门管理',   'href' => '/pages/department.php',  'icon' => '🏢', 'enabled' => true],
                ['key' => 'employees',   'label' => '员工管理',   'href' => '/index.php',             'icon' => '👥', 'enabled' => true],
                ['key' => 'prizes',      'label' => '积分奖品',   'href' => '/pages/prizes.php',      'icon' => '🎁', 'enabled' => true],
            ]
        ],
        [
            'label' => '积分运营',
            'items' => [
                ['key' => 'points',      'label' => '积分发放',   'href' => '/pages/points.php',      'icon' => '⚡', 'enabled' => true],
                ['key' => 'achievement', 'label' => '成就积分',   'href' => '/pages/achievement.php', 'icon' => '🌟', 'enabled' => true],
                ['key' => 'exchange',    'label' => '积分兑换',   'href' => '/pages/exchange.php',    'icon' => '🔄', 'enabled' => true],
            ]
        ],
        [
            'label' => '数据分析',
            'items' => [
                ['key' => 'rank',        'label' => '排行榜',     'href' => '/pages/leaderboard.php', 'icon' => '🏆', 'enabled' => true],
                ['key' => 'reports',     'label' => '报表中心',   'href' => '/pages/reports.php',     'icon' => '📋', 'enabled' => true],
            ]
        ],
    ];

    ?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>苍井寿司 AI 积分管理系统 — <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/logo.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<!-- ========== Left Sidebar ========== -->
<aside class="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-name">苍井寿司</div>
        <div class="brand-sub">AI 积分管理系统</div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <?php foreach ($navGroups as $group): ?>
        <div class="nav-section-label"><?php echo htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php foreach ($group['items'] as $item): ?>
            <?php if ($item['enabled']): ?>
                <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                   class="<?php echo $item['key'] === $activeNav ? 'active' : ''; ?>">
                    <span class="nav-icon"><?php echo $item['icon']; ?></span>
                    <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php else: ?>
                <a href="#" class="disabled">
                    <span class="nav-icon">🔒</span>
                    <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="nav-soon">即将上线</span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <span class="status-dot"></span>
        <span>系统运行中 · v3.0</span>
    </div>

</aside>

<!-- ========== Scripts (jQuery must load before content scripts) ========== -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

<!-- ========== Content Area ========== -->
<main class="content">
    <?php echo $content; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Clean up orphaned modal backdrops on page load
$(function () {
    $('.modal-backdrop').remove();
    $('body').removeClass('modal-open');
});

// Ensure backdrop is removed when any modal fully hides
$(document).on('hidden.bs.modal', '.modal', function () {
    if ($('.modal.show').length === 0) {
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open');
    }
});

// Safety: also clean up on any modal 'hide' event (belt + suspenders)
$(document).on('hide.bs.modal', '.modal', function () {
    var self = this;
    setTimeout(function () {
        if (!$(self).hasClass('show') && $('.modal.show').length === 0) {
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open');
        }
    }, 200);
});
</script>

</body>
</html>
<?php
}
