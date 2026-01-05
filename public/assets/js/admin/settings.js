(() => {
  const panelSlider = document.getElementById('panel_intensity');
  const panelValue = document.getElementById('panel_intensity_value');
  const panelHint = document.getElementById('panel_intensity_hint');
  const transparencyToggle = document.getElementById('transparency_enabled');
  if (panelSlider && panelValue) {
    const updateValue = () => {
      panelValue.textContent = panelSlider.value;
    };
    panelSlider.addEventListener('input', updateValue);
    updateValue();
  }
  if (panelSlider && transparencyToggle) {
    const syncTransparencyState = () => {
      const enabled = transparencyToggle.checked;
      panelSlider.disabled = !enabled;
      if (panelHint) {
        panelHint.textContent = enabled
          ? 'Transparency is managed globally across cards.'
          : 'Transparency is disabled. Cards will render as solid dark panels.';
      }
    };
    transparencyToggle.addEventListener('change', syncTransparencyState);
    syncTransparencyState();
  }
})();
