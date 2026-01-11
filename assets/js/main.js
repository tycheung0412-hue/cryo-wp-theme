// Desktop navigation behavior: overflow → ⋮ menu
document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('.cryo-nav[data-cryo-nav]');
  if (!root) return;

  const isDesktop = () => {
    if (typeof window.matchMedia !== 'function') return true; // safe default: desktop-first
    return window.matchMedia('(min-width: 1024px)').matches;
  };

  // WP can output multiple `ul.wp-block-navigation__container` in some cases; pick the real primary menu:
  const navRoot = root.querySelector('.cryo-nav__primaryNav');
  const primaryNav = (() => {
    if (!navRoot) return null;
    const lists = Array.from(navRoot.querySelectorAll('ul.wp-block-navigation__container'));
    if (lists.length === 0) return null;
    // choose the list with the most top-level items
    lists.sort((a, b) => {
      const ac = a.querySelectorAll(':scope > li.wp-block-navigation-item').length;
      const bc = b.querySelectorAll(':scope > li.wp-block-navigation-item').length;
      return bc - ac;
    });
    return lists[0];
  })();
  const moreWrap = root.querySelector('[data-cryo-more]');
  const moreBtn = root.querySelector('.cryo-nav__moreBtn');
  const morePanel = root.querySelector('.cryo-nav__morePanel');
  const moreList = root.querySelector('.cryo-nav__moreList');

  if (!primaryNav || !moreWrap || !moreBtn || !morePanel || !moreList) return;

  // Move injected WooCommerce header icons (if present) into the right cluster.
  // Some installs place these blocks near the Navigation block; we always want them on the right.
  const rightCluster = root.querySelector('.cryo-nav__right');
  if (rightCluster) {
    const injectedAccount = root.querySelector('.wp-block-woocommerce-customer-account');
    const injectedCart = root.querySelector('.wp-block-woocommerce-mini-cart');
    if (injectedAccount) rightCluster.insertBefore(injectedAccount, rightCluster.firstChild);
    if (injectedCart) rightCluster.insertBefore(injectedCart, rightCluster.firstChild);
  }

  // Helper: move any existing overflow items back into the primary nav (before recalculating)
  const resetOverflow = () => {
    const overflowItems = Array.from(moreList.querySelectorAll('[data-cryo-overflow-item]'));
    overflowItems.forEach((li) => {
      const original = li.__cryoOriginalNavItem;
      if (original) primaryNav.appendChild(original);
      li.remove();
    });
    morePanel.hidden = true;
    moreBtn.setAttribute('aria-expanded', 'false');
    moreBtn.setAttribute('aria-disabled', 'true');
  };

  // Create menu entry in ⋮ list pointing to the moved nav item
  const addOverflowEntry = (navItem) => {
    const link = navItem.querySelector(':scope > a, :scope > .wp-block-navigation-item__content');
    const href = link?.getAttribute('href') || '#';
    const text = (link?.textContent || '').trim() || '更多';

    const li = document.createElement('li');
    li.setAttribute('data-cryo-overflow-item', '1');
    li.__cryoOriginalNavItem = navItem;

    const a = document.createElement('a');
    a.href = href;
    a.textContent = text;
    li.appendChild(a);
    moreList.appendChild(li);
  };

  // Measure and move items that don't fit into the primary nav into the ⋮ menu.
  const recomputeOverflow = () => {
    resetOverflow();

    // If we're not on desktop widths, never overflow-move items.
    // This keeps the full menu intact for the separate mobile implementation.
    if (!isDesktop()) {
      return;
    }

    const navWrap = root.querySelector('.cryo-nav__primaryWrap');
    // Available width is the primary nav wrapper (center column)
    const available = navWrap?.clientWidth || 0;
    if (!available) return;

    const items = Array.from(primaryNav.children).filter((el) => el.classList?.contains('wp-block-navigation-item'));
    if (items.length === 0) return;

    // Don't use scrollWidth/clientWidth here: WP nav UL can expand to fit contents (no overflow),
    // which makes scrollWidth == clientWidth even when it visually exceeds the container.
    // Instead, compute the total width of top-level items + gap and compare to available.
    const getGapPx = () => {
      const gap = getComputedStyle(primaryNav).columnGap || getComputedStyle(primaryNav).gap || '0px';
      const n = parseFloat(gap);
      return Number.isFinite(n) ? n : 0;
    };
    const totalItemsWidth = () => {
      const gap = getGapPx();
      const els = Array.from(primaryNav.children).filter((el) => el.classList?.contains('wp-block-navigation-item'));
      let total = 0;
      els.forEach((el) => {
        total += el.getBoundingClientRect().width;
      });
      if (els.length > 1) total += gap * (els.length - 1);
      return total;
    };
    const overflows = () => (totalItemsWidth() - available) > 2; // small tolerance

    // If it overflows, move from the end backwards into the ⋮ list.
    // Keep at least 1 item in the primary nav.
    // Only overflow when it truly doesn't fit.
    if (!overflows()) {
      moreBtn.setAttribute('aria-disabled', 'true');
      morePanel.hidden = true;
      return;
    }

    // As soon as we need overflow, enable ⋮.
    moreBtn.setAttribute('aria-disabled', 'false');

    // Move from the end backwards until it fits.
    while (items.length > 1 && overflows()) {
      const last = items.pop();
      if (!last) break;
      // Remove from primary nav and create an overflow entry.
      last.remove();
      addOverflowEntry(last);
    }

    // If nothing ended up in ⋮, disable it.
    if (moreList.children.length === 0) {
      moreBtn.setAttribute('aria-disabled', 'true');
      morePanel.hidden = true;
    }
  };

  // Toggle the ⋮ panel
  moreBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (moreBtn.getAttribute('aria-disabled') === 'true') return;
    const open = morePanel.hidden;
    morePanel.hidden = !open;
    moreBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('click', (e) => {
    if (!moreWrap.contains(e.target)) {
      morePanel.hidden = true;
      moreBtn.setAttribute('aria-expanded', 'false');
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      morePanel.hidden = true;
      moreBtn.setAttribute('aria-expanded', 'false');
    }
  });

  // Recompute on resize (debounced)
  let t = null;
  const onResize = () => {
    if (t) window.clearTimeout(t);
    t = window.setTimeout(recomputeOverflow, 80);
  };
  window.addEventListener('resize', onResize, { passive: true });

  // Initial compute (after layout)
  window.setTimeout(recomputeOverflow, 0);
});

// Hero banner behavior: lightweight swiper (dots + drag) using placeholder slides for now
document.addEventListener('DOMContentLoaded', () => {
  const heroes = Array.from(document.querySelectorAll('[data-cryo-hero]'));
  if (heroes.length === 0) return;

  heroes.forEach((hero) => {
    const track = hero.querySelector('[data-cryo-hero-track]');
    const slides = Array.from(hero.querySelectorAll('[data-cryo-hero-slide]'));
    const dots = Array.from(hero.querySelectorAll('[data-cryo-hero-dot]'));
    if (!track || slides.length === 0 || dots.length === 0) return;

    let index = 0;
    let timer = null;
    let startX = 0;
    let dragging = false;

    const setActive = (next) => {
      const n = slides.length;
      index = ((next % n) + n) % n;
      track.style.transform = `translate3d(${-index * 100}%, 0, 0)`;
      slides.forEach((s, i) => s.classList.toggle('is-active', i === index));
      dots.forEach((d, i) => {
        const active = i === index;
        d.classList.toggle('is-active', active);
        d.toggleAttribute('aria-current', active);
      });
    };

    const stop = () => {
      if (timer) window.clearInterval(timer);
      timer = null;
    };
    const start = () => {
      stop();
      if (slides.length <= 1) return;
      const intervalAttr = hero.getAttribute('data-cryo-hero-interval');
      const interval = intervalAttr ? parseInt(intervalAttr, 10) : 6500;
      const ms = Number.isFinite(interval) && interval > 500 ? interval : 6500;
      timer = window.setInterval(() => setActive(index + 1), ms);
    };

    dots.forEach((btn, i) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        setActive(i);
        start();
      });
    });

    // Touch / pointer drag (mobile)
    const onDown = (x) => {
      dragging = true;
      startX = x;
      stop();
    };
    const onUp = (x) => {
      if (!dragging) return;
      dragging = false;
      const dx = x - startX;
      const threshold = 40;
      if (dx > threshold) setActive(index - 1);
      else if (dx < -threshold) setActive(index + 1);
      start();
    };

    hero.addEventListener('touchstart', (e) => onDown(e.touches?.[0]?.clientX ?? 0), { passive: true });
    hero.addEventListener('touchend', (e) => onUp(e.changedTouches?.[0]?.clientX ?? 0), { passive: true });

    // Pause on hover/focus (desktop)
    hero.addEventListener('mouseenter', stop);
    hero.addEventListener('mouseleave', start);
    hero.addEventListener('focusin', stop);
    hero.addEventListener('focusout', start);

    // Init
    setActive(0);
    start();
  });
});

// Desktop language switcher: globe button toggles a popup with EN/中
document.addEventListener('DOMContentLoaded', () => {
  if (typeof window.matchMedia === 'function' && !window.matchMedia('(min-width: 1024px)').matches) return;

  const wrap = document.querySelector('[data-cryo-lang-switcher]');
  if (!wrap) return;
  const btn = wrap.querySelector('.cryo-nav__langBtn');
  const panel = wrap.querySelector('.cryo-nav__langPanel');
  if (!btn || !panel) return;

  const close = () => {
    panel.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  };
  const open = () => {
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
  };
  const toggle = () => {
    if (panel.hidden) open();
    else close();
  };

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    toggle();
  });

  document.addEventListener('click', (e) => {
    if (!wrap.contains(e.target)) close();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  // Start closed
  close();
});

// Mobile navigation behavior: hamburger toggles orange drawer (slide in left → right)
document.addEventListener('DOMContentLoaded', () => {
  // Install handlers once; decide mobile/desktop at interaction time so DevTools resizing works.
  if (typeof window.matchMedia !== 'function') return; // desktop-first safe default

  const mroot = document.querySelector('[data-cryo-mnav]');
  if (!mroot) return;

  const btn = mroot.querySelector('.cryo-mnav__menuBtn');
  const drawer = document.querySelector('.cryo-mobileMenu');
  const listHost = drawer?.querySelector('[data-cryo-mobile-menu-list]');
  if (!btn || !drawer || !listHost) return;

  const desktopRoot = document.querySelector('.cryo-nav[data-cryo-nav]');
  const isMobile = () => window.matchMedia('(max-width: 1023px)').matches;
  const isDesktop = () => window.matchMedia('(min-width: 1024px)').matches;

  const getAdminBarHeight = () => {
    const admin = document.getElementById('wpadminbar');
    return admin ? Math.round(admin.getBoundingClientRect().height) : 0;
  };
  const getTopbarHeight = () => {
    const topbar = document.querySelector('.cryo-topbar');
    return topbar ? Math.round(topbar.getBoundingClientRect().height) : 0;
  };
  const getHeaderHeight = () => {
    const header = document.querySelector('.cryo-header');
    return header ? Math.round(header.getBoundingClientRect().height) : 0;
  };
  const setHeaderVars = () => {
    const adminH = getAdminBarHeight();
    const topbarH = getTopbarHeight();
    const headerH = getHeaderHeight();
    const stackH = adminH + topbarH + headerH;
    const root = document.documentElement;
    root.style.setProperty('--cryo-adminbar-height', `${adminH}px`);
    root.style.setProperty('--cryo-topbar-height', `${topbarH}px`);
    root.style.setProperty('--cryo-header-height', `${headerH}px`);
    root.style.setProperty('--cryo-header-stack-height', `${stackH}px`);
  };

  const resetAccordion = () => {
    // Collapse everything and clear active/dim state.
    drawer.classList.remove('has-active');
    listHost.querySelectorAll('.cryo-mobileMenu__section').forEach((section) => {
      section.classList.remove('is-active');
      const btn = section.querySelector('.cryo-mobileMenu__sectionHeader');
      const ul = section.querySelector('.cryo-mobileMenu__subList');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (ul) ul.hidden = true;
    });
  };

  const close = () => {
    document.body.classList.remove('cryo-mobileMenu-open');
    btn.setAttribute('aria-expanded', 'false');
    drawer.setAttribute('aria-hidden', 'true');
    resetAccordion();
  };

  const open = () => {
    setHeaderVars();
    document.body.classList.add('cryo-mobileMenu-open');
    btn.setAttribute('aria-expanded', 'true');
    drawer.setAttribute('aria-hidden', 'false');
  };

  const toggle = () => {
    if (!isMobile()) return;
    const isOpen = document.body.classList.contains('cryo-mobileMenu-open');
    if (isOpen) close();
    else open();
  };

  const getPrimaryNavList = () => {
    if (!desktopRoot) return null;
    const navRoot = desktopRoot.querySelector('.cryo-nav__primaryNav');
    if (!navRoot) return null;
    const lists = Array.from(navRoot.querySelectorAll('ul.wp-block-navigation__container'));
    if (lists.length === 0) return null;
    lists.sort((a, b) => {
      const ac = a.querySelectorAll(':scope > li.wp-block-navigation-item').length;
      const bc = b.querySelectorAll(':scope > li.wp-block-navigation-item').length;
      return bc - ac;
    });
    return lists[0];
  };

  const buildMenu = () => {
    listHost.innerHTML = '';
    drawer.classList.remove('has-active');
    const primaryNav = getPrimaryNavList();
    if (!primaryNav) return;

    const topItems = Array.from(primaryNav.querySelectorAll(':scope > li.wp-block-navigation-item'));
    topItems.forEach((li) => {
      const linkEl = li.querySelector(':scope > a, :scope > .wp-block-navigation-item__content');
      const label = (linkEl?.textContent || '').trim();
      const href = linkEl?.getAttribute('href') || '#';
      if (!label) return;

      const submenu = li.querySelector(':scope > ul.wp-block-navigation__submenu-container');
      const subItems = submenu ? Array.from(submenu.querySelectorAll(':scope > li.wp-block-navigation-item')) : [];

      if (subItems.length > 0) {
        const section = document.createElement('div');
        section.className = 'cryo-mobileMenu__section';

        const headerBtn = document.createElement('button');
        headerBtn.type = 'button';
        headerBtn.className = 'cryo-mobileMenu__sectionHeader';
        // Default: all collapsed
        headerBtn.setAttribute('aria-expanded', 'false');
        headerBtn.textContent = label;

        const chev = document.createElement('span');
        chev.className = 'cryo-mobileMenu__sectionChevron';
        // Icon is rendered via CSS (SVG mask) for cross-device consistency (no font glyph variance).
        chev.textContent = '';
        headerBtn.appendChild(chev);

        const ul = document.createElement('ul');
        ul.className = 'cryo-mobileMenu__subList';
        ul.hidden = true;

        subItems.forEach((sli) => {
          const sLink = sli.querySelector(':scope > a, :scope > .wp-block-navigation-item__content');
          const sLabel = (sLink?.textContent || '').trim();
          const sHref = sLink?.getAttribute('href') || '#';
          if (!sLabel) return;

          const li2 = document.createElement('li');
          const a = document.createElement('a');
          a.className = 'cryo-mobileMenu__subLink';
          a.href = sHref;
          a.textContent = sLabel;
          li2.appendChild(a);
          ul.appendChild(li2);
        });

        headerBtn.addEventListener('click', () => {
          const isExpanded = headerBtn.getAttribute('aria-expanded') === 'true';

          // If collapsing the active section: clear active state (all remain collapsed).
          if (isExpanded) {
            section.classList.remove('is-active');
            headerBtn.setAttribute('aria-expanded', 'false');
            ul.hidden = true;
            drawer.classList.remove('has-active');
            return;
          }

          // Otherwise: make this the ONLY active section.
          drawer.classList.add('has-active');
          listHost.querySelectorAll('.cryo-mobileMenu__section').forEach((other) => {
            const otherBtn = other.querySelector('.cryo-mobileMenu__sectionHeader');
            const otherUl = other.querySelector('.cryo-mobileMenu__subList');
            if (other === section) return;
            other.classList.remove('is-active');
            if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
            if (otherUl) otherUl.hidden = true;
          });

          section.classList.add('is-active');
          headerBtn.setAttribute('aria-expanded', 'true');
          ul.hidden = false;
        });

        section.appendChild(headerBtn);
        section.appendChild(ul);
        listHost.appendChild(section);
      } else {
        const a = document.createElement('a');
        a.className = 'cryo-mobileMenu__row';
        a.href = href;
        a.textContent = label;
        listHost.appendChild(a);
      }
    });
  };

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    if (!isMobile()) return;
    // Build right before opening so it reflects latest WP menu.
    buildMenu();
    toggle();
  });

  // Close when clicking any link inside drawer (design expectation)
  drawer.addEventListener('click', (e) => {
    const a = e.target?.closest?.('a');
    if (a) close();
  });

  // Close on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  // Keep the drawer positioned below the topbar+header as viewport changes
  let t = null;
  window.addEventListener('resize', () => {
    if (t) window.clearTimeout(t);
    t = window.setTimeout(() => {
      setHeaderVars();
      // If we resized into desktop, force close.
      if (isDesktop()) close();
    }, 80);
  }, { passive: true });

  // Init vars + aria state
  setHeaderVars();
  close();
});

// Testimonials slider behavior: arrow navigation between slides
document.addEventListener('DOMContentLoaded', () => {
  const sections = Array.from(document.querySelectorAll('[data-cryo-testimonials]'));
  if (sections.length === 0) return;

  sections.forEach((section) => {
    const track = section.querySelector('[data-cryo-testimonials-track]');
    const slides = Array.from(section.querySelectorAll('[data-cryo-testimonial-slide]'));
    if (!track || slides.length === 0) return;

    let index = 0;

    const setActive = (next) => {
      const n = slides.length;
      index = ((next % n) + n) % n;
      track.style.transform = `translate3d(${-index * 100}%, 0, 0)`;
      slides.forEach((s, i) => s.classList.toggle('is-active', i === index));
    };

    // Navigation buttons (inside each slide)
    section.addEventListener('click', (e) => {
      const prevBtn = e.target.closest('[data-cryo-testimonial-prev]');
      const nextBtn = e.target.closest('[data-cryo-testimonial-next]');
      if (prevBtn) {
        e.preventDefault();
        setActive(index - 1);
      } else if (nextBtn) {
        e.preventDefault();
        setActive(index + 1);
      }
    });

    // Touch / swipe support
    let startX = 0;
    let dragging = false;
    const onDown = (x) => { dragging = true; startX = x; };
    const onUp = (x) => {
      if (!dragging) return;
      dragging = false;
      const dx = x - startX;
      const threshold = 50;
      if (dx > threshold) setActive(index - 1);
      else if (dx < -threshold) setActive(index + 1);
    };
    section.addEventListener('touchstart', (e) => onDown(e.touches?.[0]?.clientX ?? 0), { passive: true });
    section.addEventListener('touchend', (e) => onUp(e.changedTouches?.[0]?.clientX ?? 0), { passive: true });

    // Init
    setActive(0);
  });
});

// Tech Carousel behavior: arrow navigation for horizontal scroll (技術優勢)
document.addEventListener('DOMContentLoaded', () => {
  const carousels = Array.from(document.querySelectorAll('[data-cryo-tech-carousel]'));
  if (carousels.length === 0) return;

  carousels.forEach((carousel) => {
    const track = carousel.querySelector('.cryo-techCarousel__track');
    const prevBtn = carousel.querySelector('[data-cryo-tech-prev]');
    const nextBtn = carousel.querySelector('[data-cryo-tech-next]');
    const cards = Array.from(carousel.querySelectorAll('.cryo-techCard'));
    if (!track || cards.length === 0) return;

    // Get card width including gap for scroll calculation
    const getScrollAmount = () => {
      const card = cards[0];
      if (!card) return 320;
      const style = getComputedStyle(track);
      const gap = parseFloat(style.gap) || 24;
      return card.offsetWidth + gap;
    };

    // Scroll left/right by one card width
    const scrollBy = (direction) => {
      const amount = getScrollAmount();
      track.scrollBy({
        left: direction * amount,
        behavior: 'smooth'
      });
    };

    if (prevBtn) {
      prevBtn.addEventListener('click', (e) => {
        e.preventDefault();
        scrollBy(-1);
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', (e) => {
        e.preventDefault();
        scrollBy(1);
      });
    }

    // Touch / swipe support for mobile
    let startX = 0;
    let dragging = false;
    const onDown = (x) => { dragging = true; startX = x; };
    const onUp = (x) => {
      if (!dragging) return;
      dragging = false;
      const dx = x - startX;
      const threshold = 50;
      if (dx > threshold) scrollBy(-1);
      else if (dx < -threshold) scrollBy(1);
    };
    track.addEventListener('touchstart', (e) => onDown(e.touches?.[0]?.clientX ?? 0), { passive: true });
    track.addEventListener('touchend', (e) => onUp(e.changedTouches?.[0]?.clientX ?? 0), { passive: true });
  });
});

// History Carousel behavior: arrow navigation between slides (我們的過去、現在和未來)
document.addEventListener('DOMContentLoaded', () => {
  const carousels = Array.from(document.querySelectorAll('[data-cryo-history-carousel]'));
  if (carousels.length === 0) return;

  carousels.forEach((carousel) => {
    const slides = Array.from(carousel.querySelectorAll('[data-cryo-history-slide]'));
    if (slides.length === 0) return;

    let index = 0;

    const setActive = (next) => {
      const n = slides.length;
      index = ((next % n) + n) % n;
      slides.forEach((s, i) => {
        s.classList.toggle('cryo-history__slide--active', i === index);
      });
    };

    // Navigation buttons (inside each slide's panel)
    carousel.addEventListener('click', (e) => {
      const prevBtn = e.target.closest('[data-cryo-history-prev]');
      const nextBtn = e.target.closest('[data-cryo-history-next]');
      if (prevBtn) {
        e.preventDefault();
        setActive(index - 1);
      } else if (nextBtn) {
        e.preventDefault();
        setActive(index + 1);
      }
    });

    // Touch / swipe support
    let startX = 0;
    let dragging = false;
    const onDown = (x) => { dragging = true; startX = x; };
    const onUp = (x) => {
      if (!dragging) return;
      dragging = false;
      const dx = x - startX;
      const threshold = 50;
      if (dx > threshold) setActive(index - 1);
      else if (dx < -threshold) setActive(index + 1);
    };
    carousel.addEventListener('touchstart', (e) => onDown(e.touches?.[0]?.clientX ?? 0), { passive: true });
    carousel.addEventListener('touchend', (e) => onUp(e.changedTouches?.[0]?.clientX ?? 0), { passive: true });

    // Init
    setActive(0);
  });
});

// Enquiry Form submission handler
document.addEventListener('DOMContentLoaded', () => {
  const forms = Array.from(document.querySelectorAll('[data-cryo-enquiry-form]'));
  if (forms.length === 0) return;

  forms.forEach((form) => {
    const successMsg = form.querySelector('.cryo-form__message--success');
    const errorMsg = form.querySelector('.cryo-form__message--error');
    const submitBtn = form.querySelector('.cryo-form__submit');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      // Hide any previous messages
      if (successMsg) successMsg.style.display = 'none';
      if (errorMsg) errorMsg.style.display = 'none';

      // Disable submit button
      if (submitBtn) {
        submitBtn.disabled = true;
        const textEl = submitBtn.querySelector('.cryo-form__submitText');
        if (textEl) textEl.textContent = '提交中...';
      }

      try {
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
          data[key] = value;
        });

        const response = await fetch('/wp-json/cryo/v1/enquiry', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(data),
        });

        const result = await response.json();

        if (response.ok && result.success) {
          // Show success message
          if (successMsg) successMsg.style.display = 'block';
          // Reset form
          form.reset();
        } else {
          // Show error message
          if (errorMsg) errorMsg.style.display = 'block';
        }
      } catch (error) {
        console.error('Form submission error:', error);
        if (errorMsg) errorMsg.style.display = 'block';
      } finally {
        // Re-enable submit button
        if (submitBtn) {
          submitBtn.disabled = false;
          const textEl = submitBtn.querySelector('.cryo-form__submitText');
          if (textEl) textEl.textContent = '提交表格';
        }
      }
    });
  });
});

// News Archive: Search form submit on Enter key
document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.querySelector('.cryo-filterBar__searchInput');
  if (searchInput) {
    searchInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.target.closest('form').submit();
      }
    });
  }
});

