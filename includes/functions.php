<?php 
class ShipStream_Sync_Helper_Api {
    /**
     * Check if the warehouse API is configured.
     *
     * @return bool
     */
    public static function isConfigured() {
        return !empty(get_option('shipstream_warehouse_api_url'));
    }

    /**
     * Perform a request to the warehouse API.
     *
     * @param string $method The API method to call.
     * @param array $data The data to send with the request.
     * @return mixed The response from the API.
     * @throws Exception If the API URL is not configured or the request fails.
     */
    public static function callback($method, $data = []) {
        $apiUrl = get_option('shipstream_warehouse_api_url');
        if (empty($apiUrl)) {
            throw new Exception('The warehouse API URL is required.');
        }
        $apiUrl = urldecode($apiUrl);
        if (false === strpos($apiUrl, '{{method}}')) {
            throw new Exception('The warehouse API URL format is not valid.');
        }
        $apiUrl = str_replace('{{method}}', $method, $apiUrl);

        $ch = self::_curlInit($apiUrl, 'GET', $data);
        $data = self::_curlExec($ch);

        return $data;
    }

    /**
     * Execute a cURL session.
     *
     * @param resource $ch The cURL handle.
     * @return array The response data.
     * @throws Exception If the request fails or the response is invalid.
     */
    protected static function _curlExec($ch) {
        if (false === ($response = curl_exec($ch))) {
            $e = new Exception('Response error code: "' . curl_errno($ch) . '". Error description: "' . curl_error($ch) . '".');
            curl_close($ch);
            throw $e;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Log the response details

        if (200 !== $httpCode && 201 !== $httpCode) {
            $exceptionMessage = "Warehouse API Error ($httpCode).";
            $data = json_decode($response, true);
            if (json_last_error() == JSON_ERROR_NONE) {
                if (!empty($data['errors'])) {
                    $errors = $data['errors'];
                    if (is_array($errors)) {
                        $exceptionMessage .= ': "' . implode('"; "', $errors) . '"';
                    } else {
                        $exceptionMessage .= $errors;
                    }
                }
            } else if (!empty($response)) {
                $exceptionMessage .= ' Response: "' . $response . '"';
            }
            throw new Exception($exceptionMessage, $httpCode);
        }

        $data = json_decode($response, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            $error = 'An error occurred while decoding JSON encoded string.';
            throw new Exception($error);
        }

        return $data;
    }

    /**
     * Initialize a cURL session.
     *
     * @param string $url The URL to request.
     * @param string $type The HTTP method (GET, POST, PUT, DELETE).
     * @param array $data The data to send with the request.
     * @return resource The cURL handle.
     * @throws Exception If cURL is not installed or the request type is invalid.
     */
    protected static function _curlInit($url, $type, array $data = []) {
        if (!function_exists('curl_version')) {
            throw new Exception('cURL is not installed.');
        }
        $type = strtoupper($type);
        if (!in_array($type, ['GET', 'POST', 'PUT', 'DELETE'])) {
            throw new Exception('Invalid custom request type.');
        }
        if (in_array($type, ['GET', 'DELETE']) && $data) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . http_build_query($data, '', '&');
        }
        $ch = curl_init($url);
        $header = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if (in_array($type, ['PUT', 'POST'])) {
            $json = empty($data) ? '{}' : json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $header[] = 'Content-Length: ' . strlen($json);
        }
        if (in_array($type, ['PUT', 'DELETE'])) {
            $header[] = 'X-HTTP-Method-Override: ' . $type;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true); // Enable request header output

        return $ch;
    }
}
