(() => {
  const root = document.querySelector('.js-hauler-profile');
  if (!root) return;
  const basePath = root.dataset.basePath || '';
  const note = root.querySelector('[data-profile-note]');
  const saveButton = root.querySelector('[data-profile-save]');
  const fields = {
    can_fly_freighter: root.querySelector('[data-profile-field="can_fly_freighter"]'),
    can_fly_jump_freighter: root.querySelector('[data-profile-field="can_fly_jump_freighter"]'),
    can_fly_dst: root.querySelector('[data-profile-field="can_fly_dst"]'),
    can_fly_br: root.querySelector('[data-profile-field="can_fly_br"]'),
    preferred_service_class: root.querySelector('[data-profile-field="preferred_service_class"]'),
    max_cargo_m3_override: root.querySelector('[data-profile-field="max_cargo_m3_override"]'),
  };

  const fetchJson = async (url, options = {}) => {
    const resp = await fetch(url, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
    });
    return resp.json();
  };

  const loadProfile = async () => {
    const data = await fetchJson(`${basePath}/api/hauler/profile/`);
    if (!data.ok || !data.profile) {
      if (note) note.textContent = 'Unable to load hauler profile yet.';
      return;
    }
    fields.can_fly_freighter.checked = !!data.profile.can_fly_freighter;
    fields.can_fly_jump_freighter.checked = !!data.profile.can_fly_jump_freighter;
    fields.can_fly_dst.checked = !!data.profile.can_fly_dst;
    fields.can_fly_br.checked = !!data.profile.can_fly_br;
    fields.preferred_service_class.value = data.profile.preferred_service_class || '';
    fields.max_cargo_m3_override.value = data.profile.max_cargo_m3_override ?? '';
    if (note) note.textContent = 'Profile loaded. Save changes to update your haul optimization preferences.';
  };

  const saveProfile = async () => {
    if (saveButton) saveButton.disabled = true;
    const payload = {
      can_fly_freighter: fields.can_fly_freighter.checked,
      can_fly_jump_freighter: fields.can_fly_jump_freighter.checked,
      can_fly_dst: fields.can_fly_dst.checked,
      can_fly_br: fields.can_fly_br.checked,
      preferred_service_class: fields.preferred_service_class.value,
      max_cargo_m3_override: fields.max_cargo_m3_override.value,
    };
    const data = await fetchJson(`${basePath}/api/hauler/profile/`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    if (note) {
      note.textContent = data.ok ? 'Hauler profile saved.' : (data.error || 'Unable to save profile.');
    }
    if (saveButton) saveButton.disabled = false;
  };

  saveButton?.addEventListener('click', saveProfile);
  loadProfile();
})();
