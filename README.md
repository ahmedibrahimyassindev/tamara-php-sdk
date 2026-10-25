# Tamara Payment PHP SDK (Generic & Standalone)

> **Unofficial third-party SDK.** This community project is not developed, maintained, or endorsed by Tamara.

[![Latest Stable Version](https://img.shields.io/packagist/v/ahmedibrahimyassindev/tamara-php-sdk.svg?style=flat-square)](https://packagist.org/packages/ahmedibrahimyassindev/tamara-php-sdk)
[![Total Downloads](https://img.shields.io/packagist/dt/ahmedibrahimyassindev/tamara-php-sdk.svg?style=flat-square)](https://packagist.org/packages/ahmedibrahimyassindev/tamara-php-sdk)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat-square)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](LICENSE)

A third-party PHP library for integrating Tamara Buy Now Pay Later into PHP applications. The core can be used without a web framework, with optional Laravel integration.

---

## Features

- **Framework Independent Core**: Use the SDK without a framework; Laravel integration is optional.
- **PSR Standards**: Uses PSR-4 autoloading and optional PSR-3 Logger (`Psr\Log\LoggerInterface`).
- **Laravel Ready**: Out-of-the-box auto-discovery, `Tamara` Facade, and publishable config.
- **One-Call Checkout**: Create checkout sessions and redirect customers with minimal boilerplate.
- **Unified Webhook Processor**: Map events (`order_approved`, `order_authorised`, `order_captured`, `order_declined`) to your model automatically.
- **Fee Calculation Helpers**: Opt-in helpers calculate fee amounts; the SDK does not add fees to checkout totals automatically.

## Why this SDK?

Tamara also publishes an [official PHP SDK](https://github.com/Tamara-Technology/php-sdk) as `tamara-solution/php-sdk`. Choose that package when you want Tamara's official API client and transport integrations. Choose this package when you want its orderable entity trait, webhook processor, and optional Laravel service provider.

| | Official Tamara SDK | This community SDK |
| --- | --- | --- |
| Composer package | `tamara-solution/php-sdk` | `ahmedibrahimyassindev/tamara-php-sdk` |
| PHP namespace | `Tamara\` | `TamaraPhpSdk\` |
| Maintainer | Tamara | Community project; not endorsed by Tamara |

The distinct namespace allows both packages to be installed together without this SDK claiming the official SDK's `Tamara\` prefix.

### Upgrade note for v2

Version 2 changes all SDK imports from `Tamara\...` to `TamaraPhpSdk\...`. The optional Laravel facade alias changes from `Tamara` to `TamaraPhpSdk`. Update imports and facade references when upgrading from v1.

---

## Installation

```bash
composer require ahmedibrahimyassindev/tamara-php-sdk
```

---

## Configuration

Set the following environment variables (or pass them directly into `TamaraClient`):

```env
TAMARA_MERCHANT_TOKEN=your_merchant_api_token
TAMARA_NOTIFICATION_TOKEN=your_notification_webhook_secret
TAMARA_SANDBOX=true # Set to false in production
TAMARA_AUTO_AUTHORISE=false
TAMARA_REQUIRES_SHIPPING_ADDRESS=true
TAMARA_WEBHOOK_DEDUPLICATION_TTL=86400
```

In Laravel, `TAMARA_AUTO_AUTHORISE=true` enables authorisation in the webhook
processor resolved by the service provider. Set `TAMARA_REQUIRES_SHIPPING_ADDRESS=false`
for checkouts that contain no shippable goods.
Laravel webhook processing uses the configured cache store for atomic event claims;
use a persistent shared store such as Redis, database, or file cache in production.
For capture and refund events, the service-created processor checks Tamara's current
remote status so multiple partial captures and refunds can advance the local state
through `partially_captured`, `partially_refunded`, and their completed states. Models
that do not use `TamaraOrderableTrait` should handle the `_tamara_order_status` callback
field or implement `onTamaraPartiallyCaptured()` and `onTamaraPartiallyRefunded()`.

---

## Quick Start

### 1. Make Your Model Orderable

Implement `TamaraPhpSdk\Contracts\TamaraOrderableInterface` (you can use `TamaraPhpSdk\Traits\TamaraOrderableTrait` to satisfy all default methods):

```php
use TamaraPhpSdk\Contracts\TamaraOrderableInterface;
use TamaraPhpSdk\Traits\TamaraOrderableTrait;

class Order extends \Illuminate\Database\Eloquent\Model implements TamaraOrderableInterface
{
    use TamaraOrderableTrait;

    public function getTamaraAmount(): float
    {
        return (float) $this->total_amount;
    }
}
```

The entity must be able to persist `tamara_order_id`, `tamara_checkout_id`, and `tamara_status` when using `TamaraOrderableTrait`. Eloquent models can use corresponding database attributes (or their supported dynamic attributes); plain PHP entities should declare all three properties. The SDK checks this before creating a Tamara checkout, so a configuration mistake cannot leave an unrecorded remote session.
The checkout currency comes from the order's `getTamaraCurrency()` method. The Laravel `default_locale` setting controls the default hosted checkout locale; an explicit locale passed to `initiateCheckout()` takes precedence.

### 2. Create Checkout Session

```php
use TamaraPhpSdk\TamaraService;

// Create service from environment variables
$service = TamaraService::fromEnv();

// Or instantiate manually with credentials
// $client = new \TamaraPhpSdk\TamaraClient($merchantToken, $notificationToken, $sandbox = true);
// $service = new TamaraService($client);

$checkoutUrl = $service->initiateCheckout(
    $order,
    'https://yourdomain.com/payment/success',
    'https://yourdomain.com/payment/failure',
    'https://yourdomain.com/payment/cancel'
);

// Redirect user to Tamara
header("Location: {$checkoutUrl}");
exit;
```

### 3. Handle Webhooks (Secure & Authenticated)

```php
use TamaraPhpSdk\TamaraService;

$service   = TamaraService::fromEnv();
$processor = $service->createWebhookProcessor();


// Register resolvers to find matching orders by reference ID
$processor->addResolver(function (string $orderReferenceId) {
    return Order::where('order_reference_id', $orderReferenceId)->first();
});

$rawPayload = file_get_contents('php://input');
$data       = json_decode($rawPayload, true) ?: [];
$token      = $_GET['tamaraToken'] ?? null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

$result = $processor->process($data, $rawPayload, $token, $authHeader);

header('Content-Type: application/json');
echo json_encode($result);
```

---

## Framework Integration Examples

### Laravel Controller Example

```php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use TamaraPhpSdk\TamaraService;
use TamaraPhpSdk\TamaraWebhookProcessor;

class TamaraPaymentController extends Controller
{
    public function checkout(Order $order, TamaraService $tamara)
    {
        $redirectUrl = $tamara->initiateCheckout(
            $order,
            route('tamara.success'),
            route('tamara.failure'),
            route('tamara.cancel')
        );

        return redirect()->away($redirectUrl);
    }

    public function webhook(Request $request, TamaraWebhookProcessor $processor)
    {

        $processor->addResolver(fn($ref) => Order::where('order_reference_id', $ref)->first());

        $result = $processor->process(
            $request->all(),
            $request->getContent(),
            $request->bearerToken(),
            $request->header('Authorization'),
            $request->query()
        );
        return response()->json($result, $result['http_status'] ?? 200);
    }
}
```

Return the processor's `http_status` as shown so Tamara can retry failed deliveries. `retry` is true for a `409` event that is ahead of the stored order status and may become valid later, and a `503` event another worker is still processing. Stale or terminal-state events are acknowledged as `200` and ignored. Status meanings:

| HTTP status | Meaning |
| --- | --- |
| `200` | Processed, duplicate, or unsupported event acknowledged |
| `400` | Malformed webhook payload |
| `401` | Missing or invalid webhook token |
| `404` | Matching order was not found; delivery can be retried after local order creation |
| `409` | Event is ahead of the stored order status and may become valid when earlier events arrive; retry |
| `500` | Local webhook handler or persistence failed, or the stored Tamara order status is unrecognised |
| `502` | Tamara API/status verification or auto-authorisation failed |
| `503` | Another worker holds the short processing lease; retry after it expires or finishes |

---

## Advanced API Operations

### Authorise approved orders

After receiving `order_approved`, authorise the order server-side so Tamara can
move it to `authorised`. Enable the processor's opt-in helper when creating it:

```php
$processor = $tamara->createWebhookProcessor();
$processor->autoAuthorise(true);
```

The helper calls Tamara's authorise endpoint when an `order_approved` event is
processed. If your account uses Tamara auto-authorisation, leave this disabled.

```php
// Authorise order
$service->authoriseOrder($tamaraOrderId);

// Get order details
$orderData = $service->getOrder($tamaraOrderId);

// Capture payment (provide shipping information required by Tamara)
$service->capturePayment($tamaraOrderId, $amount, 'SAR', $items, $shippingInfo);

// Cancel order
// Tamara accepts cancellation only while the order is authorised and uncaptured.
$service->cancelOrder($tamaraOrderId);
```

For capture, `$shippingInfo` must include `shipped_at` and `shipping_company`.
`tracking_number` (string, integer, or null) and `tracking_url` (string or null) are optional.
The method also accepts optional shipping, tax,
and discount amounts after `$shippingInfo`. Tamara cancellation is allowed only while
the order is authorised; the SDK checks the remote order status before calling cancel.
Canceling the uncaptured remainder after a partial capture is out of scope. Cancel events
after capture are treated as stale and acknowledged without changing the local order.

The order builder requires an address by default. For digital services, inject a builder
configured with `(new TamaraOrderBuilder())->setRequiresShippingAddress(false)` into
`TamaraService`. The builder sends
zero shipping and tax amounts when the order does not define
`getTamaraShippingAmount()` or `getTamaraTaxAmount()`. A positive discount is included
only when the order defines `getTamaraDiscount()`, and its payload includes a name.
The trait's default item represents the full order amount. If your order has shipping,
tax, or a discount, override `getTamaraItems()` so item totals plus those adjustments
match `getTamaraAmount()`. The optional discount label can be supplied by implementing
`getTamaraDiscountName()`; otherwise the builder uses `Discount`.

### Sandbox verification status

Checkout, approval, and authorisation were exercised against Tamara's sandbox. This account
automatically captured the order after authorisation, so **manual capture and cancellation
have not yet been verified in the sandbox**. Webhook payload handling was tested locally;
delivery from Tamara to a registered endpoint was not part of that local test.

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
