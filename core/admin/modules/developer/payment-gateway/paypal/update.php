<?php
	namespace BigTree;
	
	$gateway->Service = "paypal";
	$gateway->Settings["paypal-username"] = $_POST["paypal-username"];
	$gateway->Settings["paypal-password"] = $_POST["paypal-password"];
	$gateway->Settings["paypal-signature"] = $_POST["paypal-signature"];
	$gateway->Settings["paypal-environment"] = $_POST["paypal-environment"];
	$gateway->saveSettings();
	
	Utils::growl("Developer","Updated Payment Gateway");
	Router::redirect(DEVELOPER_ROOT);
	