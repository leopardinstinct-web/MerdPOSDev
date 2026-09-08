(function (Drupal) {
  'use strict';
  Drupal.behaviors.merdposFinanceWrite = {
    attach(context) {
      context.querySelectorAll('[data-finance-close-form]').forEach((form) => {
        if (form.dataset.merdposFinanceBound === '1') return;
        form.dataset.merdposFinanceBound = '1';
        form.addEventListener('submit', (event) => {
          if (!window.confirm('Close this financial day? This can only be done once.')) event.preventDefault();
        });
      });
    }
  };
})(Drupal);