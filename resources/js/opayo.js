window.opayo = ({ processing, identifier, merchantKey, name }) => {
  return {
    // We use AlpineJs modelling here as we do not want the card details to go up to Livewire.
    name: name,
    card: null,
    expiry: null,
    cvv: null,
    processing: processing,
    // This is the tokenised card we need to send up to Livewire
    identifier: identifier,
    merchantKey: merchantKey,
    errors: [],
    init() {
      window.addEventListener('opayo_threed_secure_response', e => {
          $wire.call('processThreed', {
            mdx: e.detail.mdx,
            md: e.detail.md,
            pares: e.detail.PaRes,
            cres: e.detail.cres
          })
      });
    },
    handleSubmit () {
      this.errors = []
      this.processing = true

      const date = new Date();
      const tzOffset = date.getTimezoneOffset();

      let screenSize = 'Large';

      if (window.outerWidth < 400) {
          screenSize = 'Small';
      }

      if (window.outerWidth < 800) {
          screenSize = 'Medium';
      }

      $wire.set('browser', {
        browserLanguage: navigator.language,
        challengeWindowSize: screenSize,
        browserUserAgent: navigator.userAgent,
        browserJavaEnabled: navigator.javaEnabled(),
        browserColorDepth: window.screen.colorDepth,
        browserScreenHeight: window.outerHeight,
        browserScreenWidth: window.outerWidth,
        browserTZ: tzOffset,
      })

      sagepayOwnForm({
        merchantSessionKey: this.merchantKey,
      }).tokeniseCardDetails({
        onTokenised: (result) => {
            if (!result.success) {
              this.errors = result.errors
              $wire.set('processing', false)
              // {{-- return --}}
            } else {
              $wire.set('identifier', result.cardIdentifier)
              $wire.set('sessionKey', this.merchantKey)
              $wire.call('process')
            }
        },
        cardDetails: {
          cardholderName: this.name,
          cardNumber: this.card,
          expiryDate: this.expiry,
          securityCode: this.cvv,
        }
      })
    }
  }
}
