<?php
$mlThemeColor = mlGetThemeMode() === 'light' ? '#f4f7fb' : '#000614';
?>
<link rel="icon" type="image/x-icon" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/icons/favicon.ico')) ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/icons/favicon-16x16.png')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/icons/favicon-32x32.png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/icons/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= htmlspecialchars(mlAssetUrl('manifest.json')) ?>">
<meta name="theme-color" content="<?= htmlspecialchars($mlThemeColor) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Musicball">
<meta name="application-name" content="Musicball">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-640x1136.png')) ?>" media="(device-width: 320px) and (device-height: 568px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-750x1334.png')) ?>" media="(device-width: 375px) and (device-height: 667px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-828x1792.png')) ?>" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1125x2436.png')) ?>" media="(device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1170x2532.png')) ?>" media="(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1179x2556.png')) ?>" media="(device-width: 393px) and (device-height: 852px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1242x2208.png')) ?>" media="(device-width: 414px) and (device-height: 736px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1242x2688.png')) ?>" media="(device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1284x2778.png')) ?>" media="(device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1320x2868.png')) ?>" media="(device-width: 440px) and (device-height: 956px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1536x2048.png')) ?>" media="(device-width: 768px) and (device-height: 1024px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1668x2224.png')) ?>" media="(device-width: 834px) and (device-height: 1112px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-1668x2388.png')) ?>" media="(device-width: 834px) and (device-height: 1194px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<link rel="apple-touch-startup-image" href="<?= htmlspecialchars(mlAssetUrl('assets/pwa/splash/apple-splash-2048x2732.png')) ?>" media="(device-width: 1024px) and (device-height: 1366px) and (-webkit-device-pixel-ratio: 2) and (orientation: portrait)">
<script>
window.ML_SW_URL = <?= json_encode(mlAssetUrl('service-worker.js')) ?>;
</script>
<script src="<?= htmlspecialchars(mlAssetUrl('assets/js/pwa.js')) ?>" defer></script>
