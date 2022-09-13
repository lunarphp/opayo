<?php

namespace Lunar\Opayo;

use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Models\Transaction;
use Lunar\Opayo\Facades\Opayo;
use Lunar\Opayo\Responses\PaymentAuthorize;
use Lunar\PaymentTypes\AbstractPayment;
use Illuminate\Support\Str;

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
        $this->policy = config('lunar.opayo.policy', 'automatic');
    }

    /**
     * Authorize the payment for processing.
     *
     * @return \Lunar\Base\DataTransferObjects\PaymentAuthorize
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
                status: Opayo::ALREADY_PLACED,
            );
        }

        $transactionType = 'Payment';

        if ($this->policy != 'automatic') {
            $transactionType = 'Deferred';
        }

        $payload = $this->getAuthPayload($transactionType);

        $response = Opayo::api()->post('transactions', $payload);

        if (!$response->successful()) {
            return new PaymentAuthorize(
                success: false,
                message: 'An unknown error occured'
            );
        }

        $response = $response->object();

        if ($response->status == '3DAuth') {
            return new PaymentAuthorize(
                success: true,
                status: Opayo::THREE_D_AUTH,
                acsUrl: $response->acsUrl,
                acsTransId: $response->acsTransId ?? null,
                dsTransId: $response->dsTransId ?? null,
                cReq: $response->cReq ?? null,
                paReq: $response->paReq ?? null,
                transactionId: $response->transactionId,
            );
        }

        $successful = $response->status == 'Ok';

        $this->storeTransaction(
            transaction: $response,
            success: $successful
        );

        if ($successful) {
            $this->order->update([
                'placed_at' => now(),
            ]);
        }

        return new PaymentAuthorize(
            success: $successful,
            status: $successful ? Opayo::AUTH_SUCCESSFUL : Opayo::AUTH_FAILED
        );
    }

    /**
     * Capture a payment for a transaction.
     *
     * @param \Lunar\Models\Transaction $transaction
     * @param integer $amount
     * @return \Lunar\Base\DataTransferObjects\PaymentCapture
     */
    public function capture(Transaction $transaction, $amount = 0): PaymentCapture
    {
        $response = Opayo::api()->post("transactions/{$transaction->reference}/instructions", [
            'instructionType' => 'release',
            'amount' => $amount,
        ]);

        $data = $response->object();

        if (!$response->successful() || isset($data->code)) {
            return new PaymentCapture(
                success: false,
                message: $data->description ?? 'An unknown error occured'
            );
        }

        $transaction->order->transactions()->create([
            'parent_transaction_id' => $transaction->id,
            'success' => true,
            'type' => 'capture',
            'driver' => 'opayo',
            'amount' => $amount,
            'reference' => $transaction->reference,
            'status' => $transaction->status,
            'notes' => null,
            'card_type' => $transaction->card_type,
            'last_four' => $transaction->last_four,
            'captured_at' => now()->parse($data->date),
        ]);

        return new PaymentCapture(success: true);
    }

    /**
     * Refund a captured transaction
     *
     * @param \Lunar\Models\Transaction $transaction
     * @param integer $amount
     * @param string|null $notes
     * @return \Lunar\Base\DataTransferObjects\PaymentRefund
     */
    public function refund(Transaction $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        $response = Opayo::api()->post("transactions", [
            'transactionType' => 'Refund',
            'vendorTxCode' => Str::random(40),
            'referenceTransactionId' => $transaction->reference,
            'description' => $notes ?: 'Refund',
            'amount' => $amount,
        ]);

        $data = $response->object();

        if (!$response->successful() || isset($data->code)) {
            return new PaymentRefund(
                success: false,
                message: $data->description ?? 'An unknown error occured'
            );
        }

        $transaction->order->transactions()->create([
            'parent_transaction_id' => $transaction->id,
            'success' => true,
            'type' => 'refund',
            'driver' => 'opayo',
            'amount' => $amount,
            'reference' => $data->transactionId,
            'status' => $transaction->status,
            'notes' => $notes,
            'card_type' => $transaction->card_type,
            'last_four' => $transaction->last_four,
            'captured_at' => now(),
        ]);

        return new PaymentRefund(
            success: true
        );
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

        if (($data->statusCode ?? null) == '4026') {
            return new PaymentAuthorize(
                success: false,
                status: Opayo::THREED_SECURE_FAILED
            );
        }

        if (!empty($data->status) && $data->status == 'NotAuthenticated') {
            return new PaymentAuthorize(
                success: false,
                status: Opayo::THREED_SECURE_FAILED
            );
        }

        $transaction = Opayo::getTransaction($this->data['transaction_id']);

        $successful = $transaction->status == 'Ok';

        $this->storeTransaction(
            transaction: $transaction,
            success: $successful
        );

        if ($successful) {
            $this->order->update([
                'placed_at' => now(),
            ]);
        }

        return new PaymentAuthorize(
            success: $successful,
            status: $successful ? Opayo::AUTH_SUCCESSFUL : Opayo::AUTH_FAILED
        );
    }

    /**
     * Stores a transaction against the order.
     *
     * @param stdclass $transaction
     * @param boolean $success
     * @return void
     */
    protected function storeTransaction($transaction, $success = false)
    {
        $data = [
            'success' => $success,
            'type' => $transaction->transactionType == 'Payment' ? 'capture' : 'intent',
            'driver' => 'opayo',
            'amount' => $transaction->amount->totalAmount,
            'reference' => $transaction->transactionId,
            'status' => $transaction->status,
            'notes' => $transaction->statusDetail,
            'card_type' => $transaction->paymentMethod->card->cardType,
            'last_four' => $transaction->paymentMethod->card->lastFourDigits,
            'captured_at' => $success ? ($transaction->transactionType == 'Payment' ? now() : null) : null,
            'meta' => [
                'threedSecure' => [
                    'status' => $transaction->avsCvcCheck->status,
                    'address' => $transaction->avsCvcCheck->address,
                    'postalCode' => $transaction->avsCvcCheck->postalCode,
                    'securityCode' => $transaction->avsCvcCheck->securityCode,
                ],
            ],
        ];
        $this->order->transactions()->create($data);
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
            'apply3DSecure' => 'UseMSPSetting',
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
