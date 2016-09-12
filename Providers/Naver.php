<?php
/**
 * Copyright (c) 2014 Team TamedBitches.
 * Written by Chuck JS. Oh <jinseokoh@hotmail.com>
 * http://facebook.com/chuckoh
 *
 * Date: 11 11, 2014
 * Time: 11:38 AM
 *
 * This program is free software. It comes without any warranty, to
 * the extent permitted by applicable law. You can redistribute it
 * and/or modify it under the terms of the Do What The Fuck You Want
 * To Public License, Version 2, as published by Sam Hocevar. See
 * http://www.wtfpl.net/txt/copying/ for more details. 
 *
 */
class Hybrid_Providers_Naver extends Hybrid_Provider_Model_OAuth2 {

    /**
     * initialization
     */
    function initialize() 
    {
        parent::initialize();

        // Provider API end-points
        $this->api->api_base_url = "https://openapi.naver.com";
        $this->api->authorize_url = "https://nid.naver.com/oauth2.0/authorize";
        $this->api->token_url = "https://nid.naver.com/oauth2.0/token";
    }

    /**
     * begin login step 
     */
    function loginBegin()
    {
        // 상태 토큰으로 사용할 랜덤 문자열을 생성
        $token = $this->generate_state_token();

        // 세션 또는 별도의 저장 공간에 상태 토큰을 저장
        Hybrid_Auth::storage()->set("naver_state_token", $token);

        $parameters = array(
            "client_id" => $this->api->client_id,
            "response_type" => "code",
            "redirect_uri" => $this->api->redirect_uri,
            "state" => $token,
        );

        Hybrid_Auth::redirect($this->api->authorizeUrl($parameters));
    }

    /**
     * finish login step 
     */
    function loginFinish()
    {
        $error = (array_key_exists('error', $_REQUEST)) ? $_REQUEST['error'] : "";

        // check for errors
        if ($error) {
            throw new Exception("Authentication failed! {$this->providerId} returned an error: $error", 5);
        }

        // try to authenicate user
        $code = (array_key_exists('code', $_REQUEST)) ? $_REQUEST['code'] : "";

        try {
            $this->authenticate($code);
        } catch(Exception $e) {
            throw new Exception("User profile request failed! {$this->providerId} returned an error: $e", 6);
        }

        // check if authenticated
        if (! $this->api->access_token) {
            throw new Exception("Authentication failed! {$this->providerId} returned an invalid access token.", 5);
        }

        // store tokens
        $this->token("access_token", $this->api->access_token);
        $this->token("refresh_token", $this->api->refresh_token);
        $this->token("expires_in", $this->api->access_token_expires_in);
        $this->token("expires_at", $this->api->access_token_expires_at);

        // set user connected locally
        $this->setUserConnected();
    }

    /**
     * load the user profile
     */
    function getUserProfile()
    {
        $response = $this->api->api("/v1/nid/me");
        $data = array();

        if ($response->resultcode == '00') {
            $data = (array)$response->response;
        } else {
            throw new Exception("User profile request failed! {$this->providerId} returned an invalid response.", 6);
        }

        # store the user profile.
        $this->user->profile->identifier = (array_key_exists('enc_id', $data)) ? $data['enc_id'] : "";
        $this->user->profile->age = (array_key_exists('age', $data)) ? $data['age'] : "";
        $this->user->profile->displayName = (array_key_exists('nickname', $data)) ? $data['nickname'] : "";
        $this->user->profile->lastName = (array_key_exists('name', $data)) ? $data['name'] : "";
        $this->user->profile->birthDay = (array_key_exists('birthday', $data)) ? explode("-", $data['birthday'])[1] : "";
        $this->user->profile->birthMonth = (array_key_exists('birthday', $data)) ? explode("-", $data['birthday'])[0] : "";
        $this->user->profile->email = (array_key_exists('email', $data)) ? $data['email'] : "";
        $this->user->profile->emailVerified = (array_key_exists('email', $data)) ? $data['email'] : "";
        $this->user->profile->gender = (array_key_exists('gender', $data)) ? (($data['gender'] == "M") ? "male" : "female") : "";
        $this->user->profile->photoURL = (array_key_exists('profile_image', $data)) ? $data['profile_image'] : "";

        return $this->user->profile;
    }

    private function authenticate($code)
    {
        // 세션 또는 별도의 저장 공간에서 상태 토큰을 가져옴
        $token = Hybrid_Auth::storage()->get("naver_state_token");
        $params = array(
            "client_id" => $this->api->client_id,
            "client_secret" => $this->api->client_secret,
            "grant_type" => "authorization_code",
            "state" => $token,
            "code" => $code,
        );

        Hybrid_Auth::storage()->set("naver_state_token", null);

        $response = $this->request($this->api->token_url, $params, $this->api->curl_authenticate_method);
        $response = $this->parseRequestResult($response);

        if (! $response || ! isset($response->access_token)) {
            throw new Exception("The Authorization Service has return: " . $response->error);
        }

        if (isset($response->access_token)) $this->api->access_token = $response->access_token;
        if (isset($response->refresh_token)) $this->api->refresh_token = $response->refresh_token;
        if (isset($response->expires_in)) $this->api->access_token_expires_in = $response->expires_in;

        // calculate when the access token expire
        if (isset($response->expires_in)) {
            $this->api->access_token_expires_at = time() + $response->expires_in;
        }

        return $response;
    }

    private function request($url, $params = false, $type = "GET")
    {
        Hybrid_Logger::info("Enter OAuth2Client::request( $url )");
        Hybrid_Logger::debug("OAuth2Client::request(). dump request params: ", serialize($params));

        $this->http_info = array();
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->api->curl_time_out);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->api->curl_useragent);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->api->curl_connect_time_out);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->api->curl_ssl_verifypeer);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->api->curl_header);

        if ($this->api->curl_proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->curl_proxy);
        }

        if ($type == "POST") {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($params) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        Hybrid_Logger::debug("OAuth2Client::request(). dump request info: ", serialize(curl_getinfo($ch)));
        Hybrid_Logger::debug("OAuth2Client::request(). dump request result: ", serialize($response));
        $this->http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->http_info = array_merge($this->http_info, curl_getinfo($ch));
        curl_close ($ch);

        return $response;
    }

    private function parseRequestResult($result)
    {
        if (json_decode($result)) return json_decode($result);
        parse_str($result, $ouput);
        $result = new StdClass();
        foreach ($ouput as $k => $v) {
            $result->$k = $v;
        }

        return $result;
    }

    // CSRF 방지를 위한 상태 토큰 생성 코드
    // 상태 토큰은 추후 검증을 위해 세션에 저장되어야 한다.
    private function generate_state_token()
    {
        $mt = microtime();
        $rand = mt_rand();

        return md5($mt . $rand);
    }
    
}
