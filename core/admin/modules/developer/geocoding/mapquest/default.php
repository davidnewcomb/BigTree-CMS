<?php
	namespace BigTree;

	$geocoding_service = $cms->getSetting("bigtree-internal-geocoding-service");
?>
<div class="container">
	<form method="post" action="<?=DEVELOPER_ROOT?>geocoding/mapquest/activate/">
		<section>
			<p><?=Text::translate('To use the MapQuest Geocoder API you will need a MapQuest AppKey. To acquire a key, please reference <a href=":mq_link:" target="_blank">MapQuest Geocoding API Web Service</a> at MapQuest Developers.', false, array(":mq_link:" => "http://developer.mapquest.com/web/products/dev-services/geocoding-ws"))?></p>
			<hr />
			<fieldset>
				<label><?=Text::translate("MapQuest AppKey / Consumer Key")?></label>
				<input type="text" name="mapquest_key" value="<?=htmlspecialchars($geocoding_service["mapquest_key"])?>" />
			</fieldset>
		</section>
		<footer>
			<input type="submit" class="button blue" value="<?=Text::translate("Activate MapQuest Geocoder", true)?>" />
		</footer>
	</form>
</div>