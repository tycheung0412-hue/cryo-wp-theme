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

  // Category filter (using tax_query with include_children=false for consistency)
  if ($current_cat_id > 0) {
    $query_args['tax_query'] = [
      [
        'taxonomy' => 'category',
        'field' => 'term_id',
        'terms' => $current_cat_id,
        'include_children' => false, // Only show posts DIRECTLY in this category
      ],
    ];
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

  // Get categories for filter tabs with accurate published post counts
  // Using tax_query with include_children=false to count ONLY posts directly in that category
  $categories = [];
  if ($show_filter) {
    $terms = get_terms([
      'taxonomy' => 'category',
      'hide_empty' => true,
      'orderby' => 'count',
      'order' => 'DESC',
    ]);
    if (!is_wp_error($terms) && !empty($terms)) {
      // Get accurate count of published posts per category (excluding child categories)
      foreach ($terms as $term) {
        $count_query = new WP_Query([
          'post_type' => 'post',
          'post_status' => 'publish',
          'tax_query' => [
            [
              'taxonomy' => 'category',
              'field' => 'term_id',
              'terms' => $term->term_id,
              'include_children' => false, // Only count posts DIRECTLY in this category
            ],
          ],
          'posts_per_page' => 1,
          'fields' => 'ids',
          'no_found_rows' => false,
        ]);
        $term->published_count = (int) $count_query->found_posts;
        wp_reset_postdata();
      }
      // Filter out categories with 0 published posts and re-sort
      $categories = array_filter($terms, function($t) {
        return $t->published_count > 0;
      });
      usort($categories, function($a, $b) {
        return $b->published_count - $a->published_count;
      });
    }
  }
  
  // Get total published posts count
  $total_published = (int) wp_count_posts('post')->publish;

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
    $html .= '<span class="cryo-filterBar__count">' . $total_published . '</span>';
    $html .= '</a>';

    // Category tabs (using term_id for URL, with accurate published post counts)
    foreach ($categories as $cat) {
      $is_active = ($current_cat_id === (int) $cat->term_id) ? ' cryo-filterBar__tab--active' : '';
      $cat_url = $build_url((int) $cat->term_id, null, null, 1);
      $html .= '<a class="cryo-filterBar__tab' . $is_active . '" href="' . esc_url($cat_url) . '">';
      $html .= esc_html($cat->name);
      $html .= '<span class="cryo-filterBar__count">' . $cat->published_count . '</span>';
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
      // Pass the current filter category ID so it can be highlighted/shown first
      $html .= cryo_render_archive_post_card(get_post(), $current_cat_id);
    }
    wp_reset_postdata();
  } else {
    $html .= '<div class="cryo-postGrid__empty"><p>暫無相關文章</p></div>';
  }

  $html .= '</div>';

  // Load More (AJAX)
  if ($query->max_num_pages > 1) {
    $html .= '<div style="display:flex;justify-content:center;margin-top:48px;padding:24px 0;">';
    $html .= '<span role="button" tabindex="0" ';
    $html .= 'style="display:inline-block;padding:20px 80px;font-size:16px;font-weight:400;color:#333;background:#fff;border:1px solid #d0d0d0;border-radius:999px;cursor:pointer;user-select:none;" ';
    $html .= 'data-load-more ';
    $html .= 'data-page="1" ';
    $html .= 'data-max-pages="' . esc_attr($query->max_num_pages) . '" ';
    $html .= 'data-per-page="' . esc_attr($posts_per_page) . '" ';
    $html .= 'data-cat="' . esc_attr($current_cat_id) . '" ';
    $html .= 'data-year="' . esc_attr($current_year) . '" ';
    $html .= 'data-search="' . esc_attr($current_search) . '">';
    $html .= 'Load More';
    $html .= '</span>';
    $html .= '</div>';
  }

  $html .= '</section>';

  return $html;
});

/**
 * Helper: Render a post card for the archive grid
 * @param WP_Post $post The post object
 * @param int $highlight_cat_id Optional category ID to highlight/show first (the active filter)
 */
if (!function_exists('cryo_render_archive_post_card')) {
  function cryo_render_archive_post_card($post, $highlight_cat_id = 0): string {
    if (!$post) return '';

    $title = get_the_title($post);
    $permalink = get_permalink($post);
    $date = get_the_date('Y.m.d', $post);
    $excerpt = get_the_excerpt($post);

    // Categories (render ALL assigned categories, with filtered category first)
    $category_html = '';
    $terms = get_the_terms($post, 'category');
    if (!is_wp_error($terms) && !empty($terms)) {
      // If we have a highlight category, sort it to the front
      if ($highlight_cat_id > 0) {
        usort($terms, function($a, $b) use ($highlight_cat_id) {
          if ((int)$a->term_id === $highlight_cat_id) return -1;
          if ((int)$b->term_id === $highlight_cat_id) return 1;
          return 0;
        });
      }
      
      $category_tags = [];
      foreach ($terms as $term) {
        // Skip "Uncategorized" / "未分類" if there are other categories
        if ($term->slug === 'uncategorized' && count($terms) > 1) {
          continue;
        }
        $tag_modifier = function_exists('cryo_get_tag_modifier') ? cryo_get_tag_modifier($term->name) : '';
        $tag_class = 'cryo-postCard__category';
        
        // Highlight the filtered category
        if ($highlight_cat_id > 0 && (int)$term->term_id === $highlight_cat_id) {
          $tag_class .= ' cryo-postCard__category--active';
        }
        
        if ($tag_modifier !== '') {
          $tag_class .= ' cryo-postCard__category--' . esc_attr($tag_modifier);
        }
        $category_tags[] = '<span class="' . $tag_class . '">' . esc_html($term->name) . '</span>';
      }
      $category_html = implode('', $category_tags);
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

    // Build card HTML - Order: Image → Tag+Date → Title (matches design)
    $html = '<article class="cryo-postCard">';
    $html .= '<a class="cryo-postCard__link" href="' . esc_url($permalink) . '">';

    // Media section (image first)
    $html .= '<div class="cryo-postCard__media">';
    $html .= $img_html;
    $html .= '</div>';

    // Content section (meta + title after image)
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

    $html .= '</a>';
    $html .= '</article>';

    return $html;
  }
}

/**
 * REST API endpoint for "Load More" posts
 */
add_action('rest_api_init', function () {
  register_rest_route('cryo/v1', '/load-more-posts', [
    'methods' => 'GET',
    'callback' => 'cryo_rest_load_more_posts',
    'permission_callback' => '__return_true',
    'args' => [
      'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
      'per_page' => ['default' => 6, 'sanitize_callback' => 'absint'],
      'cat' => ['default' => 0, 'sanitize_callback' => 'absint'],
      'year' => ['default' => 0, 'sanitize_callback' => 'absint'],
      'search' => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
    ],
  ]);
});

function cryo_rest_load_more_posts($request) {
  $page = max(1, (int) $request->get_param('page'));
  $per_page = max(1, min(50, (int) $request->get_param('per_page')));
  $cat_id = (int) $request->get_param('cat');
  $year = (int) $request->get_param('year');
  $search = trim((string) $request->get_param('search'));

  $query_args = [
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => $per_page,
    'paged' => $page,
    'orderby' => 'date',
    'order' => 'DESC',
  ];

  // Category filter
  if ($cat_id > 0) {
    $query_args['tax_query'] = [
      [
        'taxonomy' => 'category',
        'field' => 'term_id',
        'terms' => $cat_id,
        'include_children' => false,
      ],
    ];
  }

  // Year filter
  if ($year > 0) {
    $query_args['year'] = $year;
  }

  // Search filter
  if ($search !== '') {
    $query_args['s'] = $search;
  }

  $query = new WP_Query($query_args);

  $html = '';
  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $html .= cryo_render_archive_post_card(get_post(), $cat_id);
    }
    wp_reset_postdata();
  }

  return [
    'success' => true,
    'html' => $html,
    'has_more' => $page < $query->max_num_pages,
    'current_page' => $page,
    'max_pages' => (int) $query->max_num_pages,
  ];
}

/**
 * Enqueue REST URL for Load More
 */
add_action('wp_enqueue_scripts', function () {
  wp_localize_script('cryo-main', 'cryoLoadMore', [
    'restUrl' => rest_url('cryo/v1/load-more-posts'),
    'nonce' => wp_create_nonce('wp_rest'),
  ]);
}, 20);
