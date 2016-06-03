<?php
	namespace BigTree;

	/**
	 * @global array $bigtree
	 */

	$module = end($bigtree["path"]);
	$title = $_POST["title"];

	$form = ModuleForm::create(
		$module,
		$title,
		$_POST["table"],
		$_POST["fields"],
		$_POST["hooks"],
		$_POST["default_position"],
		$_POST["return_view"],
		$_POST["return_url"],
		$_POST["tagging"]
	);

	// See if add/edit actions already exist
	$add_route = "add";
	$edit_route = "edit";

	// If we already have add/edit routes, get unique new ones for this form
	if (ModuleAction::exists($module, "add") || ModuleAction::exists($module, "edit")) {
		$add_route = SQL::unique("bigtree_module_actions", "route", Link::urlify("add $title"), array("module" => $module), true);
		$edit_route = SQL::unique("bigtree_module_actions", "route", Link::urlify("edit $title"), array("module" => $module), true);
	}

	// Create actions for the form
	ModuleAction::create($module, "Add $title", $add_route, "on", "add", $form->ID);
	ModuleAction::create($module, "Edit $title", $edit_route, "", "edit", $form->ID);

	Utils::growl("Developer", "Created Module Form");
	Router::redirect(DEVELOPER_ROOT."modules/edit/$module/");
	