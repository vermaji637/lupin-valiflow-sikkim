(function () {
  function setImportantStyle(el, name, value) {
    el.style.setProperty(name, value, 'important');
  }

  function ensureToggle(toolbar) {
    if (toolbar.dataset.toggleId && document.getElementById(toolbar.dataset.toggleId)) {
      return document.getElementById(toolbar.dataset.toggleId);
    }

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'template-toolbar-toggle';
    toggle.title = 'Show toolbar';
    toggle.setAttribute('aria-label', 'Show toolbar');
    toggle.innerHTML = '&#9776;';

    var toolbarId = toolbar.id || ('floatingToolbar_' + Math.random().toString(36).slice(2));
    toolbar.id = toolbarId;
    toggle.id = toolbarId + '_toggle';
    toolbar.dataset.toggleId = toggle.id;

    toggle.addEventListener('click', function () {
      var nextState = toolbar.dataset.toolbarOpen === '1' ? '0' : '1';
      toolbar.dataset.toolbarOpen = nextState;
      positionToolbar(toolbar);
    });

    document.body.appendChild(toggle);
    return toggle;
  }

  function positionToolbar(toolbar) {
    var toggle = ensureToggle(toolbar);

    if (window.innerWidth <= 1100) {
      toolbar.dataset.toolbarOpen = '1';
      setImportantStyle(toolbar, 'position', 'fixed');
      setImportantStyle(toolbar, 'left', '12px');
      setImportantStyle(toolbar, 'right', '12px');
      setImportantStyle(toolbar, 'bottom', '12px');
      setImportantStyle(toolbar, 'top', 'auto');
      setImportantStyle(toolbar, 'width', 'auto');
      setImportantStyle(toolbar, 'display', 'grid');
      setImportantStyle(toolbar, 'grid-template-columns', 'repeat(2, minmax(0, 1fr))');
      setImportantStyle(toolbar, 'z-index', '1080');
      setImportantStyle(toolbar, 'transform', 'translateX(0)');
      setImportantStyle(toolbar, 'opacity', '1');

      setImportantStyle(toggle, 'display', 'none');
      return;
    }

    if (!toolbar.dataset.toolbarOpen) {
      toolbar.dataset.toolbarOpen = '0';
    }

    var isOpen = toolbar.dataset.toolbarOpen === '1';
    var panelWidth = 220;
    var dockRight = 16;
    var openToggleRight = panelWidth + dockRight + 10;

    setImportantStyle(toolbar, 'position', 'fixed');
    setImportantStyle(toolbar, 'top', '128px');
    setImportantStyle(toolbar, 'right', dockRight + 'px');
    setImportantStyle(toolbar, 'left', 'auto');
    setImportantStyle(toolbar, 'bottom', 'auto');
    setImportantStyle(toolbar, 'width', panelWidth + 'px');
    setImportantStyle(toolbar, 'display', 'flex');
    setImportantStyle(toolbar, 'flex-direction', 'column');
    setImportantStyle(toolbar, 'z-index', '1080');
    setImportantStyle(toolbar, 'opacity', isOpen ? '1' : '0.2');
    setImportantStyle(toolbar, 'pointer-events', isOpen ? 'auto' : 'none');
    setImportantStyle(toolbar, 'transform', isOpen ? 'translateX(0)' : 'translateX(calc(100% + 24px))');

    setImportantStyle(toggle, 'display', 'inline-flex');
    setImportantStyle(toggle, 'position', 'fixed');
    setImportantStyle(toggle, 'top', '128px');
    setImportantStyle(toggle, 'right', (isOpen ? openToggleRight : dockRight) + 'px');
    setImportantStyle(toggle, 'z-index', '1081');
    toggle.title = isOpen ? 'Hide toolbar' : 'Show toolbar';
    toggle.setAttribute('aria-label', isOpen ? 'Hide toolbar' : 'Show toolbar');
    toggle.innerHTML = isOpen ? '&times;' : '&#9776;';
  }

  function mountToolbar() {
    var toolbars = document.querySelectorAll('[data-floating-toolbar="1"]');
    if (!toolbars.length) {
      return;
    }

    toolbars.forEach(function (toolbar) {
      if (toolbar.dataset.toolbarMounted !== '1') {
        toolbar.dataset.toolbarMounted = '1';
        document.body.appendChild(toolbar);
      }

      positionToolbar(toolbar);
    });

    if (document.body.dataset.toolbarResizeBound !== '1') {
      document.body.dataset.toolbarResizeBound = '1';
      window.addEventListener('resize', function () {
        document.querySelectorAll('[data-floating-toolbar="1"]').forEach(function (toolbar) {
          positionToolbar(toolbar);
        });
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountToolbar);
  } else {
    mountToolbar();
  }

  window.setTimeout(mountToolbar, 100);
  window.setTimeout(mountToolbar, 400);
})();
