<?php
declare(strict_types=1);
if (!function_exists('site_asset_path')) {
    require_once dirname(__DIR__) . '/lib/site.php';
}
$ui_extra_js = $ui_extra_js ?? [];
$ui_extra_modules = $ui_extra_modules ?? [];
$ui_inline_js = $ui_inline_js ?? '';
?>
  <?php
  $_themeJsPath = dirname(__DIR__) . '/assets/ui/js/theme.js';
  $_themeJsVer = (string) (int) @filemtime($_themeJsPath);
  ?>
  <script src="<?= htmlspecialchars(site_asset_path('/assets/ui/js/theme.js?v=' . $_themeJsVer), ENT_QUOTES) ?>"></script>
  <script src="<?= htmlspecialchars(site_asset_path('/assets/ui/js/common.js'), ENT_QUOTES) ?>"></script>
<?php foreach ($ui_extra_js as $src): ?>
  <script src="<?= htmlspecialchars(preg_match('#^https?://#i', $src) ? $src : site_asset_path($src), ENT_QUOTES) ?>"></script>
<?php endforeach; ?>
<?php foreach ($ui_extra_modules as $src): ?>
  <script type="module" src="<?= htmlspecialchars(site_asset_path($src), ENT_QUOTES) ?>"></script>
<?php endforeach; ?>
<?php if (!empty($ui_inline_js)): ?>
  <script><?= $ui_inline_js ?></script>
<?php endif; ?>
</body>
</html>
