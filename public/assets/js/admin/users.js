(() => {
  const searchInput = document.getElementById('user-search');
  const rows = Array.from(document.querySelectorAll('table tbody tr'));

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const query = searchInput.value.toLowerCase().trim();
      rows.forEach((row) => {
        const cells = row.querySelectorAll('td');
        const userText = cells[0]?.textContent?.toLowerCase() ?? '';
        const characterText = cells[1]?.textContent?.toLowerCase() ?? '';
        const rolesText = row.querySelector('[data-roles]')?.getAttribute('data-roles')?.toLowerCase() ?? '';
        const matches = [userText, characterText, rolesText].some((text) => text.includes(query));
        row.style.display = matches ? '' : 'none';
      });
    });
  }

  document.querySelectorAll('.role-toggle').forEach((form) => {
    const checkbox = form.querySelector('.role-checkbox');
    const actionInput = form.querySelector('input[name="action"]');
    if (!checkbox || !actionInput) return;
    checkbox.addEventListener('change', () => {
      actionInput.value = checkbox.checked ? 'add' : 'remove';
      form.submit();
    });
  });
})();
