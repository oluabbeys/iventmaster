(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    // Select-all checkbox.
    var selectAll = document.getElementById('iqr-select-all');
    if (selectAll) {
      selectAll.addEventListener('change', function () {
        document.querySelectorAll('.iqr-select-cb').forEach(function (cb) {
          cb.checked = selectAll.checked;
        });
        updateBulkBar();
      });
      document.querySelectorAll('.iqr-select-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var all  = document.querySelectorAll('.iqr-select-cb').length;
          var checked = document.querySelectorAll('.iqr-select-cb:checked').length;
          selectAll.checked = (checked === all);
          selectAll.indeterminate = (checked > 0 && checked < all);
          updateBulkBar();
        });
      });
    }

    function updateBulkBar() {
      var bar     = document.querySelector('.iqr-bulk-bar');
      var checked = document.querySelectorAll('.iqr-select-cb:checked').length;
      if (!bar) return;
      var btn = bar.querySelector('button');
      if (btn) {
        btn.textContent = checked > 0
          ? '📲 Send WhatsApp to ' + checked + ' selected'
          : '📲 Send WhatsApp to Selected';
        btn.disabled = (checked === 0);
      }
    }

    updateBulkBar();
  });
})();
