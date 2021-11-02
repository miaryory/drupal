<?php

namespace Drupal\logisnap\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Form\FormStateInterface;
use Drupal\state_machine\WorkflowManagerInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\logisnap\Entity\Token;

/**
 * Provides the LogiSnapCarriers shipping plugin.
 *
 * @CommerceShippingMethod(
 *   id = "logisnap_carriers",
 *   label = @Translation("LogiSnap"),
 * )
 */
class LogiSnapCarriers extends ShippingMethodBase {

  /**
   * Constructs a new FlatRate object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $package_type_manager
   *   The package type manager.
   * @param \Drupal\state_machine\WorkflowManagerInterface $workflow_manager
   *   The workflow manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager, WorkflowManagerInterface $workflow_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);

    //create the record in table: commerce_shipment - need ID and label
    $this->services['logisnap'] = new ShippingService('logisnap', $this->configuration['rate_label']);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'rate_label' => '',
        'rate_description' => '',
        'rate_amount' => NULL,
        'services' => ['logisnap'],
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $amount = $this->configuration['rate_amount'];
    // A bug in the plugin_select form element causes $amount to be incomplete.
    if (isset($amount) && !isset($amount['number'], $amount['currency_code'])) {
      $amount = NULL;
    }

    $myship = $this->get_shipment_types();

    $options = [];

    if (sizeof($myship != 0)) {
      foreach ($myship as $shop) {
        $options[$shop->UID] = $shop->ClientName . ' ' . $shop->Name;
      }
    }
    
    $form['carriers'] = [
      '#type' => 'radios',
      '#title' => t('Select a service:'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['rate_label'] = [
      '#type' => 'textfield',
      '#title' => t('Rate label'),
      '#description' => t('Shown to customers when selecting the rate.'),
      '#required' => TRUE,
    ];
    $form['rate_description'] = [
      '#type' => 'textfield',
      '#title' => t('Rate description'),
      '#description' => t('Provides additional details about the rate to the customer.'),
      '#default_value' => $this->configuration['rate_description'],
    ];
    $form['rate_amount'] = [
      '#type' => 'commerce_price',
      '#title' => t('Rate amount'),
      '#default_value' => $amount,
      '#required' => TRUE,
      '#description' => t('Charged for each quantity of each shipment item.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['carrier_uid'] = $values['carriers'];
      $this->configuration['rate_label'] = $values['rate_label'];
      $this->configuration['rate_description'] = $values['rate_description'];
      $this->configuration['rate_amount'] = $values['rate_amount'];
    }

  }

  /**
   * {@inheritdoc}
   * For the display on front end 
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $ship_price = Price::fromArray($this->configuration['rate_amount']);
    $rates = [];
    $rates[] = new ShippingRate([
      'shipping_method_id' => $this->parentEntity->id(),
      'service' => $this->services['logisnap'],
      'amount' => $ship_price,
    ]);

    return $rates;
  }

  public function get_shipment_types() {
    $token = Token::get_token();

    $allCarriers = [];
    $client = \Drupal::httpClient();
    
    try
    {
      $request = $client->get('https://logiapiv1uat.azurewebsites.net/logistics/order/shipmenttypes', [
        'headers' => [
          'Content-Type' => 'application/json', 
          'Authorization' => 'basic ' . $token,
        ],
      ]);
      
      if ($request->getStatusCode() == 200) {
        $allCarriers = json_decode($request->getBody());
      }
      
      return $allCarriers;
    }
    catch (RequestException $exception)
    {
      drupal_set_message($this
      ->t('An error occured. Please try again.'), 'error');
    }

  }

}