<?php

// Make it easier to debug
// temporary function
function debug($data){

	echo "\n";
	print_r($data);
	echo "\n";
}

class TLDynamicException extends Exception{
    
    public function __toString() {
        return __CLASS__." Line: {$this->line}:\n{$this->message}\n";
    }
}

/** DynamicsAPI
*
* This class handles authentication and communication against
* Microsofts Dynamics 365 aka AX7 by using the grant_type
* 'password'. 
*
* @author Adam Folkesson
* @version 0.1
*/
class TLDynamicsAPI{

	/**
	* 
	* A with autdata
	* 
	* @var array
	*/
	private $authData = [];

  	private $tenant = "";
  	private $clientID = "";
    private $username = "";
  	private $password = "";
  	private $resource = "";

	/**
	* 
	* If refresh token expire
	* this flag is set so a new
	* login is made
	*
	* @var boolean
	*/
  	private $triedNewLoginAfterRefreshTokenFail = false;

	/**
	* 
	* Refresh token that's used to
	* get a new token when the auth
	* token expires
	*
	* @var array
	*/
	private $validAuthFields = ["tenant", "client_id", "username", "password", "resource"];
	
	/**
	* 
	* Counter to keep track of 
	* how many times authenication
	* have been made. Is set to
	* 0 on successful authentication 
	*
	* @var int
	*/
  	private $nrOfAuthTries = 0;

	/**
	* 
	* How many times it should try to 
	* authenticate before raising an
	* exception
	*
	* @var int
	*/
  	public $maxNrOfAuthTries = 4;

	/**
	* 
	* Dump info about request and
	* response to make it easier
	* to debug
	*
	* @var bool
	*/
  	public $debug = false;

	/**
	* 
	* The base url to the api.
	* If it's not set it's created
	* by using the resource in the auth
	* array in the constructor.
	*
	* @var string
	*/
  	public $apiBaseURL = "";
  	
	/**
	* 
	* The url to get a an access token
	* it's created in the constructor
	* https://login.windows.net/{$this->tenant}/oauth2/token
	*
	* @var string
	*/
  	public $accessTokenURL = "";

	/**
	* 
	* HTTP status code response
	* is being set when a request is made
	*
	* @var int
	*/
  	public $httpResponseStatusCode = -1;

	/**
	* 
	* Access token that's used
	* to make requests against the api 
	*
	* @var string
	*/
	public $accessToken = null;

	/**
	* 
	* Refresh token that's used to
	* get a new token when the auth
	* token expires
	*
	* @var string
	*/
	public $refreshToken = null;

	/**
	* 
	* When the token expiers
	* timestamp in GMT
	*
	* @var int
	*/
	private $tokenExpire = 0;

	/**
	* 
	* The path/name to where the token
	* data should be saved.
	*
	* @var string
	*/
	public $tokenDataFilename = __DIR__."/tl_auth_token_data.json";

	/**
	* 
	* Array with valid http status
	* codes. If the http status
	* code that's returned from
	* a request do not exists in
	* the array it is considered as
	* an error.
	*
	* @var array
	*/
	public $validHttpStatusCodes = [200, 201, 202, 203, 204, 205];

	/**
	*
	* The Constructor makes sure a settings file
	* exists with correct auth data. If no file
	* is found or do not seem to contain correct
	* auth data exception is raised
	* 
	* @param mixed $auth array with valid auth data or a string to a json file that contains the data
	* @return Current_Class_Name
	*/
	function __construct($auth = null){

		if ($auth === null){
			$auth = __DIR__."/tl_dynamics_auth.json";
		}

		// Use when rasing exception to make messages more informative
		$exceptionDebugInfoValidKeys = json_encode($this->validAuthFields);
		$exceptionDebugInfoSuppliedArray = json_encode($this->authData);

		// Check that param is valid must be
		// array or string
		if ( is_array($auth) || is_string($auth) ){

			// If param is string check that the file
			// exists and try to parse the json to an array
			if (is_string($auth)) {

				if (file_exists($auth)){

					$jsonAuthDataString = file_get_contents($auth);
					$this->authData = json_decode($jsonAuthDataString, true);
					$jsonError = json_last_error();
					if ($jsonError !== JSON_ERROR_NONE){
						throw new TLDynamicException("Couldn't parse json at path '$auth' JSON ERROR: '$jsonError'");	
					}
				}
				else{				 
					throw new TLDynamicException("Couldn't find a file at the path: '$auth'");
				}
			}
			else{
				$this->authData = $auth;
			}

			// Check that the supplied auth array
			// contains valid keys it not raise
			// an exception
			$keys = array_keys($this->authData);
			foreach ($this->validAuthFields as $validKey) {
				$isValidKey = in_array($validKey, $keys);
				if ($isValidKey === false){

					// Raise different exception depending on
					// if it's a string or not

					if (is_string($auth)){
						throw new TLDynamicException("Supplied auth json file '$auth' do not contain valid keys, valid keys are: $exceptionDebugInfoValidKeys");	
					}
					else{
						throw new TLDynamicException("Supplied auth array '$exceptionDebugInfoSuppliedArray' do not contain valid keys, valid keys are: $exceptionDebugInfoValidKeys");	
					}
				}
				else{

					// Set some intance vars to make
					// is easier to work with instead
					// of using the array
					$data = $this->authData[$validKey];
					switch ($validKey) {
						case 'tenant':
							$this->tenant = $data;
							break;
						case 'client_id':
							$this->clientID = $data;
							break;
						case 'username':
							$this->username = $data;
							break;
						case 'password':
							$this->password = $data;
							break;
						case 'resource':
							$this->resource = $data;
							break;
						default:
							break;
					}
				}
			}

			$this->accessTokenURL = "https://login.windows.net/{$this->tenant}/oauth2/token";
			
			$this->apiBaseURL = $this->resource."/data";

			// Try to get a token with the
			// supplied auth data
			$this->fetchAccessToken();
		}
		else{
			throw new TLDynamicException("Invalid parameter to constructor, must contain array with following keys: $exceptionDebugInfoValidKeys or the path to a json file with auth data");
		}
	}

	/**
	*
	* Parses the token file in the $tokenDataFilename.
	* Parses the file and sets the vars $tokenExpire,
	* $accessToken and $refreshToken. Raises exception
	* if something goes wrong.
	*
	* @return void
	*/
	private function parseTokenData(){

		// Parse the data in the token file
		$tokenData = file_get_contents($this->tokenDataFilename);
		if ($tokenData !== false){
			
			$tokenDataArray = json_decode($tokenData, true);
			$jsonError = json_last_error();
			if ($jsonError !== JSON_ERROR_NONE){
				throw new TLDynamicException("Couldn't parse json at path '$auth' JSON ERROR: '$jsonError'");	
			}
			else{

				// Check that token file contains correct values
				if ( !isset($tokenDataArray['expires_on']) ){
					throw new TLDynamicException("Couldn't find when token expires 'expires_on' in token file '{$this->tokenDataFilename}'");		
				}
				if ( !isset($tokenDataArray['access_token']) ){
					throw new TLDynamicException("Couldn't acess token 'access_token' in token file '{$this->tokenDataFilename}'");		
				}
				if ( !isset($tokenDataArray['refresh_token']) ){
					throw new TLDynamicException("Couldn't refresh token 'refresh_token' in token file '{$this->tokenDataFilename}'");		
				}

				$this->tokenExpire = $tokenDataArray['expires_on'];
				$this->accessToken = $tokenDataArray['access_token'];
				$this->refreshToken = $tokenDataArray['refresh_token'];
			}
		}
		else{
			throw new TLDynamicException("Couldn't get contents of file '{$this->tokenDataFilename}', check permissions and that the file exists");
		}
	}

	/**
	*
	* Handels the authenication. It first looks for token data in the
	* $tokenDataFilename, if such a file exists it tries to use the
	* access_token, if it has expierd it tries to fetch a 
	* new token by using the refresh_token. If that fails it falls
	* back to getting a new token by logging in with the data supplied in
	* the $auth parameter. It tries to authenicate $maxNrOfAuthTries before
	* raising an error.
	*
	* @return void
	*/
	public function fetchAccessToken(){

		$this->nrOfAuthTries += 1;

		// If the token should be refreshed
		// or trying to get it again with auth
		// data => password and username
		$shouldRefreshToken = false;

		// Check if token file exists
		if (file_exists($this->tokenDataFilename)){
			$tokenFile = file_get_contents($this->tokenDataFilename);
			if ($tokenFile !== false){

				$this->parseTokenData();
				
				// A valid token exists since 
				// it has not expierd
				if ( $this->tokenExpire >= time() ){
					return;
				}
				else{
					$shouldRefreshToken = true;
				}
			}
		}

		// Construct request
		$ch = curl_init();

		// Send different POST params
		// depending on if the token
		// should be renewed with
		// the refresh token or
		// via username and password
		$postFieldsString = "";
		if ($shouldRefreshToken){

			$postFieldsString = "grant_type=refresh_token";
			$postFieldsString .= "&client_id={$this->clientID}";
			$postFieldsString .= "&resource={$this->resource}";
			$postFieldsString .= "&refresh_token={$this->refreshToken}";
		}
		else{
			$usernameURLEncoded = urlencode($this->username);
			$passwordURLEncoded = urlencode($this->password);

			$postFieldsString = "grant_type=password";
			$postFieldsString .= "&client_id={$this->clientID}";
			$postFieldsString .= "&resource={$this->resource}";
			$postFieldsString .= "&username=$usernameURLEncoded";
			$postFieldsString .= "&password=$passwordURLEncoded";			
		}
		
		curl_setopt($ch, CURLOPT_URL, $this->accessTokenURL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFieldsString);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
		$result = curl_exec($ch);

		// Check if error
		if (curl_errno($ch)) {
			
			$curlError = curl_error($ch);

			if ($shouldRefreshToken){

				if ($this->nrOfAuthTries >= $this->maxNrOfAuthTries){
					throw new TLDynamicException("Faild to get access token, refresh token no longer valid. curl error: '$curlError'. Tried '{$this->nrOfAuthTries}' times");
				}
				else{
					$this->fetchAccessToken();
				}
			}
			else{

				if ($this->nrOfAuthTries >= $this->maxNrOfAuthTries){
					throw new TLDynamicException("Failed to get access token, check supplied auth params. curl error: '$curlError' Tried '{$this->nrOfAuthTries}' times");
				}
				else{
					$this->fetchAccessToken();
				}
			}
		}
		else{

			$this->httpResponseStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$resultArray = json_decode($result, true);
			if (isset($resultArray['error'])){

				if ($shouldRefreshToken){

					if ($this->nrOfAuthTries >= $this->maxNrOfAuthTries){

						// Try one time to remove token info so new
						// refresh token is fetched
						if ( $this->triedNewLoginAfterRefreshTokenFail === false && file_exists($this->tokenDataFilename) ) {
							unlink($this->tokenDataFilename);
							$this->triedNewLoginAfterRefreshTokenFail = true;
							$this->fetchAccessToken();
						}
						else{

							throw new TLDynamicException("Something went wrong, refresh token not valid\nHTTP status code: '{$this->httpResponseStatusCode}'\nBody: '$result' Tried '{$this->nrOfAuthTries}' times");
						}

					}
					else{
						$this->fetchAccessToken();
					}
					
				}
				else{

					if ($this->nrOfAuthTries >= $this->maxNrOfAuthTries){
						throw new TLDynamicException("Auth error, check supplied auth data\nHTTP status code: '{$this->httpResponseStatusCode}'\nBody: '$result' Tried '{$this->nrOfAuthTries}' times");
					}
					else{
						$this->fetchAccessToken();
					}
				}
				
			}
			else{

				// Store the token data to file and parse
				$success = file_put_contents($this->tokenDataFilename, $result);
				if ($success === false){
					throw new TLDynamicException("Couldn't store token data to file '{$this->tokenDataFilename}', check permissions");			
				}

				$this->nrOfAuthTries = 0;
				$this->parseTokenData();
			}
		}

		curl_close($ch);
	}

	/**
	*
	* Makes a request by using the $apiBaseURL
	* $path param. Throws an exception on error.
	*
	* @param string $method request method to use GET, POST, PUT, DELETE
	* @param string $path which path the request should be made against
	* @param array $data which data should be sent, is encoded to json
	* @param string $queryParams appended to the end of the path
	*
	* @return array
	*/
	public function makeRequest($method, $path, $data, $queryParams = ""){
		
		$upperCaseMethod = strtoupper($method);
		$validMethods = ["GET", "POST", "PATCH", "PUT", "DELETE"];

		// Check that http method is valid
		if ( !in_array($upperCaseMethod, $validMethods) ){
			throw new TLDynamicException("'$method' is not a supported http method");
		}
		else{

			// Create url
			$url = "{$this->apiBaseURL}/$path";

			// Default headers
			$headers = ["Authorization: Bearer {$this->accessToken}",
						"Cache-Control: no-cache",
						"Pragma: no-cache",
						"Content-Type: application/json",
						];

			// Holds the total items
			// when it's a GET request
			$totalItems = 0;

			// Construct the request
			$ch = curl_init();

			// Count how many exists in total
			// before making the "real" request
			if ($upperCaseMethod === "GET"){
				
				$headers[] = "Content-Length: 0";
				
				// First count how many exist before
				// returning results
				curl_setopt($ch, CURLOPT_URL, "$url/".'$count');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_VERBOSE, $this->debug);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				$response = curl_exec($ch);
				curl_close($ch);
				
				$totalItems = $response;
			}


			$ch = curl_init();

			if ($upperCaseMethod === "POST" || $upperCaseMethod === "PUT" || $upperCaseMethod === "PATCH"){

				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperCaseMethod);
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
				curl_setopt($ch, CURLOPT_POST, true);
			}
			
			if ($method !== "POST"){

				// Url encode the query params
				parse_str($queryParams, $queryParamsArray);
				$urlEncodedQueryParams = "";
				$index = 0;
				foreach ($queryParamsArray as $key => $value) {

					$separator = "";
					if ($index !== 0){
						$separator = "&";
					}

					$urlEncodedQueryParams .= $separator.$key."=".curl_escape($ch, $value);
					$index += 1;
				}

				$url .= $urlEncodedQueryParams;
			}

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_VERBOSE, $this->debug);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			$response = curl_exec($ch);

			$this->httpResponseStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			$responseData = [];

			// Check if curl error
			$curlError = curl_errno($ch);
			if ($curlError){
				$curlErrorText = curl_error($ch);
				curl_close($ch);
				throw new TLDynamicException("HTTP Status code: {$this->httpResponseStatusCode}\nURL: $url\nCurl error: '$curlErrorText'");
			}
			else{

				curl_close($ch);
				
		    	// The token has expierd, try to fetch it again
		    	if ($this->httpResponseStatusCode == 401){
					$this->fetchAccessToken();
		    	}
		    	
		    	$isResponseValid = in_array($this->httpResponseStatusCode, $this->validHttpStatusCodes);
		    	if ($isResponseValid){

					$responseData = json_decode($response, true);

					if ($upperCaseMethod === "GET"){
						
						if (isset($responseData['value'])) {
							$responseData = $responseData['value'];
						}

						$responseData = ["data" => $responseData, "total" => $totalItems, "current" => count($responseData)];
					}

					return $responseData;
		    	}
		    	else{
		    		throw new TLDynamicException("HTTP Status code: {$this->httpResponseStatusCode}\nURL: $url\nError: $response");
		    	}
			}

			return $responseData;
		}
	}

	/**
	*
	* WORK IN PROGRESS
	* Makes a batch request data/$batch
	*
	* @param string $entity the name of the entity to create
	* @param array $data what should be created
	*
	* @return array
	*/
	public function batch($entity, $data){

		$jsonEncodedData = json_encode($data);
		$boundaryID = "batch_".uniqid();
		$changeSetID = "changeset_".uniqid();

		// Create request headers
		$headers = [
			"Content-Type: multipart/mixed; boundary=$boundaryID",
			"Authorization: Bearer {$this->accessToken}"
		];

		// Create the request payload
		$body = "--$boundaryID\n";
		$body .= "Content-Type: multipart/mixed; boundary=$changeSetID\n";
		$body .= "\n";

		$body .= "--$changeSetID\n";
		$body .= "Content-Type: application/http\n";
		$body .= "Content-Transfer-Encoding: binary\n";
		$body .= "Content-ID: 1\n";
		$body .= "\n";
		
		$body .= "POST {$this->resource}/data/$entity HTTP/1.1\n";
		$body .= "Content-Type: application/json;odata.metadata=minimal\n";
		$body .= "\n";

		$body .= "$jsonEncodedData\n";
		$body .= "--$changeSetID--\n";
		$body .= "--$boundaryID--";
		
		// Construct batch request
		$ch = curl_init();
		$batchUrl = $this->apiBaseURL.'/$batch';
		curl_setopt($ch, CURLOPT_URL, $batchUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		curl_setopt($ch, CURLOPT_VERBOSE, $this->debug);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$response = curl_exec($ch);

		$this->httpResponseStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// Check if curl error
		$curlError = curl_errno($ch);
		if ($curlError){
			$curlErrorText = curl_error($ch);
			curl_close($ch);
			throw new TLDynamicException("HTTP Status code: {$this->httpResponseStatusCode}\nURL: $batchUrl\nCurl error: '$curlErrorText'");
		}
		else{
			
			curl_close($ch);
			
			// Check if request against /$batch service was ok
			if ($this->httpResponseStatusCode == 200){

				// Check the response from embedded request
				preg_match("/HTTP\/1\.1.*(\d{3})/", $response, $matches);
				if (count($matches) == 2){

					$httpStatusCode = $matches[1];
					$this->httpResponseStatusCode = $httpStatusCode;

					if ($this->httpResponseStatusCode == 201){

						// Parse json in reqponse
						preg_match_all("/(\{.*\})/s", $response, $matches);
						if (isset($matches[1][0])){
							
							// Try to parse json
							$json = $matches[1][0];
							$response = json_decode($json, true);
							$jsonError = json_last_error();
							if ($jsonError === JSON_ERROR_NONE){
								return $response;
							}
							else{
								throw new TLDynamicException("BATCH entity '$entity' Coludn't parse json in response json error: '$jsonError'\nResponse: '$response'\nRequest data:\n'$jsonEncodedData'");
							}
							
						}
						else{
							throw new TLDynamicException("BATCH entity '$entity' Coludn't find json in response\nResponse: '$response'\nRequest data:\n'$jsonEncodedData'");
						}
					}
					else{
						throw new TLDynamicException("BATCH entity '$entity' Bad http status code: '{$this->httpResponseStatusCode}'\nResponse: '$response'\nRequest data:\n'$jsonEncodedData'");
					}
				}
				else{
					throw new TLDynamicException("BATCH entity '$entity' Couldn't find http status code '{$this->httpResponseStatusCode}' in response: '$response'\nRequest data:\n'$jsonEncodedData'");
				}
			}
			else{
				throw new TLDynamicException("BATCH  entity '$entity' HTTP Status code: {$this->httpResponseStatusCode}\nURL: $batchUrl\Response body: '$response'\nRequest data:\n'$jsonEncodedData'");
			}
		}
	}

	/**
	*
	* Convenience method to make a POST request
	* uses the makeRequest() method returns the
	* result body as an array
	*
	* @return array
	*/
	public function post($path, $data){
		return $this->makeRequest('POST', $path, $data);
	}

	/**
	*
	* Convenience method to make a GET request
	* uses the makeRequest() method returns the
	* result body as an array
	*
	* @return array
	*/
	public function put($path, $data, $id){
		return $this->makeRequest('PUT', $path, $data, $id);
	}

	/**
	*
	* Convenience method to make a PATCH request
	* uses the makeRequest() method returns the
	* result body as an array
	*
	* @return array
	*/
	public function patch($path, $data, $id){
		return $this->makeRequest('PATCH', $path, $data, $id);
	}

	/**
	*
	* Convenience method to make a GET request
	* uses the makeRequest() method returns the
	* result body as an array
	*
	* @return array
	*/
	public function get($path, $queryParams = ""){
		return $this->makeRequest('GET', $path, [], $queryParams);
	}

	/**
	*
	* Convenience method to make a DELETE request
	* uses the makeRequest() method returns the
	* result body as an array
	*
	* @return array
	*/
	public function delete($path, $id){
		return $this->makeRequest('DELETE', $path, [], $id);	
	}
}