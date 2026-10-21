# Tamara Payment PHP SDK (Generic & Standalone)

[![Latest Stable Version](https://img.shields.io/packagist/v/ahmedibrahimyassindev/tamara-php-sdk.svg?style=flat-square)](https://packagist.org/packages/ahmedibrahimyassindev/tamara-php-sdk)
[![Total Downloads](https://img.shields.io/packagist/dt/ahmedibrahimyassindev/tamara-php-sdk.svg?style=flat-square)](https://packagist.org/packages/ahmedibrahimyassindev/tamara-php-sdk)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg?style=flat-square)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](LICENSE)

A modern, framework-agnostic PHP SDK for integrating **Tamara (Buy Now Pay Later)** into any PHP project: **Laravel, Symfony, Yii2, WordPress, or custom vanilla applications**.

---

## Features

- **Framework Agnostic**: Zero dependency on any specific framework (Yii2, Laravel, etc.).
- **PSR Standards**: Uses PSR-4 autoloading and optional PSR-3 Logger (`Psr\Log\LoggerInterface`).
- **Laravel Ready**: Out-of-the-box auto-discovery, `Tamara` Facade, and publishable config.
- **One-Call Checkout**: Create checkout sessions and redirect customers with minimal boilerplate.
- **Unified Webhook Processor**: Map events (`order_approved`, `order_authorised`, `order_captured`, `order_declined`) to your model automatically.
- **Configurable Fees / Markup**: Easily configure or bypass markup rates via `TamaraService::setFeeRatio()`.

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
```

---

## Quick Start

### 1. Make Your Model Orderable

Implement `Tamara\Contracts\TamaraOrderableInterface` (you can use `Tamara\Traits\TamaraOrderableTrait` to satisfy all default methods):

```php
use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Traits\TamaraOrderableTrait;

class Order extends \Illuminate\Database\Eloquent\Model implements TamaraOrderableInterface
{
    use TamaraOrderableTrait;

    public function getTamaraAmount(): float
    {
        return (float) $this->total_amount;
    }
}
```

### 2. Create Checkout Session

```php
use Tamara\TamaraService;

// Create service from environment variables
$service = TamaraService::fromEnv();

// Or instantiate manually with credentials
// $client = new \Tamara\TamaraClient($merchantToken, $notificationToken, $sandbox = true);
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

### 3. Handle Webhooks

```php
use Tamara\TamaraWebhookProcessor;

$processor = new TamaraWebhookProcessor();

// Register resolvers to find matching orders by reference ID
$processor->addResolver(function (string $orderReferenceId) {
    return Order::where('order_reference_id', $orderReferenceId)->first();
});

$rawPayload = file_get_contents('php://input');
$data       = json_decode($rawPayload, true) ?: [];

$result = $processor->process($data, $rawPayload);

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
use Tamara\TamaraService;
use Tamara\TamaraWebhookProcessor;

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

    public function webhook(Request $request)
    {
        $processor = new TamaraWebhookProcessor();
        $processor->addResolver(fn($ref) => Order::where('order_reference', $ref)->first());

        $result = $processor->process($request->all(), $request->getContent());
        return response()->json($result);
    }
}
```

---

## Advanced API Operations

```php
// Authorise order
$service->authoriseOrder($tamaraOrderId);

// Get order details
$orderData = $service->getOrder($tamaraOrderId);

// Capture payment
$service->capturePayment($tamaraOrderId, $amount, 'SAR');

// Cancel order
$service->cancelOrder($tamaraOrderId);
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
