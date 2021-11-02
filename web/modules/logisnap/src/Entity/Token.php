<?php

namespace Drupal\logisnap\Entity;

class Token {
    
  public function get_token() {
    $accessToken = '';
    $config = \Drupal::config('logisnap.login');
    $token1 = $config->get('logisnap_first_token');
    $token2 = $config->get('logisnap_webshop_token');

    if ($token1 != null && $token2 != null) {
      $accessToken = $token1 . '.' . $token2;
    }

    return $accessToken;
  }

}