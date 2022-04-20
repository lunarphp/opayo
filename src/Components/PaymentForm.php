<?php

namespace GetCandy\Opayo\Components;

use GetCandy\Facades\Payments;
use GetCandy\Models\Cart;
use GetCandy\Opayo\Facades\Opayo;
use GetCandy\Stripe\Facades\StripeFacade;
use Livewire\Component;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class PaymentForm extends Component
{
    /**
     * The instance of the order.
     *
     * @var Order
     */
    public Cart $cart;

    /**
     * The return URL on a successful transaction
     *
     * @var string
     */
    public $returnUrl;

    /**
     * The policy for handling payments.
     *
     * @var string
     */
    public $policy;

    /**
     * The card identifier token.
     *
     * @var string
     */
    public $identifier;

    /**
     * The session key
     *
     * @var [type]
     */
    public $sessionKey;

    /**
     * Information regarding the browser.
     *
     * @var array
     */
    public array $browser = [];

    /**
     * The ThreeDSecure information
     *
     * @var array
     */
    public $threeDSecure = [
        'acsUrl' => null,
        'acsTransId' => null,
        'dsTransId' => null,
        'cReq' => null,
        'transactionId' => null,
    ];

    /**
     * Whether we are processing the payment.
     *
     * @var boolean
     */
    public bool $processing = false;

    /**
     * Whether to show the ThreeDSecure challenge.
     *
     * @var boolean
     */
    public bool $showChallenge = false;

    /**
     * The payment processing error.
     *
     * @var string|null
     */
    public ?string $error = null;

    public $merchantKey = null;

    /**
     * {@inheritDoc}
     */
    protected $listeners = [
        'cardDetailsSubmitted',
        'opayoThreedSecureResponse'
    ];

    /**
     * {@inheritDoc}
     */
    public function mount()
    {
        $this->policy = config('opayo.policy', 'capture');
        $this->refreshMerchantKey();
    }

    /**
     * {@inheritDoc}
     */
    public function rules()
    {
        return [
            'identifier' => 'string|required',
        ];
    }

    /**
     * Return the client secret for Payment Intent
     *
     * @return void
     */
    public function refreshMerchantKey()
    {
        $this->merchantKey = Opayo::getMerchantKey();
    }

    /**
     * Process the transaction
     *
     * @return void
     */
    public function process()
    {
        $result = Payments::driver('opayo')->cart($this->cart)->withData(array_merge([
            'card_identifier' => $this->identifier,
            'merchant_key' => $this->sessionKey,
            'ip' => app()->request->ip(),
            'accept' => app()->request->header('Accept'),
        ], $this->browser))->authorize();

        if ($result->success) {
            if ($result->status == '3DAuth') {
                $this->threeDSecure['acsUrl'] = $result->acsUrl;
                $this->threeDSecure['acsTransId'] = $result->acsTransId;
                $this->threeDSecure['dsTransId'] = $result->dsTransId;
                $this->threeDSecure['cReq'] = $result->cReq;
                $this->threeDSecure['transactionId'] = $result->transactionId;
                $this->showChallenge = true;
                return;
            }
        }

        dd($result);
        // dd($result);
    }

    /**
     * Process the ThreeDSecure response
     *
     * @param array $params
     * @return void
     */
    public function processThreed($params)
    {
        $result = Payments::driver('opayo')->cart($this->cart)->withData([
            'cres' => $params['cres'] ?? null,
            'pares' => $params['pares'] ?? null,
            'transaction_id' => $this->threeDSecure['transactionId']
        ])->threedsecure();

        if (!$result->success) {
            if ($result->status = 'threed_secure_fail') {
                $this->error = 'You must complete the extra authentication';
                $this->processing = false;
                $this->showChallenge = false;
                $this->threedSecure = [
                    'acsUrl' => null,
                    'acsTransId' => null,
                    'dsTransId' => null,
                    'cReq' => null,
                    'transactionId' => null,
                ];
                $this->refreshMerchantKey();
                return;
            }
        }

        dd($result);

        // \GetCandy\Facades\CartSession::forget();
    }

    public function opayoThreedSecureResponse()
    {
        dd('Hi!');
    }

    /**
     * Return the carts billing address.
     *
     * @return void
     */
    public function getBillingProperty()
    {
        return $this->cart->billingAddress;
    }

    /**
     * {@inheritDoc}
     */
    public function render()
    {
        return view("getcandy::opayo.components.payment-form");
    }
}
