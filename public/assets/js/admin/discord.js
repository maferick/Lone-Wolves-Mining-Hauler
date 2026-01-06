(() => {
  const nav = document.querySelector('[data-discord-tabs]');
  if (!nav) return;

  const links = Array.from(nav.querySelectorAll('a[data-section]'));
  if (links.length === 0) return;

  const storageKey = 'admin.discord.tab';
  const sections = links
    .map((link) => document.getElementById(link.dataset.section || ''))
    .filter(Boolean);

  const setActive = (id) => {
    links.forEach((link) => {
      link.classList.toggle('is-active', link.dataset.section === id);
    });
    sections.forEach((section) => {
      section.classList.toggle('is-active', section.id === id);
    });
  };

  const getHashId = () => window.location.hash.replace('#', '').trim();

  const applyHash = () => {
    const hashId = getHashId();
    if (hashId) {
      setActive(hashId);
      window.localStorage.setItem(storageKey, hashId);
      return true;
    }
    return false;
  };

  const storedId = window.localStorage.getItem(storageKey);
  if (!applyHash() && storedId && document.getElementById(storedId)) {
    history.replaceState(null, '', `#${storedId}`);
    setActive(storedId);
    document.getElementById(storedId).scrollIntoView({ behavior: 'auto', block: 'start' });
  } else if (!storedId && links[0]) {
    setActive(links[0].dataset.section);
  }

  links.forEach((link) => {
    link.addEventListener('click', () => {
      const id = link.dataset.section;
      if (!id) return;
      window.localStorage.setItem(storageKey, id);
      setActive(id);
    });
  });

  window.addEventListener('hashchange', () => {
    applyHash();
  });

  if ('IntersectionObserver' in window && sections.length > 0) {
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible[0]) {
          const activeId = visible[0].target.id;
          setActive(activeId);
          window.localStorage.setItem(storageKey, activeId);
        }
      },
      {
        rootMargin: '-35% 0px -55% 0px',
        threshold: [0.1, 0.4, 0.8],
      }
    );

    sections.forEach((section) => observer.observe(section));
  }
})();
