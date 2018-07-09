/**
 * Ported from legacy/reference/dashboard-snapshot/dashboard-snapshot_files/extra.js
 * — the sidebar toggle and live table-search were the two genuinely working
 * pieces of JS found in the recovered March 2018 snapshot (the most
 * advanced UI state discovered from the original project). Rewritten
 * without jQuery dependency for the new build, same behavior.
 */

function toggleSidebar() {
  document.querySelector('.sidebar').classList.toggle('collapsed');
}

document.addEventListener('DOMContentLoaded', function () {
  var filterInput = document.getElementById('filter');
  if (filterInput) {
    filterInput.addEventListener('keyup', function () {
      var query = this.value.toLowerCase();
      var rows = document.querySelectorAll('.searchable tbody tr');
      rows.forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().indexOf(query) !== -1 ? '' : 'none';
      });
    });
  }

  // Tab switching (Home / Profiles / Tables / About pattern from the snapshot)
  document.querySelectorAll('[data-tab-target]').forEach(function (tabBtn) {
    tabBtn.addEventListener('click', function () {
      var target = tabBtn.getAttribute('data-tab-target');
      document.querySelectorAll('.tab-panel').forEach(function (panel) {
        panel.style.display = panel.id === target ? 'block' : 'none';
      });
      document.querySelectorAll('[data-tab-target]').forEach(function (btn) {
        btn.classList.toggle('active', btn === tabBtn);
      });
    });
  });
});
