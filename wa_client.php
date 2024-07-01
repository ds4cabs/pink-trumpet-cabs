<?php

// MCL - turned off error reporting 5/6/2016 (see if it fixes internal server errors)
//error_reporting(-1);
//ini_set('display_errors', 'On');


class wa_client
{
    const CABS_API_KEY = "ddkt9y2qer1qncditml7lxbayno2yb";

    private $wa_api_client;
    public $account;

    //  constructor
    public function __construct()
    {

        $this->wa_api_client = WaApiClient::getInstance();
        
        // TODO: REMOVE MAGIC CONSTANT (MCL)
        //$CABS_API_KEY = 
        //$this->wa_api_client->initTokenByApiKey("ddkt9y2qer1qncditml7lxbayno2yb");  //FULL ACCESS
        $this->wa_api_client->initTokenByApiKey(self::CABS_API_KEY);  //FULL ACCESS

        //$waApiClient->initTokenByApiKey('9i1yv9fegwvu0udijhsrnhqyr6zb05');  // can't retrieve event list
        //$waApiClient->initTokenByContactCredentials('mike@zentasticmike.com', 'gUbybqdt');    //  this is the admin user (event list, member list retrieve OK)
        //$waApiClient->initTokenByContactCredentials('michael.c.lin@gmail.com', 'dingo123');   /// this is a regular member (can't retreive event list)
        //$waApiClient->initTokenByContactCredentials('michaelclin+123@gmail.com', 'peter123');   // peter pan (can't login not working)

        // fetch account info
        $this->account = $this->get_account();

    }

    private function wa_request($url, $subitem = 0)
    {
        $response = $this->wa_api_client->makeRequest($url);
        return  ( is_null($subitem) ? $response : $response[$subitem] );
    }

    public function get_account()
    {
       $baseUrl = 'https://api.wildapricot.org/v2/Accounts/';
       return $this->wa_request($baseUrl);
    }
    
    public function get_contact($contactid)
    {
       $baseUrl = 'https://api.wildapricot.org/v2/Accounts/' . $this->account['Id'];
       $url = $baseUrl . "/Contacts/$contactid";
       return $this->wa_request($url, null);
    }

    public function get_event_list( $show_details = 1, $show_upcoming = 1, $show_recent = 0,  $tags = "", $eventid = null)
    {
        $account = $this->account;

        $url = 'https://api.wildapricot.org/v2/Accounts/' . $account['Id'] . '/Events?';

        $filter = "";
        if ($show_upcoming && $show_recent) {
            // nothing here
            $a = 1;
        }
        else {
            if ($show_upcoming) {
                $filter .= urlencode('IsUpcoming eq true ');
            }
            if ($show_recent) {
               $filter .= urlencode('IsUpcoming eq false ');
            }
        }

        if ($tags != "") {
           $filter .= ($filter != "" ? urlencode(" AND ") : "");
           $filter .= urlencode("Tags in [$tags]");
        }

        if ($eventid != "") {
           $filter .= ($filter != "" ? urlencode(" AND ") : "");
           $filter .= urlencode("ID in [$eventid]");
        }

        $url .=  ( $filter != "" ?  "\$filter=$filter" : "");

        if ($show_details) {
            $url .= "&includeEventDetails=true";
        }


       // boolean
       //$filter=substringof('Name', 'training') eq true OR $filter=Tags in [training]

       // include event details (works)
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true';

       // upcoming (works)
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode('IsUpcoming eq true');

       // search (works)
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode("substringof('Name', 'Gala')");
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode("substringof('TextIndex', 'conference')");
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode("substringof('TextIndex', '生物药物开发')");

       // registration enabled (works)
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode('RegistrationEnabled eq true');

       // filter by tags (works)
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode('Tags in [recruiting] AND Tags in [collaboration]');
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode('Tags in [recruiting,collaboration]');
       //$url = 'https://api.wildapricot.org/v2/Accounts/' .  $account['Id'] . '/Events?includeEventDetails=true&$filter=' . urlencode('Tags in [collaboration]');

       //print_r("<pre><small>1111111111111 ---------><br>\n" . $url . "</small></pre>");


       return  $this->wa_request($url, "Events");
       //return elist;

    }

}


/**
 * API helper class. You can copy whole class in your PHP application.
 */
class WaApiClient
{
   const AUTH_URL       = 'https://oauth.wildapricot.org/auth/token';

   // CABS API CLIENT ID (MCL)
   const CLIENT_ID      = 'dywbpnmqwd';
   const CLIENT_SECRET  = 'cj0mx2cmdakbj4iocw59swl926b6gv';

   private $tokenScope = 'auto';
   private static $_instance;
   private $token;

   public function initTokenByContactCredentials($userName, $password, $scope = null)
   {
      if ($scope) {
         $this->tokenScope = $scope;
      }
      $this->token = $this->getAuthTokenByAdminCredentials($userName, $password);
      if (!$this->token) {
         throw new Exception('Unable to get authorization token.');
      }
   }
   public function initTokenByApiKey($apiKey, $scope = null)
   {
      if ($scope) {
         $this->tokenScope = $scope;
      }
      $this->token = $this->getAuthTokenByApiKey($apiKey);
      if (!$this->token) {
         throw new Exception('Unable to get authorization token.');
      }
   }
   // this function makes authenticated request to API
   // -----------------------
   // $url is an absolute URL
   // $verb is an optional parameter.
   // Use 'GET' to retrieve data,
   //     'POST' to create new record
   //     'PUT' to update existing record
   //     'DELETE' to remove record
   // $data is an optional parameter - data to sent to server. Pass this parameter with 'POST' or 'PUT' requests.
   // ------------------------
   // returns object decoded from response json
   public function makeRequest($url, $verb = 'GET', $data = null)
   {
      if (!$this->token) {
         throw new Exception('Access token is not initialized. Call initTokenByApiKey or initTokenByContactCredentials before performing requests.');
      }
      $ch = curl_init();
      $headers = array(
         'Authorization: Bearer ' . $this->token,
         'Content-Type: application/json'
      );
      curl_setopt($ch, CURLOPT_URL, $url);

      if ($data) {
         $jsonData = json_encode($data);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
         $headers = array_merge($headers, array('Content-Length: '.strlen($jsonData)));
      }
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $verb);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 500);
      $jsonResult = curl_exec($ch);
      if ($jsonResult === false) {
         throw new Exception(curl_errno($ch) . ': ' . curl_error($ch));
      }
/*
      var_dump(">>>  makerequest >> $url");
      var_dump($jsonResult); // Uncomment line to debug response
      var_dump("<<<  makerequest >> $url");
*/
      curl_close($ch);
      return json_decode($jsonResult, true);
   }
   private function getAuthTokenByAdminCredentials($login, $password)
   {
      if ($login == '') {
         throw new Exception('login is empty');
      }
      $data = sprintf("grant_type=%s&username=%s&password=%s&scope=%s", 'password', $login, $password, $this->tokenScope);
      $authorizationHeader = "Authorization: Basic " . base64_encode( self::CLIENT_ID . ":" . self::CLIENT_SECRET);
      return $this->getAuthToken($data, $authorizationHeader);
   }
   private function getAuthTokenByApiKey($apiKey)
   {
      $data = sprintf("grant_type=%s&scope=%s", 'client_credentials', $this->tokenScope);
      $authorizationHeader = "Authorization: Basic " . base64_encode("APIKEY:" . $apiKey);
      return $this->getAuthToken($data, $authorizationHeader);
   }
   private function getAuthToken($data, $authorizationHeader)
   {

      $ch = curl_init();
      $headers = array(
         $authorizationHeader,
         'Content-Length: ' . strlen($data)
      );
      curl_setopt($ch, CURLOPT_URL, WaApiClient::AUTH_URL);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $response = curl_exec($ch);
      if ($response === false) {
         throw new Exception(curl_errno($ch) . ': ' . curl_error($ch));
      }
      // var_dump($response); // Uncomment line to debug response

      $result = json_decode($response , true);
      curl_close($ch);
      return $result['access_token'];
   }
   public static function getInstance()
   {
      if (!is_object(self::$_instance)) {
         self::$_instance = new self();
      }
      return self::$_instance;
   }
   public final function __clone()
   {
      throw new Exception('It\'s impossible to clone singleton "' . __CLASS__ . '"!');
   }
   private function __construct()
   {
      if (!extension_loaded('curl')) {
         throw new Exception('cURL library is not loaded');
      }
   }
   public function __destruct()
   {
      $this->token = null;
   }
}

?>
