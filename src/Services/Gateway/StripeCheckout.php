<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Paylist;
use App\Models\Setting;
use App\Services\Auth;
use App\Services\Config;
use App\Services\View;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Request;
use Slim\Http\Response;

final class StripeCheckout extends AbstractPayment
{
    const STRIPE_WEBHOOK_ENDPOINT_SECRET = 'stripe_webhook_endpoint_secret';
    const STRIPE_CHECKOUT_SECRET_KEY = 'stripe_checkout_sk';
    const STRIPE_CHECKOUT_PUBLIC_KEY = 'stripe_checkout_pk';
    const STRIPE_CHECKOUT_CURRENCY = 'stripe_checkout_currency';
    const STRIPE_CHECKOUT_MIN_RECHARGE = 'stripe_checkout_min_recharge';
    const STRIPE_CHECKOUT_MAX_RECHARGE = 'stripe_checkout_max_recharge';
    const STRIPE_CHECKOUT_ENABLED_EVENTS = array(
        \Stripe\Event::CHECKOUT_SESSION_COMPLETED,
    );

    public static function _name(): string
    {
        return 'stripe_checkout';
    }

    public static function _enable(): bool
    {
        if (self::getActiveGateway('stripe_checkout') && Setting::obtain('stripe_checkout')) {
            return true;
        }

        return false;
    }

    public static function _readableName(): string
    {
        return 'Stripe Checkout';
    }

    public function purchase(Request $request, Response $response, array $args): ResponseInterface
    {
        if (!$this->checkWebhook($request, $response, $args)) {
            $response->withStatus(400, "Stripe webhook endpoint check failed! Please contact your website admin.");
        }

        $trade_no = uniqid();
        $user = Auth::getUser();
        $uid = $user->id;
        $customer_email = $user->email;
        $price = $request->getParam('price');

        if (
            $price < Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_MIN_RECHARGE) ||
            $price > Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_MAX_RECHARGE)
        ) {
            return $response->withStatus(400, "Recharge price is not in range");
        }

        $customers = \Stripe\Customer::all(['limit' => 1, 'email' => $customer_email]);

        // Create a new customer or update customer info
        if ($customers->data == null) {
            $customer = \Stripe\Customer::create([
                "email" => $customer_email,
                "name" => $user->user_name,
                "currency" => "CNY",
            ]);
        } else if ($customers->data[0]->name == null) {
            $customer = $customers->data[0];
            \Stripe\Customer::update(
                $customer->id,
                [
                    'name' => $user->user_name,
                ]
            );
        } else {
            $customer = $customers->data[0];
        }

        $pl = new Paylist();
        $pl->userid = $user->id;
        $pl->total = $price;
        $pl->tradeno = $trade_no;
        $pl->save();

        $exchange_amount = $price / self::exchange(Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_CURRENCY)) * 100;

        \Stripe\Stripe::setApiKey(Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_SECRET_KEY));
        $session = \Stripe\Checkout\Session::create([
            'customer' => $customer->id,
            'metadata' => [
                'trade_no' => $trade_no,
            ],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_CURRENCY),
                        'product_data' => [
                            'name' => Config::get('appName') . " 在线充值",
                            'description' => '充值 ' . round($exchange_amount / 100, 1) . '元到账户余额，充值出现任何问题请联系网站管理员',
                        ],
                        'unit_amount' => (int) $exchange_amount,
                    ],
                    'quantity' => 1,
                ],
            ],
            'payment_intent_data' => [
                'capture_method' => 'automatic',
            ],
            'mode' => 'payment',
            'locale' => 'zh',
            'submit_type' => 'pay',
            'allow_promotion_codes' => true,
            'client_reference_id' => $uid,
            'success_url' => Config::get('baseUrl') . '/user/code',
            'cancel_url' => Config::get('baseUrl') . '/user/code',
        ]);

        return $response->withRedirect($session->url);
    }

    public function notify($request, $response, $args): ResponseInterface
    {
        return $this->webhookHandler($request, $response, $args);
    }

    public static function getPurchaseHTML(): string
    {
        return View::getSmarty()->fetch('user/stripe_checkout.tpl');
    }

    public function getReturnHTML($request, $response, $args): ResponseInterface
    {
        return $response->withRedirect($_ENV['baseUrl'] . '/user/code');
    }

    public static function exchange($currency)
    {
        $ch = curl_init();
        $url = 'https://api.exchangerate.host/latest?symbols=CNY&base=' . strtoupper($currency);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $currency = json_decode(curl_exec($ch));
        curl_close($ch);

        return $currency->rates->CNY;
    }

    /**
     * Check if webhook is ready for call or else create it
     */
    public function checkWebhook(Request $request, Response $response, array $args)
    {
        $stripe = new \Stripe\StripeClient(Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_SECRET_KEY));
        $all_webhoooks = $stripe->webhookEndpoints->all()->data;

        $webhook_url = $_ENV['baseUrl'] . "/payment/notify/" . $this->_name();

        foreach ($all_webhoooks as $key => $webhook) {
            if ($webhook->status == "enabled") {
                $result = array_intersect(StripeCheckout::STRIPE_CHECKOUT_ENABLED_EVENTS, $webhook->enabled_events);

                if (count($result) == count(StripeCheckout::STRIPE_CHECKOUT_ENABLED_EVENTS) && $webhook->url == $webhook_url) {
                    // Found an valid webhook and return to end check
                    return true;
                }
            }
        }

        // Not found any valid webhook to capture events, create a new one
        $new_endpoint = $stripe->webhookEndpoints->create([
            'url' => $webhook_url,
            'enabled_events' => StripeCheckout::STRIPE_CHECKOUT_ENABLED_EVENTS,
        ]);

        $setting = Setting::where('item', '=', StripeCheckout::STRIPE_WEBHOOK_ENDPOINT_SECRET)->first();
        if ($setting == null) {
            return false;
        }

        $setting->value = $new_endpoint->secret;
        if (!$setting->save()) {
            return false;
        }

        return true;
    }

    /**
     * This is the handler process all type of Stripe events
     */
    public function webhookHandler(Request $request, Response $response, array $args): ResponseInterface
    {
        \Stripe\Stripe::setApiKey(Setting::obtain('stripe_checkout_sk'));

        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;
        $endpoint_secret = Setting::obtain('stripe_webhook_endpoint_secret');   //Load endpoint signature

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request,
                $sig_header,
                $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            return $response->withStatus(400, "Payload is not valid");
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            return $response->withStatus(400, "Signature check failed!");
        }

        $type = $event->type;

        switch ($type) {
            case \Stripe\Event::CHECKOUT_SESSION_COMPLETED:
                return $this->checkoutCompletedEvent($request, $response, $event->data->object);
            default:
                return $response->withStatus(400, 'Not a valid event');
        }
    }


    public function checkoutCompletedEvent(Request $request, Response $response, \Stripe\Checkout\Session $checkout)
    {
        $addnum = $checkout->id;                        //Checkout Session ID
        $uid = $checkout->client_reference_id;          //User id
        $currency = $checkout->currency;
        $live_mode = $checkout->livemode;

        /**
         * Extract payment method information from checkout session.
         */
        $stripe = new \Stripe\StripeClient(Setting::obtain(StripeCheckout::STRIPE_CHECKOUT_SECRET_KEY));
        $payment_intent = $stripe->paymentIntents->retrieve(
            $checkout->payment_intent,
            []
        );

        if (empty($payment_intent)) {
            return $response->withStatus(400, 'Not a valid checkout session, no payment intent');    // Not valid checkout session
        }

        $payment_method = $stripe->paymentMethods->retrieve(
            $payment_intent->payment_method,
            []
        );

        if (empty($payment_method)) {
            return $response->withStatus(400, 'Not a valid checkout session, no payment info');
        }

        $this->postPayment($addnum, $this->_readableName() . ' 在线支付');

        return $response->withStatus(200);
    }
}
