(() => {
  const STORAGE_KEY = 'hache_tienda_cart_v1';
  const panel = document.getElementById('cartPanel');
  const backdrop = document.getElementById('cartBackdrop');
  const openButton = document.getElementById('openCart');
  const closeButton = document.getElementById('closeCart');
  const countNode = document.getElementById('cartCount');
  const itemsNode = document.getElementById('cartItems');
  const emptyNode = document.getElementById('cartEmpty');
  const totalNode = document.getElementById('cartTotal');

  const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
  });

  function readCart() {
    try {
      const value = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  }

  let cart = readCart();

  function saveCart() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
    renderCart();
  }

  function openCart() {
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    openButton.setAttribute('aria-expanded', 'true');
    backdrop.hidden = false;
  }

  function closeCart() {
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    openButton.setAttribute('aria-expanded', 'false');
    backdrop.hidden = true;
  }

  function addProduct(product) {
    const existing = cart.find(item => item.id === product.id);
    if (existing) {
      existing.quantity = Math.min(existing.quantity + 1, product.stock);
    } else {
      cart.push({ ...product, quantity: 1 });
    }
    saveCart();
    openCart();
  }

  function updateQuantity(id, delta) {
    const item = cart.find(product => product.id === id);
    if (!item) return;
    item.quantity = Math.max(0, Math.min(item.quantity + delta, item.stock));
    if (item.quantity === 0) {
      cart = cart.filter(product => product.id !== id);
    }
    saveCart();
  }

  function renderCart() {
    itemsNode.innerHTML = '';
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

    countNode.textContent = String(itemCount);
    totalNode.textContent = money.format(total);
    emptyNode.hidden = cart.length !== 0;

    cart.forEach(item => {
      const row = document.createElement('div');
      row.className = 'cart-item';

      const info = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = item.name;
      const price = document.createElement('small');
      price.textContent = `${money.format(item.price)} c/u`;
      info.append(name, price);

      const controls = document.createElement('div');
      controls.className = 'cart-controls';
      const minus = document.createElement('button');
      minus.type = 'button';
      minus.textContent = '−';
      minus.setAttribute('aria-label', `Quitar una unidad de ${item.name}`);
      minus.addEventListener('click', () => updateQuantity(item.id, -1));
      const quantity = document.createElement('span');
      quantity.textContent = String(item.quantity);
      const plus = document.createElement('button');
      plus.type = 'button';
      plus.textContent = '+';
      plus.setAttribute('aria-label', `Agregar una unidad de ${item.name}`);
      plus.disabled = item.quantity >= item.stock;
      plus.addEventListener('click', () => updateQuantity(item.id, 1));
      controls.append(minus, quantity, plus);

      row.append(info, controls);
      itemsNode.append(row);
    });
  }

  document.querySelectorAll('[data-add-product]').forEach(button => {
    button.addEventListener('click', () => {
      addProduct({
        id: Number(button.dataset.id),
        name: button.dataset.name || 'Producto',
        price: Number(button.dataset.price || 0),
        stock: Number(button.dataset.stock || 0),
      });
    });
  });

  openButton.addEventListener('click', openCart);
  closeButton.addEventListener('click', closeCart);
  backdrop.addEventListener('click', closeCart);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeCart();
  });

  renderCart();
})();
