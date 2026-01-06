(() => {
  const sections = Array.from(document.querySelectorAll('[data-live-section]'));
  if (sections.length === 0) return;

  const captureFieldState = (section) => {
    const fields = Array.from(section.querySelectorAll('input, select, textarea'));
    const state = new Map();
    fields.forEach((field, index) => {
      const key = field.getAttribute('data-live-key')
        || field.id
        || `${field.name || field.type || 'field'}-${index}`;
      state.set(key, {
        value: field.value,
        checked: field.checked,
        type: field.type,
        selectionStart: field.selectionStart,
        selectionEnd: field.selectionEnd,
        isFocused: document.activeElement === field,
      });
    });
    return state;
  };

  const restoreFieldState = (section, state) => {
    if (!state || state.size === 0) return;
    const fields = Array.from(section.querySelectorAll('input, select, textarea'));
    let focusedKey = null;
    let focusedState = null;
    fields.forEach((field, index) => {
      const key = field.getAttribute('data-live-key')
        || field.id
        || `${field.name || field.type || 'field'}-${index}`;
      const saved = state.get(key);
      if (!saved) return;
      if (field.type === 'checkbox' || field.type === 'radio') {
        field.checked = saved.checked;
      } else {
        field.value = saved.value;
      }
      if (saved.isFocused) {
        focusedKey = key;
        focusedState = saved;
      }
    });

    if (focusedKey) {
      const focusedField = fields.find((field, index) => {
        const key = field.getAttribute('data-live-key')
          || field.id
          || `${field.name || field.type || 'field'}-${index}`;
        return key === focusedKey;
      });
      if (focusedField) {
        focusedField.focus({ preventScroll: true });
        if (focusedState && typeof focusedState.selectionStart === 'number') {
          try {
            focusedField.setSelectionRange(focusedState.selectionStart, focusedState.selectionEnd ?? focusedState.selectionStart);
          } catch (err) {
            // Ignore selection restore failures for non-text fields.
          }
        }
      }
    }
  };

  sections.forEach((section) => {
    const url = section.getAttribute('data-live-url');
    if (!url) return;
    const intervalSeconds = Number(section.getAttribute('data-live-interval') || 15);
    const intervalMs = Math.max(5000, intervalSeconds * 1000);
    let isRefreshing = false;
    let lastSnapshot = section.innerHTML;

    const refresh = async () => {
      if (isRefreshing) return;
      isRefreshing = true;
      section.setAttribute('aria-busy', 'true');
      const fieldState = captureFieldState(section);

      try {
        const resp = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
        if (!resp.ok) {
          return;
        }
        const html = await resp.text();
        if (!html) return;
        const trimmed = html.trim();
        if (trimmed.length === 0) return;
        if (trimmed !== lastSnapshot.trim()) {
          section.innerHTML = html;
          restoreFieldState(section, fieldState);
          lastSnapshot = section.innerHTML;
        }
      } catch (err) {
      } finally {
        section.removeAttribute('aria-busy');
        isRefreshing = false;
      }
    };

    refresh();
    window.setInterval(refresh, intervalMs);
  });
})();
