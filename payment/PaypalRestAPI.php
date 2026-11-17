<?php
/*
PayPal REST API usage example:

$items = 
[
    [
        'name'      =>  'Item 1',
        'price'     =>  '10.00',
        'quantity'  =>  1
    ],
    [
        'name'      =>  'Item 2',
        'price'     =>  '5.00',
        'quantity'  =>  2
    ]
];

$clientId = '...';
$clientSecret = '...';

// Start a checkout
$PaypalRestAPI = new PaypalRestAPI($clientId, $clientSecret, true);

// Items can be added one at a time
$PaypalRestAPI->addItem([
    'name'      =>  'Item 1',
    'price'     =>  '10.00',
    'quantity'  =>  1
]);
$PaypalRestAPI->addItem([
    'name'      =>  'Item 2',
    'price'     =>  '5.00',
    'quantity'  =>  2
]);

$PaypalRestAPI->setReturnUrl('http://localhost/payment/success');
$PaypalRestAPI->setCancelUrl('http://localhost/payment/faild');

$paypalCheckout = $PaypalRestAPI->doCheckout($items); // non-empty $items replaces the existing items
if(!empty($paypalCheckout)) {
    header('Location:'.$paypalCheckout['payUrl']);
    exit;
}

// Complete the payment after the user returns from PayPal
$PaypalRestAPI = new PaypalRestAPI($clientId, $clientSecret, true);
if($paymentResult = $PaypalRestAPI->doComplete($paymentId, $payerId)) {
    dump($paymentResult);
}

// Fetch transaction details
$PaypalRestAPI->transactionDetails($paymentId);

// Fetch the transaction ID
$PaypalRestAPI->transactionID($paymentId); // $paymentId is optional
*/
namespace App\Libs\payment;

/**
 * PayPal REST API client.
 *
 * Wraps the legacy PayPal REST payments endpoints: OAuth token acquisition,
 * payment creation (checkout), payment execution (complete), and
 * transaction lookup. Supports a sandbox/production endpoint switch and
 * optional discounts, shipping, and tax.
 *
 * Responses from the API are returned as decoded arrays; on failure the
 * method returns false and the error message is available via
 * getErrorMessage().
 */
class PaypalRestAPI {
    
    /** PayPal REST application client ID. */
    protected $clientId = '';
    /** PayPal REST application client secret. */
    protected $clientSecret = '';
    /** Whether to use the sandbox endpoint. */
    protected $sandboxMode = false;
    /** Last error message, if any. */
    protected $errorMessage = '';
    
    /** Items to charge (name, price, quantity, currency). */
    protected $checkoutItems = [];
    /** ISO currency code (default HKD). */
    protected $currency = 'HKD';
    /** Discount amount (subtracted from the total). */
    protected $discount = 0;
    /** Description shown for the discount line. */
    protected $discountDescription = 'Discount Amount';
    /** Shipping fee added to the total. */
    protected $shippingFee = 0;
    /** Tax amount added to the total. */
    protected $tax = 0;
    
    /** URL PayPal redirects to after approval. */
    protected $returnUrl = '';
    /** URL PayPal redirects to when the user cancels. */
    protected $cancelUrl = '';
    /** API base endpoint (production by default). */
    protected $endPoint = 'https://api-m.paypal.com/v1';

    /** Captured sale transaction ID from the most recent complete/details call. */
    protected $transactionID = '';

    /**
     * Constructor.
     *
     * Selects the sandbox endpoint when $sandboxMode is truthy.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param bool   $sandboxMode
     */
    public function __construct($clientId, $clientSecret, $sandboxMode = false) {    
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->sandboxMode = $sandboxMode;
        if(!empty($this->sandboxMode)) {
            $this->endPoint = 'https://api-m.sandbox.paypal.com/v1';
        }
    }

    /**
     * Obtain an OAuth access token via the client-credentials grant.
     *
     * @return array|false Token payload (includes access_token), or false on error
     */
    public function doAuth() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endPoint.'/oauth2/token');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId.':'.$this->clientSecret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            // Transport-level failure
            $this->errorMessage = curl_error($ch);
            return false;
        }
        else {
            $response = json_decode($response, true);
            if(!empty($response['error'])) {
                // API-level failure
                $this->errorMessage = (!empty($response['error_description']))?$response['error_description']:'';
                return false;
            }
        }
        curl_close($ch);
        
        return (!empty($response))?$response:false;
    }
    
    /**
     * Append a single item to the checkout.
     *
     * Price is coerced to a non-negative, 2-decimal value; quantity to a
     * non-negative integer.
     *
     * @param array $data name, price, quantity
     * @return static
     */
    public function addItem($data = []) {
        if(!empty($data) && !empty($data['name'])) {
			$price = max(0, (float)$data['price'] ?? 0);
			$qty = max(0, (int)$data['quantity'] ?? 1);
            $this->checkoutItems = array_merge($this->checkoutItems, [
				[
					'name'      =>  $data['name'],
					'price'     =>  round((double)max(0, $price), 2),
					'quantity'  =>  $qty
				]
			]);
        }
        return $this;
    }

    /**
     * Set the ISO currency code (uppercased).
     *
     * @param string $value
     * @return static
     */
    public function setCurrency($value) {
        $this->currency = strtoupper($value);
        return $this;
    }
    
    /**
     * Set the discount amount (and optionally its description).
     *
     * @param float  $value
     * @param string $description
     * @return static
     */
    public function setDiscount($value, $description = '') {
        $this->discount = round((double)max(0, $value), 2);
        if(!empty($description)) {
            $this->discountDescription = $description;
        }
        return $this;
    }
    
    /**
     * Set the shipping fee (non-negative, 2 decimals).
     *
     * @param float $value
     * @return static
     */
    public function setShippingFee($value) {
        $this->shippingFee = round((double)max(0, $value), 2);
        return $this;
    }
    
    /**
     * Set the tax amount (non-negative, 2 decimals).
     *
     * @param float $value
     * @return static
     */
    public function setTax($value) {
        $this->tax = round((double)max(0, $value), 2);
        return $this;
    }
    
    /**
     * Set the URL PayPal redirects to after approval.
     *
     * @param string $value
     * @return static
     */
    public function setReturnUrl($value) {
        $this->returnUrl = (string)$value;
        return $this;
    }
    
    /**
     * Set the URL PayPal redirects to when the user cancels.
     *
     * @param string $value
     * @return static
     */
    public function setCancelUrl($value) {
        $this->cancelUrl = (string)$value;
        return $this;
    }

    /**
     * Create a PayPal payment and return the approval URL.
     *
     * When $items is non-empty it replaces the current item list. Totals are
     * computed as subtotal + shipping + tax - discount. On success returns
     * ['payID', 'payUrl', 'token'] for redirecting the buyer to PayPal.
     *
     * @param array $items Optional replacement item list
     * @return array|false
     */
    public function doCheckout($items = []) {
        if(!empty($items)) {
            $this->checkoutItems = $items;
        }
        
        if(!empty($this->checkoutItems) && $authInfo = $this->doAuth()) {
            // Compute the subtotal and stamp each item with the currency
            $subtotal = 0;
            foreach ($this->checkoutItems as $key => $item) {
                $this->checkoutItems[$key]['currency'] = $this->currency;
                $subtotal += round(($item['price']*$item['quantity']), 2);
            }
            $subtotal = round($subtotal, 2);
            
            // Build the payment payload
            $payment = 
            [
                'intent'                    =>  'sale',
                'payer'                     =>  
                [
                    'payment_method'        =>  'paypal'
                ],
                'transactions'              =>  
                [
                    [
                        'amount'            => 
                        [
                            'total'         =>  round(($subtotal + ($this->discount*-1)+ $this->shippingFee + $this->tax), 2),
                            'currency'      =>  $this->currency,
                            'details'       => 
                            [
                                'subtotal'  =>  $subtotal,
                                'discount'  =>  $this->discount,
                                'shipping'  =>  $this->shippingFee,
                                'tax'       =>  $this->tax,
                            ]
                        ],
                        'item_list'         =>      
                        [
                            'items'         =>  $this->checkoutItems
                        ]
                    ]
                ],
                'redirect_urls'             =>
                [
                    'return_url'            =>  $this->returnUrl,
                    'cancel_url'            =>  $this->cancelUrl
                ]
            ];
            
            // Create the payment
            $accessToken = $authInfo['access_token'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->endPoint.'/payments/payment');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, 
            [
                'Content-Type: application/json',
                'Authorization: Bearer '.$accessToken
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment));
			curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                // Transport-level failure
                $this->errorMessage = curl_error($ch);
                return false;
            }
            else {
                $response = json_decode($response, true);
                if(!empty($response['error'])) {
                    // API-level failure
                    $this->errorMessage = (!empty($response['error_description']))?$response['error_description']:'';
                    return false;
                }
            }
            curl_close($ch);
            
            // Locate the approval URL and extract its token
            $approvalUrl = '';
            $token = '';
            if(!empty($response['links'])) {
                foreach ($response['links'] as $link) {
                    if (strtolower($link['rel']) == 'approval_url') {
                        $approvalUrl = $link['href'];
                        preg_match('/[?&]token=([^&]+)/', $approvalUrl, $match);
                        if(!empty($match)) {
                            $token = trim($match[1]);
                        }
                        break;
                    }
                }
            }
            
            if(!empty($approvalUrl)) {
                return 
                [
                    'payID'     =>  $response['id'],
                    'payUrl'    =>  $approvalUrl,
                    'token'     =>  $token
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Execute an approved payment.
     *
     * @param string $paymentId
     * @param string $PayerID
     * @return array|false The full API response, or false on error
     */
    public function doComplete($paymentId = '', $PayerID = '') {
        if (!empty($paymentId) && !empty($PayerID) && $authInfo = $this->doAuth()) {
            // Execute the payment
            $accessToken = $authInfo['access_token'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->endPoint.'/payments/payment/'.$paymentId.'/execute');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, 
            [
                'Content-Type: application/json',
                'Authorization: Bearer '.$accessToken
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['payer_id' => $PayerID]));
            
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                // Transport-level failure
                $this->errorMessage = curl_error($ch);
                return false;
            }
            else {
                $response = json_decode($response, true);
                if(!empty($response['error'])) {
                    // API-level failure
                    $this->errorMessage = (!empty($response['error_description']))?$response['error_description']:'';
                    return false;
                }
            }
            curl_close($ch);
            
            // Capture the sale transaction ID when the payment is approved
            $this->transactionID = '';
            $this->errorMessage = '';
            if(!empty($response) && !empty($response['state']) && strtolower($response['state']) == 'approved'){
                $this->transactionID = $response['transactions'][0]['related_resources'][0]['sale']['id'];
            }
            else if(!empty($response) && !empty($response['message'])) {
                $this->errorMessage = $response['message'];
            }
            
            return $response;
        }
        
        return false;
    }

    /**
     * Fetch payment/transaction details by payment ID.
     *
     * @param string $paymentId
     * @return array|false The full API response, or false on error
     */
    public function transactionDetails($paymentId = '') {
        if(!empty($paymentId) && $authInfo = $this->doAuth()) {
            // Query the payment
            $accessToken = $authInfo['access_token'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->endPoint.'/payments/payment/'.$paymentId);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, 
            [
                'Content-Type: application/json',
                'Authorization: Bearer '.$accessToken
            ]);
            
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                // Transport-level failure
                $this->errorMessage = curl_error($ch);
                return false;
            }
            else {
                $response = json_decode($response, true);
                if(!empty($response['error'])) {
                    // API-level failure
                    $this->errorMessage = (!empty($response['error_description']))?$response['error_description']:'';
                    return false;
                }
            }
            curl_close($ch);
            
            // Capture the sale transaction ID when the payment is approved
            $this->transactionID = '';
            $this->errorMessage = '';
            if(!empty($response) && !empty($response['state']) && strtolower($response['state']) == 'approved'){
                $this->transactionID = $response['transactions'][0]['related_resources'][0]['sale']['id'];
            }
            else if(!empty($response) && !empty($response['message'])) {
                $this->errorMessage = $response['message'];
            }
            
            return $response;
        }
        
        return false;
    }
    
    /**
     * Return the sale transaction ID for a payment.
     *
     * When $paymentId is supplied, the details are fetched first; otherwise
     * the ID from the most recent complete/details call is returned.
     *
     * @param string $paymentId
     * @return string
     */
    public function transactionID($paymentId = '') {
        if(!empty($paymentId)) {
            $this->transactionDetails($paymentId);
        }
        return $this->transactionID;
    }

    /**
     * Get the last error message (empty when no error occurred).
     *
     * @return string
     */
    public function getErrorMessage() {
        return $this->errorMessage;
    }
}