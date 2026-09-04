    </main>
  </div>
</div>
<script>
(function () {
  var sidebar = document.getElementById('admin-sidebar');
  var backdrop = document.getElementById('admin-backdrop');
  var toggle = document.getElementById('admin-sidebar-toggle');
  if (!sidebar || !toggle) return;

  function setOpen(open) {
    sidebar.classList.toggle('is-open', open);
    if (backdrop) {
      backdrop.hidden = !open;
      backdrop.classList.toggle('is-visible', open);
    }
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? '关闭菜单' : '打开菜单');
    document.body.classList.toggle('admin-sidebar-open', open);
  }

  toggle.addEventListener('click', function () {
    setOpen(!sidebar.classList.contains('is-open'));
  });
  backdrop && backdrop.addEventListener('click', function () { setOpen(false); });
  sidebar.querySelectorAll('.admin-nav__item').forEach(function (link) {
    link.addEventListener('click', function () {
      if (window.matchMedia('(max-width: 1023px)').matches) setOpen(false);
    });
  });
  window.addEventListener('resize', function () {
    if (window.matchMedia('(min-width: 1024px)').matches) setOpen(false);
  });
})();
if (window.renderIcons) window.renderIcons(document.getElementById('admin-app'));
</script>
