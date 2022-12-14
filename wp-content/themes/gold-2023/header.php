<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo wp_get_document_title(); ?></title>

    <meta name='description' content="This is the Gold Collective website.">
    <meta property="og:image" content=" <?php echo get_template_directory_uri() . '/images/meta/meta.png' ?>">
    <script src="//use.typekit.net/zoz2roa.js" async=""></script>
    <?php
    wp_head();
    // WP function to include any styles, scripts, or things before* page load
    ?>
</head>

<body <?php body_class(); ?>>

    <header class='site-header'>
        <inner-column>
            <logo class="gold-collective">

                <?php include('wp-content/themes/gold-2023/images/logo.php'); ?>

            </logo>

            <button class='hamburger-button' id='toggle' rel='hamburger'>
            </button>

            <?php include('templates/modules/site-menu/template.php') ?>

        </inner-column>
    </header>

    <?php
    if (!is_page('home')) { ?>

        <style>
            @media(min-width:700px) {
                main {
                    margin-top: 96px;
                }
            }

            @media(min-width: 912px) {

                .my_extra_menu_class {

                    color: var(--ink);
                }
            }
        </style>

    <?php } ?>

    <?php if (is_page('home')) { ?>

        <style>
            @media(min-width:700px) {
                header.site-header .gold-collective {
                    fill: var(--page);
                }
            }
        </style>

    <?php } ?>

    <main class="page-content">