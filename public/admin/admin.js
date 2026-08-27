(() => {
  const form = document.querySelector('[data-product-form]');
  if (!form) return;

  const toggle = form.querySelector('[data-variants-toggle]');
  const simpleStock = form.querySelector('[data-simple-stock]');
  const variantsEditor = form.querySelector('[data-variants-editor]');
  const variantList = form.querySelector('[data-variant-list]');
  const addVariantButton = form.querySelector('[data-add-variant]');
  const template = document.getElementById('variantRowTemplate');

  function syncVariantMode() {
    const enabled = Boolean(toggle?.checked);
    if (simpleStock) simpleStock.hidden = enabled;
    if (variantsEditor) variantsEditor.hidden = !enabled;

    if (enabled && variantList && variantList.children.length === 0) {
      addVariant();
    }
  }

  function bindVariantRow(row) {
    const removeButton = row.querySelector('[data-remove-variant]');
    removeButton?.addEventListener('click', () => {
      row.remove();
      if (toggle?.checked && variantList && variantList.children.length === 0) {
        addVariant();
      }
    });
  }

  function addVariant() {
    if (!template || !variantList) return;
    const fragment = template.content.cloneNode(true);
    const row = fragment.querySelector('[data-variant-row]');
    if (!row) return;
    bindVariantRow(row);
    variantList.appendChild(fragment);
    row.querySelector('input[name="variant_nombre[]"]')?.focus();
  }

  variantList?.querySelectorAll('[data-variant-row]').forEach(bindVariantRow);
  addVariantButton?.addEventListener('click', addVariant);
  toggle?.addEventListener('change', syncVariantMode);
  syncVariantMode();

  const fileInput = form.querySelector('input[type="file"][name="imagenes[]"]');
  const uploadBox = fileInput?.closest('.upload-box');

  fileInput?.addEventListener('change', () => {
    if (!uploadBox) return;
    const count = fileInput.files?.length || 0;
    const strong = uploadBox.querySelector('strong');
    if (strong) strong.textContent = count > 0 ? `${count} foto${count === 1 ? '' : 's'} seleccionada${count === 1 ? '' : 's'}` : 'Agregar fotos';
  });
})();
