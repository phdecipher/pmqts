// Copies each column heading onto its cells so the mobile card layout can label them.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('main table.table').forEach((table) => {
    const heads = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    if (!heads.length) return;
    table.querySelectorAll('tbody tr').forEach((row) => {
      [...row.children].forEach((cell, i) => {
        if (cell.tagName === 'TD' && !cell.hasAttribute('colspan') && heads[i]) cell.dataset.label = heads[i];
      });
    });
  });
});