// Click a column header to sort a table; click again to reverse. Add data-no-sort to a <th> to opt out.
(() => {
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

  const cellValue = (row, index) => {
    const cell = row.cells[index];
    return cell ? cell.textContent.trim() : '';
  };

  const toNumber = (text) => {
    const cleaned = text.replace(/[₱$,\s]/g, '');
    return cleaned !== '' && /^-?\d+(\.\d+)?$/.test(cleaned) ? parseFloat(cleaned) : null;
  };

  const toDate = (text) => {
    if (!/\d{4}|\d{1,2}[\/-]\d{1,2}/.test(text)) return null;
    const time = Date.parse(text);
    return Number.isNaN(time) ? null : time;
  };

  const compare = (a, b) => {
    const empty = (v) => v === '' || v === '—';
    if (empty(a) || empty(b)) return empty(a) === empty(b) ? 0 : empty(a) ? 1 : -1;
    const na = toNumber(a), nb = toNumber(b);
    if (na !== null && nb !== null) return na - nb;
    const da = toDate(a), db = toDate(b);
    if (da !== null && db !== null) return da - db;
    return collator.compare(a, b);
  };

  document.querySelectorAll('table').forEach((table) => {
    const head = table.tHead && table.tHead.rows[0];
    const body = table.tBodies[0];
    if (!head || !body) return;

    [...head.cells].forEach((th, index) => {
      const label = th.textContent.trim().toLowerCase();
      if (th.hasAttribute('data-no-sort') || label === 'actions' || label === '') return;

      th.style.cursor = 'pointer';
      th.style.userSelect = 'none';
      th.setAttribute('aria-sort', 'none');
      th.title = 'Click to sort';
      const arrow = document.createElement('span');
      arrow.className = 'ms-1 text-muted small';
      arrow.textContent = '↕';
      th.appendChild(arrow);

      th.addEventListener('click', () => {
        const ascending = th.getAttribute('aria-sort') !== 'ascending';
        [...head.cells].forEach((other) => {
          if (other.hasAttribute('aria-sort')) {
            other.setAttribute('aria-sort', 'none');
            other.lastElementChild.textContent = '↕';
          }
        });
        th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
        arrow.textContent = ascending ? '▲' : '▼';

        const rows = [...body.rows].filter((row) => row.cells.length === head.cells.length);
        rows.sort((r1, r2) => (ascending ? 1 : -1) * compare(cellValue(r1, index), cellValue(r2, index)));
        rows.forEach((row) => body.appendChild(row));
      });
    });
  });
})();
