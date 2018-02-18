<?php

namespace Drupal\commerce_securepayau\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce_payment\CreditCard as CreditCardHelper;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;

/**
 * Provides the SecurePay payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "secure_pay_cc",
 *   label = @Translation("SecurePay"),
 *   create_label = @Translation("Credit Card")
 * )
 */
class SecurePayPaymentMethodType extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    $request = \Drupal::request();
    $session = $request->getSession();
    $payment_details = $session->get('payment_details');
    $card_type = CreditCardHelper::getType($payment_details['type']);
    $args = [
      '@card_type' => $card_type->getLabel(),
      '@card_number' => substr($payment_details['number'], -4),
    ];
    return $this->t('@card_type ending in @card_number', $args);
  }

}
