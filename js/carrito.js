const CART_STORAGE_KEY = "restaurante-la-argentina-cart";

const currencyFormatter = new Intl.NumberFormat("es-CR", {
  style: "currency",
  currency: "CRC",
  minimumFractionDigits: 0,
});

function readCart() {
  try {
    const raw = localStorage.getItem(CART_STORAGE_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function writeCart(items) {
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(items));
  updateCartCount(items);
}

function getCartCount(items = readCart()) {
  return items.reduce((total, item) => total + Number(item.quantity || 0), 0);
}

function getCartTotal(items = readCart()) {
  return items.reduce(
    (total, item) => total + Number(item.price || 0) * Number(item.quantity || 0),
    0
  );
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => {
    const entities = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    };

    return entities[character];
  });
}

function updateCartCount(items = readCart()) {
  const count = getCartCount(items);

  document.querySelectorAll("[data-cart-count]").forEach((element) => {
    element.textContent = count;
  });
}

function showMessage(message, type = "success") {
  const container = document.querySelector("[data-cart-message]");
  if (!container) {
    return;
  }

  container.textContent = message;
  container.hidden = false;
  container.className = `feedback-message feedback-${type}`;
}

function clearMessage() {
  const container = document.querySelector("[data-cart-message]");
  if (!container) {
    return;
  }

  container.hidden = true;
  container.textContent = "";
  container.className = "feedback-message";
}

function showCartToast(message) {
  let toast = document.querySelector("[data-cart-toast]");

  if (!toast) {
    toast = document.createElement("div");
    toast.className = "cart-toast";
    toast.setAttribute("data-cart-toast", "");
    toast.setAttribute("role", "status");
    toast.setAttribute("aria-live", "polite");
    document.body.appendChild(toast);
  }

  toast.textContent = message;
  toast.classList.remove("is-visible");
  void toast.offsetWidth;
  toast.classList.add("is-visible");

  window.clearTimeout(showCartToast.timeoutId);
  showCartToast.timeoutId = window.setTimeout(() => {
    toast.classList.remove("is-visible");
  }, 2200);
}

function animateCartBadge() {
  document.querySelectorAll("[data-cart-count]").forEach((element) => {
    element.classList.remove("is-bumping");
    void element.offsetWidth;
    element.classList.add("is-bumping");
  });
}

function animateAddedButton(button) {
  const originalText = button.textContent;

  button.classList.add("is-added");
  button.textContent = "Agregado";

  window.setTimeout(() => {
    button.classList.remove("is-added");
    button.textContent = originalText;
  }, 1200);
}

function addToCart(item) {
  const cart = readCart();
  const existing = cart.find((product) => product.id === item.id);

  if (existing) {
    existing.quantity += 1;
  } else {
    cart.push({ ...item, quantity: 1 });
  }

  writeCart(cart);
  showMessage(`${item.name} fue agregado al carrito.`);
  showCartToast(`${item.name} se agrego al carrito.`);
  animateCartBadge();
}

function removeFromCart(id) {
  const nextCart = readCart().filter((item) => item.id !== id);
  writeCart(nextCart);
  renderCartPage();
}

function updateQuantity(id, delta) {
  const nextCart = readCart()
    .map((item) => {
      if (item.id !== id) {
        return item;
      }

      return {
        ...item,
        quantity: item.quantity + delta,
      };
    })
    .filter((item) => item.quantity > 0);

  writeCart(nextCart);
  renderCartPage();
}

function clearCart() {
  localStorage.removeItem(CART_STORAGE_KEY);
  updateCartCount([]);
  renderCartPage();
}

function detectCardType(number, strict = false) {
  const digits = number.replace(/\D/g, "");

  if (strict && /^4\d{12}(\d{3})?$/.test(digits)) {
    return "visa";
  }

  if (
    strict &&
    (/^(5[1-5]\d{14})$/.test(digits) ||
      /^(222[1-9]\d{12}|22[3-9]\d{13}|2[3-6]\d{14}|27[01]\d{13}|2720\d{12})$/.test(
        digits
      ))
  ) {
    return "mastercard";
  }

  if (strict && /^3[47]\d{13}$/.test(digits)) {
    return "amex";
  }

  if (strict) {
    return "desconocida";
  }

  if (/^4/.test(digits)) {
    return "visa";
  }

  if (/^3[47]/.test(digits)) {
    return "amex";
  }

  if (/^5[1-5]/.test(digits) || /^2[2-7]/.test(digits)) {
    return "mastercard";
  }

  return "desconocida";
}

function isValidCardForType(type, number) {
  const digits = number.replace(/\D/g, "");

  if (type === "visa") {
    return /^4\d{12}(\d{3})?$/.test(digits);
  }

  if (type === "mastercard") {
    return (
      /^(5[1-5]\d{14})$/.test(digits) ||
      /^(222[1-9]\d{12}|22[3-9]\d{13}|2[3-6]\d{14}|27[01]\d{13}|2720\d{12})$/.test(
        digits
      )
    );
  }

  if (type === "amex") {
    return /^3[47]\d{13}$/.test(digits);
  }

  return false;
}

function isValidExpiry(value) {
  const match = /^(\d{2})\/(\d{2})$/.exec(value.trim());
  if (!match) {
    return false;
  }

  const month = Number(match[1]);
  const year = Number(`20${match[2]}`);
  if (month < 1 || month > 12) {
    return false;
  }

  const today = new Date();
  const currentMonth = today.getMonth() + 1;
  const currentYear = today.getFullYear();

  return year > currentYear || (year === currentYear && month >= currentMonth);
}

function isValidCvv(type, cvv) {
  if (type === "amex") {
    return /^\d{4}$/.test(cvv);
  }

  return /^\d{3}$/.test(cvv);
}

function syncHiddenOrderFields(items) {
  const hiddenOrder = document.querySelector('input[name="pedido_json"]');
  const hiddenTotal = document.querySelector('input[name="total_pedido"]');

  if (!hiddenOrder || !hiddenTotal) {
    return;
  }

  hiddenOrder.value = JSON.stringify(
    items.map((item) => ({
      id: item.id,
      name: item.name,
      price: item.price,
      quantity: item.quantity,
    }))
  );
  hiddenTotal.value = String(getCartTotal(items));
}

function renderCartPage() {
  const cartPage = document.querySelector("[data-cart-page]");
  if (!cartPage) {
    updateCartCount();
    return;
  }

  const items = readCart();
  const cartItemsContainer = document.querySelector("[data-cart-items]");
  const cartEmptyState = document.querySelector("[data-cart-empty]");
  const totalElement = document.querySelector("[data-cart-total]");
  const checkoutButton = document.querySelector("[data-checkout-button]");

  if (!cartItemsContainer || !cartEmptyState || !totalElement || !checkoutButton) {
    updateCartCount(items);
    return;
  }

  cartItemsContainer.innerHTML = "";

  if (items.length === 0) {
    cartEmptyState.hidden = false;
    checkoutButton.disabled = true;
  } else {
    cartEmptyState.hidden = true;
    checkoutButton.disabled = false;
  }

  items.forEach((item) => {
    const row = document.createElement("article");
    const safeId = escapeHtml(item.id);
    const safeName = escapeHtml(item.name);
    row.className = "cart-item";
    row.innerHTML = `
      <div>
        <h3>${safeName}</h3>
        <p>Precio unitario: ${currencyFormatter.format(item.price)}</p>
        <p>Subtotal: ${currencyFormatter.format(item.price * item.quantity)}</p>
      </div>
      <div class="cart-item-actions">
        <button type="button" class="button button-secondary" data-cart-action="decrease" data-item-id="${safeId}">-</button>
        <span class="cart-item-qty">${item.quantity}</span>
        <button type="button" class="button button-secondary" data-cart-action="increase" data-item-id="${safeId}">+</button>
        <button type="button" class="button button-danger" data-cart-action="remove" data-item-id="${safeId}">Quitar</button>
      </div>
    `;
    cartItemsContainer.appendChild(row);
  });

  totalElement.textContent = currencyFormatter.format(getCartTotal(items));
  syncHiddenOrderFields(items);
  updateCartCount(items);
}

function bindAddToCartButtons() {
  document.querySelectorAll("[data-add-to-cart]").forEach((button) => {
    button.addEventListener("click", () => {
      const card = button.closest("[data-item-id]");
      if (!card) {
        return;
      }

      const item = {
        id: card.dataset.itemId,
        name: card.dataset.itemName,
        price: Number(card.dataset.itemPrice),
      };

      addToCart(item);
      animateAddedButton(button);
    });
  });
}

function bindCartActions() {
  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }

    const action = target.dataset.cartAction;
    const itemId = target.dataset.itemId;

    if (action && itemId) {
      clearMessage();

      if (action === "increase") {
        updateQuantity(itemId, 1);
      }

      if (action === "decrease") {
        updateQuantity(itemId, -1);
      }

      if (action === "remove") {
        removeFromCart(itemId);
      }
    }

    if (target.matches("[data-clear-cart]")) {
      clearMessage();
      clearCart();
      const paymentForm = document.querySelector("[data-payment-form]");
      if (paymentForm instanceof HTMLFormElement) {
        paymentForm.reset();
      }
      showMessage("La compra fue cancelada y el carrito quedo vacio.", "warning");
    }
  });
}

function bindPaymentValidation() {
  const form = document.querySelector("[data-payment-form]");
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  const cardNumberInput = form.querySelector('input[name="numero_tarjeta"]');
  const cardTypeHidden = form.querySelector('input[name="tipo_tarjeta"]');
  const cardTypeDisplay = form.querySelector("[data-card-type-display]");
  const expiryInput = form.querySelector('input[name="vencimiento"]');
  const cvvInput = form.querySelector('input[name="cvv"]');
  const brandHint = document.querySelector("[data-card-brand]");

  if (
    !(cardNumberInput instanceof HTMLInputElement) ||
    !(cardTypeHidden instanceof HTMLInputElement) ||
    !(expiryInput instanceof HTMLInputElement) ||
    !(cvvInput instanceof HTMLInputElement)
  ) {
    return;
  }

  const showBrand = () => {
    const detectedType = detectCardType(cardNumberInput.value);
    const detectedLabel =
      detectedType === "desconocida" ? "Pendiente de detectar" : detectedType.toUpperCase();

    cardTypeHidden.value = detectedType === "desconocida" ? "" : detectedType;

    if (cardTypeDisplay instanceof HTMLInputElement) {
      cardTypeDisplay.value = detectedLabel;
    }

    if (brandHint) {
      brandHint.textContent =
        detectedType === "desconocida"
          ? "Formato pendiente de identificar."
          : `Formato detectado: ${detectedType.toUpperCase()}.`;
    }
  };

  cardNumberInput.addEventListener("input", showBrand);

  form.addEventListener("submit", (event) => {
    clearMessage();

    const items = readCart();
    if (items.length === 0) {
      event.preventDefault();
      showMessage("Agrega al menos un platillo antes de pagar.", "error");
      return;
    }

    const detectedType = detectCardType(cardNumberInput.value, true);
    cardTypeHidden.value = detectedType === "desconocida" ? "" : detectedType;

    if (detectedType === "desconocida") {
      event.preventDefault();
      showMessage("Ingresa una tarjeta Visa, Mastercard o AMEX valida.", "error");
      return;
    }

    if (!isValidCardForType(detectedType, cardNumberInput.value)) {
      event.preventDefault();
      showMessage(
        "El numero de tarjeta no coincide con el formato de Visa, Mastercard o AMEX.",
        "error"
      );
      return;
    }

    if (!isValidExpiry(expiryInput.value)) {
      event.preventDefault();
      showMessage("La fecha de vencimiento debe tener formato MM/AA y no estar expirada.", "error");
      return;
    }

    if (!isValidCvv(detectedType, cvvInput.value.trim())) {
      event.preventDefault();
      showMessage("El CVV no tiene el formato correcto para la tarjeta detectada.", "error");
    }
  });

  showBrand();
}

document.addEventListener("DOMContentLoaded", () => {
  const cartPage = document.querySelector("[data-cart-page]");
  if (cartPage?.dataset.clearCart === "true") {
    localStorage.removeItem(CART_STORAGE_KEY);
  }

  updateCartCount();
  bindAddToCartButtons();
  bindCartActions();
  renderCartPage();
  bindPaymentValidation();
});
