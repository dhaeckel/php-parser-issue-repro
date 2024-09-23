<?php

$params = array_merge(
    parent::getAuthorizationParameters($options),
    array_filter([
        'hd'          => $this->hostedDomain,
        'access_type' => $this->accessType,
		'scope'       => $this->scope, // this is indeted by two tabs instead of spaces like the surroundig lines
        // if the user is logged in with more than one account ask which one to use for the login!
        'authuser'    => '-1'
    ])
);
