<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/conexion.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function detectCardType(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if (preg_match('/^4\d{12}(\d{3})?$/', $digits) === 1) {
        return 'visa';
    }

    if (
        preg_match('/^5[1-5]\d{14}$/', $digits) === 1 ||
        preg_match('/^(222[1-9]\d{12}|22[3-9]\d{13}|2[3-6]\d{14}|27[01]\d{13}|2720\d{12})$/', $digits) === 1
    ) {
        return 'mastercard';
    }

    if (preg_match('/^3[47]\d{13}$/', $digits) === 1) {
        return 'amex';
    }

    return 'desconocida';
}

function isValidCardForType(string $type, string $number): bool
{
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($type === 'visa') {
        return preg_match('/^4\d{12}(\d{3})?$/', $digits) === 1;
    }

    if ($type === 'mastercard') {
        return preg_match('/^5[1-5]\d{14}$/', $digits) === 1
            || preg_match('/^(222[1-9]\d{12}|22[3-9]\d{13}|2[3-6]\d{14}|27[01]\d{13}|2720\d{12})$/', $digits) === 1;
    }

    if ($type === 'amex') {
        return preg_match('/^3[47]\d{13}$/', $digits) === 1;
    }

    return false;
}

function isValidExpiry(string $expiry): bool
{
    if (preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', trim($expiry), $matches) !== 1) {
        return false;
    }

    $month = (int) $matches[1];
    $year = (int) ('20' . $matches[2]);
    $currentYear = (int) date('Y');
    $currentMonth = (int) date('n');

    return $year > $currentYear || ($year === $currentYear && $month >= $currentMonth);
}

function isValidCvv(string $type, string $cvv): bool
{
    if ($type === 'amex') {
        return preg_match('/^\d{4}$/', trim($cvv)) === 1;
    }

    return preg_match('/^\d{3}$/', trim($cvv)) === 1;
}

function parseOrderItems(string $orderJson): array
{
    $decoded = json_decode($orderJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $id = isset($item['id']) ? trim((string) $item['id']) : '';
        $name = isset($item['name']) ? trim((string) $item['name']) : '';
        $price = isset($item['price']) ? (float) $item['price'] : 0;
        $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;

        if ($id === '' || $name === '' || $price <= 0 || $quantity <= 0) {
            continue;
        }

        $items[] = [
            'id' => $id,
            'name' => $name,
            'price' => $price,
            'quantity' => $quantity,
            'subtotal' => $price * $quantity,
        ];
    }

    return $items;
}

function saveSimulatedOrder(
    string $receiptCode,
    string $customerName,
    string $customerEmail,
    array $orderItems,
    float $total,
    string $cardType,
    string $cardNumber,
    string $expiry
): void {
    $connection = null;

    try {
        $connection = conectarBaseDatos();
        $connection->begin_transaction();

        $stmtOrder = $connection->prepare(
            'INSERT INTO pedidos (codigo, nombre_cliente, correo_cliente, total)
             VALUES (?, ?, ?, ?)'
        );
        $stmtOrder->bind_param('sssd', $receiptCode, $customerName, $customerEmail, $total);
        $stmtOrder->execute();

        $orderId = $connection->insert_id;

        $stmtDetail = $connection->prepare(
            'INSERT INTO detalle_pedido
                (pedido_id, producto_id, nombre_producto, precio_unitario, cantidad, subtotal)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($orderItems as $item) {
            $productId = (string) $item['id'];
            $productName = (string) $item['name'];
            $unitPrice = (float) $item['price'];
            $quantity = (int) $item['quantity'];
            $subtotal = (float) $item['subtotal'];

            $stmtDetail->bind_param(
                'issdid',
                $orderId,
                $productId,
                $productName,
                $unitPrice,
                $quantity,
                $subtotal
            );
            $stmtDetail->execute();
        }

        $digits = preg_replace('/\D+/', '', $cardNumber) ?? '';
        $lastFour = substr($digits, -4);
        $status = 'aprobado_simulado';

        $stmtPayment = $connection->prepare(
            'INSERT INTO pagos_simulados
                (pedido_id, tipo_tarjeta, ultimos_4, vencimiento, estado, total)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmtPayment->bind_param(
            'issssd',
            $orderId,
            $cardType,
            $lastFour,
            $expiry,
            $status,
            $total
        );
        $stmtPayment->execute();

        $connection->commit();
    } catch (Throwable $error) {
        if ($connection instanceof mysqli) {
            $connection->rollback();
        }
        throw new RuntimeException(
            'No se pudo guardar el pedido en la base de datos. Detalle: ' . $error->getMessage()
        );
    } finally {
        if ($connection instanceof mysqli) {
            $connection->close();
        }
    }
}

$errors = [];
$success = false;
$orderItems = [];
$total = 0.0;
$receiptCode = '';

$customerName = '';
$customerEmail = '';
$cardType = '';
$expiry = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerName = trim((string) ($_POST['nombre_cliente'] ?? ''));
    $customerEmail = trim((string) ($_POST['correo_cliente'] ?? ''));
    $cardNumber = trim((string) ($_POST['numero_tarjeta'] ?? ''));
    $cardType = detectCardType($cardNumber);
    $expiry = trim((string) ($_POST['vencimiento'] ?? ''));
    $cvv = trim((string) ($_POST['cvv'] ?? ''));
    $orderJson = (string) ($_POST['pedido_json'] ?? '');

    if ($customerName === '') {
        $errors[] = 'Ingresa el nombre del cliente.';
    }

    if ($customerEmail === '' || filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Ingresa un correo electronico valido.';
    }

    $isSupportedCard = in_array($cardType, ['visa', 'mastercard', 'amex'], true);

    if (!$isSupportedCard) {
        $errors[] = 'No se pudo detectar si la tarjeta es Visa, Mastercard o AMEX.';
    }

    $orderItems = parseOrderItems($orderJson);
    if ($orderItems === []) {
        $errors[] = 'El carrito esta vacio o no se pudo leer el pedido.';
    }

    foreach ($orderItems as $item) {
        $total += $item['subtotal'];
    }

    if ($isSupportedCard && !isValidCardForType($cardType, $cardNumber)) {
        $errors[] = 'El numero de tarjeta no coincide con el formato detectado.';
    }

    if (!isValidExpiry($expiry)) {
        $errors[] = 'La fecha de vencimiento debe tener formato MM/AA y no estar expirada.';
    }

    if ($isSupportedCard && !isValidCvv($cardType, $cvv)) {
        $errors[] = 'El CVV no tiene el formato correcto para la tarjeta detectada.';
    }

    if ($errors === []) {
        $receiptCode = 'ARG-' . date('Ymd-His');

        try {
            saveSimulatedOrder(
                $receiptCode,
                $customerName,
                $customerEmail,
                $orderItems,
                $total,
                $cardType,
                $cardNumber,
                $expiry
            );
            $success = true;
        } catch (RuntimeException $error) {
            $errors[] = $error->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta
      name="description"
      content="Carrito de compras del Restaurante La Argentina con validacion de tarjeta Visa, Mastercard y AMEX."
    />
    <title>Carrito | Restaurante La Argentina</title>
    <link rel="stylesheet" href="../css/styles.css" />
    <link rel="stylesheet" href="../css/stylesCarrito.css" />
    <script src="../js/smooth.js" defer></script>
    <script src="../js/carrito.js" defer></script>
  </head>
  <body
    class="carrito-page"
    data-cart-page
    data-clear-cart="<?= $success ? 'true' : 'false' ?>"
  >
    <a class="skip-link" href="#contenido">Saltar al contenido</a>

    <div class="site-shell">
      <header class="site-header">
        <div class="site-header-inner">
          <div class="brand">
            <img
              class="brand-flag"
              src="../images/bandera.jpg"
              alt="Bandera de Argentina"
              width="96"
              height="96"
            />
            <div class="brand-copy">
              <p class="brand-kicker">Compra en linea</p>
              <p class="brand-title">Restaurante La Argentina</p>
            </div>
          </div>

          <nav class="site-nav" aria-label="Principal">
            <a href="../index.html">Inicio</a>
            <a href="informacion.html">Informacion</a>
            <a href="menu.html">Menu</a>
            <a href="bebidas.html">Bebidas</a>
            <a href="contacto.html">Contacto</a>
            <a href="carrito.php" aria-current="page"
              >Carrito <span class="cart-badge" data-cart-count>0</span></a
            >
          </nav>
        </div>
      </header>

      <main id="contenido" class="page-main">
        <section class="panel panel-dark page-hero" aria-labelledby="carrito-titulo">
          <p class="hero-badge">Pedido y pago</p>
          <h1 id="carrito-titulo" class="section-title">
            Carrito de compras
          </h1>
          <p class="section-copy">
            Revisa los platillos agregados, modifica cantidades, cancela la compra
            si lo necesitas y valida el pago con Visa, Mastercard o AMEX.
          </p>
          <div class="quick-links">
            <a class="top-link" href="menu.html">Volver al menu</a>
            <a class="top-link" href="../index.html">Ir al inicio</a>
          </div>
        </section>

        <?php if ($success): ?>
          <section class="panel checkout-success" aria-labelledby="compra-exitosa">
            <p class="eyebrow">Compra registrada</p>
            <h2 id="compra-exitosa">Pago validado correctamente</h2>
            <p>
              Gracias, <?= h($customerName) ?>. Tu pedido fue procesado con el codigo
              <strong><?= h($receiptCode) ?></strong> y el monto total fue
              <strong>CRC <?= number_format($total, 0, '.', ',') ?></strong>.
              El pedido y el pago simulado quedaron guardados en MySQL.
            </p>
            <ul class="order-list">
              <?php foreach ($orderItems as $item): ?>
                <li>
                  <?= h($item['name']) ?> x <?= (int) $item['quantity'] ?>
                  (CRC <?= number_format((float) $item['subtotal'], 0, '.', ',') ?>)
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
          <section class="panel" aria-labelledby="errores-pago">
            <p class="eyebrow">Revisar datos</p>
            <h2 id="errores-pago" class="section-title">No se pudo procesar el pago</h2>
            <ul class="server-errors">
              <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>

        <section class="panel checkout-layout" aria-labelledby="detalle-pedido">
          <div class="summary-stack">
            <div class="section-heading">
              <h2 id="detalle-pedido" class="section-title">Detalle del pedido</h2>
              <p class="section-copy">
                Puedes sumar o quitar productos antes de confirmar la compra.
              </p>
            </div>

            <p class="feedback-message" data-cart-message hidden></p>

            <div class="checkout-empty" data-cart-empty>
              <h3>Tu carrito esta vacio</h3>
              <p>
                Vuelve al menu para agregar varios platillos antes de completar el pago.
              </p>
            </div>

            <div class="cart-list" data-cart-items></div>
          </div>

          <aside class="summary-stack">
            <article class="summary-card">
              <h3>Resumen de compra</h3>
              <p>
                Total del pedido:
                <strong class="summary-total" data-cart-total>CRC 0</strong>
              </p>
              <p class="payment-note">
                Desde aqui tambien puedes cancelar la compra y vaciar el carrito.
              </p>
            </article>

            <article class="summary-card">
              <h3>Datos de pago</h3>
              <p class="payment-note">
                Pago simulado: no se realiza ningun cobro real. Puedes probar con
                Visa 4111111111111111, Mastercard 5555555555554444 o AMEX
                378282246310005.
              </p>
              <form class="contact-form" method="post" data-payment-form>
                <div class="payment-grid">
                  <div class="field-full">
                    <label for="nombre_cliente">Nombre del cliente</label>
                    <input
                      type="text"
                      id="nombre_cliente"
                      name="nombre_cliente"
                      value="<?= h($customerName) ?>"
                      required
                    />
                  </div>

                  <div class="field-full">
                    <label for="correo_cliente">Correo electronico</label>
                    <input
                      type="email"
                      id="correo_cliente"
                      name="correo_cliente"
                      value="<?= h($customerEmail) ?>"
                      required
                    />
                  </div>

                  <div class="field-full">
                    <label for="numero_tarjeta">Numero de tarjeta</label>
                    <input
                      type="text"
                      id="numero_tarjeta"
                      name="numero_tarjeta"
                      inputmode="numeric"
                      autocomplete="cc-number"
                      placeholder="Ejemplo: 4111111111111111"
                      required
                    />
                    <p class="payment-note" data-card-brand>
                      Formato pendiente de identificar.
                    </p>
                  </div>

                  <div class="field-full">
                    <label for="tarjeta_detectada">Tipo de tarjeta</label>
                    <input
                      type="text"
                      id="tarjeta_detectada"
                      value="<?= $cardType !== '' && $cardType !== 'desconocida' ? h(strtoupper($cardType)) : 'Pendiente de detectar' ?>"
                      readonly
                      data-card-type-display
                    />
                  </div>

                  <div>
                    <label for="vencimiento">Vencimiento</label>
                    <input
                      type="text"
                      id="vencimiento"
                      name="vencimiento"
                      inputmode="numeric"
                      placeholder="MM/AA"
                      value="<?= h($expiry) ?>"
                      required
                    />
                  </div>

                  <div>
                    <label for="cvv">CVV</label>
                    <input
                      type="password"
                      id="cvv"
                      name="cvv"
                      inputmode="numeric"
                      autocomplete="cc-csc"
                      placeholder="3 o 4 digitos"
                      required
                    />
                  </div>
                </div>

                <input type="hidden" name="pedido_json" value="[]" />
                <input type="hidden" name="total_pedido" value="0" />
                <input type="hidden" name="tipo_tarjeta" value="<?= h($cardType) ?>" />

                <div class="form-actions">
                  <button type="submit" data-checkout-button>Simular pago</button>
                  <button type="button" class="button button-secondary" data-clear-cart>
                    Cancelar compra
                  </button>
                </div>
              </form>
            </article>
          </aside>
        </section>
      </main>

      <footer class="site-footer">
        <p>
          &copy; 2026 Restaurante La Argentina. Todos los derechos reservados.
          Sitio desarrollado por Fabian Herra.
        </p>
      </footer>
    </div>
  </body>
</html>
