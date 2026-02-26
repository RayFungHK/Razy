/* Theme Toggle */
(function () {
  const stored = localStorage.getItem('razy-docs-theme');
  if (stored) document.documentElement.setAttribute('data-theme', stored);
  else if (window.matchMedia('(prefers-color-scheme: dark)').matches)
    document.documentElement.setAttribute('data-theme', 'dark');
})();

document.addEventListener('DOMContentLoaded', () => {
  /* ─── Theme toggle ─── */
  const toggle = document.querySelector('.theme-toggle');
  if (toggle) {
    const update = () => {
      const dark = document.documentElement.getAttribute('data-theme') === 'dark';
      toggle.textContent = dark ? '☀️' : '🌙';
    };
    update();
    toggle.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('razy-docs-theme', next);
      update();
    });
  }

  /* ─── Mobile sidebar ─── */
  const menuBtn = document.querySelector('.menu-toggle');
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');

  if (menuBtn && sidebar) {
    menuBtn.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay?.classList.toggle('active');
    });
    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
    });
  }

  /* ─── Active sidebar link ─── */
  const currentPath = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.sidebar-link').forEach(link => {
    const href = link.getAttribute('href')?.split('/').pop();
    if (href === currentPath) link.classList.add('active');
  });

  /* ─── Copy buttons for code blocks ─── */
  document.querySelectorAll('pre').forEach(pre => {
    const btn = document.createElement('button');
    btn.className = 'copy-btn';
    btn.textContent = '📋 Copy';
    btn.addEventListener('click', () => {
      const code = pre.querySelector('code')?.textContent || pre.textContent;
      navigator.clipboard.writeText(code).then(() => {
        btn.textContent = '✓ Copied';
        setTimeout(() => btn.textContent = '📋 Copy', 2000);
      });
    });
    pre.appendChild(btn);
  });

  /* ─── Table of Contents scroll spy ─── */
  const toc = document.querySelector('.toc');
  if (toc) {
    const links = toc.querySelectorAll('a');
    const sections = [];
    links.forEach(link => {
      const id = link.getAttribute('href')?.substring(1);
      const el = document.getElementById(id);
      if (el) sections.push({ el, link });
    });

    const onScroll = () => {
      let active = sections[0];
      for (const s of sections) {
        if (s.el.getBoundingClientRect().top <= 100) active = s;
      }
      links.forEach(l => l.classList.remove('active'));
      if (active) active.link.classList.add('active');
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ─── Tabs ─── */
  document.querySelectorAll('.tabs').forEach(tabGroup => {
    const tabs = tabGroup.querySelectorAll('.tab');
    const contents = tabGroup.parentElement.querySelectorAll('.tab-content');
    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const target = tab.dataset.tab;
        tabs.forEach(t => t.classList.remove('active'));
        contents.forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        const content = tabGroup.parentElement.querySelector(`.tab-content[data-tab="${target}"]`);
        if (content) content.classList.add('active');
      });
    });
  });
});
