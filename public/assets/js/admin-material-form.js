(() => {
  const tracking = document.getElementById('material-tracks-stock');
  const unit = document.getElementById('material-unit');
  const field = document.getElementById('material-minimum-field');
  const input = document.getElementById('material-minimum-stock');
  const label = document.getElementById('material-minimum-unit');
  if (!tracking || !unit || !field || !input || !label) return;
  const update = () => {
    field.hidden = !tracking.checked;
    input.disabled = !tracking.checked;
    input.required = tracking.checked;
    label.textContent = unit.value.trim() || 'selected unit';
  };
  tracking.addEventListener('change', update);
  unit.addEventListener('input', update);
  update();
})();
