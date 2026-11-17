<?php
/*
Stripe integration usage guide:

1. Create a new Stripe transaction. This returns "payID" and "payUrl":

    require_once(app_path('Libs/payment/StripeAPI.php'));
    $stripeAPI = new \StripeAPI($stripeClientSecret);
    $stripeAPI->setReturnUrl(xxxx);
    $stripeAPI->setCancelUrl(xxxx);
    $stripeCheckout = $stripeAPI->doCheckout($items);
    redirect($stripeCheckout['payment_url']);


2. Webhook verification. Determines whether the transaction is complete;
   returns the payment object when paid, otherwise false:

    require_once(app_path('Libs/payment/StripeAPI.php'));
    $stripeAPI = new \StripeAPI($stripeClientSecret);
    $stripeResponse = $stripeAPI->webHookResult();


3. Return-link handling with a polling loop:

    $securityCheckoutId = $this->getParamValue('sc_id');
    if(!empty($securityCheckoutId)) {
        $targetTemp = $eshopOrderModel->getTempOrderByPayID($securityCheckoutId);
        if(!empty($targetTemp)) {
            $stripeClientSecret = $this->setting_model->getByName('stripe_clientSecret');
            require_once(app_path('Libs/payment/StripeAPI.php'));
            $stripeAPI = new \StripeAPI($stripeClientSecret);

            // Poll for the payment result
            $maxLoop = 1;
            $paymentStatus = '';
            do {
                if($maxLoop > 1) {
                    sleep(5);
                }
                $paymentStatus = $stripeAPI->fetchResult($securityCheckoutId, true);
                $maxLoop++;
                if($paymentStatus == 'paid' || $paymentStatus == 'succeeded') {
                    $paidTransactionId = $stripeAPI->transactionID();
                    ...
                }
                $maxLoop++;
            } while(($paymentStatus != 'paid' && $paymentStatus != 'succeeded') && $maxLoop < 10);
        }
    }
*/

/**
 * Stripe payment API client.
 *
 * Wraps Stripe Checkout (web) and PaymentIntent (app) flows:
 *  - doCheckout()     create a Checkout Session or PaymentIntent
 *  - fetchResult()    poll the status of a session/intent
 *  - webHookResult()  verify and interpret an incoming webhook
 *  - transactionID()  return the captured payment intent ID
 *
 * Each checkout is tagged with a generated `securityCheckoutID` so the
 * application can correlate its own order with the Stripe object.
 */
class StripeAPI {
    /** Stripe secret API key. */
    private $secretKey = '';
    /** ISO currency code (lowercase; Stripe convention). */
    private $currency = 'hkd';
    /** Discount amount (subtracted from the total). */
    private $discount = 0;
    /** Shipping fee (added to the total). */
    private $shippingFee = 0;
    /** URL Stripe redirects to after a successful payment. */
    private $returnUrl = '';
    /** URL Stripe redirects to when the user cancels. */
    private $cancelUrl = '';
    /** Last parsed webhook event/response. */
    private $response = null;
    /** Captured payment intent ID. */
    private $transactionID = '';
    /** Webhook signing secret (leave as "whsec_" to disable verification). */
    private $endpointSecret = 'whsec_';

    /**
     * Constructor.
     *
     * @param string $secretKey      Stripe secret API key
     * @param string $endpointSecret Webhook signing secret (optional)
     */
    public function __construct($secretKey = '', $endpointSecret = '') {
        if(!empty($secretKey)) {
            $this->secretKey = $secretKey;
        }
        if(!empty($endpointSecret)) {
            $this->endpointSecret = $endpointSecret;
        }
    }
    
    /**
     * Set the ISO currency code.
     *
     * @param string $currency
     * @return void
     */
    public function setCurrency($currency = 'hkd') {
        if(!empty($currency)) {
            $this->currency = $currency;
        }
    }
    
    /**
     * Set the discount amount (non-negative).
     *
     * @param float $value
     * @return void
     */
    public function setDiscount($value = 0) {
        $this->discount = max(0, (float)$value);
    }
    
    /**
     * Set the shipping fee (non-negative).
     *
     * @param float $value
     * @return void
     */
    public function setShippingFee($value = 0) {
        $this->shippingFee = max(0, (float)$value);
    }
    
    /**
     * Set the success redirect URL.
     *
     * @param string $url
     * @return void
     */
    public function setReturnUrl($url = '') {
        if(!empty($url)) {
            $this->returnUrl = $url;
        }
    }
    
    /**
     * Set the cancel redirect URL.
     *
     * @param string $url
     * @return void
     */
    public function setCancelUrl($url = '') {
        if(!empty($url)) {
            $this->cancelUrl = $url;
        }
    }
    
    /**
     * Get the last parsed webhook event/response.
     *
     * @return mixed
     */
    public function getResponse() {
        return $this->response;
    }
   
    /**
     * Create a Stripe checkout for the given items.
     *
     * Two modes are supported:
     *  - 'app'  : creates a PaymentIntent and returns the client secret for
     *             client-side confirmation.
     *  - 'web'  : creates a Checkout Session and returns the hosted payment
     *             URL. Requires both the return and cancel URLs to be set.
     *
     * Each item is ['name', 'price', 'quantity']. Discount and shipping fee
     * are applied when set. A unique securityCheckoutID is generated and
     * embedded in metadata (and in the redirect URLs for the web flow) so
     * the caller can correlate its order with the Stripe object.
     *
     * @param array  $items    List of items to charge
     * @param string $platform 'web' (default) or 'app'
     * @return array|false Checkout data, or false on failure
     */
    public function doCheckout($items = [], $platform = 'web') {
        require_once(app_path('Libs/payment/stripe-php/init.php')); 
        \Stripe\Stripe::setApiKey($this->secretKey);
        
        // Generate a custom identification ID for correlating with Stripe
        $securityCheckoutID = 'SC-'.md5(date('YmdHis').$platform.uniqid(rand()));
        
        // Start processing when items are supplied
        if(is_array($items) && !empty($items)) {
            if(strtolower($platform) == 'app') {
                // App mode: build a PaymentIntent with aggregated metadata
                $itemsDetails = [];
                $totalAmount = 0;
                $k = 1;
                foreach ($items as $item) {
                    $totalAmount += $item['price'] * $item['quantity'];
                    $itemsDetails['Item'.$k] = $item['name'].' x '.$item['quantity'].' | $'.number_format(round($item['price'], 2), 2);
                    $k++;
                }
                
                if(!empty($this->discount)) {
                    $totalAmount -= $this->discount;
                    $itemsDetails['Discount'] = '$'.number_format(round($this->discount, 2), 2);
                }
                
                if(!empty($this->shippingFee)) {
                    $totalAmount += $this->shippingFee;
                    $itemsDetails['ShippingFee'] = '$'.number_format(round($this->shippingFee, 2), 2);
                }
                $itemsDetails['securityCheckoutID'] = $securityCheckoutID;
  
                $paymentIntent = \Stripe\PaymentIntent::create([
                    'amount'    =>  round($totalAmount * 100),
                    'currency'  =>  $this->currency,
                    'metadata'  =>  $itemsDetails,
                    'automatic_payment_methods' =>  ['enabled' => true],
                ]);

                return [
                    'payID'                 =>  $paymentIntent->id,
                    'clientSecret'          =>  $paymentIntent->client_secret,
                    'securityCheckoutID'    =>  $securityCheckoutID
                ];
            }
            else if(!empty($this->returnUrl) && !empty($this->cancelUrl)) {
                // Web mode: build a hosted Checkout Session with line items
                $lineItems = [];
                foreach ($items as $key => $value) {
                    $lineItems[] = [
                        'price_data' => [
                            'product_data' => [
                                'name' => $value['name'],
                            ],
                            'currency' => $this->currency,
                            'unit_amount' => round($value['price']*100),
                        ],
                        'quantity' => $value['quantity']
                    ];
                }

                $options =
                [
                    'line_items'    =>  $lineItems,
                    'discounts'     =>  null,
                    'mode'          =>  'payment',
                    'success_url'   =>  $this->returnUrl.((strpos($this->returnUrl, '?') === false)?'?':'&').'sc_id='.$securityCheckoutID,
                    'cancel_url'    =>  $this->cancelUrl.((strpos($this->cancelUrl, '?') === false)?'?':'&').'sc_id='.$securityCheckoutID,
                    'expires_at'    =>  (time() + 30 * 60), // Session expires after 30 minutes
                    'metadata'      => 
                    [
                        'securityCheckoutID' => $securityCheckoutID
                    ]
                ];

                // Apply a one-off coupon when a discount is set
                if(!empty($this->discount)) {
                    $coupon = \Stripe\Coupon::create(
                    [
                        'currency'      =>  $this->currency,
                        'amount_off'    =>  round($this->discount*100),
                        'duration'      =>  'once'
                    ]);
                    $options['discounts'] = 
                    [
                        ['coupon' => $coupon->id]
                    ];
                }

                // Apply a fixed-amount shipping rate when set
                if(!empty($this->shippingFee)) {
                    $options['shipping_options'] = 
                    [
                        [
                            'shipping_rate_data' =>  
                            [
                                'type' => 'fixed_amount',
                                'fixed_amount' => 
                                [
                                    'currency'  => $this->currency,
                                    'amount'    => round($this->shippingFee*100)
                                ],
                                'display_name' => 'Shipping Fee'
                            ]
                        ]
                    ];
                }

                $checkoutSession = \Stripe\Checkout\Session::create($options);
                
                return 
                [
                    'payID'                 =>  $checkoutSession->id,  // e.g. cs_test_b1dcM72EiT6klc7zMCqmJB8hFusgVgseqHmWmGzHKcFh3cuju97oRs2uaG
                    'payUrl'                =>  $checkoutSession->url,
                    'securityCheckoutID'    =>  $securityCheckoutID
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Fetch the current status of a checkout session or payment intent.
     *
     * In 'app' mode the ID is treated as a PaymentIntent; otherwise it is a
     * Checkout Session. When $paymentStatusOnly is true, just the lowercased
     * status string is returned; otherwise the full object is returned. The
     * captured payment intent ID is stored in $transactionID.
     *
     * @param string $sessionId
     * @param bool   $paymentStatusOnly
     * @param string $platform 'web' (default) or 'app'
     * @return mixed Status string, full object, or false on invalid input
     */
    public function fetchResult($sessionId = '', $paymentStatusOnly = false, $platform = 'web') {
        if(!empty($sessionId)) {
            require_once(app_path('Libs/payment/stripe-php/init.php'));
            \Stripe\Stripe::setApiKey($this->secretKey);
            
            try {
                if(strtolower($platform) == 'app') {
                    // App mode: retrieve the PaymentIntent directly
                    $intent = \Stripe\PaymentIntent::retrieve($sessionId);
                    $this->transactionID = $sessionId;
                    return ($paymentStatusOnly && !empty($intent['status']))?strtolower($intent['status']):$intent;
                }
                else {
                    // Web mode: retrieve the Checkout Session
                    $session = \Stripe\Checkout\Session::retrieve($sessionId);
                    $this->transactionID = ((!empty($session['payment_intent']))?$session['payment_intent']:'');
                    return ($paymentStatusOnly && !empty($session['payment_status']))?strtolower($session['payment_status']):$session;
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
            
        return false;
    }

    /**
     * Verify and interpret an incoming Stripe webhook.
     *
     * Reads the raw request body (or the supplied override payload). When a
     * webhook signing secret is configured, the signature header is verified;
     * otherwise the payload is parsed without verification. Returns the
     * completed checkout session for successful payments, or false.
     *
     * Note: the signature header is read from `$_SERVER['HTTP_STRIPE_SIGNATURE']`,
     * which is missing the leading `$` and should be `$_SERVER[...]`.
     *
     * @param string $overwritePayload Optional payload override (for testing)
     * @return mixed Session/payment object, or false
     */
    public function webHookResult($overwritePayload = '') {
        // Read the raw webhook payload
        $payload = @file_get_contents('php://input');
        if(!empty($overwritePayload)) {
            $payload = $overwritePayload;
        }
        
        if(!empty($payload)) {
            require_once(app_path('Libs/payment/stripe-php/init.php'));
            \Stripe\Stripe::setApiKey($this->secretKey);
            try {
                if (!empty($this->endpointSecret) && ($this->endpointSecret != 'whsec_')) {
                    // Verify the webhook signature when a secret is configured
                    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
                    if(empty($sigHeader)) {
                        http_response_code(400);
                        return false;
                    }
                    $this->response = \Stripe\Webhook::constructEvent(
                        $payload, $sigHeader, $this->endpointSecret
                    );
                }
                else {
                    // No secret: parse the event without verification
                    $this->response = \Stripe\Event::constructFrom(
                        json_decode($payload, true)
                    );
                }
            } catch(\UnexpectedValueException $e) {
                // Malformed payload or invalid signature
                http_response_code(400);
                return false;
            }
            
            // Checkout Session completed and paid
            if (strtolower($this->response['type']) == 'checkout.session.completed') {
                if(strtolower($this->response['data']['object']['payment_status']) == 'paid') {
                    return $this->response['data']['object'];
                }
            }
            // PaymentIntent succeeded: resolve its related Checkout Session
            else if (strtolower($this->response['type']) == 'payment_intent.succeeded') {
                if(strtolower($this->response['data']['object']['status']) == 'succeeded') {
                    $relatedCheckoutSession = \Stripe\Checkout\Session::all([
                        'payment_intent' => $this->response['data']['object']['id'],
                        'limit' => 1
                    ]);
                    
                    return  $relatedCheckoutSession->data[0] ?? null;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Return the payment intent ID for a session (or the last captured one).
     *
     * When $sessionId is supplied, fetchResult() is called first to refresh
     * the captured ID; otherwise the previously captured ID is returned.
     *
     * @param string $sessionId
     * @return string
     */
    public function transactionID($sessionId = '') {
        if(!empty($sessionId)) {
            $this->fetchResult($sessionId);
        }
        
        return $this->transactionID;
    }
}