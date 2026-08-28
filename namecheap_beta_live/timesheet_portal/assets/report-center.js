(function () {
  const cards = Array.from(document.querySelectorAll('[data-report-target]'));
  if (!cards.length) return;

  cards.forEach(card => {
    card.addEventListener('click', () => {
      const panelId = card.dataset.reportTarget;
      const tab = document.querySelector('.portal-tab[data-panel="' + panelId + '"]');
      if (!tab) return;
      tab.click();
    });
  });
})();
