(() => {
  const STORAGE_KEY = 'hache_tienda_cart_v2';
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
    if (!panel || !backdrop || !openButton) return;
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    openButton.setAttribute('aria-expanded', 'true');
    backdrop.hidden = false;
  }

  function closeCart() {
    if (!panel || !backdrop || !openButton) return;
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    openButton.setAttribute('aria-expanded', 'false');
    backdrop.hidden = true;
  }

  function addProduct(product) {
    const requested = Math.max(1, Number(product.quantity || 1));
    const existing = cart.find(item => item.key === product.key);

    if (existing) {
      existing.quantity = Math.min(existing.quantity + requested, product.stock);
      existing.stock = product.stock;
    } else {
      cart.push({ ...product, quantity: Math.min(requested, product.stock) });
    }

    saveCart();
    openCart();
  }

  function updateQuantity(key, delta) {
    const item = cart.find(product => product.key === key);
    if (!item) return;
    item.quantity = Math.max(0, Math.min(item.quantity + delta, item.stock));
    if (item.quantity === 0) {
      cart = cart.filter(product => product.key !== key);
    }
    saveCart();
  }

  function renderCart() {
    if (!itemsNode || !countNode || !totalNode || !emptyNode) return;

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
      const variant = item.variantLabel ? ` · ${item.variantLabel}` : '';
      price.textContent = `${money.format(item.price)} c/u${variant}`;
      info.append(name, price);

      const controls = document.createElement('div');
      controls.className = 'cart-controls';
      const minus = document.createElement('button');
      minus.type = 'button';
      minus.textContent = '−';
      minus.setAttribute('aria-label', `Quitar una unidad de ${item.name}`);
      minus.addEventListener('click', () => updateQuantity(item.key, -1));
      const quantity = document.createElement('span');
      quantity.textContent = String(item.quantity);
      const plus = document.createElement('button');
      plus.type = 'button';
      plus.textContent = '+';
      plus.setAttribute('aria-label', `Agregar una unidad de ${item.name}`);
      plus.disabled = item.quantity >= item.stock;
      plus.addEventListener('click', () => updateQuantity(item.key, 1));
      controls.append(minus, quantity, plus);

      row.append(info, controls);
      itemsNode.append(row);
    });
  }

  document.querySelectorAll('[data-product-card]').forEach(card => {
    const mainImage = card.querySelector('[data-main-image]');
    const thumbnails = [...card.querySelectorAll('[data-product-image]')];
    const variantOptions = [...card.querySelectorAll('[data-variant-option]')];
    const addButton = card.querySelector('[data-add-product]');
    const minusButton = card.querySelector('[data-quantity-minus]');
    const plusButton = card.querySelector('[data-quantity-plus]');
    const quantityNode = card.querySelector('[data-quantity-value]');
    const stockStatus = card.querySelector('[data-stock-status]');

    let quantity = 1;
    let selectedVariant = variantOptions.find(option => option.classList.contains('is-selected')) || null;

    thumbnails.forEach(thumbnail => {
      thumbnail.addEventListener('click', () => {
        if (!mainImage) return;
        mainImage.src = thumbnail.dataset.productImage || mainImage.src;
        mainImage.alt = thumbnail.dataset.productAlt || mainImage.alt;
        thumbnails.forEach(item => item.classList.remove('is-active'));
        thumbnail.classList.add('is-active');
      });
    });

    function currentStock() {
      if (selectedVariant) return Number(selectedVariant.dataset.stock || 0);
      return Number(addButton?.dataset.stock || 0);
    }

    function renderSelection() {
      const stock = currentStock();
      quantity = Math.max(1, Math.min(quantity, Math.max(stock, 1)));

      if (quantityNode) quantityNode.textContent = String(quantity);
      if (minusButton) minusButton.disabled = quantity <= 1 || stock <= 0;
      if (plusButton) plusButton.disabled = quantity >= stock || stock <= 0;
      if (addButton) addButton.disabled = stock <= 0 || (variantOptions.length > 0 && !selectedVariant);

      if (stockStatus) {
        if (selectedVariant) {
          const sizeName = selectedVariant.dataset.variantName || 'talla seleccionada';
          stockStatus.textContent = `${stock} disponibles en ${sizeName}`;
        } else {
          stockStatus.textContent = stock > 0 ? `${stock} disponibles` : 'Sin existencias';
        }
      }
    }

    variantOptions.forEach(option => {
      option.addEventListener('click', event => {
        event.preventDefault();
        if (option.disabled) return;

        selectedVariant = option;
        quantity = 1;

        variantOptions.forEach(item => {
          const selected = item === option;
          item.classList.toggle('is-selected', selected);
          item.setAttribute('aria-checked', selected ? 'true' : 'false');
        });

        renderSelection();
      });
    });

    minusButton?.addEventListener('click', event => {
      event.preventDefault();
      quantity = Math.max(1, quantity - 1);
      renderSelection();
    });

    plusButton?.addEventListener('click', event => {
      event.preventDefault();
      quantity = Math.min(currentStock(), quantity + 1);
      renderSelection();
    });

    addButton?.addEventListener('click', event => {
      event.preventDefault();
      const productId = String(addButton.dataset.id || '0');
      const stock = currentStock();
      if (stock <= 0) return;

      let variantId = '';
      let variantLabel = '';

      if (variantOptions.length > 0) {
        if (!selectedVariant || selectedVariant.disabled) return;
        variantId = selectedVariant.dataset.variantId || '';
        variantLabel = selectedVariant.dataset.variantLabel || '';
      }

      addProduct({
        key: variantId ? `${productId}:${variantId}` : productId,
        id: Number(productId),
        variantId: variantId ? Number(variantId) : null,
        variantLabel,
        name: addButton.dataset.name || 'Producto',
        price: Number(addButton.dataset.price || 0),
        stock,
        quantity,
      });

      quantity = 1;
      renderSelection();
    });

    renderSelection();
  });

  openButton?.addEventListener('click', openCart);
  closeButton?.addEventListener('click', closeCart);
  backdrop?.addEventListener('click', closeCart);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeCart();
  });

  renderCart();
})();
