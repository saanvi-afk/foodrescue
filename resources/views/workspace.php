<?php require_once BASE_PATH . '/resources/views/components.php'; ?>
<div class="workspace">
<?php require BASE_PATH . '/resources/views/navigation.php'; ?>
<section class="workspace-main"><p class="eyebrow"><?= e($user['role_name']) ?> WORKSPACE</p><h1><?= e($title) ?></h1>
<?php require BASE_PATH . '/resources/views/pages/' . $page . '.php'; ?>
</section></div>
