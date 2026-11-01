<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($_SESSION['csrf']) ?>">
  <meta name="description" content="FoodRescue connects surplus-food donors with community receivers through accountable food redistribution.">
  <title><?= e($title) ?> · FoodRescue</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/vendor/leaflet.css">
  <script src="/assets/vendor/leaflet.js" defer></script>
  <script src="/assets/vendor/chart.js" defer></script>
  <script src="/assets/vendor/jsQR.js" defer></script>
  <script src="/assets/js/app.js" defer></script>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar"><a class="brand" href="/"><span class="brand-icon" aria-hidden="true">↗</span> foodrescue<span class="brand-dot">.</span></a><nav aria-label="Main navigation"><a href="/">Our mission</a><?php if (isset($_SESSION['user_id'])): ?><a href="/dashboard">Workspace</a><button class="text-button" data-logout>Sign out</button><?php else: ?><a href="/login">Sign in</a><a class="button small" href="/register">Join the network ↗</a><?php endif ?></nav></header>
<main id="main"><?= $content ?></main>
<footer><span class="brand">foodrescue.</span><span>Good food deserves a second chance.</span><span>From surplus to community</span></footer>
<div id="toast" role="status" aria-live="polite" hidden></div>
</body></html>
