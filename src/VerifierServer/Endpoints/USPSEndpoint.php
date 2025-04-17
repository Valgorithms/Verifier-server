<?php declare(strict_types=1);

namespace VerifierServer\Endpoints;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use SimpleXMLElement;
use VerifierServer\Endpoint;

use RuntimeException;


/**
 * Class USPSEndpoint
 *
 * This class implements the EndpointInterface and provides functionality
 * to handle USPS ZipCodeLookup API requests. It supports HTTP methods
 * such as GET, HEAD, and POST, and generates XML requests to interact
 * with the USPS API.
 */
class USPSEndpoint extends Endpoint
{
    CONST BASE_URL  = 'http://production.shippingapis.com/ShippingAPITest.dll?API=ZipCodeLookup&XML=';
    CONST INFO      = 'Information provided by www.usps.com';

    public string $id       = '0';
    public string $address1 = '';
    public string $address2 = '6406 IVY LANE';
    public string $city     = 'GREENBELT';
    public string $state    = 'MD';
    public string $zip5     = '20770';
    public string $zip4     = '1441';

    public function __construct(public string $userid)
    {
    }

    public function handle(
        string $method,
        $request,
        int|string &$response,
        array &$headers,
        string &$body,
        //bool $bypass_token = false // Token comparing not supported for this endpoint
    ): void
    {
        switch ($method) {
            case 'GET':
                $this->get($request, $response, $headers, $body);
                break;
            case 'HEAD':
                $this->get($request, $response, $headers, $body);
                $body = '';
                break;
            case 'POST':
                $this->post($request, $response, $headers, $body);
                break;
            case 'PUT':
            case 'DELETE':
            case 'PATCH':
            case 'OPTIONS':
            case 'CONNECT':
            default:
                $response = Response::STATUS_METHOD_NOT_ALLOWED;
                $headers = ['Content-Type' => 'text/plain'];
                $body = 'Method Not Allowed';
                break;
        }
    }

    /**
     * Handles a GET request to the USPS endpoint.
     *
     * @param ServerRequestInterface|string $request The incoming request object or a query string.
     * @param int|string &$response The HTTP response status code, passed by reference.
     * @param array &$headers The HTTP response headers, passed by reference.
     * @param string &$body The HTTP response body, passed by reference.
     * @param bool $bypass_token Optional. If true, bypasses token validation. Default is false.
     *
     * @return void
     *
     * The method performs the following steps:
     * - Validates the remote address if token bypass is not enabled.
     * - Extracts query parameters from the request.
     * - Validates required fields ('address2', 'city', 'state') in the query parameters.
     * - Sets additional fields ('zip5', 'zip4') if provided.
     * - Makes an API request and sets the response based on the result.
     *
     * Response Codes:
     * - 401 (Unauthorized): If the remote address is invalid and token bypass is not enabled.
     * - 400 (Bad Request): If required fields are missing or the API request fails.
     * - 200 (OK): If the API request succeeds.
     *
     * Response Headers:
     * - 'Content-Type': Set to 'text/plain' for error responses or 'application/xml; charset=UTF-8' for successful responses.
     *
     * Response Body:
     * - Contains an error message for invalid requests or the API result for successful requests.
     */
    private function get(
        $request,
        int|string &$response,
        array &$headers,
        string &$body,
        bool $bypass_token = false
    ): void
    {
        $remoteAddr = $request instanceof ServerRequestInterface
            ? $request->getServerParams()['REMOTE_ADDR'] ?? ''
            : '';

        if (! $bypass_token && filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $response = Response::STATUS_UNAUTHORIZED;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Unauthorized';
            return;
        }

        $params = $request instanceof ServerRequestInterface
            ? $request->getQueryParams()
            : (is_string($request)
                ? $this->getQueryParams($request)
                : []);

        $requiredFields = ['address2', 'city', 'state'];
        foreach ($requiredFields as $field) {
            if (empty($params[$field])) {
                $response = Response::STATUS_BAD_REQUEST;
                $headers = ['Content-Type' => 'text/plain'];
                $body = "$field is required.";
                return;
            }
            $this->{$field} = $params[$field];
        }

        $this->zip5 = $params['zip5'] ?? '';
        $this->zip4 = $params['zip4'] ?? '';

        if (! $result = $this->apiRequest()) {
            $response = Response::STATUS_BAD_REQUEST;
            $headers = ['Content-Type' => 'text/plain'];
            $body = 'Bad Request';
            return;
        }

        $response = Response::STATUS_OK;
        $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];
        $body = $result;
    }

    /**
     * Handles the HEAD request by setting the response status and headers, and returning the content.
     */
    private function head(
        $request,
        int|string &$response,
        array &$headers,
        string &$body,
        bool $bypass_token = false
    ): void
    {
        $this->get($request, $response, $headers, $body, $bypass_token);
        $body = '';
    }

    /**
     * Sends a POST request by internally calling the `get` method with the provided parameters.
     */
    private function post(
        ServerRequestInterface|string $request,
        int|string &$response,
        array &$headers,
        string &$body,
        bool $bypass_token = false
    ): void
    {
        $this->get($request, $response, $headers, $body, $bypass_token);
    }

    /**
     * Generates an XML string for a USPS ZipCodeLookupRequest.
     *
     * This method constructs an XML representation of address data
     * required for a USPS ZipCodeLookupRequest. The address data is
     * converted to uppercase before being added to the XML structure.
     *
     * @return string The generated XML string.
     *
     * @throws RuntimeException If the XML string generation fails.
     */
    private function __xmlstring(): string
    {
        $addressData = [
            'USERID' => $this->userid,
            'Address' => [
                'ID' => $this->id,
                'Address1' => $this->address1,
                'Address2' => $this->address2,
                'City' => $this->city,
                'State' => $this->state,
                'Zip5' => $this->zip5,
                'Zip4' => $this->zip4,
            ],
        ];
        $addressData['Address'] = array_map('strtoupper', $addressData['Address']);

        $xml = new SimpleXMLElement('<ZipCodeLookupRequest/>');
        $xml->addAttribute('USERID', $addressData['USERID']);
        $address = $xml->addChild('Address');
        $address->addAttribute('ID',   $addressData['Address']['ID']);
        $address->addChild('Address1', $addressData['Address']['Address1']);
        $address->addChild('Address2', $addressData['Address']['Address2']);
        $address->addChild('City',     $addressData['Address']['City']);
        $address->addChild('State',    $addressData['Address']['State']);
        $address->addChild('Zip5',     $addressData['Address']['Zip5']);
        $address->addChild('Zip4',     $addressData['Address']['Zip4']);

        return $xml->asXML()
            ?: throw new RuntimeException('Failed to generate XML string.');
    }

    /**
     * Sends an API request using cURL to the specified URL with the XML string as a parameter.
     *
     * @param bool $associative Determines if the response should be returned as an associative array (not used in the current implementation).
     * @return string|bool The response from the API as a string, or false on failure.
     * @throws \ErrorException If a cURL error occurs during the request.
     */
    private function apiRequest(bool $associative = false): string|bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, SELF::BASE_URL . urlencode($this->__xmlstring()));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        if( ! $response = curl_exec($ch)) trigger_error(curl_error($ch));
        return $response;
    }
}