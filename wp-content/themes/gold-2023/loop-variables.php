4<?php
// Reminder: "get_" returns, "the_" echoes

$heading = get_sub_field('heading');
$intro = get_sub_field('intro');

$link = get_sub_field('link');
if($link) {
	$link_url = $link['url'];
	$link_title = $link['title'];
	// $link_target = $link['target'];
}

// $imageOne = get_sub_field('image_1')['url'] ?? null;
// $altTextOne = get_sub_field('alttext_1');
// $captionOne = get_sub_field('caption_1');

// $imageTwo = get_sub_field('image_2')['url'] ?? null;
// $altTextTwo = get_sub_field('alttext_2');
// $captionTwo = get_sub_field('caption_2');

// $imageThree = get_sub_field('image_3')['url'] ?? null;
// $altTextThree = get_sub_field('alttext_3');
// $captionThree = get_sub_field('caption_3');

$textBlock = get_sub_field('generic_wysiwyg');

$groupOne = get_sub_field('image_one');
$groupTwo = get_sub_field('image_two') ?? null;
$groupThree = get_sub_field('image_three') ?? null;

// $titleOne = $groupOne['title'];
$imageOne = $groupOne['image']['url'];
$altTextOne = $groupOne['alt_text'];
$descriptionOne = $groupOne['description'];

// $titleTwo = $groupTwo['title'];
$imageTwo = $groupTwo['image']['url'];
$altTextTwo = $groupTwo['alt_text'];
$descriptionTwo = $groupTwo['description'];

// $titleThree = $groupThree['title'];
$imageThree = $groupThree['image']['url'];
$altTextThree = $groupThree['alt_text'];
$descriptionThree = $groupThree['description'];