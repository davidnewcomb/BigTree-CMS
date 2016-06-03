<?php
	namespace BigTree;
	
	$user = $admin->getUser(end($bigtree["commands"]));
	if (!$user) {
?>
<div class="container">
	<section>
		<h3><?=Text::translate("Error")?></h3>
		<p><?=Text::translate("The user you are trying to emulate no longer exists.")?></p>
	</section>
</div>
<?php
	} else {
		$_SESSION["bigtree_admin"]["id"] = $user["id"];
		$_SESSION["bigtree_admin"]["email"] = $user["email"];
		$_SESSION["bigtree_admin"]["level"] = $user["level"];
		$_SESSION["bigtree_admin"]["name"] = $user["name"];
		$_SESSION["bigtree_admin"]["permissions"] = $user["permissions"];
		Utils::growl("Developer","Emulating ".$user["name"]);
		
		Router::redirect(ADMIN_ROOT);
	}
