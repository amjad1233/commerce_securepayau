<?php

namespace Drupal\commerce_securepayau\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Drupal\commerce_securepayau\Controller\SecurePayXML;

/**
 * Provides the SecurePay payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "secure_pay",
 *   label = "SecurePay",
 *   display_label = "Secure Pay",
 *   forms = {
 *     "add-payment-method" =
 *   "Drupal\commerce_securepayau\PluginForm\SecurePay\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"secure_pay_cc"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard",
 *   "visa",
 *   },
 * )
 */
class SecurePay extends OnsitePaymentGatewayBase implements SecurePayInterface {

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * The default currency.
   */
  const CURRENCY = 'aud';

  /**
   * The default transaction type.
   */
  const TRANSACTION_TYPE = 'payment';

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'merchant_id' => '',
      'password' => '',
      'currency' => 'AUD',
      'gateway_urls' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  ) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => t('API Merchant ID and password'),
    ];
    $form['credentials']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => t('Merchant Id'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];
    $form['credentials']['password'] = [
      '#type' => 'textfield',
      '#title' => t('Password'),
      '#default_value' => $this->configuration['password'],
      '#required' => TRUE,
    ];

    $form['settings'] = [
      '#type' => 'fieldset',
      '#title' => t('Mode/Settings'),
    ];
    $form['settings']['currency'] = [
      '#type' => 'radios',
      '#title' => 'Currency',
      '#requried' => TRUE,
      '#options' => [
        'AUD' => 'AUD',
      ],
      '#default_value' => $this->configuration['currency'],
    ];

    $form['settings']['gateway_urls'] = [
      '#type' => 'fieldset',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#title' => t('Gateway URL'),
      '#description' => t("You shouldn't need to update these, they should just work. The are made available incase securepay decides to change their domain and you need to be able to switch this<br/><br/>Securepay have different gateway URLs for different message types. The last part of the URL indicates the type of message so you do not need to enter that here as that will be added by this module at the time of payment request.<br/><br/>Ie:<br/>For the standard payment gateway it is accessed at:<br/>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;https://www.securepay.com.au/xmlapi/payment<br/><br/>The 'payment' part is added by this module so you should just enter:<br/>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;https://www.securepay.com.au/xmlapi/"),
    ];

    $accounts = [
      'live' => [
        'label' => t('Live transactions in a live account'),
        'url' => 'https://www.securepay.com.au/xmlapi/',
      ],
      'test' => [
        'label' => t('Developer test account transactions'),
        'url' => 'https://test.securepay.com.au/xmlapi/',
      ],
    ];

    foreach ($accounts as $type => $account) {
      $form['settings']['gateway_urls'][$type] = [
        '#type' => 'textfield',
        '#title' => $account['label'],
        '#default_value' => $account['url'],
      ];
    }

    // \Drupal::moduleHandler()->alter('commerce_securepayauau_xmlapi_settings_form', $form, $settings);.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = &$form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['credentials']['merchant_id'];
      $this->configuration['password'] = $values['credentials']['password'];
      $this->configuration['currency'] = $values['settings']['currency'];
      $this->configuration['gateway_urls'] = $values['settings']['gateway_urls'];
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createPaymentMethod(
    PaymentMethodInterface $payment_method,
    array $payment_details
  ) {
    $required_keys = [
      // The expected keys are payment gateway specific and usually match
      // the PaymentMethodAddForm form elements. They are expected to be valid.
      'type', 'number', 'expiration', 'security_code',
    ];

    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    /**@todo Make it more secure by calling creating remote_id */
    static::setPaymentDetails($payment_details);

    // Setting static payment info.
    $payment_method->setReusable(FALSE);
    $payment_method->save();
  }

  /**
   * Set Creditcard details to session.
   *
   * @param array $payment_details
   *
   * @return void
   */
  private static function setPaymentDetails($payment_details) {
    $request = \Drupal::request();
    $session = $request->getSession();
    $session->set('payment_details', $payment_details);
  }

  /**
   * Get Credit Card details from Sesssion.
   *
   * @return void
   */
  private static function getPaymentDetails() {
    $request = \Drupal::request();
    $session = $request->getSession();
    return $session->get('payment_details');
  }

  /**
   * Unset variable with Credit Card Details.
   *
   * @return void
   */
  private static function destroyPaymentDetails() {
    $request = \Drupal::request();
    $session = $request->getSession();
    $session->remove('payment_details');
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    // Perform the create payment request here, throw an exception if it fails.
    $securepay = new SecurePayXML($this->configuration, $payment, static::getPaymentDetails());
    $response = $securepay->sendXMLRequest();

    if (!$response) {
      \Drupal::logger('commerce_securepayau')->error(serialize($response));
      throw new Exception("We could not connect to SecurePay.");
    }

    if ($response['statusCode'] != "000") {
      \Drupal::logger('commerce_securepayau')->error(serialize($response));
      drupal_set_message(t('Securepay Authentication Failed'), 'error', FALSE);
      throw new PaymentGatewayException();
    }

    if ($response['approved'] == "No") {
      throw new HardDeclineException('The payment was declined');
    }

    // Remember to take into account $capture when performing the request.
    $amount = $payment->getAmount();
    $next_state = $capture ? 'completed' : 'authorization';
    $remote_id = $response['txnID'];
    $payment->setState($next_state);
    $payment->setRemoteId($remote_id);
    static::destroyPaymentDetails();
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Delete the local entity.
    $payment_method->delete();
  }

}
