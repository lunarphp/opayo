<?php

namespace GetCandy\Opayo\Responses;

use GetCandy\Base\DataTransferObjects\PaymentAuthorize as GcPaymentAuthorize;

class PaymentAuthorize extends GcPaymentAuthorize
{
    public function __construct(
        public bool $success = false,
        public ?string $status = null,
        public ?string $acsUrl = null,
        public ?string $acsTransId = null,
        public ?string $dsTransId = null,
        public ?string $cReq = null,
        public ?string $transactionId = null,
        public ?string $message = null
    ) {
        //
    }
}
