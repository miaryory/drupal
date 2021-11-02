<?php

namespace Drupal\logisnap\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;

class Webshop extends ConfigFormBase {  

  protected function getEditableConfigNames() {  
    return [  
      'logisnap.login'
    ];  
  }  
  
  public function getFormId() {  
    return 'logisnap_webshop';  
  }

  public function buildForm(array $form, FormStateInterface $form_state) {  
    $form = parent::buildForm($form, $form_state);
    $token1 = \Drupal::request()->get('token1'); // parameter passed from Drupal\logisnap\Form\LoginForm

    if ($token1 != null) {

      $allShops = $this->get_webshops($token1);
      $options = [];
  
      // check if we get any webshop to display
      if (sizeof($allShops != 0)) {

        foreach ($allShops as $shop) {
            $options[$shop->AccountToken] = $shop->ClientName;
        }
    
        $form['webshop'] = [
            '#type' => 'radios',
            '#title' => t('Select a webshop:'),
            '#options' => $options,
        ];
  
        $form['actions']['submit']['#value'] = $this->t('Save');
    
        return $form;

      }

    }
  }
 
  public function submitForm(array &$form, FormStateInterface $form_state) { 
    // from the radio button chosen
    $token2 = $form_state->getValue('webshop');
    $config = $this->config('logisnap.login'); 
    $email = \Drupal::request()->get('email');
    $password = \Drupal::request()->get('password');

    if ($token2 != null && $email != null && $password != null) {
      // save token2, email and password in the DB
      $config  
      ->set('logisnap_email', $email)
      ->set('logisnap_password', $password)
      ->set('logisnap_webshop_token', $token2)
      ->save();
  
      drupal_set_message($this
      ->t('Successfully logged in to LogiSnap.'));
  
      // if a shop was selected > redirect to login page and show success message ^
      $form_state->setRedirect('logisnap.login');

    }

  }

  public function get_webshops($token1) {
    $allShops = [];
    $client = \Drupal::httpClient();
    
    try
    {
      $request = $client->get('https://logiapiv1.azurewebsites.net/user/getaccounts', [
        'headers' => [
          'Content-Type' => 'application/json', 
          'Authorization' => 'basic ' . $token1,
        ],
      ]);
      
      if ($request->getStatusCode() == 200) {
        $allShops = json_decode($request->getBody());
      }
      
      return $allShops;
    }
    catch (RequestException $exception)
    {
      drupal_set_message($this
      ->t('An error occured. Please try again.'), 'error');
    }

  }

}
