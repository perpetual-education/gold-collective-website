<?php
$heading = get_sub_field('heading');
$intro = get_sub_field('intro');

$textBlock = get_sub_field('generic_wysiwyg');

$groupOne = get_sub_field('image_one');
$groupTwo = get_sub_field('image_two');
$groupThree = get_sub_field('image_three');

// $titleOne = $groupOne['title'];
$imageOne = $groupOne['image']['url'] ?? null;
$altTextOne = $groupOne['alt_text'] ?? null;
$hasDescriptionOne = $groupOne['has_description'] ?? null;
$descriptionOne = $groupOne['description'] ?? null;

// $titleTwo = $groupTwo['title'];
$imageTwo = $groupTwo['image']['url'] ?? null;
$altTextTwo = $groupTwo['alt_text'] ?? null;
$hasDescriptionTwo = $groupTwo['has_description'] ?? null;
$descriptionTwo = $groupTwo['description'] ?? null;

// $titleThree = $groupThree['title'];
$imageThree = $groupThree['image']['url'] ?? null;
$altTextThree = $groupThree['alt_text'] ?? null;
$hasDescriptionThree = $groupThree['has_description'] ?? null;
$descriptionThree = $groupThree['description'] ?? null;
?>

<module-eleven class="base-template">
	<div class="module-text">
		<h2><?= $heading ?></h2>
		<text-content><?= $intro ?></text-content>
	</div>
	
	<?php include(getFile("templates/partials/figures.php")) ?>
	
</module-eleven>