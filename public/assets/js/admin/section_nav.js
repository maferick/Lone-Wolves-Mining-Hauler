(() => {
  const nav = document.querySelector('[data-section-nav]');
  const links = nav ? Array.from(nav.querySelectorAll('[data-section-link]')) : [];
  const sections = Array.from(document.querySelectorAll('[data-section]'));
  const storageKey = 'admin.discord.section';

  const getStored = () => {
    try {
      return localStorage.getItem(storageKey);
    } catch (err) {
      return null;
    }
  };

  const setStored = (value) => {
    try {
      localStorage.setItem(storageKey, value);
    } catch (err) {
      // ignore storage errors
    }
  };

  const setActiveLink = (sectionId) => {
    links.forEach((link) => {
      const isActive = link.dataset.sectionLink === sectionId;
      link.classList.toggle('is-active', isActive);
    });
  };

  if (links.length && sections.length) {
    links.forEach((link) => {
      link.addEventListener('click', (event) => {
        const targetId = link.getAttribute('href')?.replace('#', '');
        const target = targetId ? document.getElementById(targetId) : null;
        if (target) {
          event.preventDefault();
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          setActiveLink(targetId);
          setStored(targetId);
          history.replaceState(null, '', `#${targetId}`);
        }
      });
    });

    const stored = getStored();
    if (!location.hash && stored) {
      const target = document.getElementById(stored);
      if (target) {
        requestAnimationFrame(() => {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          setActiveLink(stored);
        });
      }
    }

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              const id = entry.target.getAttribute('id');
              if (id) {
                setActiveLink(id);
                setStored(id);
              }
            }
          });
        },
        { rootMargin: '-35% 0px -55% 0px', threshold: 0.1 }
      );
      sections.forEach((section) => observer.observe(section));
    } else {
      window.addEventListener('scroll', () => {
        const offset = window.scrollY + window.innerHeight * 0.3;
        let current = sections[0]?.id;
        sections.forEach((section) => {
          if (section.offsetTop <= offset) {
            current = section.id;
          }
        });
        if (current) {
          setActiveLink(current);
          setStored(current);
        }
      });
    }
  }

  const groupKeyPrefix = 'admin.discord.templates.group.';
  const templateKeyPrefix = 'admin.discord.templates.template.';

  const getStoredFlag = (key) => {
    try {
      return localStorage.getItem(key);
    } catch (err) {
      return null;
    }
  };

  const setStoredFlag = (key, value) => {
    try {
      localStorage.setItem(key, value);
    } catch (err) {
      // ignore storage errors
    }
  };

  const togglePanel = (button, panel, storageKeyPrefix, storageId) => {
    const isExpanded = button.getAttribute('aria-expanded') === 'true';
    const nextExpanded = !isExpanded;
    button.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
    panel.classList.toggle('is-collapsed', !nextExpanded);
    setStoredFlag(`${storageKeyPrefix}${storageId}`, nextExpanded ? '1' : '0');
  };

  document.querySelectorAll('[data-template-group]').forEach((group) => {
    const groupKey = group.getAttribute('data-template-group');
    const toggle = group.querySelector('.template-group-toggle');
    const panel = group.querySelector('.template-group-body');
    if (!groupKey || !toggle || !panel) {
      return;
    }

    const stored = getStoredFlag(`${groupKeyPrefix}${groupKey}`);
    const expanded = stored === '1';
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    panel.classList.toggle('is-collapsed', !expanded);

    toggle.addEventListener('click', () => {
      togglePanel(toggle, panel, groupKeyPrefix, groupKey);
    });
  });

  document.querySelectorAll('[data-template-key]').forEach((item) => {
    const templateKey = item.getAttribute('data-template-key');
    const toggle = item.querySelector('.template-toggle');
    const panel = item.querySelector('.template-item-body');
    if (!templateKey || !toggle || !panel) {
      return;
    }

    const stored = getStoredFlag(`${templateKeyPrefix}${templateKey}`);
    const expanded = stored === '1';
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    panel.classList.toggle('is-collapsed', !expanded);

    toggle.addEventListener('click', () => {
      togglePanel(toggle, panel, templateKeyPrefix, templateKey);
    });
  });
})();
