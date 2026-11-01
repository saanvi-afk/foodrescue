<aside class="sidebar"><p class="eyebrow">WORKSPACE</p>
<?php
$links=['/dashboard'=>'Overview'];
if (in_array($user['role'],['donor','receiver'],true)) { $links['/organizations']='Organizations'; }
if ($user['role']==='receiver') { $links['/receiving']='Capacity & availability'; }
if (in_array($user['role'],['donor','receiver','super_admin'],true)) { $links['/donations']=$user['role']==='donor' ? 'My donations' : 'Discover food'; }
if (in_array($user['role'],['donor','receiver','volunteer','super_admin'],true)) { $links['/pickups']='Pickups'; }
if ($user['role']==='super_admin') { $links['/admin/manage']='Platform management'; }
if ($user['role']==='researcher') { $links['/research/experiments']='Experiments'; }
$links['/impact']='Impact';$links['/notifications']='Notifications';$links['/profile']='Profile';
foreach ($links as $href=>$label): ?>
<a href="<?= e($href) ?>" <?= parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)===$href ? 'class="active" aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
<div class="sidebar-note"><p>Good food.<br>A better destination.</p></div></aside>
