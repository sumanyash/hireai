<div style="height:40px"></div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Global Flatpickr auto-init for all input[type="date"] on admin pages
(function initGlobalDatePickers() {
  const today = new Date();
  today.setHours(0,0,0,0);

  document.querySelectorAll('input[type="date"]').forEach(function(el) {
    if (el._flatpickr) return; // already initialized

    const min = el.getAttribute('min') || null;
    const max = el.getAttribute('max') || null;
    const val = el.value || null;

    flatpickr(el, {
      dateFormat   : 'Y-m-d',     // keep YYYY-MM-DD value (native format)
      altInput     : true,         // show human-readable DD/MM/YYYY in a separate display input
      altFormat    : 'd/m/Y',      // always DD/MM/YYYY regardless of OS locale
      allowInput   : false,
      disableMobile: false,
      minDate      : min || null,
      maxDate      : max || null,
      defaultDate  : val || null,
    });
  });
})();

// Re-run when new date inputs are injected dynamically (e.g. interview.php question type=date)
if (typeof MutationObserver !== 'undefined') {
  new MutationObserver(function(mutations) {
    mutations.forEach(function(m) {
      m.addedNodes.forEach(function(node) {
        if (node.nodeType !== 1) return;
        var inputs = node.matches && node.matches('input[type="date"]')
          ? [node]
          : Array.from(node.querySelectorAll ? node.querySelectorAll('input[type="date"]') : []);
        inputs.forEach(function(el) {
          if (el._flatpickr) return;
          const min = el.getAttribute('min') || null;
          const max = el.getAttribute('max') || null;
          flatpickr(el, {
            dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y',
            allowInput: false, disableMobile: false,
            minDate: min || null, maxDate: max || null,
          });
        });
      });
    });
  }).observe(document.body, { childList: true, subtree: true });
}
</script>
