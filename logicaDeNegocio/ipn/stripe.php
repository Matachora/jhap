<?php

//NO DISPONIBLE _ Borrador

set_time_limit(0);
include '../config.php';
include '../functions.php';
require_once('../lib/stripe/init.php');
\Stripe\Stripe::setApiKey(stripe_secret_key);
if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
    exit('No signature specified!');
}
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$event = null;
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig_header, stripe_webhook_secret);
} catch(\UnexpectedValueException $e) {
    http_response_code(400);
    exit;
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}
function get_line_items_data($id, $sessions, $starting_after = null) {
    $data = [];
    if ($starting_after != null) {
        $line_items = $sessions->allLineItems($id, ['limit' => 99, 'starting_after' => $starting_after]);
    } else {
        $line_items = $sessions->allLineItems($id, ['limit' => 99]);
    }
    $data = $line_items->data;
    if ($line_items->has_more) {
        $item = end($data);
        $data = $data + get_line_items_data($id, $sessions, $item->id);
    }
    return $data;
}
if ($event->type == 'checkout.session.completed') {
    $intent = $event->data->object;
    $stripe = new \Stripe\StripeClient(stripe_secret_key);
    $pdo = pdo_connect_mysql();
    $products_in_cart = [];
    $subtotal = 0.00;
    $shipping_total = 0.00;
    $shipping_method = '';
    $line_items = get_line_items_data($intent->id, $stripe->checkout->sessions);
    $discount_code = isset($intent->metadata->discount_code) ? $intent->metadata->discount_code : '';
    $txn_id = '';
    $payment_status = default_payment_status;
    $email = isset($intent->customer_email) ? $intent->customer_email : '';
    $first_name = isset($intent->metadata->first_name) ? $intent->metadata->first_name : '';
    $last_name = isset($intent->metadata->last_name) ? $intent->metadata->last_name : '';
    $address_street = isset($intent->metadata->address_street) ? $intent->metadata->address_street : '';
    $address_city = isset($intent->metadata->address_city) ? $intent->metadata->address_city : '';
    $address_state = isset($intent->metadata->address_state) ? $intent->metadata->address_state : '';
    $address_zip = isset($intent->metadata->address_zip) ? $intent->metadata->address_zip : '';
    $address_country = isset($intent->metadata->address_country) ? $intent->metadata->address_country : '';
    $account_id = isset($intent->metadata->account_id) ? $intent->metadata->account_id : '';
    if (isset($intent->subscription) && !empty($intent->subscription)) {
        $txn_id = $intent->subscription;
        $payment_status = 'Subscribed';
    } else {
        $txn_id = $intent->payment_intent;
    }
    if ($account_id) {
        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([ $account_id ]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($account) {
            $email = empty($email) ? $account['email'] : $email;
            $first_name = empty($first_name) ? $account['first_name'] : $first_name;
            $last_name = empty($last_name) ? $account['last_name'] : $last_name;
            $address_street = empty($address_street) ? $account['address_street'] : $address_street;
            $address_city = empty($address_city) ? $account['address_city'] : $address_city;
            $address_state = empty($address_state) ? $account['address_state'] : $address_state;
            $address_zip = empty($address_zip) ? $account['address_zip'] : $address_zip;
            $address_country = empty($address_country) ? $account['address_country'] : $address_country;
        }
    }
    foreach ($line_items as $line_item) {
        $product = $stripe->products->retrieve($line_item->price->product);
        $item_options = isset($product->metadata->item_options) ? $product->metadata->item_options : '';
        $item_shipping = isset($product->metadata->item_shipping) ? $product->metadata->item_shipping : 0.00;
        if ($product->metadata->item_id == 'shipping') {
            $shipping_total = floatval($line_item->price->unit_amount) / 100;
            $shipping_method = isset($product->metadata->shipping_method) ? $product->metadata->shipping_method : '';
            continue;
        }
        $stmt = $pdo->prepare('UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE quantity > 0 AND id = ?');
        $stmt->execute([ $line_item->quantity, $product->metadata->item_id ]);
        if ($item_options) {
            $options = explode(',', $item_options);
            foreach ($options as $opt) {
                $option_name = explode('-', $opt)[0];
                $option_value = explode('-', $opt)[1];
                $stmt = $pdo->prepare('UPDATE products_options SET quantity = GREATEST(quantity - ?, 0) WHERE quantity > 0 AND option_name = ? AND (option_value = ? OR option_value = "") AND product_id = ?');
                $stmt->execute([ $line_item->quantity, $option_name, $option_value, $product->metadata->item_id ]);         
            }
        }
        $stmt = $pdo->prepare('INSERT INTO transactions_items (txn_id, item_id, item_price, item_quantity, item_options) VALUES (?,?,?,?,?)');
        $stmt->execute([ $txn_id, $product->metadata->item_id, floatval($line_item->price->unit_amount) / 100, $line_item->quantity, $item_options ]);
        $products_in_cart[] = [
            'id' => $product->metadata->item_id,
            'quantity' => $line_item->quantity,
            'options' => $item_options,
            'final_price' => floatval($line_item->price->unit_amount) / 100,
            'meta' => [
                'title' => $line_item->description,
                'price' => floatval($line_item->price->unit_amount) / 100
            ]
        ];
        $subtotal += (floatval($line_item->price->unit_amount) / 100) * intval($line_item->quantity);
    }
    $total = $subtotal + $shipping_total;
    $stmt = $pdo->prepare('INSERT INTO transactions (txn_id, payment_amount, payment_status, created, payer_email, first_name, last_name, address_street, address_city, address_state, address_zip, address_country, account_id, payment_method, shipping_method, shipping_amount, discount_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payment_status = VALUES(payment_status)');
    $stmt->execute([ $txn_id, $total, $payment_status, date('Y-m-d H:i:s'), $email, $first_name, $last_name, $address_street, $address_city, $address_state, $address_zip, $address_country, $account_id, 'stripe', $shipping_method, $shipping_total, $discount_code ]);
    $order_id = $pdo->lastInsertId();
    send_order_details_email($email, $products_in_cart, $first_name, $last_name, $address_street, $address_city, $address_state, $address_zip, $address_country, $total, $order_id);
}
if ($event->type == 'customer.subscription.deleted') {
    $intent = $event->data->object;
    $pdo = pdo_connect_mysql();
    $stmt = $pdo->prepare('UPDATE transactions SET payment_status = ? WHERE txn_id = ?');
    $stmt->execute([ 'Unsubscribed', $intent->id ]);
}
if ($event->type == 'invoice.payment_failed') {
    $intent = $event->data->object;
    $pdo = pdo_connect_mysql();
    $stmt = $pdo->prepare('UPDATE transactions SET payment_status = ? WHERE txn_id = ?');
    $stmt->execute([ 'Unsubscribed', $intent->subscription ]);
}
?>