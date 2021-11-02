<?php

namespace Drupal\logisnap\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use GuzzleHttp\Exception\RequestException;
use Drupal\logisnap\Entity\Token;

/**
 * Class CheckoutEventsSubscriber
 *
 * @package Drupal\custom_events\EventSubscriber
 */
class CheckoutEventsSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents() {
    // The format for adding a state machine event to subscribe to is:
    // {group}.{transition key}.pre_transition or {group}.{transition key}.post_transition
    // depending on when you want to react.
    $events = ['commerce_order.place.post_transition' => 'onOrderPlace'];
    return $events;
  }

  /**
   * Execute actions when a new order is placed.
   */
  public function onOrderPlace(WorkflowTransitionEvent $event) {
    // get order info
    $order = $event->getEntity();
    $orderNumber = $order->getOrderNumber();

    // get shipment info
    $shipment = $order->shipments->first()->entity;
    $shippingService = $shipment->getShippingService(); //return logisnap
    
    // send order only if the carrier service selected is from logisnap
    if ($shippingService == 'logisnap') {

      // get the token from DB
      $token = Token::get_token();
      
      if ($token != null) {
        // get selected shipping method
        $shippingConf = $shipment->getShippingMethod()->getPlugin()->getConfiguration();
        $shippingUID = $shippingConf['carrier_uid'];
        $shipmentTypes = $this->get_shipment_types($token, $shippingUID);
        
        // get sender info
        $sender = $this->get_sender_details($token);
        
        // get receiver info
        $customerEmail = $order->getEmail();
        $shippingProfile = $shipment->getShippingProfile()->get('address')->first();
        $customerFirstname = $shippingProfile->getGivenName();
        $customerLastname = $shippingProfile->getFamilyName();
        $shippingStreet = $shippingProfile->getAddressLine1();
        $shippingPostcode = $shippingProfile->getPostalCode();
        $shippingCity = $shippingProfile->getLocality();
    
        $orderData = [
            'ClientShipmentTypeUID' => $shipmentTypes->UID,
            'PickDate' => 20310801,
            'DeliveryDate' => 20310801,
            'Number' => $orderNumber,
            'Ref1' => '',
            'TypeID' => $shipmentTypes->TypeID,
            'StatusID' => $shipmentTypes->StatusID,
            'ContactInformation' => [
              'Receiver' => [
                  'StatusID' => 10,
                  'Name' => $customerFirstname . ' ' . $customerLastname,
                  'ContactType' => 100, // should stay 100
                  'TypeID' =>10,            
                  'Adr1' => $shippingStreet,
                  'PostalCode' => $shippingPostcode,
                  'City' => $shippingCity,
                  'Phone' => '',
                  'Email' => $customerEmail,
              ],
              'Sender' => [
                  'StatusID' => $sender->StatusID,
                  'Name' => $sender->Name,
                  'ContactType' => 10,
                  'TypeID' => $sender->TypeID,            
                  'Adr1' => $sender->Adr1,
                  'PostalCode' => $sender->PostalCode,
                  'City' => $sender->City,
                  'Phone' => $sender->Phone,
                  'Email' => $sender->PrimaryEmail,
              ],
            ],
        ];

        $orderItems = $order->getItems();
        $orderlineData = [];

        foreach ($orderItems as $item) {
         $orderlineData[] = [
          'ProdNumber' => $item->getPurchasedEntityId(),
          'ProdName' => $item->getTitle(),
          'ProdAmount' => intval($item->getQuantity()),
         ];
        }

        // check if the order number is already present before posting new order in LG admin
        $existingOrderUID = $this->check_order_number($token, $orderNumber);
        
        if ($existingOrderUID == null) {
          $this->post_order($token, $orderData, $orderlineData);
        }

        if ($existingOrderUID != null) {

          // update order info: order, sender, receiver, orderline
          $updatedOrderData = [
            "UID" => $existingOrderUID,
            "Number" => $orderNumber,
            "ClientShipmentTypeUID" => $shipmentTypes->UID,
            "Ref1" => "",
            "Ref2" => "",
            "PickDate" => "20310801",
            "DeliveryDate" => "20310801",
            "TypeID" => $shipmentTypes->TypeID,
            "StatusID" => $shipmentTypes->StatusID
          ];

          $updatedReceiver = [
            "TypeID" => 10,
            "StatusID" => 10,
            "Name" => $customerFirstname . ' ' . $customerLastname,
            "Adr1" => $shippingStreet,
            "Adr2" => null,
            "Adr3" => null,
            "PostalCode" => $shippingPostcode,
            "City" => $shippingCity,
            "State" => null,
            "Country" => null,
            "Phone" => "",
            "SMS" => null,
            "Email" => $customerEmail,
            "SortOrder" => 10,
            "ContactType" => 100
          ];

          $updatedSender = [
            "TypeID" => $sender->TypeID,
            "StatusID" => $sender->StatusID,
            "Name" => $sender->Name,
            "Adr1" => $sender->Adr1,
            "Adr2" => null,
            "Adr3" => null,
            "PostalCode" => $sender->PostalCode,
            "City" => $sender->City,
            "State" => null,
            "Country" => null,
            "Phone" => $sender->Phone,
            "SMS" => null,
            "Email" => $sender->PrimaryEmail,
            "SortOrder" => 10,
            "ContactType" => 10
          ];

          $updatedOrderline = [];

          foreach ($orderItems as $newItem) {
            $updatedOrderline[] = [
              'OrderUID' => $existingOrderUID,
              'Number' => $orderNumber,
              'ProdNumber' => $newItem->getPurchasedEntityId(),
              'ProdName' => $newItem->getTitle(),
              'ProdLocation' => '',
              'ProdAmount' => intval($newItem->getQuantity()),
              'ColliPrProd' => 1,
              'TypeID' => 10,
              'StatusID' => 10,
             ];
          }

          $this->update_order($token, $updatedOrderData, $updatedReceiver, $updatedSender, $updatedOrderline);
        }
        
      }
    }

  }

  /**
   * Return all the info about the order sender.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @return json $sender
   *  The sender object. This is usually the shop owner.
   */
  public function get_sender_details($token) {
    $client = \Drupal::httpClient();
  
    try
    {
      $request = $client->get('https://logiapiv1.azurewebsites.net/client/get/current', [
        'headers' => [
          'Content-Type' => 'application/json', 
          'Authorization' => 'basic ' . $token,
        ],
      ]);
      
      if ($request->getStatusCode() == 200) {
        $sender = json_decode($request->getBody());
        return $sender;
      }
      
    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Return all the info about the shipment type selected by the customer.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param int $shippingUID
   *  Value of the shipement selected.
   * 
   * @return json $carrier
   *  The shipment object.
   */
  public function get_shipment_types($token, $shippingUID) {
    $client = \Drupal::httpClient();
  
    try
    {
      $request = $client->get('https://logiapiv1.azurewebsites.net/logistics/order/shipmenttypes', [
        'headers' => [
          'Content-Type' => 'application/json', 
          'Authorization' => 'basic ' . $token,
        ],
      ]);
      
      if ($request->getStatusCode() == 200) {
        $shipments = json_decode($request->getBody());

        // return shipment info selected
        foreach ($shipments as $carrier) {
          if ($carrier->UID === $shippingUID) {
              return $carrier;
          }
        }
      }
      
    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Executed when a new order is placed.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param array $order
   *  Contains all info about the order.
   * 
   * @param array $orderLine
   *  Contains sub-arrays of info about each item in the order. More fields are added here.
   */
  public function post_order($token, $order, $orderLine) {
    $client = \Drupal::httpClient();

    try
    {
      
      $request = $client->post('https://logiapiv1.azurewebsites.net/logistics/order/create/bulk', [
        'json' => $order,
        'headers' => [
          'Authorization' => 'basic ' . $token
        ],
      ]);

      if ($request->getStatusCode() == 200) {

        $response = json_decode($request->getBody());
        $orderlineData = [];

        foreach ($orderLine as $item) {
            $orderlineData = [
                'OrderUID' => $response->UID,
                'Number' => $response->Number,
                'ProdNumber' => $item['ProdNumber'],
                'ProdName' => $item['ProdName'],
                'ProdLocation' => '',
                'ProdAmount' => $item['ProdAmount'],
                'ColliPrProd' => 1,
                'TypeID' => 10,
                'StatusID' => 10,
            ];
            
            // create and orderline for each item in the order
            $this->post_orderline($token, $orderlineData);
        }

      }
    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Executed once an order is posted and the order UID is returned.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param array $orderLine
   *  Contains sub-arrays of info about each item in the order.
   * 
   */
  public function post_orderline($token, $orderLine) {
    $client = \Drupal::httpClient();

    try
    {
      
      $request = $client->post('https://logiapiv1.azurewebsites.net/logistics/order/line/create', [
        'json' => $orderLine,
        'headers' => [
          'Authorization' => 'basic ' . $token,
        ],
      ]);

    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Checks if the order number already exists in LogiSnap.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param int $orderNumber
   * 
   * @return string $orderUID
   * 
   */
  public function check_order_number($token, $orderNumber){
    
    $client = \Drupal::httpClient();
    $params = [
      'SearchString' => $orderNumber
    ];

    try
    {
      
      $request = $client->post('https://logiapiv1.azurewebsites.net/logistics/order/search', [
        'json' => $params,
        'headers' => [
          'Authorization' => 'basic ' . $token
        ],
      ]);

      if ($request->getStatusCode() == 200) {

        $response = json_decode($request->getBody());
        $allorders = $response->Orders;
        $orderUID = '';

        foreach ($allorders as $order) {
            if ($orderNumber == $order->OrderNumber) {
              $orderUID = $order->OrderUID;
              break;
            }
        }
          
        return $orderUID;
      }
    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Updates order info if it already exists in LogiSnap.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param array $updatedOrderData
   *  Contains the new info about the order.
   * 
   * @param array $updatedReceiver
   *  Contains the new info about the receiver.
   * 
   * @param array $updatedReceiver
   *  Contains the new info about the sender.
   * 
   * @param array $updatedOrderline
   *  Contains sub-arrays of the new info about each order item.
   * 
   */
  public function update_order($token, $updatedOrderData, $updatedReceiver, $updatedSender, $updatedOrderline) {
    $client = \Drupal::httpClient();

    try
    {
      
      $request = $client->post('https://logiapiv1.azurewebsites.net/logistics/order/update', [
        'json' => $updatedOrderData,
        'headers' => [
          'Authorization' => 'basic ' . $token,
        ],
      ]);

      if ($request->getStatusCode() == 200) {
        $order = json_decode($request->getBody());

        // update receiver
        $receiver = [
          "UID" => $order->Actor[1]->UID,
          "TypeID" => $updatedReceiver['TypeID'],
          "StatusID" => $updatedReceiver['StatusID'],
          "Name" => $updatedReceiver['Name'],
          "Adr1" => $updatedReceiver['Adr1'],
          "Adr2" => $updatedReceiver['Adr2'],
          "Adr3" => $updatedReceiver['Adr3'],
          "PostalCode" => $updatedReceiver['PostalCode'],
          "City" => $updatedReceiver['City'],
          "State" => $updatedReceiver['State'],
          "Country" => $updatedReceiver['Country'],
          "Phone" => $updatedReceiver['Phone'],
          "SMS" => $updatedReceiver['SMS'],
          "Email" => $updatedReceiver['Email'],
          "SortOrder" => $updatedReceiver['SortOrder'],
          "ContactType" => $updatedReceiver['ContactType']
        ];

        $this->update_order_actor($token, $receiver);

        // update sender
        $sender = [
          "UID" => $order->Actor[0]->UID,
          "TypeID" => $updatedSender['TypeID'],
          "StatusID" => $updatedSender['StatusID'],
          "Name" => $updatedSender['Name'],
          "Adr1" => $updatedSender['Adr1'],
          "Adr2" => $updatedSender['Adr2'],
          "Adr3" => $updatedSender['Adr3'],
          "PostalCode" => $updatedSender['PostalCode'],
          "City" => $updatedSender['City'],
          "State" => $updatedSender['State'],
          "Country" => $updatedSender['Country'],
          "Phone" => $updatedSender['Phone'],
          "SMS" => $updatedSender['SMS'],
          "Email" => $updatedSender['Email'],
          "SortOrder" => $updatedSender['SortOrder'],
          "ContactType" => $updatedSender['ContactType']
        ];

        $this->update_order_actor($token, $sender);

        //delete existing orderline
        $orderLines = $order->Lines;

        if ($orderLines != null) {
          foreach ($orderLines as $delLine){
            $this->delete_orderline($token, $delLine->UID);
          }
        }

        // update orderline
        foreach ($updatedOrderline as $updatedItem) {
           $this->post_orderline($token, $updatedItem);
        }

      }

    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Updates actor info of an order, they are linked by the actor order UID.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param array $actor
   *  Contains the new info about the actor to update.
   * 
   */
  public function update_order_actor($token, $actor) {
    $client = \Drupal::httpClient();

    try
    {
      
      $request = $client->post('https://logiapiv1.azurewebsites.net/logistics/actor/update', [
        'json' => $actor,
        'headers' => [
          'Authorization' => 'basic ' . $token,
        ],
      ]);

    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

  /**
   * Deletes the existing orderLine in an order based on the orderLineUID. 
   * This is only called when an order number already exists and needs to be overridden.
   * 
   * @param string $token
   *  Token stored in database used for the request header.
   * 
   * @param int $orderLineUID
   * 
   */
  public function delete_orderline($token, $orderLineUID){
    $client = \Drupal::httpClient();

    try
    {
      
      $request = $client->delete('https://logiapiv1.azurewebsites.net/logistics/order/line/delete?OrderLineUID=' . $orderLineUID, [
        'headers' => [
          'Authorization' => 'basic ' . $token,
        ],
      ]);

    }
    catch (RequestException $exception)
    {
      return false;
    }

  }

}
