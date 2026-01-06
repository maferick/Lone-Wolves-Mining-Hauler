(() => {
  const tabContainers = document.querySelectorAll('[data-admin-tabs]');
  if (tabContainers.length === 0) return;

  const errorSelectors = '.input--error, .alert.alert-warning, .alert.alert-danger, .alert.alert-error';

  tabContainers.forEach((container) => {
    const moduleKey = container.dataset.adminTabs || 'module';
    const storageKey = `admin.${moduleKey}.tab`;
    const nav = container.querySelector('[data-admin-tabs-nav]');
    if (!nav) return;

    const links = Array.from(nav.querySelectorAll('[data-section]'));
    const sections = Array.from(container.querySelectorAll('.admin-section[data-section]'));
    if (links.length === 0 || sections.length === 0) return;

    const getSectionId = (section) => section.dataset.section || section.id;
    const hasSection = (id) => sections.some((section) => getSectionId(section) === id);

    const setActive = (id) => {
      links.forEach((link) => {
        link.classList.toggle('is-active', link.dataset.section === id);
      });
      sections.forEach((section) => {
        section.classList.toggle('is-active', getSectionId(section) === id);
      });
    };

    const getHashId = () => window.location.hash.replace('#', '').trim();

    const applyHash = () => {
      const hashId = getHashId();
      if (hashId && hasSection(hashId)) {
        setActive(hashId);
        window.localStorage.setItem(storageKey, hashId);
        return true;
      }
      return false;
    };

    const applyStored = () => {
      const storedId = window.localStorage.getItem(storageKey);
      if (storedId && hasSection(storedId)) {
        setActive(storedId);
        history.replaceState(null, '', `#${storedId}`);
        return true;
      }
      return false;
    };

    const errorSection = sections.find((section) => section.querySelector(errorSelectors));
    if (errorSection) {
      const errorId = getSectionId(errorSection);
      if (errorId) {
        setActive(errorId);
        window.localStorage.setItem(storageKey, errorId);
        history.replaceState(null, '', `#${errorId}`);
        return;
      }
    }

    if (!applyHash() && !applyStored() && links[0]?.dataset.section) {
      setActive(links[0].dataset.section);
    }

    links.forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const id = link.dataset.section;
        if (!id || !hasSection(id)) return;
        window.localStorage.setItem(storageKey, id);
        setActive(id);
        history.replaceState(null, '', `#${id}`);
      });
    });

    window.addEventListener('hashchange', () => {
      applyHash();
    });
  });
})();
