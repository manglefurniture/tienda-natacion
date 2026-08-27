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
  const checkoutButton = document.getElementById('checkoutButton');
  const toast = document.getElementById('cartToast');
  const toastTitle = document.getElementById('cartToastTitle');
  const toastDetail = document.getElementById('cartToastDetail');
  const toastAction = document.getElementById('cartToastAction');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const money = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
  });

  let toastTimer = null;

  function readCart() {
    try {
      const value = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  }

  let cart = readCart();

  function persistCart() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
  }

  function hideToast() {
    if (!toast) return;
    window.clearTimeout(toastTimer);
    toast.classList.remove('is-visible');
    toast.setAttribute('aria-hidden', 'true');
  }

  function showToast(product, addedQuantity) {
    if (!toast || !toastTitle || !toastDetail) return;

    window.clearTimeout(toastTimer);
    toastTitle.textContent = addedQuantity === 1 ? 'Producto agregado' : `${addedQuantity} productos agregados`;
    const variant = product.variantLabel ? ` · ${product.variantLabel}` : '';
    toastDetail.textContent = `${product.name}${variant}`;
    toast.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    toastTimer = window.setTimeout(hideToast, 4600);
  }

  function openCart() {
    if (!panel || !backdrop || !openButton) return;
    hideToast();
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
    let addedQuantity = 0;

    if (existing) {
      const previousQuantity = existing.quantity;
      existing.quantity = Math.min(existing.quantity + requested, product.stock);
      existing.stock = product.stock;
      addedQuantity = existing.quantity - previousQuantity;
    } else {
      const initialQuantity = Math.min(requested, product.stock);
      cart.push({ ...product, quantity: initialQuantity });
      addedQuantity = initialQuantity;
    }

    persistCart();
    return addedQuantity;
  }

  function updateQuantity(key, delta) {
    const item = cart.find(product => product.key === key);
    if (!item) return;
    item.quantity = Math.max(0, Math.min(item.quantity + delta, item.stock));
    if (item.quantity === 0) {
      cart = cart.filter(product => product.key !== key);
    }
    persistCart();
    renderCart();
  }

  function pulseCart() {
    if (!openButton) return;
    openButton.classList.remove('cart-bump');
    void openButton.offsetWidth;
    openButton.classList.add('cart-bump');
    window.setTimeout(() => openButton.classList.remove('cart-bump'), 520);
  }

  function flyToCart(card, quantity) {
    return new Promise(resolve => {
      if (!openButton || reduceMotion) {
        resolve();
        return;
      }

      const source = card?.querySelector('[data-main-image]') || card;
      if (!source) {
        resolve();
        return;
      }

      const sourceRect = source.getBoundingClientRect();
      const targetRect = openButton.getBoundingClientRect();
      if (sourceRect.width <= 0 || sourceRect.height <= 0) {
        resolve();
        return;
      }

      const flyer = document.createElement('div');
      flyer.className = 'cart-flyer';

      if (source instanceof HTMLImageElement && source.currentSrc) {
        flyer.style.backgroundImage = `url("${source.currentSrc.replace(/"/g, '\\"')}")`;
      } else if (source instanceof HTMLImageElement && source.src) {
        flyer.style.backgroundImage = `url("${source.src.replace(/"/g, '\\"')}")`;
      }

      if (quantity > 1) {
        const badge = document.createElement('span');
        badge.textContent = String(quantity);
        flyer.appendChild(badge);
      }

      const size = Math.min(74, Math.max(54, sourceRect.width * 0.22));
      const startX = sourceRect.left + (sourceRect.width / 2) - (size / 2);
      const startY = sourceRect.top + (sourceRect.height / 2) - (size / 2);
      const endX = targetRect.left + (targetRect.width / 2) - (size / 2);
      const endY = targetRect.top + (targetRect.height / 2) - (size / 2);

      flyer.style.width = `${size}px`;
      flyer.style.height = `${size}px`;
      flyer.style.left = `${startX}px`;
      flyer.style.top = `${startY}px`;
      document.body.appendChild(flyer);

      const animation = flyer.animate([
        { transform: 'translate3d(0,0,0) scale(1)', opacity: 1, offset: 0 },
        { transform: `translate3d(${(endX - startX) * 0.45}px,${(endY - startY) * 0.25 - 55}px,0) scale(.78)`, opacity: .96, offset: .48 },
        { transform: `translate3d(${endX - startX}px,${endY - startY}px,0) scale(.28)`, opacity: .18, offset: 1 },
      ], {
        duration: 620,
        easing: 'cubic-bezier(.22,.8,.28,1)',
        fill: 'forwards',
      });

      animation.finished
        .catch(() => undefined)
        .finally(() => {
          flyer.remove();
          resolve();
        });
    });
  }

  function renderCart() {
    if (!itemsNode || !countNode || !totalNode || !emptyNode) return;

    itemsNode.innerHTML = '';
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

    countNode.textContent = String(itemCount);
    totalNode.textContent = money.format(total);
    emptyNode.hidden = cart.length !== 0;
    if (checkoutButton) checkoutButton.disabled = cart.length === 0;

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

    addButton?.addEventListener('click', async event => {
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

      const product = {
        key: variantId ? `${productId}:${variantId}` : productId,
        id: Number(productId),
        variantId: variantId ? Number(variantId) : null,
        variantLabel,
        name: addButton.dataset.name || 'Producto',
        price: Number(addButton.dataset.price || 0),
        stock,
        quantity,
      };

      const addedQuantity = addProduct(product);
      if (addedQuantity <= 0) return;

      addButton.classList.add('is-adding');
      await flyToCart(card, addedQuantity);
      addButton.classList.remove('is-adding');
      renderCart();
      pulseCart();
      showToast(product, addedQuantity);

      quantity = 1;
      renderSelection();
    });

    renderSelection();
  });

  openButton?.addEventListener('click', openCart);
  closeButton?.addEventListener('click', closeCart);
  backdrop?.addEventListener('click', closeCart);
  toastAction?.addEventListener('click', openCart);

  window.addEventListener('scroll', () => {
    if (toast?.classList.contains('is-visible')) hideToast();
  }, { passive: true });

  document.addEventListener('pointerdown', event => {
    if (!toast?.classList.contains('is-visible')) return;
    const target = event.target;
    if (target instanceof Node && (toast.contains(target) || openButton?.contains(target))) return;
    hideToast();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      hideToast();
      closeCart();
    }
  });

  renderCart();
})();
