<?php
/**
 * Cryo News Archive — paginated post grid with category filtering
 * Uses URL query parameters (WordPress way) instead of AJAX
 *
 * URL Parameters:
 * - cryo_cat: category slug
 * - cryo_year: year (e.g., 2024)
 * - cryo_search: search term
 * - cryo_paged: page number
 *
 * Usage:
 * - [cryo_news_archive posts_per_page="6"]
 */
add_shortcode('cryo_news_archive', function ($atts): string {
  $atts = shortcode_atts([
    'posts_per_page' => 6,
    'title' => '相關資訊及活動',
    'show_filter' => 'true',
    'class' => '',
  ], (array) $atts, 'cryo_news_archive');

  $posts_per_page = max(1, (int) $atts['posts_per_page']);
  $title = trim((string) $atts['title']);
  $show_filter = strtolower(trim((string) $atts['show_filter'])) !== 'false';
  $class = trim((string) $atts['class']);

  // Read filter values from URL (using WordPress standard: cat=ID)
  $current_cat_id = isset($_GET['cat']) ? (int) $_GET['cat'] : 0;
  $current_year = isset($_GET['cryo_year']) ? (int) $_GET['cryo_year'] : 0;
  $current_search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
  $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

  // Base URL for filters (current page without query params)
  $base_url = strtok($_SERVER['REQUEST_URI'], '?');
  if (empty($base_url)) {
    $base_url = get_permalink();
  }

  // Build query args
  $query_args = [
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => $posts_per_page,
    'paged' => $current_page,
    'orderby' => 'date',
    'order' => 'DESC',
  ];

  // Category filter (using term_id)
  if ($current_cat_id > 0) {
    $query_args['cat'] = $current_cat_id;
  }

  // Year filter
  if ($current_year > 0) {
    $query_args['year'] = $current_year;
  }

  // Search filter
  if ($current_search !== '') {
    $query_args['s'] = $current_search;
  }

  $query = new WP_Query($query_args);

  // Get categories for filter tabs
  $categories = [];
  if ($show_filter) {
    $terms = get_terms([
      'taxonomy' => 'category',
      'hide_empty' => true,
      'orderby' => 'count',
      'order' => 'DESC',
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
      $categories = $terms;
    }
  }

  // Get available years
  global $wpdb;
  $years = $wpdb->get_col("
    SELECT DISTINCT YEAR(post_date) as year 
    FROM $wpdb->posts 
    WHERE post_type = 'post' AND post_status = 'publish' 
    ORDER BY year DESC
  ");

  // Helper to build filter URL (using WordPress standard params: cat, s, paged)
  $build_url = function($cat_id = null, $year = null, $search = null, $page = null) use ($base_url, $current_cat_id, $current_year, $current_search) {
    $params = [];
    
    $cat_val = $cat_id !== null ? $cat_id : $current_cat_id;
    $year_val = $year !== null ? $year : $current_year;
    $search_val = $search !== null ? $search : $current_search;
    $page_val = $page !== null ? $page : 1;
    
    if ($cat_val > 0) $params['cat'] = $cat_val;
    if ($year_val > 0) $params['cryo_year'] = $year_val;
    if ($search_val !== '') $params['s'] = $search_val;
    if ($page_val > 1) $params['paged'] = $page_val;
    
    if (empty($params)) {
      return $base_url;
    }
    return $base_url . '?' . http_build_query($params);
  };

  $html = '';

  // Archive Header
  if ($title !== '') {
    $html .= '<section class="cryo-archiveHeader">';
    $html .= '<div class="cryo-archiveHeader__inner">';
    $html .= '<h1 class="cryo-archiveHeader__title">' . esc_html($title) . '</h1>';
    $html .= '</div>';
    $html .= '</section>';
  }

  // Filter Bar
  if ($show_filter) {
    $html .= '<nav class="cryo-filterBar" aria-label="文章篩選">';
    $html .= '<div class="cryo-filterBar__inner">';

    // Left side: Category tabs (as links)
    $html .= '<div class="cryo-filterBar__left">';
    $html .= '<div class="cryo-filterBar__tabs">';

    // "All" tab
    $all_active = $current_cat_id === 0 ? ' cryo-filterBar__tab--active' : '';
    $all_url = $build_url(0, null, null, 1);
    $html .= '<a class="cryo-filterBar__tab' . $all_active . '" href="' . esc_url($all_url) . '">';
    $html .= '全部';
    $html .= '<span class="cryo-filterBar__count">' . (int) wp_count_posts('post')->publish . '</span>';
    $html .= '</a>';

    // Category tabs (using term_id for URL, WordPress standard)
    foreach ($categories as $cat) {
      $is_active = ($current_cat_id === (int) $cat->term_id) ? ' cryo-filterBar__tab--active' : '';
      $cat_url = $build_url((int) $cat->term_id, null, null, 1);
      $html .= '<a class="cryo-filterBar__tab' . $is_active . '" href="' . esc_url($cat_url) . '">';
      $html .= esc_html($cat->name);
      $html .= '<span class="cryo-filterBar__count">' . (int) $cat->count . '</span>';
      $html .= '</a>';
    }

    $html .= '</div>'; // .cryo-filterBar__tabs
    $html .= '</div>'; // .cryo-filterBar__left

    // Right side: Year filter + Search (as form)
    $html .= '<div class="cryo-filterBar__right">';
    $html .= '<form class="cryo-filterBar__form" method="get" action="' . esc_url($base_url) . '">';
    
    // Preserve current category (using WordPress standard: cat=ID)
    if ($current_cat_id > 0) {
      $html .= '<input type="hidden" name="cat" value="' . esc_attr($current_cat_id) . '" />';
    }

    // Year filter dropdown
    if (!empty($years)) {
      $html .= '<div class="cryo-filterBar__yearFilter">';
      $html .= '<select class="cryo-filterBar__select" name="cryo_year" onchange="this.form.submit()">';
      $html .= '<option value="">全部年份</option>';
      foreach ($years as $year) {
        $selected = ($current_year === (int) $year) ? ' selected' : '';
        $html .= '<option value="' . esc_attr($year) . '"' . $selected . '>' . esc_html($year) . '</option>';
      }
      $html .= '</select>';
      $html .= '</div>';
    }

    // Search input (using WordPress standard: s)
    $html .= '<div class="cryo-filterBar__search">';
    $html .= '<svg class="cryo-filterBar__searchIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>';
    $html .= '<input type="text" class="cryo-filterBar__searchInput" name="s" value="' . esc_attr($current_search) . '" placeholder="搜尋文章..." />';
    $html .= '</div>';

    $html .= '</form>';
    $html .= '</div>'; // .cryo-filterBar__right

    $html .= '</div>'; // .cryo-filterBar__inner
    $html .= '</nav>';
  }

  // Post Grid
  $grid_class = 'cryo-postGrid';
  if ($class !== '') {
    $grid_class .= ' ' . esc_attr($class);
  }

  $html .= '<section class="' . $grid_class . '">';
  $html .= '<div class="cryo-postGrid__inner">';

  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $html .= cryo_render_archive_post_card(get_post());
    }
    wp_reset_postdata();
  } else {
    $html .= '<div class="cryo-postGrid__empty"><p>暫無相關文章</p></div>';
  }

  $html .= '</div>';

  // Pagination
  if ($query->max_num_pages > 1) {
    $html .= '<div class="cryo-postGrid__pagination">';
    
    // Previous page
    if ($current_page > 1) {
      $prev_url = $build_url(null, null, null, $current_page - 1);
      $html .= '<a class="cryo-postGrid__pageBtn cryo-postGrid__pageBtn--prev" href="' . esc_url($prev_url) . '">上一頁</a>';
    }
    
    // Page numbers
    $html .= '<span class="cryo-postGrid__pageInfo">第 ' . $current_page . ' / ' . $query->max_num_pages . ' 頁</span>';
    
    // Next page
    if ($current_page < $query->max_num_pages) {
      $next_url = $build_url(null, null, null, $current_page + 1);
      $html .= '<a class="cryo-postGrid__pageBtn cryo-postGrid__pageBtn--next" href="' . esc_url($next_url) . '">下一頁</a>';
    }
    
    $html .= '</div>';
  }

  $html .= '</section>';

  return $html;
});

/**
 * Helper: Render a post card for the archive grid
 */
if (!function_exists('cryo_render_archive_post_card')) {
  function cryo_render_archive_post_card($post): string {
    if (!$post) return '';

    $title = get_the_title($post);
    $permalink = get_permalink($post);
    $date = get_the_date('Y.m.d', $post);
    $excerpt = get_the_excerpt($post);

    // Category
    $category_html = '';
    $terms = get_the_terms($post, 'category');
    if (!is_wp_error($terms) && !empty($terms)) {
      $term = $terms[0];
      $tag_modifier = function_exists('cryo_get_tag_modifier') ? cryo_get_tag_modifier($term->name) : '';
      $tag_class = 'cryo-postCard__category';
      if ($tag_modifier !== '') {
        $tag_class .= ' cryo-postCard__category--' . esc_attr($tag_modifier);
      }
      $category_html = '<span class="' . $tag_class . '">' . esc_html($term->name) . '</span>';
    }

    // Featured image
    $img_html = '';
    $thumb_id = (int) get_post_thumbnail_id($post);

    // Fallback: Uncode2 featured media
    if ($thumb_id <= 0) {
      $uncode_featured = trim((string) get_post_meta($post->ID, '_uncode_featured_media', true));
      if ($uncode_featured !== '') {
        $ids = array_filter(array_map('trim', explode(',', $uncode_featured)));
        foreach ($ids as $id) {
          $id = (int) $id;
          if ($id > 0 && wp_attachment_is_image($id)) {
            $thumb_id = $id;
            break;
          }
        }
      }
    }

    if ($thumb_id > 0 && wp_attachment_is_image($thumb_id)) {
      $src = wp_get_attachment_image_url($thumb_id, 'medium_large');
      $alt = (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
      if ($alt === '') {
        $alt = $title;
      }
      if ($src) {
        $img_html = '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
      }
    }

    if ($img_html === '') {
      $img_html = '<div class="cryo-postCard__placeholder" role="img" aria-label="' . esc_attr($title) . '"></div>';
    }

    // Build card HTML
    $html = '<article class="cryo-postCard">';
    $html .= '<a class="cryo-postCard__link" href="' . esc_url($permalink) . '">';

    // Content section
    $html .= '<div class="cryo-postCard__content">';
    $html .= '<div class="cryo-postCard__meta">';
    $html .= $category_html;
    $html .= '<span class="cryo-postCard__date">' . esc_html($date) . '</span>';
    $html .= '</div>';
    $html .= '<h3 class="cryo-postCard__title">' . esc_html($title) . '</h3>';
    if ($excerpt) {
      $html .= '<p class="cryo-postCard__excerpt">' . esc_html(wp_trim_words($excerpt, 30, '...')) . '</p>';
    }
    $html .= '</div>';

    // Media section
    $html .= '<div class="cryo-postCard__media">';
    $html .= $img_html;
    $html .= '</div>';

    $html .= '</a>';
    $html .= '</article>';

    return $html;
  }
}
