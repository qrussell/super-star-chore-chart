<?php
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>
.sscc-full-width-page .site-content,
.sscc-full-width-page #content,
.sscc-full-width-page .entry-content,
.sscc-full-width-page .content-area { max-width:100%!important;width:100%!important;padding:0!important;margin:0!important;float:none!important; }
.sscc-full-width-page .widget-area, .sscc-full-width-page #secondary { display:none!important; }
</style>
<script>document.body.classList.add('sscc-full-width-page');</script>
<div id="primary" class="content-area">
  <main id="main" class="site-main">
    <?php while ( have_posts() ) : the_post(); ?>
      <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <div class="entry-content" style="padding:0;max-width:100%;"><?php the_content(); ?></div>
      </article>
    <?php endwhile; ?>
  </main>
</div>
<?php get_footer(); ?>
