<?php

namespace Drupal\logisnap\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Exception\RequestException;
  
class LoginForm extends ConfigFormBase {  

  protected function getEditableConfigNames() {  
    return [  
      'logisnap.login'
    ];  
  }  
  
  public function getFormId() {  
    return 'logisnap_form';  
  } 

  public function buildForm(array $form, FormStateInterface $form_state) {  
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('logisnap.login');  
  
    $form['logisnap_email'] = [  
      '#type' => 'textfield',  
      '#title' => $this->t('Email'),  
      '#default_value' => $config->get('logisnap_email'),
      '#required' => TRUE,
    ];  
    
    $form['logisnap_password'] = [  
      '#type' => 'password',  
      '#title' => $this->t('Password'),  
      '#default_value' => $config->get('logisnap_password'),
      '#required' => TRUE,  
    ];

    $form['actions']['submit']['#value'] = $this->t('Log in');

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {  
    $email = $form_state->getValue('logisnap_email');
    $password = $form_state->getValue('logisnap_password');
    $config = $this->config('logisnap.login');  
    $token2 = $config->get('logisnap_webshop_token');
    $token1 = $this->get_first_token($email, $password);
    $storedUser = $config->get('logisnap_email');
    // \Drupal::messenger()->addStatus('Token1 : ' . $token1);

    if ($token1 != null) {
      $config
      ->set('logisnap_first_token', $token1)
      ->save();

      // redirect to the page to select shop if not stored in DB yet
      if ($token2 == null) {
        $form_state->setRedirect('logisnap.webshop', [
          'token1' => $token1,
          'email' => $email,
          'password' => $password
        ]);
      }
    }

    // if token is OK and data is already stored
    if ($token1 != null && $storedUser != null) {
      drupal_set_message($this
      ->t('Successfully logged in to LogiSnap.'));
    }

    // if token is not OK, erase all data
    if ($token1 == null) {
      $config
      ->clear('logisnap_email')
      ->clear('logisnap_password')
      ->clear('logisnap_first_token')
      ->clear('logisnap_webshop_token')
      ->save();
    }
    
  } 

  public function get_first_token($email, $password){
    $token1 = '';
    $client = \Drupal::httpClient();
    $params = [
      'Email'=> $email,
      'Password' => $password,
      'ExtraInfo' => [
        'OperatingSystem' => 'User OS',
        'OsVersion' => 'User OS v10',
      ],
    ];
    
    try
    {
      $request = $client->post('https://logi-scallback.azurewebsites.net/v1/user/getaccesstoken', [
        'json' => $params,
      ]);

      if ($request->getStatusCode() == 200) {
        $token1 = json_decode($request->getBody());
      }
      
      return $token1;
    }
    catch (RequestException $exception)
    {
      // if the token is not valid
      drupal_set_message($this
      ->t('Wrong credentials. Please try again'), 'error');
      
    }
    
  }

}