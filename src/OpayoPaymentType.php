<?php

namespace GetCandy\Opayo;

use GetCandy\Base\DataTransferObjects\PaymentCapture;
use GetCandy\Base\DataTransferObjects\PaymentRefund;
use GetCandy\Models\Transaction;
use GetCandy\Opayo\Facades\Opayo;
use GetCandy\Opayo\Responses\PaymentAuthorize;
use GetCandy\PaymentTypes\AbstractPayment;
use GetCandy\Stripe\Facades\StripeFacade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Exception\InvalidRequestException;

class OpayoPaymentType extends AbstractPayment
{
    /**
     * The policy when capturing payments.
     *
     * @var string
     */
    protected $policy;

    /**
     * Initialise the payment type.
     */
    public function __construct()
    {
        $this->policy = config('getcandy.opayo.policy', 'automatic');
    }

    /**
     * Authorize the payment for processing.
     *
     * @return \GetCandy\Base\DataTransferObjects\PaymentAuthorize
     */
    public function authorize(): PaymentAuthorize
    {
        if (!$this->order) {
            if (!$this->order = $this->cart->order) {
                $this->order = $this->cart->getManager()->createOrder();
            }
        }

        if ($this->order->placed_at) {
            // Somethings gone wrong!
            return new PaymentAuthorize(
                success: false,
                message: 'This order has already been placed',
            );
        }

        $transactionType = 'Payment';

        if ($this->policy != 'automatic') {
            $transactionType = 'Deferred';
        }


        $payload = $this->getAuthPayload($transactionType);

        $response = Opayo::api()->post('transactions', $payload);

        if (!$response->successful()) {
            dd($response);
        }

        $response = $response->object();

        if ($response->status == '3DAuth') {
            return new PaymentAuthorize(
                success: true,
                status: $response->status,
                acsUrl: $response->acsUrl,
                acsTransId: $response->acsTransId,
                dsTransId: $response->dsTransId,
                cReq: $response->cReq,
                transactionId: $response->transactionId,
            );
        }

        dd('Hi!');

        return $this->releaseSuccess();
    }

    /**
     * Return a unique vendor tx code for the transaction.
     *
     * @return string
     */
    protected function getVendorTxCode()
    {
        return base64_encode($this->order->id.'-'.microtime(true));
    }

    /**
     * Capture a payment for a transaction.
     *
     * @param \GetCandy\Models\Transaction $transaction
     * @param integer $amount
     * @return \GetCandy\Base\DataTransferObjects\PaymentCapture
     */
    public function capture(Transaction $transaction, $amount = 0): PaymentCapture
    {
        $payload = [];

        if ($amount > 0) {
            $payload['amount_to_capture'] = $amount;
        }

        try {
            $response = $this->stripe->paymentIntents->capture(
                $transaction->reference,
                $payload
            );
        } catch (InvalidRequestException $e) {
            return new PaymentCapture(
                success: false,
                message: $e->getMessage()
            );
        }

        $charges = $response->charges->data;

        $transactions = [];

        foreach ($charges as $charge) {
            $card = $charge->payment_method_details->card;
            $transactions[] = [
                'parent_transaction_id' => $transaction->id,
                'success' => $charge->status != 'failed',
                'type' => 'capture',
                'driver' => 'stripe',
                'amount' => $charge->amount_captured,
                'reference' => $response->id,
                'status' => $charge->status,
                'notes' => $charge->failure_message,
                'card_type' => $card->brand,
                'last_four' => $card->last4,
                'captured_at' => $charge->amount_captured ? now() : null,
            ];
        }

        $transaction->order->transactions()->createMany($transactions);

        return new PaymentCapture(success: true);
    }

    /**
     * Refund a captured transaction
     *
     * @param \GetCandy\Models\Transaction $transaction
     * @param integer $amount
     * @param string|null $notes
     * @return \GetCandy\Base\DataTransferObjects\PaymentRefund
     */
    public function refund(Transaction $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        try {
            $refund = $this->stripe->refunds->create(
                ['payment_intent' => $transaction->reference, 'amount' => $amount]
            );
        } catch (InvalidRequestException $e) {
            return new PaymentRefund(
                success: false,
                message: $e->getMessage()
            );
        }

        $transaction->order->transactions()->create([
            'success' => $refund->status != 'failed',
            'type' => 'refund',
            'driver' => 'stripe',
            'amount' => $refund->amount,
            'reference' => $refund->payment_intent,
            'status' => $refund->status,
            'notes' => $notes,
            'card_type' => $transaction->card_type,
            'last_four' => $transaction->last_four,
        ]);

        return new PaymentRefund(
            success: true
        );
    }

    /**
     * Return a successfully released payment.
     *
     * @return void
     */
    private function releaseSuccess()
    {
        DB::transaction(function () {

            // Get our first successful charge.
            $charges = $this->paymentIntent->charges->data;

            $successCharge = collect($charges)->first(function ($charge) {
                return !$charge->refunded && ($charge->status == 'succeeded' || $charge->status == 'paid');
            });

            $this->order->update([
                'status' => $this->config['released'] ?? 'paid',
                'placed_at' => now()->parse($successCharge->created),
            ]);

            $transactions = [];

            $type = 'capture';

            if ($this->policy == 'manual') {
                $type = 'intent';
            }

            foreach ($charges as $charge) {
                $card = $charge->payment_method_details->card;
                $transactions[] = [
                    'success' => $charge->status != 'failed',
                    'type' => $charge->amount_refunded ? 'refund' : $type,
                    'driver' => 'stripe',
                    'amount' => $charge->amount,
                    'reference' => $this->paymentIntent->id,
                    'status' => $charge->status,
                    'notes' => $charge->failure_message,
                    'card_type' => $card->brand,
                    'last_four' => $card->last4,
                    'captured_at' => $charge->amount_captured ? now() : null,
                    'meta' => [
                        'address_line1_check' => $card->checks->address_line1_check,
                        'address_postal_code_check' => $card->checks->address_postal_code_check,
                        'cvc_check' => $card->checks->cvc_check,
                    ],
                ];
            }
            $this->order->transactions()->createMany($transactions);
        });

        return new PaymentAuthorize(success: true);
    }

    /**
     * Handle the Three D Secure response.
     *
     * @return void
     */
    public function threedsecure()
    {
        if (!$this->order) {
            if (!$this->order = $this->cart->order) {
                $this->order = $this->cart->getManager()->createOrder();
            }
        }

        $path = ($this->data['cres'] ?? false) ? '3d-secure-challenge' : '3d-secure';

        $payload = [];

        if ($paRes = $this->data['pares'] ?? null) {
            $payload['paRes'] = $paRes;
        }

        if ($cres = $this->data['cres'] ?? null) {
            $payload['cRes'] = $cres;
        }

        $response = Opayo::api()->post('transactions/'.$this->data['transaction_id'].'/'.$path, $payload);

        if (!$response->successful()) {
            return new PaymentAuthorize(
                success: false
            );
        }

        $data = $response->object();

        \Log::debug((array) $response);

        if ($data->statusCode == '4026') {
            return new PaymentAuthorize(
                success: false,
                status: 'threed_secure_fail'
            );
        }

        $transaction = Opayo::getTransaction($this->data['transaction_id']);

        if ($transaction->status != 'Ok') {
            return $this->createFailedTransaction($transaction);
        }

        $this->order->transactions()->create([
            'success' => $transaction->status == 'Ok',
            'type' => $transaction->transactionType == 'Payment' ? 'charge' : 'intent',
            'driver' => 'opayo',
            'amount' => $transaction->amount->totalAmount,
            'reference' => $transaction->transactionId,
            'status' => $transaction->status,
            'notes' => $transaction->statusDetail,
            'card_type' => $transaction->paymentMethod->card->cardType,
            'last_four' => $transaction->paymentMethod->card->lastFourDigits,
            'captured_at' => $transaction->transactionType == 'Payment' ? now() : null,
            'meta' => [
                'threedSecure' => [
                    'status' => $transaction->avsCvcCheck->status,
                    'address' => $transaction->avsCvcCheck->address,
                    'postalCode' => $transaction->avsCvcCheck->postalCode,
                    'securityCode' => $transaction->avsCvcCheck->securityCode,
                ],
            ],
        ]);

        $this->order->update([
            'placed_at' => now(),
        ]);

        return new PaymentAuthorize(success: true);
    }

    /**
     * Get the payload for authorizing a payment
     *
     * @param string $type
     * @return array
     */
    protected function getAuthPayload($type = 'Payment')
    {
        $payload = [
            'transactionType' => $type,
            'paymentMethod' => [
                'card' => [
                    'merchantSessionKey' => $this->data['merchant_key'],
                    'cardIdentifier' => $this->data['card_identifier'],
                ],
            ],
            'vendorTxCode' => Str::random(40),
            'amount' => $this->order->total->value,
            'currency' => $this->order->currency_code,
            'description' => 'Webstore Transaction',
            'apply3DSecure' => 'Force',
            'customerFirstName' => $this->order->billingAddress->first_name,
            'customerLastName' => $this->order->billingAddress->last_name,
            'billingAddress' => [
                'address1' => $this->order->billingAddress->line_one,
                'city' => $this->order->billingAddress->city,
                'postalCode' => $this->order->billingAddress->postcode,
                'country' => $this->order->billingAddress->country->iso2,
            ],
            'strongCustomerAuthentication' => [
                'customerMobilePhone' => $this->order->billingAddress->phone,
                'transType' => 'GoodsAndServicePurchase',
                'browserLanguage' => $this->data['browserLanguage'] ?? null,
                'challengeWindowSize' => $this->data['challengeWindowSize'] ?? null,
                'browserIP' => $this->data['ip'] ?? null,
                'notificationURL' => route('opayo.threed.response'),
                'browserAcceptHeader' => $this->data['accept'] ?? null,
                'browserJavascriptEnabled' => true,
                'browserUserAgent' => $this->data['browserUserAgent'] ?? null,
                'browserJavaEnabled' => (bool) ($this->data['browserJavaEnabled'] ?? null),
                'browserColorDepth' => $this->data['browserColorDepth'] ?? null,
                'browserScreenHeight' => $this->data['browserScreenHeight'] ?? null,
                'browserScreenWidth' => $this->data['browserScreenWidth'] ?? null,
                'browserTZ' => $this->data['browserTZ'] ?? null,
            ],
            'entryMethod' => 'Ecommerce',
        ];

        if (! empty($this->data['save'])) {
            $payload['credentialType'] = [
                'cofUsage' => 'First',
                'initiatedType' => 'CIT',
                'mitType' => 'Unscheduled',
            ];
            $payload['paymentMethod']['card']['save'] = true;
        }
        // dd($payload);

        if (! empty($this->data['reusable'])) {
            // $reusedCard = ReusablePayment::whereToken($this->token)->first();
            // $payload['credentialType'] = [
            //     'cofUsage' => 'Subsequent',
            //     'initiatedType' => 'CIT',
            //     'mitType' => 'Unscheduled',
            // ];
            // if ($reusedCard->auth_code) {
            //     $payload['strongCustomerAuthentication']['threeDSRequestorPriorAuthenticationInfo']['threeDSReqPriorRef'] = $reusedCard->auth_code;
            // }
            // $payload['paymentMethod']['card']['reusable'] = true;
        }

        return $payload;
    }
}
