/******/ (() => { // webpackBootstrap
var __webpack_exports__ = {};
/*!*******************************!*\
  !*** ./resources/js/opayo.js ***!
  \*******************************/
window.opayo = function (_ref) {
  var processing = _ref.processing,
      identifier = _ref.identifier,
      merchantKey = _ref.merchantKey,
      name = _ref.name,
      $wire = _ref.$wire;
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
    init: function init() {
      window.addEventListener('opayo_threed_secure_response', function (e) {
        $wire.call('processThreed', {
          mdx: e.detail.mdx,
          md: e.detail.md,
          pares: e.detail.PaRes,
          cres: e.detail.cres
        });
      });
    },
    handleSubmit: function handleSubmit() {
      var _this = this;

      this.errors = [];
      this.processing = true;
      var date = new Date();
      var tzOffset = date.getTimezoneOffset();
      var screenSize = 'Large';

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
        browserTZ: tzOffset
      });
      sagepayOwnForm({
        merchantSessionKey: this.merchantKey
      }).tokeniseCardDetails({
        onTokenised: function onTokenised(result) {
          if (!result.success) {
            _this.errors = result.errors;
            $wire.set('processing', false); // {{-- return --}}
          } else {
            $wire.set('identifier', result.cardIdentifier);
            $wire.set('sessionKey', _this.merchantKey);
            $wire.call('process');
          }
        },
        cardDetails: {
          cardholderName: this.name,
          cardNumber: this.card,
          expiryDate: this.expiry,
          securityCode: this.cvv
        }
      });
    }
  };
};
/******/ })()
;