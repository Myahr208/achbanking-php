<?php

class OAClient extends OAAPIBase
{
    protected $sessionId;
    protected $curlHandle;
    protected $rawResponse;
    protected $debug = true;
    protected $config;
    protected $response;

    public function connect($apiToken = '', $apiKey = '', $endpointUrl = '', $portNumber = '')
    {
        if ($apiToken) {
            $this->config->apiToken = $apiToken;
        }

        if ($apiKey) {
            $this->config->apiKey = $apiKey;
        }

        if ($endpointUrl) {
            $this->config->endpointUrl = $endpointUrl;
        }

        if ($portNumber) {
            $this->config->portNumber = $portNumber;
        }

        $connectRequest = new OAConnectRequest();
        $connectRequest->user_api_token = $this->config->apiToken;
        $connectRequest->user_api_key = $this->config->apiKey;

        $response = $this->sendRequest($connectRequest);

        $body = json_decode($response->body, false);

        if ($response->success) {
            $this->sessionId = $response->session_id;
        } else {
            $this->sessionId = null;

            throw new RuntimeException($body->error);
        }

        return $response;
    }

    public function disconnect()
    {
        $disconnectRequest = new OADisconnectRequest();
        $response = $this->sendRequest($disconnectRequest);

        if ($response->success) {
            curl_close($this->curlHandle);
        }

        return $response;
    }

    public function sendRequest(OAAPIRequest $request)
    {
        $params = $request->getParams();
        $connectUrl = $this->config->endpointUrl . $request->action;

        $fieldPairs = [];
        foreach ($params as $key => $value) {
            $fieldPairs[] = $key . '=' . urlencode($value);
        }
        $queryString = implode('&', $fieldPairs);

        $this->curlHandle = curl_init();

        // Set the URL, POST var count, and POST data
        $options = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_TIMEOUT => 50,
            CURLOPT_URL => $connectUrl,
            CURLOPT_POST => count($params),
            CURLOPT_POSTFIELDS => $queryString,
            CURLOPT_PORT => $this->config->portNumber,
        ];

        if ($this->sessionId) {
            $options[CURLOPT_COOKIE] = 'PHPSESSID=' . $this->sessionId;
        }

        curl_setopt_array($this->curlHandle, $options);

        $this->response = $this->execCurl();

        if ($this->debug && !$this->response->success) {
            $this->response->postFields = $queryString;
        }

        $body = json_decode($this->response->body, false);

        if (isset($body->success)) {
            $this->response->success = $this->response->success && $body->success;
        }

        return $this->response;
    }

    protected function execCurl()
    {
        $this->rawResponse = curl_exec($this->curlHandle);

        $error = curl_error($this->curlHandle);

        $response = new OAAPIResponse();

        if ($error) {
            $response->error = $error;

            return $response;
        }

        $response->body = $this->rawResponse;
        $response->httpCode = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
        $response->lastUrl = curl_getinfo($this->curlHandle, CURLINFO_EFFECTIVE_URL);

        if ($jsonResult = json_decode($response->body, false)) {
            if ($jsonResult instanceof stdClass) {
                $response->merge($jsonResult);
            } else {
                $response->setRecords($jsonResult);
            }
            $response->success = true;
        } else {
            $response->error = 'Unable to json_decode the response from the server.';
        }

        return $response;
    }
}
