// Hitech Medicos Delivery admin helpers (no framework)
(function () {
  'use strict';
  // Select-all + bulk bar
  document.addEventListener('change', function (e) {
    if (e.target.id === 'eva-all') {
      document.querySelectorAll('input.rowchk').forEach(function (c) { c.checked = e.target.checked; });
    }
    if (e.target.id === 'eva-all' || e.target.classList.contains('rowchk')) {
      var n = document.querySelectorAll('input.rowchk:checked').length;
      var bar = document.getElementById('bulkform');
      if (bar) { bar.classList.toggle('on', n > 0); var c = bar.querySelector('.n'); if (c) c.textContent = n; }
    }
  });
  // Two-step confirmation for destructive actions:
  // data-confirm="Do you really want to ...?" data-confirm2="Exactly what will happen. Proceed?"
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var btn = e.submitter || f.querySelector('[data-confirm]');
    var el = (btn && btn.dataset && btn.dataset.confirm) ? btn : f;
    if (el.dataset && el.dataset.confirm) {
      if (!window.confirm(el.dataset.confirm)) { e.preventDefault(); return; }
      if (el.dataset.confirm2 && !window.confirm(el.dataset.confirm2)) { e.preventDefault(); return; }
    }
    if (f.id === 'bulkform') {
      var ids = Array.prototype.map.call(document.querySelectorAll('input.rowchk:checked'), function (c) { return c.value; });
      f.querySelector('input[name=ids]').value = ids.join(',');
      if (!ids.length) { e.preventDefault(); alert('Select at least one row.'); }
    }
  });
  // Page-size selector
  document.addEventListener('change', function (e) {
    if (e.target.classList.contains('limit-sel')) { window.location = e.target.value; }
  });
  window.HM = window.HM || {};
})();
