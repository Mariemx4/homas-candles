document.querySelectorAll('[data-payment-record-form]').forEach((form) => {
  const method = form.querySelector('[name="payment_method"]');
  const proofFields = form.querySelector('[data-payment-d17-fields]');
  const receipt = form.querySelector('[data-payment-cash-confirmation]');
  const confirmation = receipt.querySelector('input');
  const button = form.querySelector('[data-payment-record-button]');
  const update = () => {
    const cash = method.value === 'CASH_ON_DELIVERY';
    proofFields.hidden = cash;
    proofFields.querySelectorAll('input').forEach((input) => { input.disabled = cash; });
    receipt.hidden = !cash;
    confirmation.disabled = !cash;
    confirmation.required = cash;
    button.textContent = cash ? 'Mark payment as received' : 'Submit D17 record for verification';
  };
  method.addEventListener('change', update);
  update();
});
