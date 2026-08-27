(() => {
  const STORAGE_KEY = 'hache_tienda_cart_v2';
  const form = document.getElementById('checkoutForm');
  const itemsNode = document.getElementById('checkoutItems');
  const emptyNode = document.getElementById('checkoutEmpty');
  const countNode = document.getElementById('summaryCount');
  const totalNode = document.getElementById('checkoutTotal');
  const errorNode = document.getElementById('checkoutError');
  const payButton = document.getElementById('payButton');
  const token = document.querySelector('meta[name="checkout-token"]')?.content || '';

  const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

  function readCart() {
    try {
      const value = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  }

  const cart = readCart().filter(item => Number(item.quantity) > 0);

  function render() {
    if (!itemsNode || !emptyNode || !countNode || !totalNode || !payButton) return;
    itemsNode.innerHTML = '';
    const count = cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    const total = cart.reduce((sum, item) => sum + Number(item.price || 0) * Number(item.quantity || 0), 0);
    countNode.textContent = `${count} artículo${count === 1 ? '' : 's'}`;
    totalNode.textContent = money.format(total);
    emptyNode.hidden = cart.length !== 0;
    payButton.disabled = cart.length === 0;

    cart.forEach(item => {
      const row = document.createElement('div');
      row.className = 'checkout-item';
      const copy = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = item.name || 'Producto';
      const detail = document.createElement('small');
      const variant = item.variantLabel ? ` · ${item.variantLabel}` : '';
      detail.textContent = `${item.quantity} × ${money.format(Number(item.price || 0))}${variant}`;
      const lineTotal = document.createElement('span');
      lineTotal.textContent = money.format(Number(item.price || 0) * Number(item.quantity || 0));
      copy.append(name, detail);
      row.append(copy, lineTotal);
      itemsNode.append(row);
    });
  }

  function showError(message) {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = false;
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    if (cart.length === 0 || !payButton) return;
    if (errorNode) errorNode.hidden = true;

    const data = new FormData(form);
    const payload = {
      nombre: String(data.get('nombre') || '').trim(),
      telefono: String(data.get('telefono') || '').trim(),
      email: String(data.get('email') || '').trim(),
      items: cart.map(item => ({
        producto_id: Number(item.id || 0),
        variante_id: item.variantId ? Number(item.variantId) : null,
        cantidad: Number(item.quantity || 0),
      })),
    };

    payButton.disabled = true;
    const originalText = payButton.textContent;
    payButton.textContent = 'Preparando pago…';

    try {
      const response = await fetch('/api/checkout.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Checkout-Token': token,
        },
        body: JSON.stringify(payload),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.init_point) {
        throw new Error(result.message || 'No pudimos preparar tu pago. Inténtalo de nuevo.');
      }
      window.location.assign(result.init_point);
    } catch (error) {
      showError(error instanceof Error ? error.message : 'No pudimos preparar tu pago.');
      payButton.disabled = false;
      payButton.textContent = originalText || 'Continuar a Mercado Pago';
    }
  });

  render();
})();
