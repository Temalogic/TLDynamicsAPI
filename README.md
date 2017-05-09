# TLDynamicsAPI

### Description

This class handles authentication against
Microsofts [Dynamics 365](https://msdn.microsoft.com/en-us/library/mt593051.aspx?f=255&MSPPError=-2147217396) aka [AX7](https://msdn.microsoft.com/en-us/library/mt593051.aspx?f=255&MSPPError=-2147217396) by using the *grant_type "password"*. It also contains methods to make requests against an [Odata 4.0](http://www.odata.org/documentation/) api, which is what Dynamics 365 uses. The class should only be used as a starting point, **It DOES NOT fully implement the [Odata 4.0](http://www.odata.org/documentation/) standard**.

### Authenication

**The class can only be used with correct authenication details. There are three ways that authenication can be supplied:**

* (Easiest) Create a file called **tl_dynamics_auth.json** in the same folder as the **TLDynamicsAPI.php** file. Then just use the class by ```new TLDynamicsAPI()```

	Example: **tl_dynamics_auth.json**
	```json
	{
	  "tenant": "domain.onmicrosoft.com",
	  "client_id": "xxxxx-xxxx-xxxx-xxxx-xxxxxx",
	  "username": "name@domain.onmicrosoft.com",
	  "password": "password",
	  "resource": "https://myresource.sandbox.ax.dynamics.com"
	}
	```
* Create a file with a different filename or path. The file must be places relative to **TLDynamicsAPI.php**. ```new TLDynamicsAPI("auth/my_auth.json")``` See below how it should look like.

* Use an array with auth parameters. Example:
```php
	$authArray = [
		"tenant" => "domain.onmicrosoft.com",
		"client_id" => "xxxxx-xxxx-xxxx-xxxx-xxxxxx",
		"username" => "name@domain.onmicrosoft.com",
		"password" => "password",
		"resource" => "https://myresource.sandbox.ax.dynamics.com"
	];
	TLDynamicsAPI($authArray);
```

### Make requests

When authenication is successful a file called **tl_auth_token_data.json** will be created in the same directory as the **TLDynamicsAPI.php**. The file will contain access- and refresh token that will be used to make requests. If something goes wrong with the request or authenication an **TLDynamicException** will be throwed.

Example code:

```php
try{

	$dynamics = new TLDynamicsAPI();
	
	// Make get request
	$accounts = $dynamics->get("Accounts");
	
	$startDate = "2017-01-03";
	$endDate = "2017-01-10";
	$queryParams = "?$filter=Date ge $startDate. and Date le $endDate";
	$response = $dynamics->get("Accounts", $queryParams);
	
	// Make post request
	$response = $dynamics->post("Accounts", ["Foo" => "Bar"]);

	// Make put request
	$response = $dynamics->put("Accounts", ["Foo" => "Bar"], 100);
	
	// Make patch request
	$response = $dynamics->patch("Accounts", ["Foo" => "Bar 2"], 100);
	
	// Make delete request
	$response = $dynamics->delete(100);
	
	// Make batch request (WORK IN PROGRESS, ONLY MAKES BATCH POST REQUEST)
	$response = $dynamics->batch("Accounts", ["Foo" => "Bar 2"]);
}
catch(Exception $e){

	echo $e->__toString();
	exit;
}

```


### Documenation

The code is documented by following the [phpdoc](https://phpdoc.org/) standard. Follow the [install instructions](https://phpdoc.org/docs/latest/getting-started/installing.html) to install phpdoc. Then you can generate documentaion by following [this guide](https://phpdoc.org/docs/latest/getting-started/your-first-set-of-documentation.html#running-phpdocumentor)