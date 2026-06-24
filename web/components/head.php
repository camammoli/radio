<?php
/**
 * head.php — <head> compartido.
 * Requiere que el contexto defina: $page_title, $page_desc, $page_canon
 * Opcionales: $page_og_image, $page_og_audio
 */
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($page_title) ?></title>
  <meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
  <link rel="canonical" href="<?= htmlspecialchars($page_canon) ?>">
  <meta property="og:type"        content="website">
  <meta property="og:url"         content="<?= htmlspecialchars($page_canon) ?>">
  <meta property="og:site_name"   content="Radio Argentina">
  <meta property="og:title"       content="<?= htmlspecialchars($page_title) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($page_desc) ?>">
  <?php if (!empty($page_og_image)): ?>
  <meta property="og:image" content="<?= htmlspecialchars($page_og_image) ?>">
  <?php endif; ?>
  <?php if (!empty($page_og_audio)): ?>
  <meta property="og:audio" content="<?= htmlspecialchars($page_og_audio) ?>">
  <?php endif; ?>
  <meta name="twitter:card"        content="summary">
  <meta name="twitter:title"       content="<?= htmlspecialchars($page_title) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($page_desc) ?>">
  <?php $__base = defined('RADIO_BASE') ? RADIO_BASE : '/radio'; ?>
  <link rel="manifest" href="<?= $__base ?>/manifest.json">
  <meta name="theme-color"                       content="#111827">
  <meta name="mobile-web-app-capable"            content="yes">
  <meta name="apple-mobile-web-app-capable"      content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title"        content="Radio AR">
  <link rel="stylesheet" href="<?= $__base ?>/assets/style.css">
  <link rel="stylesheet" href="<?= $__base ?>/assets/player.css">
  <?php if (defined('GA_ID') && GA_ID): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= GA_ID ?>"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= GA_ID ?>');</script>
  <?php endif; ?>
</head>
